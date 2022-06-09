<?php

namespace AbsolutePlugins\RoxwpSiteMonitor\Api;

class RoxWP_Update_Check {

	public function get_site_health(){
		if ( ! class_exists( 'WP_Debug_Data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';
		}
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}

		$site_health =  \WP_Site_Health::get_instance();

		$tests = $site_health::get_tests();

		$results = [];
		foreach ( $tests['direct'] as $test ) {
			if ( ! empty( $test['skip_cron'] ) ) {
				continue;
			}

			if ( is_string( $test['test'] ) ) {
				$test_function = sprintf(
					'get_test_%s',
					$test['test']
				);
				$include_test = [
					'get_test_wordpress_version',
					'get_test_plugin_version',
					'get_test_plugin_theme_auto_updates',
					'detect_plugin_theme_auto_update_issues',
					'get_test_theme_version',
					'get_test_php_version',
				];
				$exclude_tests = [
					'get_test_plugin_theme_auto_updates',
				];

				if( in_array( $test_function, $exclude_tests  ) ) {
					continue;
				}

				if ( in_array( $test_function, $include_test ) && method_exists( $this, $test_function ) && is_callable( array( $this, $test_function ) ) ) {
					$results[] = $this->perform_test( array( $this, $test_function ) );
					continue;
				}

				if ( method_exists( $site_health, $test_function ) && is_callable( array( $site_health, $test_function ) ) ) {
					$results[] = $this->perform_test( array( $site_health, $test_function ) );
					continue;
				}
			}

			if ( is_callable( $test['test'] ) ) {
				$results[] = $this->perform_test( $test['test'] );
			}
		}

		foreach ( $tests['async'] as $test ) {
			if ( ! empty( $test['skip_cron'] ) ) {
				continue;
			}

			// Local endpoints may require authentication, so asynchronous tests can pass a direct test runner as well.
			if ( ! empty( $test['async_direct_test'] ) && is_callable( $test['async_direct_test'] ) ) {
				// This test is callable, do so and continue to the next asynchronous check.
				$results[] = $this->perform_test( $test['async_direct_test'] );
				continue;
			}

			if ( is_string( $test['test'] ) ) {
				// Check if this test has a REST API endpoint.
				if ( isset( $test['has_rest'] ) && $test['has_rest'] ) {
					$result_fetch = wp_remote_get(
						$test['test'],
						array(
							'body' => array(
								'_wpnonce' => wp_create_nonce( 'wp_rest' ),
							),
						)
					);
				} else {
					$result_fetch = wp_remote_post(
						admin_url( 'admin-ajax.php' ),
						array(
							'body' => array(
								'action'   => $test['test'],
								'_wpnonce' => wp_create_nonce( 'Api-site-status' ),
							),
						)
					);
				}

				if ( ! is_wp_error( $result_fetch ) && 200 === wp_remote_retrieve_response_code( $result_fetch ) ) {
					$result = json_decode( wp_remote_retrieve_body( $result_fetch ), true );
				} else {
					$result = false;
				}

				if ( is_array( $result ) ) {
					$results[] = $result;
				} else {
					$results[] = array(
						'status' => 'recommended',
						'label'  => __( 'A test is unavailable' ),
					);
				}
			}
		}

		return $results;
	}


	/**
	 * Test if plugin and theme auto-updates appear to be configured correctly.
	 *
	 * @since 5.5.0
	 *
	 * @return array The test results.
	 */
	public function get_test_plugin_theme_auto_updates() {
		$result = array(
			'label'       => __( 'Plugin and theme auto-updates appear to be configured correctly' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security' ),
				'color' => 'blue',
			),
			'description' => sprintf(
				'<p>%s</p>',
				__( 'Plugin and theme auto-updates ensure that the latest versions are always installed.' )
			),
			'actions'     => '',
			'test'        => 'plugin_theme_auto_updates',
		);

		$check_plugin_theme_updates = $this->detect_plugin_theme_auto_update_issues();

		$result['status'] = $check_plugin_theme_updates->status;

		if ( 'good' !== $result['status'] ) {
			$result['label'] = __( 'Your site may have problems auto-updating plugins and themes' );

			$result['description'] .= sprintf(
				'<p>%s</p>',
				$check_plugin_theme_updates->message
			);
		}

		return $result;
	}

