#!/usr/bin/env bash
#
# Switches the test site's WooCommerce between its two ways of storing orders, so the browser tests can be run on both:
#   hpos      high-performance order storage (the default on a new store; WooCommerce 9.4 here)
#   classic   orders as posts, which many existing stores still use. It does not support meta queries on orders.
#
#   tests/env/order-storage.sh classic && NODE_PATH=... tests/e2e/run-all.sh 17 19 21
#   tests/env/order-storage.sh hpos
#
# The switch is made in the database because WooCommerce refuses to change it while orders are out of sync (the test site
# does not sync them). Orders made on one storage are not visible on the other; the tests only use orders they make.
set -euo pipefail

case "${1:-}" in
	hpos) VALUE=yes ;;
	classic) VALUE=no ;;
	*) echo "usage: $0 hpos|classic" >&2; exit 2 ;;
esac

mariadb wp_test -e "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('woocommerce_custom_orders_table_enabled', '${VALUE}', 'yes') ON DUPLICATE KEY UPDATE option_value='${VALUE}'; INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('woocommerce_custom_orders_table_data_sync_enabled', 'no', 'yes') ON DUPLICATE KEY UPDATE option_value='no';"
echo "Orders are now stored: ${1}"
