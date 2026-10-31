#!/usr/bin/env bash
#
# Checks that product packages are protected on nginx, which ignores the .htaccess file that protects them on Apache.
#
# It puts a 1.x style package (at the predictable address 1.x used) on the test site, serves the site's files with a real
# nginx and PHP-FPM using the usual WordPress configuration, and then:
#   1. fetches the old address before the upgrade (it must work, otherwise the check proves nothing),
#   2. runs the upgrade step that moves packages into random folders,
#   3. fetches the old address again (it must fail), reads the new address from the product (random folder, same bytes),
#      and tries the folders above the package (no listing, no token in anything they return).
#
#   tests/env/nginx-check.sh
#
# Needs the test site (tests/env/setup.sh), nginx and php-fpm (apt install nginx php-fpm). Uses port 8082.
# Prints one line per check and exits with 1 if any failed.

set -uo pipefail

ROOT="${WPLIT_ENV_ROOT:-/opt/wp-test}"
SITE="$ROOT/wordpress"
PORT="${WPLIT_NGINX_PORT:-8082}"
WORK="$ROOT/nginx"
BASE="http://127.0.0.1:${PORT}"
FAIL=0

check() {
	if [ "$2" = "yes" ]; then echo "  ok    $1"; else echo "  FAIL  $1"; FAIL=1; fi
}

code() { curl -s -o /dev/null -m 10 -w '%{http_code}' "$1"; }

# Whether a URL hands out zip bytes. A missing file is passed on to WordPress, which may answer with a redirect or its own
# 404 page, so the status alone is not the test: the answer must not be a 200 and must not start with the zip signature.
serves_zip() {
	[ "$(code "$1")" = 200 ] && [ "$(curl -s -m 10 -r 0-1 "$1" | head -c 2)" = "PK" ]
}

NGINX="$(command -v nginx || true)"
FPM="$(command -v php-fpm || ls /usr/sbin/php-fpm* 2>/dev/null | head -1)"
if [ -z "$NGINX" ] || [ -z "$FPM" ]; then
	echo "nginx and php-fpm are needed: apt install nginx php-fpm"
	exit 2
fi

mkdir -p "$WORK"

cat > "$WORK/fpm.conf" <<CONF
[global]
pid = $WORK/fpm.pid
error_log = $WORK/fpm.log
daemonize = yes
[www]
listen = $WORK/fpm.sock
listen.mode = 0666
pm = static
pm.max_children = 3
CONF

cat > "$WORK/nginx.conf" <<CONF
pid $WORK/nginx.pid;
error_log $WORK/error.log;
daemon on;
events { worker_connections 64; }
http {
	access_log $WORK/access.log;
	client_body_temp_path $WORK/tmp-body;
	proxy_temp_path $WORK/tmp-proxy;
	fastcgi_temp_path $WORK/tmp-fcgi;
	uwsgi_temp_path $WORK/tmp-uwsgi;
	scgi_temp_path $WORK/tmp-scgi;
	include /etc/nginx/mime.types;
	server {
		listen 127.0.0.1:${PORT};
		root $SITE;
		index index.php;
		# The usual WordPress configuration: nothing here knows about the packages folder.
		location / { try_files \$uri \$uri/ /index.php?\$args; }
		location ~ \\.php\$ {
			include /etc/nginx/fastcgi_params;
			fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
			fastcgi_pass unix:$WORK/fpm.sock;
		}
	}
}
CONF

stop() {
	[ -f "$WORK/nginx.pid" ] && kill "$(cat "$WORK/nginx.pid")" 2>/dev/null
	[ -f "$WORK/fpm.pid" ] && kill "$(cat "$WORK/fpm.pid")" 2>/dev/null
	rm -f "$WORK/nginx.pid" "$WORK/fpm.pid"
}
trap stop EXIT
stop

"$FPM" -R -y "$WORK/fpm.conf" || { echo "php-fpm did not start"; cat "$WORK/fpm.log"; exit 2; }
"$NGINX" -c "$WORK/nginx.conf" -e "$WORK/error.log" || { echo "nginx did not start"; cat "$WORK/error.log"; exit 2; }
for _ in $(seq 1 20); do curl -s -o /dev/null -m 2 "$BASE/wp-includes/images/blank.gif" && break; sleep 0.5; done

