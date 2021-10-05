<?php
/**
 * Data Monitor Base
 *
 * @package RoxwpSiteMonitor\Monitors
 * @version 1.0.0
 * @since RoxwpSiteMonitor 1.0.0
 */

namespace AbsolutePlugins\RoxwpSiteMonitor\Monitors;

use Exception;
use WP_Widget;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	die();
}

class Monitor_Widgets_Activity extends Activity_Monitor_Base {

	use Activity_Monitor_Trait;

	protected $check_maybe_log = false;

	public function init() {
		/**
		 * when sidebar get deleted from new widget editor it fires "rest_save_sidebar"
		 * ultimately wp_set_sidebars_widgets get called before the action which just updates the ids of remaining
		 * widgets on that sidebar and then calls update_option to save the state, the option key is already set for
		 * monitoring by the Option Activity monitor.
		 * @see WP_REST_Sidebars_Controller::update_item()
		 * @see wp_set_sidebars_widgets
		 */

		add_filter( 'widget_update_callback', [ $this, 'on_update' ], 99999, 4 );
		add_action( 'sidebar_admin_setup', [ $this, 'on_delete' ] ); // Widget delete.
	}

	protected function maybe_log_widget( $action, $sidebar, $widget ) {

		/**
		 * Should report activity?
		 *
		 * @param bool $status
		 * @param string $option
		 * @param string $action
		 */
		return (bool) apply_filters( 'roxwp_should_log_widgets_activity', true, $sidebar, $widget );
	}

	/**
	 * @param array $instance
	 * @param array $new_instance
	 * @param array $old_instance
	 * @param WP_Widget $widget
	 *
	 * @return array
	 */
	public function on_update( $instance, $new_instance, $old_instance, WP_Widget $widget ) {
		if ( ! empty( $_REQUEST['sidebar'] ) ) {
			$sidebar = sanitize_text_field( $_REQUEST['sidebar'] );

			if ( $this->maybe_log_widget( Activity_Monitor_Base::ITEM_UPDATED, $sidebar, $widget ) ) {
				try {
					$this->log_activity(
						Activity_Monitor_Base::ITEM_UPDATED,
						0,
						'widget',
						$widget->name,
						[
							'widget_base'  => $widget->id_base,
							'sidebar_name' => $this->get_sidebar_name( $sidebar ),
							'old_instance' => $old_instance,
						]
					);
				} catch ( Exception $e ) {}
			}
		}

		return $instance;
	}

	public function on_delete() {
		if ( 'post' == strtolower( $_SERVER['REQUEST_METHOD'] ) && ! empty( $_REQUEST['widget-id'] ) ) {
			if ( isset( $_REQUEST['sidebar'], $_REQUEST['delete_widget'] ) && 1 === (int) $_REQUEST['delete_widget'] ) {
				$sidebar = sanitize_text_field( $_REQUEST['sidebar'] );
				$widget  = sanitize_text_field( $_REQUEST['id_base'] );
				if ( $this->maybe_log_widget( Activity_Monitor_Base::ITEM_DELETED, $sidebar, '' ) ) {

					$this->log_activity(
						Activity_Monitor_Base::ITEM_DELETED,
						0,
						'widget',
						$widget,
						[
							'widget_base'  => $widget,
							'sidebar_name' => $this->get_sidebar_name( $sidebar ),
						]
					);
				}
			}
		}
	}

	protected function get_sidebar_name( $sidebar ) {
		global $wp_registered_sidebars;

		return isset( $wp_registered_sidebars[ $sidebar ] ) ? $wp_registered_sidebars[ $sidebar ] : $sidebar;
	}
}

// End of file Monitor_Widgets_Activity.php.