	/**
	 * Check for potential issues with plugin and theme auto-updates.
	 *
	 * Though there is no way to 100% determine if plugin and theme auto-updates are configured
	 * correctly, a few educated guesses could be made to flag any conditions that would
	 * potentially cause unexpected behaviors.
	 *
	 * @since 5.5.0
	 *
	 * @return object The test results.
	 */
	public function detect_plugin_theme_auto_update_issues() {
		$mock_plugin = (object) array(
			'id'            => 'w.org/plugins/a-fake-plugin',
			'slug'          => 'a-fake-plugin',
			'plugin'        => 'a-fake-plugin/a-fake-plugin.php',
			'new_version'   => '9.9',
			'url'           => 'https://wordpress.org/plugins/a-fake-plugin/',
			'package'       => 'https://downloads.wordpress.org/plugin/a-fake-plugin.9.9.zip',
			'icons'         => array(
				'2x' => 'https://ps.w.org/a-fake-plugin/assets/icon-256x256.png',
				'1x' => 'https://ps.w.org/a-fake-plugin/assets/icon-128x128.png',
			),
			'banners'       => array(
				'2x' => 'https://ps.w.org/a-fake-plugin/assets/banner-1544x500.png',
				'1x' => 'https://ps.w.org/a-fake-plugin/assets/banner-772x250.png',
			),
			'banners_rtl'   => array(),
			'tested'        => '5.5.0',
			'requires_php'  => '5.6.20',
		);

		$mock_theme = (object) array(
			'theme'        => 'a-fake-theme',
			'new_version'  => '9.9',
			'url'          => 'https://wordpress.org/themes/a-fake-theme/',
			'package'      => 'https://downloads.wordpress.org/theme/a-fake-theme.9.9.zip',
			'requires'     => '5.0.0',
			'requires_php' => '5.6.20',
		);

		$test_plugins_enabled = $this->wp_is_auto_update_forced_for_item( 'plugin', true, $mock_plugin );
		$test_themes_enabled  = $this->wp_is_auto_update_forced_for_item( 'theme', true, $mock_theme );

		$ui_enabled_for_plugins = $this->wp_is_auto_update_enabled_for_type( 'plugin' );
		$ui_enabled_for_themes  = $this->wp_is_auto_update_enabled_for_type( 'theme' );
		$plugin_filter_present  = has_filter( 'auto_update_plugin' );
		$theme_filter_present   = has_filter( 'auto_update_theme' );

		if ( ( ! $test_plugins_enabled && $ui_enabled_for_plugins )
		     || ( ! $test_themes_enabled && $ui_enabled_for_themes )
		) {
			return (object) array(
				'status'  => 'critical',
				'message' => __( 'Auto-updates for plugins and/or themes appear to be disabled, but settings are still set to be displayed. This could cause auto-updates to not work as expected.' ),
			);
		}

		if ( ( ! $test_plugins_enabled && $plugin_filter_present )
		     && ( ! $test_themes_enabled && $theme_filter_present )
		) {
			return (object) array(
				'status'  => 'recommended',
				'message' => __( 'Auto-updates for plugins and themes appear to be disabled. This will prevent your site from receiving new versions automatically when available.' ),
			);
		} elseif ( ! $test_plugins_enabled && $plugin_filter_present ) {
			return (object) array(
				'status'  => 'recommended',
				'message' => __( 'Auto-updates for plugins appear to be disabled. This will prevent your site from receiving new versions automatically when available.' ),
			);
		} elseif ( ! $test_themes_enabled && $theme_filter_present ) {
			return (object) array(
				'status'  => 'recommended',
				'message' => __( 'Auto-updates for themes appear to be disabled. This will prevent your site from receiving new versions automatically when available.' ),
			);
		}

		return (object) array(
			'status'  => 'good',
			'message' => __( 'There appear to be no issues with plugin and theme auto-updates.' ),
		);
	}


