<?php
/**
 * Upgrader Skin
 *
 * @package UptimeMonster\SiteMonitor
 */

namespace UptimeMonster\SiteMonitor\CoreUpdate;

use WP_Upgrader_Skin;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	die();
}

/**
 * An Upgrader Skin for WordPress that only generates plain-text
 */
class UptimeMonsterUpgraderSkin extends WP_Upgrader_Skin {

	public $api;

	public function header() {}
	public function footer() {}
	public function bulk_header() {}
	public function bulk_footer() {}

	/**
	 * Show error message.
	 *
	 * @param string $error Error message.
	 *
	 * @return void
	 */
	public function error( $error ) {
		if ( ! $error ) {
			return;
		}

		if ( is_string( $error ) && isset( $this->upgrader->strings[ $error ] ) ) {
			$error = $this->upgrader->strings[ $error ];
		}

		// TODO: show all errors, not just the first one
		//WP_CLI::warning( $error );
	}

	/**
	 * @param string $string
	 * @param mixed  ...$args Optional text replacements.
	 */
	public function feedback( $string, ...$args ) { // phpcs:ignore PHPCompatibility.LanguageConstructs.NewLanguageConstructs.t_ellipsisFound
		$args_array = [];
		foreach ( $args as $arg ) {
			$args_array[] = $args;
		}

		$this->process_feedback( $string, $args );
	}

	/**
	 * Process the feedback collected through the compat indirection.
	 *
	 * @param string $string String to use as feedback message.
	 * @param array $args Array of additional arguments to process.
	 */
	public function process_feedback( $string, $args ) {

		if ( 'parent_theme_prepare_install' === $string ) {
			//WP_CLI::get_http_cache_manager()->whitelist_package( $this->api->download_link, 'theme', $this->api->slug, $this->api->version );
		}

		if ( isset( $this->upgrader->strings[ $string ] ) ) {
			$string = $this->upgrader->strings[ $string ];
		}

		if ( ! empty( $args ) && strpos( $string, '%' ) !== false ) {
			$string = vsprintf( $string, $args );
		}

		if ( empty( $string ) ) {
			return;
		}

		$string = str_replace( '&#8230;', '...', wp_strip_all_tags( $string ) );
		$string = html_entity_decode( $string, ENT_QUOTES, get_bloginfo( 'charset' ) );

		//WP_CLI::log( $string );
	}
}
