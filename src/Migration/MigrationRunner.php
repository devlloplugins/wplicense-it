<?php
/**
 * Runs the migration inside WordPress.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Migration;

use Devllo\WPLicenseIt\Plugin;

/**
 * Schedules the migration with WP-Cron, shows progress to administrators, and builds the Migrator.
 */
final class MigrationRunner {

	public const CRON_HOOK    = 'wplit_migration_tick';
	public const ADMIN_ACTION = 'wplit_run_migration';
	private const LOCK        = 'wplit_migration_lock';

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_action( 'plugins_loaded', array( $this, 'maybe_schedule' ), 20 );
		add_action( self::CRON_HOOK, array( $this, 'tick' ) );
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
		add_action( 'admin_post_' . self::ADMIN_ACTION, array( $this, 'handle_admin_request' ) );
	}

	/**
	 * Builds the migrator from the live database.
	 */
	public static function migrator(): Migrator {
		global $wpdb;

		$converter = new LegacyConverter( wp_timezone() );
		$products  = new WpProductMetaMigrator( $converter );

		return new Migrator(
			new WpdbLegacySource( $wpdb ),
			new WpdbMigrationTarget( $wpdb ),
			new OptionStateStore(),
			Plugin::instance()->licenses(),
			$converter,
			array( $products, 'migrate' ),
			static function (): void {
				update_option( 'wplit_db_version', '2.0.0', true );
			},
			(bool) apply_filters( 'wplicense_it_keep_legacy_billing', (bool) get_option( 'wplit_keep_legacy_billing', true ) ),
			static fn( int $product_id ): string => (string) get_post_meta( $product_id, 'wplit_product_api_key', true )
		);
	}

	/**
	 * Schedules a migration step if the migration is not finished.
	 */
	public function maybe_schedule(): void {
		$state = ( new OptionStateStore() )->load();

		if ( MigrationState::DONE === $state->status || MigrationState::NEEDS_ATTENTION === $state->status ) {
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK );
		}
	}

	/**
	 * Cron callback: runs a slice of the migration and schedules the next one.
	 */
	public function tick(): void {
		$state = $this->run_locked( 20, false );

		if ( null !== $state && MigrationState::DONE !== $state->status && MigrationState::NEEDS_ATTENTION !== $state->status ) {
			wp_schedule_single_event( time() + 10, self::CRON_HOOK );
		}
	}

	/**
	 * Runs the migration while holding a lock, so cron and the admin button cannot overlap.
	 *
	 * @param int  $budget        Seconds to run for.
	 * @param bool $accept_errors Switch over even if verification found problems.
	 * @return MigrationState|null Null if another run holds the lock.
	 */
	private function run_locked( int $budget, bool $accept_errors ): ?MigrationState {
		if ( get_transient( self::LOCK ) ) {
			return null;
		}

		set_transient( self::LOCK, 1, 2 * MINUTE_IN_SECONDS );

		try {
			$source = new WpdbLegacySource( $GLOBALS['wpdb'] );

			// Nothing to migrate, for example on a fresh install: switch straight to 2.0.
			if ( ! $source->has_tables() ) {
				update_option( 'wplit_db_version', '2.0.0', true );
				$store        = new OptionStateStore();
				$state        = $store->load();
				$state->status = MigrationState::DONE;
				$state->finalized = true;
				$store->save( $state );

				return $state;
			}

			return self::migrator()->run( $budget, 200, $accept_errors );
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/**
	 * Shows migration progress and problems to administrators.
	 */
	public function admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$state = ( new OptionStateStore() )->load();

		if ( MigrationState::DONE === $state->status ) {
			return;
		}

		$needs_attention = MigrationState::NEEDS_ATTENTION === $state->status;
		?>
		<div class="notice <?php echo $needs_attention ? 'notice-error' : 'notice-info'; ?>">
			<p>
				<strong><?php esc_html_e( 'WPLicense It is moving your licenses to the new 2.0 format.', 'wplicense-it' ); ?></strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of licenses, 2: number of orders. */
						__( 'Migrated so far: %1$d licenses, %2$d orders. Your customers\' licenses keep working during the move.', 'wplicense-it' ),
						$state->licenses_migrated,
						$state->orders_migrated
					)
				);
				?>
			</p>
			<?php if ( array() !== $state->problems ) : ?>
				<ul style="list-style: disc; margin-left: 2em;">
					<?php foreach ( array_slice( $state->problems, 0, 10 ) as $problem ) : ?>
						<li><?php echo esc_html( $problem ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p>
				<?php $this->button( 'retry', __( 'Run the migration now', 'wplicense-it' ), false ); ?>
				<?php if ( $needs_attention ) : ?>
					<?php $this->button( 'accept', __( 'Switch to 2.0 anyway', 'wplicense-it' ), true ); ?>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Prints a form button that runs the migration.
	 *
	 * @param string $mode  retry or accept.
	 * @param string $label Button label.
	 * @param bool   $warn  Ask for confirmation.
	 */
	private function button( string $mode, string $label, bool $warn ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block; margin-right: 8px;">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ADMIN_ACTION ); ?>">
			<input type="hidden" name="mode" value="<?php echo esc_attr( $mode ); ?>">
			<?php wp_nonce_field( self::ADMIN_ACTION ); ?>
			<button type="submit" class="button <?php echo $warn ? '' : 'button-primary'; ?>"
				<?php if ( $warn ) : ?>
					onclick="return confirm('<?php echo esc_js( __( 'Rows that could not be migrated will be left behind. Continue?', 'wplicense-it' ) ); ?>');"
				<?php endif; ?>>
				<?php echo esc_html( $label ); ?>
			</button>
		</form>
		<?php
	}

	/**
	 * Handles the admin buttons.
	 */
	public function handle_admin_request(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wplicense-it' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ADMIN_ACTION );

		$accept = isset( $_POST['mode'] ) && 'accept' === sanitize_key( wp_unslash( $_POST['mode'] ) );

		$this->run_locked( 25, $accept );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