	/**
	 * Checks whether auto-updates are enabled.
	 *
	 * @since 5.5.0
	 *
	 * @param string $type The type of update being checked: 'theme' or 'plugin'.
	 * @return bool True if auto-updates are enabled for `$type`, false otherwise.
	 */
	function wp_is_auto_update_enabled_for_type( $type ) {
		if ( ! class_exists( 'WP_Automatic_Updater' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
		}

		$updater = new WP_Automatic_Updater();
		$enabled = ! $updater->is_disabled();

		switch ( $type ) {
			case 'plugin':
				/**
				 * Filters whether plugins auto-update is enabled.
				 *
				 * @since 5.5.0
				 *
				 * @param bool $enabled True if plugins auto-update is enabled, false otherwise.
				 */
				return apply_filters( 'plugins_auto_update_enabled', $enabled );
			case 'theme':
				/**
				 * Filters whether themes auto-update is enabled.
				 *
				 * @since 5.5.0
				 *
				 * @param bool $enabled True if themes auto-update is enabled, false otherwise.
				 */
				return apply_filters( 'themes_auto_update_enabled', $enabled );
		}

		return false;
	}

	/**
	 * Checks whether auto-updates are forced for an item.
	 *
	 * @since 5.6.0
	 *
	 * @param string    $type   The type of update being checked: 'theme' or 'plugin'.
	 * @param bool|null $update Whether to update. The value of null is internally used
	 *                          to detect whether nothing has hooked into this filter.
	 * @param object    $item   The update offer.
	 * @return bool True if auto-updates are forced for `$item`, false otherwise.
	 */
	function wp_is_auto_update_forced_for_item( $type, $update, $item ) {
		/** This filter is documented in wp-admin/includes/class-wp-automatic-updater.php */
		return apply_filters( "auto_update_{$type}", $update, $item );
	}
	/**
	 * Test if the supplied PHP version is supported.
	 *
	 * @since 5.2.0
	 *
	 * @return array The test results.
	 */
	public function get_test_php_version() {
		$response = $this->wp_check_php_version();

		$result = array(
			'label'       => sprintf(
			/* translators: %s: The current PHP version. */
				__( 'Your site is running the current version of PHP (%s)' ),
				PHP_VERSION
			),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Performance' ),
				'color' => 'blue',
			),
			'description' => sprintf(
				'<p>%s</p>',
				sprintf(
				/* translators: %s: The minimum recommended PHP version. */
					__( 'PHP is the programming language used to build and maintain WordPress. Newer versions of PHP are created with increased performance in mind, so you may see a positive effect on your site&#8217;s performance. The minimum recommended version of PHP is %s.' ),
					$response ? $response['recommended_version'] : ''
				)
			),
			'actions'     => sprintf(
				'<p><a href="%s" target="_blank" rel="noopener">%s <span class="screen-reader-text">%s</span><span aria-hidden="true" class="dashicons dashicons-external"></span></a></p>',
				esc_url( wp_get_update_php_url() ),
				__( 'Learn more about updating PHP' ),
				/* translators: Accessibility text. */
				__( '(opens in a new tab)' )
			),
			'test'        => 'php_version',
		);

		// PHP is up to date.
		if ( ! $response || version_compare( PHP_VERSION, $response['recommended_version'], '>=' ) ) {
			return $result;
		}

		// The PHP version is older than the recommended version, but still receiving active support.
		if ( $response['is_supported'] ) {
			$result['label'] = sprintf(
			/* translators: %s: The server PHP version. */
				__( 'Your site is running an older version of PHP (%s)' ),
				PHP_VERSION
			);
			$result['status'] = 'recommended';

			return $result;
		}

		// The PHP version is only receiving security fixes.
		if ( $response['is_secure'] ) {
			$result['label'] = sprintf(
			/* translators: %s: The server PHP version. */
				__( 'Your site is running an older version of PHP (%s), which should be updated' ),
				PHP_VERSION
			);
			$result['status'] = 'recommended';

			return $result;
		}

		// Anything no longer secure must be updated.
		$result['label'] = sprintf(
		/* translators: %s: The server PHP version. */
			__( 'Your site is running an outdated version of PHP (%s), which requires an update' ),
			PHP_VERSION
		);
		$result['status']         = 'critical';
		$result['badge']['label'] = __( 'Security' );

		return $result;
	}

