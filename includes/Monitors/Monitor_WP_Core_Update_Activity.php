<?php
/**
 * Data Monitor Base
 *
 * @package UptimeMonster\SiteMonitor\Monitors
 * @version 1.0.0
 * @since SiteMonitor 1.0.0
 */

namespace UptimeMonster\SiteMonitor\Monitors;

use UptimeMonster\SiteMonitor\Traits\Singleton;
use Exception;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	die();
}

class Monitor_WP_Core_Update_Activity extends Activity_Monitor_Base {

	use Singleton;

	public function init() {
		add_action( 'admin_head', [ $this, 'log_on_update_start' ] );
		add_action( 'wp_maybe_auto_update', [ $this, 'log_on_update_start' ] );
		add_action( '_core_updated_successfully', [ $this, 'log_on_successful_update' ] );
		// @TODO find way to log update failed.
	}

	protected function maybe_log_activity( $action, $object_id ) {

		/**
		 * Should report activity?
		 *
		 * @param bool $status
		 * @param null $object
		 * @param string $action
		 */
		return (bool) apply_filters( 'uptimemonster_should_log_wp_core_update_activity', true, null, $action );
	}

	/**
	 * @throws Exception
	 */
	public function log_on_update_start() {
		global $pagenow;

		if ( 'wp_maybe_auto_update' === current_filter() ) {
			uptimemonster_switch_to_english();
			/* translators: 1. WordPress Version. */
			$name = __( 'WordPress Auto Upgrading From %s', 'uptimemonster-site-monitor' );
			uptimemonster_restore_locale();

			$version = get_bloginfo( 'version' );

			$this->log_activity(
				Activity_Monitor_Base::ITEM_UPGRADING,
				0,
				'WordPressCore',
				sprintf( $name, $version ),
				[ 'new_version' => $version ]
			);
		}

		if ( 'update-core.php' !== $pagenow ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? $_GET['action'] : 'upgrade-core'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended

		if ( 'do-core-upgrade' === $action || 'do-core-reinstall' === $action ) {
			if ( isset( $_POST['upgrade'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$action = 'do-core-upgrade' === $action ? Activity_Monitor_Base::ITEM_UPGRADING : Activity_Monitor_Base::ITEM_REINSTALLING;
				uptimemonster_switch_to_english();
				/* translators: 1. WordPress Version. */
				$name = 'do-core-upgrade' == $action ? __( 'WordPress Upgrading From %s', 'uptimemonster-site-monitor' ) : __( 'WordPress Reinstalling %s', 'uptimemonster-site-monitor' );
				uptimemonster_restore_locale();

				$version = get_bloginfo( 'version' );

				$this->log_activity(
					$action,
					0,
					'WordPressCore',
					sprintf( $name, $version ),
					[ 'new_version' => $version ]
				);
			}
		}
	}

	public function log_on_successful_update( $version ) {
		global $pagenow;

		uptimemonster_switch_to_english();
		/* translators: 1. WordPress Updated Version. */
		$name = 'update-core.php' !== $pagenow ? __( 'WordPress Auto Updated to %s', 'uptimemonster-site-monitor' ) : __( 'WordPress Updated to %s', 'uptimemonster-site-monitor' );
		uptimemonster_restore_locale();

		$this->log_activity(
			Activity_Monitor_Base::ITEM_UPDATED,
			0,
			'WordPressCore',
			sprintf( $name, $version ),
			[ 'new_version' => $version ]
		);
	}
}

// End of file Monitor_WP_Core_Update_Activity.php.