echo "== Put a 1.x package at the 1.x address"
SEED="$(php -d memory_limit=512M -r '
	define( "WP_ADMIN", true );
	require "'"$SITE"'/wp-load.php";
	$uploads = wp_upload_dir();
	$id      = wp_insert_post( array( "post_type" => "wplit_product", "post_status" => "publish", "post_title" => "Nginx check" ) );
	$rel     = "wplit-files/nginx-check-" . $id . "/v1.0.0/nginx-check.zip";
	$abs     = $uploads["basedir"] . "/" . $rel;
	wp_mkdir_p( dirname( $abs ) );
	$zip = new ZipArchive();
	$zip->open( $abs, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( "nginx-check/nginx-check.php", "<?php\n/* Plugin Name: Nginx check */\n" );
	$zip->close();
	update_post_meta( $id, "file_dir_path", $rel );
	update_post_meta( $id, "file_dir_location", $uploads["baseurl"] . "/" . $rel );
	// Any WordPress request that arrives now would move the package before it is fetched (the test servers of the
	// browser tests are still running, and Action Scheduler and WP-Cron send requests to them), so the upgrade is
	// only made due in the step that runs it.
	update_option( \Devllo\WPLicenseIt\Files\ProtectedStorage::VERSION_OPTION, \Devllo\WPLicenseIt\Files\ProtectedStorage::VERSION );
	echo wp_json_encode( array( "id" => $id, "rel" => $rel, "md5" => md5_file( $abs ) ) );
')"
ID="$(php -r 'echo json_decode($argv[1])->id;' "$SEED")"
OLD="$(php -r 'echo json_decode($argv[1])->rel;' "$SEED")"
MD5="$(php -r 'echo json_decode($argv[1])->md5;' "$SEED")"
UPLOADS="/wp-content/uploads"
echo "  product $ID, 1.x address $UPLOADS/$OLD"

echo "== Before the upgrade"
# A fresh nginx can answer the first requests slowly or with an error, so give it a few seconds to serve the file.
for _ in $(seq 1 20); do [ "$(code "$BASE$UPLOADS/$OLD")" = 200 ] && break; sleep 0.5; done
STATUS_BEFORE="$(code "$BASE$UPLOADS/$OLD")"
check "nginx serves the 1.x address (so the check below is meaningful; got HTTP $STATUS_BEFORE)" "$([ "$STATUS_BEFORE" = 200 ] && echo yes || echo no)"
if [ "$STATUS_BEFORE" != 200 ]; then
	echo "  file on disk: $(ls -l "$SITE/wp-content/uploads/$OLD" 2>&1)"
	echo "  nginx error log:"; tail -n 8 "$WORK/error.log" | sed 's/^/    /'
fi

echo "== Run the upgrade step"
php -d memory_limit=512M -r '
	define( "WP_ADMIN", true );
	require "'"$SITE"'/wp-load.php";
	delete_option( \Devllo\WPLicenseIt\Files\ProtectedStorage::VERSION_OPTION );
	\Devllo\WPLicenseIt\Files\ProtectedStorage::maybe_create();
'

NEW="$(php -r '
	require "'"$SITE"'/wp-load.php";
	echo get_post_meta( (int) $argv[1], "file_dir_path", true );
' "$ID")"
echo "  new path: $NEW"

echo "== After the upgrade"
check "the product points at a random folder" "$(echo "$NEW" | grep -Eq "^wplit-files/nginx-check-${ID}/v1\.0\.0/[a-f0-9]{32}/nginx-check\.zip$" && echo yes || echo no)"
check "the old address no longer serves the file (HTTP $(code "$BASE$UPLOADS/$OLD"))" "$(serves_zip "$BASE$UPLOADS/$OLD" && echo no || echo yes)"
check "the old address does not answer 200 at all" "$([ "$(code "$BASE$UPLOADS/$OLD")" != 200 ] && echo yes || echo no)"
check "the file did not change in the move" "$([ "$(md5sum "$SITE/wp-content/uploads/$NEW" | cut -d' ' -f1)" = "$MD5" ] && echo yes || echo no)"
check "the old file is gone from disk" "$([ ! -e "$SITE/wp-content/uploads/$OLD" ] && echo yes || echo no)"

TOKEN="$(echo "$NEW" | cut -d/ -f4)"
GUESS="$(echo "$NEW" | sed "s#/$TOKEN/#/00000000000000000000000000000000/#")"
check "a guessed random folder does not serve the file (HTTP $(code "$BASE$UPLOADS/$GUESS"))" "$(serves_zip "$BASE$UPLOADS/$GUESS" && echo no || echo yes)"
check "the real address does serve it (the token is the only protection on nginx)" "$(serves_zip "$BASE$UPLOADS/$NEW" && echo yes || echo no)"

# The folders above the package must not give the random folder away.
for dir in "wplit-files/" "wplit-files/nginx-check-${ID}/" "wplit-files/nginx-check-${ID}/v1.0.0/"; do
	body="$(curl -s -m 10 "$BASE$UPLOADS/$dir")"
	status="$(code "$BASE$UPLOADS/$dir")"
	check "$dir shows no token and no zip (HTTP $status)" "$(echo "$body" | grep -Eq "$TOKEN|\.zip" && echo no || echo yes)"
	check "$dir does not list its contents (HTTP $status)" "$(echo "$body" | grep -qi "index of" && echo no || echo yes)"
done

echo "== Clean up"
php -r '
	require "'"$SITE"'/wp-load.php";
	wp_delete_post( (int) $argv[1], true );
' "$ID"
rm -rf "$SITE/wp-content/uploads/wplit-files/nginx-check-${ID}"

echo
if [ "$FAIL" -eq 0 ]; then echo "Package protection on nginx: all checks passed"; else echo "Package protection on nginx: FAILED"; fi
exit "$FAIL"