	/**
	 * Fallback function replicating core behavior from WordPress 5.1.0 to check PHP versions.
	 *
	 * @return array|bool|mixed|object|WP_Error
	 */
	function wp_check_php_version() {
		$version = phpversion();
		$key     = md5( $version );

		$response = get_site_transient( 'php_check_' . $key );
		if ( false === $response ) {
			$url = 'http://api.wordpress.org/core/serve-happy/1.0/';
			if ( wp_http_supports( array( 'ssl' ) ) ) {
				$url = set_url_scheme( $url, 'https' );
			}

			$url = add_query_arg( 'php_version', $version, $url );

			$response = wp_remote_get( $url );

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return false;
			}

			/**
			 * Response should be an array with:
			 *  'recommended_version' - string - The PHP version recommended by WordPress.
			 *  'is_supported' - boolean - Whether the PHP version is actively supported.
			 *  'is_secure' - boolean - Whether the PHP version receives security updates.
			 *  'is_acceptable' - boolean - Whether the PHP version is still acceptable for WordPress.
			 */
			$response = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $response ) ) {
				return false;
			}

			set_site_transient( 'php_check_' . $key, $response, WEEK_IN_SECONDS );
		}

		if ( isset( $response['is_acceptable'] ) && $response['is_acceptable'] ) {
			/**
			 * Filters whether the active PHP version is considered acceptable by WordPress.
			 *
			 * Returning false will trigger a PHP version warning to show up in the admin dashboard to administrators.
			 *
			 * This filter is only run if the wordpress.org Serve Happy API considers the PHP version acceptable, ensuring
			 * that this filter can only make this check stricter, but not loosen it.
			 *
			 * @since 5.1.1
			 *
			 * @param bool   $is_acceptable Whether the PHP version is considered acceptable. Default true.
			 * @param string $version       PHP version checked.
			 */
			$response['is_acceptable'] = (bool) apply_filters( 'wp_is_php_version_acceptable', true, $version );
		}

		return $response;
	}
	/**
	 * Tests for WordPress version and outputs it.
	 *
	 * Gives various results depending on what kind of updates are available, if any, to encourage
	 * the user to install security updates as a priority.
	 *
	 * @since 5.2.0
	 *
	 * @return array The test result.
	 */
	public function get_test_wordpress_version() {
		$result = array(
			'label'       => '',
			'status'      => '',
			'badge'       => array(
				'label' => __( 'Performance' ),
				'color' => 'blue',
			),
			'description' => '',
			'actions'     => '',
			'test'        => 'wordpress_version',
		);

		$core_current_version = get_bloginfo( 'version' );
		$core_updates         = $this->get_core_updates();

		if ( ! is_array( $core_updates ) ) {
			$result['status'] = 'recommended';

			$result['label'] = sprintf(
			/* translators: %s: Your current version of WordPress. */
				__( 'WordPress version %s' ),
				$core_current_version
			);

			$result['description'] = sprintf(
				'<p>%s</p>',
				__( 'Unable to check if any new versions of WordPress are available.' )
			);

			$result['actions'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'update-core.php?force-check=1' ) ),
				__( 'Check for updates manually' )
			);
		} else {
			foreach ( $core_updates as $core => $update ) {
				if ( 'upgrade' === $update->response ) {
					$current_version = explode( '.', $core_current_version );
					$new_version     = explode( '.', $update->version );

					$current_major = $current_version[0] . '.' . $current_version[1];
					$new_major     = $new_version[0] . '.' . $new_version[1];

					$result['label'] = sprintf(
					/* translators: %s: The latest version of WordPress available. */
						__( 'WordPress update available (%s)' ),
						$update->version
					);

					$result['actions'] = sprintf(
						'<a href="%s">%s</a>',
						esc_url( admin_url( 'update-core.php' ) ),
						__( 'Install the latest version of WordPress' )
					);

					if ( $current_major !== $new_major ) {
						// This is a major version mismatch.
						$result['status']      = 'recommended';
						$result['description'] = sprintf(
							'<p>%s</p>',
							__( 'A new version of WordPress is available.' )
						);
					} else {
						// This is a minor version, sometimes considered more critical.
						$result['status']         = 'critical';
						$result['badge']['label'] = __( 'Security' );
						$result['description']    = sprintf(
							'<p>%s</p>',
							__( 'A new minor update is available for your site. Because minor updates often address security, it&#8217;s important to install them.' )
						);
					}
				} else {
					$result['status'] = 'good';
					$result['label']  = sprintf(
					/* translators: %s: The current version of WordPress installed on this site. */
						__( 'Your version of WordPress (%s) is up to date' ),
						$core_current_version
					);

					$result['description'] = sprintf(
						'<p>%s</p>',
						__( 'You are currently running the latest version of WordPress available, keep it up!' )
					);
				}
			}
		}

		return $result;
	}


	/**
	 * Test if themes are outdated, or unnecessary.
	 *
	 * Сhecks if your site has a default theme (to fall back on if there is a need),
	 * if your themes are up to date and, finally, encourages you to remove any themes
	 * that are not needed.
	 *
	 * @since 5.2.0
	 *
	 * @return array The test results.
	 */
	public function get_test_theme_version() {
		$result = array(
			'label'       => __( 'Your themes are all up to date' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security' ),
				'color' => 'blue',
			),
			'description' => sprintf(
				'<p>%s</p>',
				__( 'Themes add your site&#8217;s look and feel. It&#8217;s important to keep them up to date, to stay consistent with your brand and keep your site secure.' )
			),
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'themes.php' ) ),
				__( 'Manage your themes' )
			),
			'test'        => 'theme_version',
		);

		$theme_updates = $this->get_theme_updates();

		$themes_total        = 0;
		$themes_need_updates = 0;
		$themes_inactive     = 0;

		// This value is changed during processing to determine how many themes are considered a reasonable amount.
		$allowed_theme_count = 1;

		$has_default_theme   = false;
		$has_unused_themes   = false;
		$show_unused_themes  = true;
		$using_default_theme = false;

		// Populate a list of all themes available in the install.
		$all_themes   = wp_get_themes();
		$active_theme = wp_get_theme();

		// If WP_DEFAULT_THEME doesn't exist, fall back to the latest core default theme.
		$default_theme = wp_get_theme( WP_DEFAULT_THEME );
		if ( ! $default_theme->exists() ) {
			$default_theme = WP_Theme::get_core_default_theme();
		}

		if ( $default_theme ) {
			$has_default_theme = true;

			if (
				$active_theme->get_stylesheet() === $default_theme->get_stylesheet()
				||
				is_child_theme() && $active_theme->get_template() === $default_theme->get_template()
			) {
				$using_default_theme = true;
			}
		}

		foreach ( $all_themes as $theme_slug => $theme ) {
			$themes_total++;

			if ( array_key_exists( $theme_slug, $theme_updates ) ) {
				$themes_need_updates++;
			}
		}

		// If this is a child theme, increase the allowed theme count by one, to account for the parent.
		if ( is_child_theme() ) {
			$allowed_theme_count++;
		}

		// If there's a default theme installed and not in use, we count that as allowed as well.
		if ( $has_default_theme && ! $using_default_theme ) {
			$allowed_theme_count++;
		}

		if ( $themes_total > $allowed_theme_count ) {
			$has_unused_themes = true;
			$themes_inactive   = ( $themes_total - $allowed_theme_count );
		}

		// Check if any themes need to be updated.
		if ( $themes_need_updates > 0 ) {
			$result['status'] = 'critical';

			$result['label'] = __( 'You have themes waiting to be updated' );

			$result['description'] .= sprintf(
				'<p>%s</p>',
				sprintf(
				/* translators: %d: The number of outdated themes. */
					_n(
						'Your site has %d theme waiting to be updated.',
						'Your site has %d themes waiting to be updated.',
						$themes_need_updates
					),
					$themes_need_updates
				)
			);
		} else {
			// Give positive feedback about the site being good about keeping things up to date.
			if ( 1 === $themes_total ) {
				$result['description'] .= sprintf(
					'<p>%s</p>',
					__( 'Your site has 1 installed theme, and it is up to date.' )
				);
			} else {
				$result['description'] .= sprintf(
					'<p>%s</p>',
					sprintf(
					/* translators: %d: The number of themes. */
						_n(
							'Your site has %d installed theme, and it is up to date.',
							'Your site has %d installed themes, and they are all up to date.',
							$themes_total
						),
						$themes_total
					)
				);
			}
		}

		if ( $has_unused_themes && $show_unused_themes && ! is_multisite() ) {

			// This is a child theme, so we want to be a bit more explicit in our messages.
			if ( $active_theme->parent() ) {
				// Recommend removing inactive themes, except a default theme, your current one, and the parent theme.
				$result['status'] = 'recommended';

				$result['label'] = __( 'You should remove inactive themes' );

				if ( $using_default_theme ) {
					$result['description'] .= sprintf(
						'<p>%s %s</p>',
						sprintf(
						/* translators: %d: The number of inactive themes. */
							_n(
								'Your site has %d inactive theme.',
								'Your site has %d inactive themes.',
								$themes_inactive
							),
							$themes_inactive
						),
						sprintf(
						/* translators: 1: The currently active theme. 2: The active theme's parent theme. */
							__( 'To enhance your site&#8217;s security, you should consider removing any themes you are not using. You should keep your active theme, %1$s, and %2$s, its parent theme.' ),
							$active_theme->name,
							$active_theme->parent()->name
						)
					);
				} else {
					$result['description'] .= sprintf(
						'<p>%s %s</p>',
						sprintf(
						/* translators: %d: The number of inactive themes. */
							_n(
								'Your site has %d inactive theme.',
								'Your site has %d inactive themes.',
								$themes_inactive
							),
							$themes_inactive
						),
						sprintf(
						/* translators: 1: The default theme for WordPress. 2: The currently active theme. 3: The active theme's parent theme. */
							__( 'To enhance your site&#8217;s security, you should consider removing any themes you are not using. You should keep %1$s, the default WordPress theme, %2$s, your active theme, and %3$s, its parent theme.' ),
							$default_theme ? $default_theme->name : WP_DEFAULT_THEME,
							$active_theme->name,
							$active_theme->parent()->name
						)
					);
				}
			} else {
				// Recommend removing all inactive themes.
				$result['status'] = 'recommended';

				$result['label'] = __( 'You should remove inactive themes' );

				if ( $using_default_theme ) {
					$result['description'] .= sprintf(
						'<p>%s %s</p>',
						sprintf(
						/* translators: 1: The amount of inactive themes. 2: The currently active theme. */
							_n(
								'Your site has %1$d inactive theme, other than %2$s, your active theme.',
								'Your site has %1$d inactive themes, other than %2$s, your active theme.',
								$themes_inactive
							),
							$themes_inactive,
							$active_theme->name
						),
						__( 'You should consider removing any unused themes to enhance your site&#8217;s security.' )
					);
				} else {
					$result['description'] .= sprintf(
						'<p>%s %s</p>',
						sprintf(
						/* translators: 1: The amount of inactive themes. 2: The default theme for WordPress. 3: The currently active theme. */
							_n(
								'Your site has %1$d inactive theme, other than %2$s, the default WordPress theme, and %3$s, your active theme.',
								'Your site has %1$d inactive themes, other than %2$s, the default WordPress theme, and %3$s, your active theme.',
								$themes_inactive
							),
							$themes_inactive,
							$default_theme ? $default_theme->name : WP_DEFAULT_THEME,
							$active_theme->name
						),
						__( 'You should consider removing any unused themes to enhance your site&#8217;s security.' )
					);
				}
			}
		}

		// If no default Twenty* theme exists.
		if ( ! $has_default_theme ) {
			$result['status'] = 'recommended';

			$result['label'] = __( 'Have a default theme available' );

			$result['description'] .= sprintf(
				'<p>%s</p>',
				__( 'Your site does not have any default theme. Default themes are used by WordPress automatically if anything is wrong with your chosen theme.' )
			);
		}

		return $result;
	}

	/**
	 * @since 2.9.0
	 *
	 * @return array
	 */
	function get_theme_updates() {
		$current = get_site_transient( 'update_themes' );

		if ( ! isset( $current->response ) ) {
			return array();
		}

		$update_themes = array();
		foreach ( $current->response as $stylesheet => $data ) {
			$update_themes[ $stylesheet ]         = wp_get_theme( $stylesheet );
			$update_themes[ $stylesheet ]->update = $data;
		}

		return $update_themes;
	}

	/**
	 * Gets available core updates.
	 *
	 * @since 2.7.0
	 *
	 * @param array $options Set $options['dismissed'] to true to show dismissed upgrades too,
	 *                       set $options['available'] to false to skip not-dismissed updates.
	 * @return array|false Array of the update objects on success, false on failure.
	 */
	function get_core_updates( $options = array() ) {
		$options   = array_merge(
			array(
				'available' => true,
				'dismissed' => false,
			),
			$options
		);
		$dismissed = get_site_option( 'dismissed_update_core' );

		if ( ! is_array( $dismissed ) ) {
			$dismissed = array();
		}

		$from_api = get_site_transient( 'update_core' );

		if ( ! isset( $from_api->updates ) || ! is_array( $from_api->updates ) ) {
			return false;
		}

		$updates = $from_api->updates;
		$result  = array();
		foreach ( $updates as $update ) {
			if ( 'autoupdate' === $update->response ) {
				continue;
			}

			if ( array_key_exists( $update->current . '|' . $update->locale, $dismissed ) ) {
				if ( $options['dismissed'] ) {
					$update->dismissed = true;
					$result[]          = $update;
				}
			} else {
				if ( $options['available'] ) {
					$update->dismissed = false;
					$result[]          = $update;
				}
			}
		}
		return $result;
	}

	/**
	 * Test if plugins are outdated, or unnecessary.
	 *
	 * The tests checks if your plugins are up to date, and encourages you to remove any
	 * that are not in use.
	 *
	 * @since 5.2.0
	 *
	 * @return array The test result.
	 */
	public function get_test_plugin_version() {
		$result = array(
			'label'       => __( 'Your plugins are all up to date' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security' ),
				'color' => 'blue',
			),
			'description' => sprintf(
				'<p>%s</p>',
				__( 'Plugins extend your site&#8217;s functionality with things like contact forms, ecommerce and much more. That means they have deep access to your site, so it&#8217;s vital to keep them up to date.' )
			),
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'plugins.php' ) ),
				__( 'Manage your plugins' )
			),
			'test'        => 'plugin_version',
		);

		$plugins        = get_plugins();
		$plugin_updates = $this->get_plugin_updates();

		$plugins_have_updates = false;
		$plugins_active       = 0;
		$plugins_total        = 0;
		$plugins_need_update  = 0;

		// Loop over the available plugins and check their versions and active state.
		foreach ( $plugins as $plugin_path => $plugin ) {
			$plugins_total++;

			if ( is_plugin_active( $plugin_path ) ) {
				$plugins_active++;
			}

			$plugin_version = $plugin['Version'];

			if ( array_key_exists( $plugin_path, $plugin_updates ) ) {
				$plugins_need_update++;
				$plugins_have_updates = true;
			}
		}

		// Add a notice if there are outdated plugins.
		if ( $plugins_need_update > 0 ) {
			$result['status'] = 'critical';

			$result['label'] = __( 'You have plugins waiting to be updated' );

			$result['description'] .= sprintf(
				'<p>%s</p>',
				sprintf(
				/* translators: %d: The number of outdated plugins. */
					_n(
						'Your site has %d plugin waiting to be updated.',
						'Your site has %d plugins waiting to be updated.',
						$plugins_need_update
					),
					$plugins_need_update
				)
			);

			$result['actions'] .= sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( network_admin_url( 'plugins.php?plugin_status=upgrade' ) ),
				__( 'Update your plugins' )
			);
		} else {
			if ( 1 === $plugins_active ) {
				$result['description'] .= sprintf(
					'<p>%s</p>',
					__( 'Your site has 1 active plugin, and it is up to date.' )
				);
			} else {
				$result['description'] .= sprintf(
					'<p>%s</p>',
					sprintf(
					/* translators: %d: The number of active plugins. */
						_n(
							'Your site has %d active plugin, and it is up to date.',
							'Your site has %d active plugins, and they are all up to date.',
							$plugins_active
						),
						$plugins_active
					)
				);
			}
		}

		// Check if there are inactive plugins.
		if ( $plugins_total > $plugins_active && ! is_multisite() ) {
			$unused_plugins = $plugins_total - $plugins_active;

			$result['status'] = 'recommended';

			$result['label'] = __( 'You should remove inactive plugins' );

			$result['description'] .= sprintf(
				'<p>%s %s</p>',
				sprintf(
				/* translators: %d: The number of inactive plugins. */
					_n(
						'Your site has %d inactive plugin.',
						'Your site has %d inactive plugins.',
						$unused_plugins
					),
					$unused_plugins
				),
				__( 'Inactive plugins are tempting targets for attackers. If you are not going to use a plugin, you should consider removing it.' )
			);

			$result['actions'] .= sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'plugins.php?plugin_status=inactive' ) ),
				__( 'Manage inactive plugins' )
			);
		}

		return $result;
	}


	/**
	 * @since 2.9.0
	 *
	 * @return array
	 */
	function get_plugin_updates() {
		$all_plugins     = get_plugins();
		$upgrade_plugins = array();
		$current         = get_site_transient( 'update_plugins' );
		foreach ( (array) $all_plugins as $plugin_file => $plugin_data ) {
			if ( isset( $current->response[ $plugin_file ] ) ) {
				$upgrade_plugins[ $plugin_file ]         = (object) $plugin_data;
				$upgrade_plugins[ $plugin_file ]->update = $current->response[ $plugin_file ];
			}
		}

		return $upgrade_plugins;
	}

	/**
	 * @param $callback
	 *
	 * @return mixed|void
	 */
	public function perform_test( $callback ){

		return apply_filters( 'site_status_test_result', call_user_func( $callback ) );
	}
}
