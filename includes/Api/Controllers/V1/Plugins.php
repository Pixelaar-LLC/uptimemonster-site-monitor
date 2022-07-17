<?php

namespace AbsolutePlugins\RoxwpSiteMonitor\Api\Controllers\V1;

use AbsolutePlugins\RoxwpSiteMonitor\Api\Controllers\Controller_Base;

/**
 * Class Plugins
 */
class Plugins extends Controller_Base {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = '/plugin';

	/**
	 * constructor.
	 */
	public function __construct() {

	}

	/**
	 * Register routes.
	 * @return void
	 */
	public function register_routes() {

		// Register install plugin.
		register_rest_route(
			$this->namespace,
			$this->rest_base . '/install',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'install_plugins' ),
					'permission_callback' => array( $this, 'get_route_access' ),
					'args'                => array(),
				),

			)
		);

		// Register activate plugin.
		register_rest_route(
			$this->namespace,
			$this->rest_base . '/activate',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'activate_plugins' ),
					'permission_callback' => array( $this, 'get_route_access' ),
					'args'                => array(),
				),

			)
		);

		// Register deactivate plugin.
		register_rest_route(
			$this->namespace,
			$this->rest_base . '/deactivate',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'deactivate_plugins' ),
					'permission_callback' => array( $this, 'get_route_access' ),
					'args'                => array(),
				),

			)
		);

		// Register uninstall plugin.
		register_rest_route(
			$this->namespace,
			$this->rest_base . '/uninstall',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'uninstall_plugins' ),
					'permission_callback' => array( $this, 'get_route_access' ),
					'args'                => array(),
				),

			)
		);

		// Register delete plugin.
		register_rest_route(
			$this->namespace,
			$this->rest_base . '/delete',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'delete_plugins' ),
					'permission_callback' => array( $this, 'get_route_access' ),
					'args'                => array(),
				),

			)
		);

		// Register update plugin.
		register_rest_route(
			$this->namespace,
			$this->rest_base . '/update',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_plugins' ),
					'permission_callback' => array( $this, 'get_route_access' ),
					'args'                => array(),
				),

			)
		);

	}

	/**
	 * @param \WP_REST_Request $request Full details about the request.
	 *
	 * @return \WP_Error|\WP_HTTP_Response|\WP_REST_Response|
	 */
	public function install_plugins( \WP_REST_Request $request ) {

		$response = array(
			'action' => 'install',
			'data'   => [],
			'extra'  => []
		);

		$data = json_decode( $request->get_body() );

		if ( ! isset( $data->slugs ) || empty( $data->slugs ) ) {
			return rest_ensure_response( [
					'status' => false,
					'action' => 'install',
					'data'   => [
						'message' => __( 'No plugin specified.', 'roxwp-site-mon' ),
					],
					'extra'  => []
				]
			);
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		if ( ! class_exists( 'WP_Ajax_Upgrader_Skin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
		}
		if ( ! class_exists( 'WP_Filesystem_Base' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		}

		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$statuses = [];
		foreach ( $data->slugs as $path ) {
			$status = array( 'status' => false );
			$slug   = $this->get_slug( $path );

			if ( ! $this->is_plugin_exists( $path ) ) {

				$api = plugins_api(
					'plugin_information',
					array(
						'slug'   => $slug,
						'fields' => array(
							'sections' => false,
						),
					)
				);

				if ( is_wp_error( $api ) ) {
					$status['message']  = $api->get_error_message();
					$statuses [ $path ] = $status;
					continue;
				}

				$result = $upgrader->install( $api->download_link );
				if ( is_wp_error( $result ) ) {
					$status['message'] = $result->get_error_message();
				} elseif ( is_wp_error( $skin->result ) ) {
					$status['message'] = $skin->result->get_error_message();
				} elseif ( $skin->get_errors()->has_errors() ) {
					$status['message'] = $skin->get_error_messages();
				} elseif ( is_null( $result ) ) {
					global $wp_filesystem;

					// Pass through the error from WP_Filesystem if one was raised.
					if ( $wp_filesystem instanceof \WP_Filesystem_Base && is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->has_errors() ) {
						$status['message'] = esc_html( $wp_filesystem->errors->get_error_message() );
					} else {
						$status['message'] = __( 'Unable to connect to the filesystem. Please confirm your credentials.' );
					}
				}

				if ( ! isset( $status['message'] ) ) {
					$status['status']  = true;
					$status['message'] = sprintf( __( '%s installed.', 'roxwp-site-mon' ), $slug );
				}
			} else {
				$status['status']  = false;
				$status['message'] = sprintf( __( '%s already exists.', 'roxwp-site-mon' ), $slug );
			}

			$statuses [ $path ] = $status;
		}


		$response['status'] = true;
		$response['data']   = $statuses;

		return rest_ensure_response( $response );
	}

	/**
	 * @param \WP_REST_Request $request Full details about the request.
	 *
	 * @return \WP_Error|\WP_HTTP_Response|\WP_REST_Response|
	 */
	public function activate_plugins( $request ) {

		$data = json_decode( $request->get_body() );

		$response = [
			'status' => true,
			'action' => 'activate',
			'data'   => [],
			'extra'  => [],
		];

		if ( ! isset( $data->slugs ) || empty( $data->slugs ) ) {
			return rest_ensure_response( [
					'action' => 'activate',
					'status' => false,
					'data'   => [
						'message' => __( 'No plugin specified.', 'roxwp-site-mon' ),
					],
					'extra'  => []
				]
			);
		}


		$statuses = [];
		foreach ( $data->slugs as $slug ) {
			$status = array();
			$plugin = ( isset( $slug ) ) ? esc_attr( $slug ) : false;
			if ( $this->is_plugin_exists( $plugin ) ) {

				if ( is_plugin_active( $plugin ) ) {
					$status['status']  = false;
					$status['message'] = __( 'Plugin already active', 'roxwp-site-mon' );
					$statuses[ $slug ] = $status;
					continue;
				}
				$activate = activate_plugin( $plugin, '', false, false );
				if ( is_wp_error( $activate ) ) {
					$status['status']  = false;
					$status['message'] = $activate->get_error_message();
				} else {
					$status['status']  = true;
					$status['message'] = __( 'Plugin activated', 'roxwp-site-mon' );
				}

			} else {
				$status['status']  = false;
				$status['message'] = __( 'Plugin does not exist', 'roxwp-site-mon' );
			}

			$statuses[ $slug ] = $status;
		}

		$response['data'] = $statuses;

		return rest_ensure_response( $response );
	}

	/**
	 * @param \WP_REST_Request $request Full details about the request.
	 *
	 * @return \WP_Error|\WP_HTTP_Response|\WP_REST_Response|
	 */
	public function deactivate_plugins( \WP_REST_Request $request ) {
		$action   = 'deactivate';
		$response = [
			'status' => true,
			'action' => 'deactivate',
			'data'   => [],
			'extra'  => [],
		];

		$data = json_decode( $request->get_body() );

		if ( ! isset( $data->slugs ) || empty( $data->slugs ) ) {
			return rest_ensure_response( [
					'status' => false,
					'action' => 'deactivate',
					'data'   => [
						'message' => __( 'No plugin specified.', 'roxwp-site-mon' ),
					],
					'extra'  => [],
				]
			);
		}

		// Prevent from deactivating self.
		$slugs = $this->exclude_self( $data->slugs, $action );

		$existed_plugins = $this->existed_plugins( $slugs );


		$count = count( $slugs );
		if ( count( $existed_plugins ) === 0 ) {
			return rest_ensure_response( [
				'status' => false,
				'action' => 'deactivate',
				'data'   => [
					'message' => _n( 'Plugin does\'nt exists with this slug', 'Plugins does\'nt exists with these slugs', $count, 'roxwp-site-mon' ),
				],
				'extra'  => [],
			] );
		}

		deactivate_plugins( $existed_plugins );

		$statuses = [];
		foreach ( $existed_plugins as $plugin ) {
			$statuses[ $plugin ]['status'] = is_plugin_inactive( $plugin );
		}

		$response['status'] = true;
		$response['data']   = $statuses;

		return rest_ensure_response( $response );
	}

	/**
	 * @param \WP_REST_Request $request Full details about the request.
	 *
	 * @return \WP_Error|\WP_HTTP_Response|\WP_REST_Response|
	 */
	public function uninstall_plugins( \WP_REST_Request $request ) {

		$response = [
			'status' => true,
			'action' => 'uninstall',
			'data'   => [],
			'extra'  => [],
		];

		$data = json_decode( $request->get_body() );

		if ( ! isset( $data->slugs ) || empty( $data->slugs ) ) {
			return rest_ensure_response( [
					'status' => false,
					'action' => 'uninstall',
					'data'   => [
						'message' => __( 'No plugin specified.', 'roxwp-site-mon' ),
					],
					'extra'  => [],
				]
			);
		}


		// Prevent from uninstall self.
		$slugs = $this->exclude_self( $data->slugs, 'uninstall' );

		$existed_plugins = $this->existed_plugins( $slugs );

		$count = count( $slugs );
		if ( count( $existed_plugins ) === 0 ) {
			return rest_ensure_response( [
				'status' => false,
				'action' => 'uninstall',
				'data'   => [
					'message' => _n( 'Plugin does\'nt exists with this slug', 'Plugins does\'nt exists with these slugs', $count, 'roxwp-site-mon' ),
				],
				'extra'  => [],
			] );
		}
		$statuses = [];
		foreach ( $existed_plugins as $slug ) {
			$status = [ 'status' => false, ];

			if ( is_uninstallable_plugin( $slug ) ) {
				uninstall_plugin( $slug );
				if ( is_plugin_active( $slug ) ) {
					$status['message'] = __( 'Uninstallation failed', 'roxwp-site-mon' );
				} else {
					$status['status']  = true;
					$status['message'] = __( 'Plugin uninstalled', 'roxwp-site-mon' );
				}
			} else {
				$status['message'] = __( 'Can\'nt be uninstalled', 'roxwp-site-mon' );
			}

			$statuses[ $slug ] = $status;
		}

		$response['data'] = $statuses;

		return rest_ensure_response( $response );
	}

	/**
	 * @param \WP_REST_Request $request Full details about the request.
	 *
	 * @return \WP_Error|\WP_HTTP_Response|\WP_REST_Response|
	 */
	public function delete_plugins( \WP_REST_Request $request ) {

		$response = [
			'status' => true,
			'action' => 'delete',
			'data'   => [],
			'extra'  => [],
		];

		$data = json_decode( $request->get_body() );

		if ( ! isset( $data->slugs ) || empty( $data->slugs ) ) {
			return rest_ensure_response( [
					'status' => false,
					'data'   => [
						'message' => __( 'No plugin specified.', 'roxwp-site-mon' ),
					],
					'extra'  => []
				]
			);
		}

		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Prevent from delete self.
		$slugs = $this->exclude_self( $data->slugs, 'uninstall' );

		$existed_plugins = $this->existed_plugins( $slugs );

		if ( count( $existed_plugins ) === 0 ) {
			$count = count( $data->slugs );

			return rest_ensure_response( [
				'status' => false,
				'action' => 'delete',
				'data'   => [
					'message' => _n( 'Plugin does\'nt exists with this slug', 'Plugins does\'nt exists with these slugs', $count, 'roxwp-site-mon' ),
				],
				'extra'  => [],
			] );
		}
		$status = delete_plugins( $existed_plugins );
		$count  = count( $existed_plugins );
		if ( null === $status ) {
			$response['status'] = false;
			$response['data']   = new \WP_Error( 'filesystem-not-writable', _n( 'Unable to delete plugin. Filesystem is readonly.', 'Unable to delete plugins. Filesystem is readonly.', $count, 'roxwp-site-mon' ) );
		} else if ( ! is_wp_error( $status ) ) {
			$response = [
				'status' => true,
				'data'   => [
					'message' => _n( 'Specified plugin deleted', 'Specified plugins deleted', $count, 'roxwp-site-mon' ),
				]
			];
		}

		return rest_ensure_response( $response );
	}

	/**
	 * @param \WP_REST_Request $request Full details about the request.
	 *
	 * @return \WP_Error|\WP_HTTP_Response|\WP_REST_Response|
	 */
	public function update_plugins( \WP_REST_Request $request ) {

		$response = array(
			'status' => true,
			'action' => 'update',
			'data'   => [],
			'extra'  => []
		);

		$data = json_decode( $request->get_body() );

		if ( ! isset( $data->slugs ) || empty( $data->slugs ) ) {
			return rest_ensure_response( [
					'status' => false,
					'action' => 'update',
					'data'   => [
						'message' => __( 'No plugin specified.', 'roxwp-site-mon' ),
					],
					'extra'  => []
				]
			);
		}

		// Prevent from uninstall self.
		$slugs = $this->exclude_self( $data->slugs, 'uninstall' );

		$existed_plugins = $this->existed_plugins( $slugs );

		if ( count( $existed_plugins ) === 0 ) {
			$count = count( $data->slugs );

			return rest_ensure_response( [
				'status' => false,
				'action' => 'update',
				'data'   => [
					'message' => _n( 'Plugin does\'nt exists with this slug', 'Plugins does\'nt exists with these slugs', $count, 'roxwp-site-mon' ),
				],
				'extra'  => [],
			] );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		if ( ! class_exists( 'WP_Filesystem_Base' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		}
		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		wp_update_plugins();

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$statuses = [];
		foreach ( $existed_plugins as $path ) {
			$status = [
				'status' => false,
			];
			$plugin = plugin_basename( sanitize_text_field( wp_unslash( $path ) ) );

			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );

			$result = $upgrader->bulk_upgrade( array( $plugin ) );

			if ( is_wp_error( $skin->result ) ) {
				$status['message'] = $skin->result->get_error_message();
			} elseif ( $skin->get_errors()->has_errors() ) {
				$status['message'] = $skin->get_error_messages();
			} elseif ( is_array( $result ) && ! empty( $result[ $plugin ] ) ) {
				/*
				 * Plugin is already at the latest version.
				 *
				 * This may also be the return value if the `update_plugins` site transient is empty,
				 * e.g. when you update two plugins in quick succession before the transient repopulates.
				 *
				 * Preferably something can be done to ensure `update_plugins` isn't empty.
				 * For now, surface some sort of error here.
				 */
				if ( true === $result[ $plugin ] ) {
					$status['message'] = $upgrader->strings['up_to_date'];
				} else {
					$plugin_data       = get_plugins( '/' . $result[ $plugin ]['destination_name'] );
					$plugin_data       = reset( $plugin_data );
					$version           = isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : '';
					$status['status']  = true;
					$status['message'] = sprintf( __( '%s Updated. New version %s.', 'roxwp-site-mon' ), $plugin, $version );
				}
			} elseif ( false === $result ) {
				global $wp_filesystem;
				// Pass through the error from WP_Filesystem if one was raised.
				if ( $wp_filesystem instanceof \WP_Filesystem_Base && is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->has_errors() ) {
					$status['message'] = esc_html( $wp_filesystem->errors->get_error_message() );
				} else {
					$status['message'] = __( 'Unable to connect to the filesystem. Please confirm your credentials.', 'roxwp-site-monitor' );
				}
			}

			$statuses [ $path ] = $status;
		}

		$response['data'] = $statuses;

		return rest_ensure_response( $response );
	}

	/**
	 * @param $plugin
	 *
	 * @return bool
	 */
	private function is_plugin_exists( $plugin ) {
		return file_exists( WP_PLUGIN_DIR . '/' . $plugin );
	}

	/**
	 * @param $plugins
	 *
	 * @return array|\WP_Error|\WP_HTTP_Response|\WP_REST_Response
	 */
	private function existed_plugins( $plugins ) {
		$existed_plugins = [];
		foreach ( $plugins as $plugin ) {
			if ( $this->is_plugin_exists( $plugin ) ) {
				$existed_plugins[] = $plugin;
			}
		}

		return $existed_plugins;
	}

	/**
	 * @param $plugin_path
	 *
	 * @return string
	 */
	private function get_slug( $plugin_path ) {
		if ( strpos( $plugin_path, '/' ) > 0 ) {
			$temparr = explode( '/', $plugin_path );
			if ( isset( $temparr[0] ) ) {
				return $temparr[0];
			}
		}

		return null;
	}

	/**
	 * @param $data
	 * @param $action
	 *
	 * @return array|void|\wp_send_json_success
	 */
	public function exclude_self( $data, $action = '' ) {
		$slugs = [];
		if ( in_array( 'roxwp-site-monitor/roxwp-site-monitor.php', $data ) ) {
			if ( count( $data ) === 1 ) {
				return wp_send_json_success( [
					'status' => false,
					'action' => $action,
					'data'   => [
						'message' => sprintf( __( 'roxwp-site-monitor/roxwp-site-monitor.php can\'not be %s.', 'roxwp-site-mon' ), $action ),
					],
					'extra'  => [],
				] );
			}

			$slugs = array_filter( $data, function ( $slug ) {
				if ( 'roxwp-site-monitor/roxwp-site-monitor.php' !== $slug ) {
					return $slug;
				}
			} );
		} else {
			$slugs = $data;
		}

		return $slugs;
	}

}
