<?php
/**
 *
 *
 * @package Package
 * @author Name <email>
 * @version
 * @since
 * @license
 */

namespace AbsolutePlugins\RoxwpSiteMonitor;

use AbsolutePlugins\RoxwpSiteMonitor\Monitors\Singleton;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	die();
}

class RoxWP_Client {

	use Singleton;

	private $host = 'https://app.roxwp.com/';

	private $version = 'v1';

	private $api_key;

	private $api_secret;

	/**
	 * RoxWP Client Constructor.
	 *
	 * @return void
	 */
	protected function __construct() {
		$this->reload_api_keys();
	}

	/**
	 * Reload API keys from database.
	 *
	 * @return $this
	 */
	public function reload_api_keys() {
		$api_keys = get_option( 'roxwp_site_monitor_api_keys', [] );

		if ( isset( $api_keys['api_key'], $api_keys['api_secret'] ) ) {
			$this->api_key    = $api_keys['api_key'];
			$this->api_secret = $api_keys['api_secret'];
		}

		return $this;
	}

	/**
	 * Get API server host with network protocol & trailing slash.
	 *
	 * @return string
	 */
	public function get_host() {
		return trailingslashit( apply_filters( 'roxwp_client_app_host', $this->host ) );
	}

	/**
	 * Sets API Key (Public Key).
	 *
	 * @param string $api_key API Key
	 *
	 * @return $this
	 */
	public function set_api_key( $api_key ) {
		$this->api_key = $api_key;

		return $this;
	}

	/**
	 * Sets API Secret key.
	 *
	 * @param string $api_secret API secret.
	 *
	 * @return $this
	 */
	public function set_api_secret( $api_secret ) {
		$this->api_secret = $api_secret;

		return $this;
	}

	/**
	 * Checks and confirm client has api keys.
	 *
	 * @return bool
	 */
	public function has_keys() {
		return $this->api_key && $this->api_secret;
	}

	/**
	 * Ping.
	 * Tests api connectivity.
	 *
	 * @return array|mixed|string|WP_Error
	 */
	public function ping() {
		return $this->request( 'ping' );
	}

	/**
	 * Send Log Data to RoxWP.
	 *
	 * @param array $log Log Data.
	 *
	 * @return array|mixed|string|WP_Error
	 */
	public function send_log( $log ) {
		return $this->request(
			'site/activity/log',
			$log,
			'post',
			[
				'blocking' => false,
				'timeout'  => 5,
			]
		);
	}

	/**
	 * Sends api request.
	 *
	 * @param string $route Endpoint route to send request to.
	 * @param array $data Request payload.
	 * @param string $method Request method.
	 * @param array $args Optional. wp_remote_request args.
	 *
	 * @return array|mixed|string|WP_Error
	 */
	public function request( $route, $data = [], $method = 'get', $args = [] ) {
		if ( ! $this->has_keys() ) {
			return new WP_Error( 'missing-api-keys', __( 'Missing API Keys.', 'rwp-site-mon' ) );
		}

		list( $algo, $timestamp, $signature ) = $this->signature( $data, $method );

		$defaults = [
			'sslverify' => apply_filters( 'roxwp_client_ssl_verify', true ), // https_local_ssl_verify local
			'headers'   => [],
			'method'    => strtoupper( $method ),
			'body'      => [],
			'blocking'  => true,
			'timeout'   => 15, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
		];

		$args = wp_parse_args( $args, $defaults );

		$args['headers'] = array_merge(
			$args['headers'],
			[
				'X-Api-Key'        => $this->api_key,
				'X-Signature-Algo' => $algo,
				'X-Api-Signature'  => $signature,
				'X-Api-Timestamp'  => $timestamp,
				'Content-Type'     => 'application/json',
				'Accept'           => 'application/json',
			]
		);

		if ( ! empty( $data ) ) {
			$args['body'] = 'get' === $method ? $data : wp_json_encode( $data );
		}

		$route       = ltrim( $route, '\\/' );
		$request_url = $this->get_host() . 'api/' . $this->version . '/' . $route;

		if ( false !== strpos( $this->get_host(), '.test/' ) ) {
			$response = wp_remote_request( $request_url, $args );
		} elseif ( function_exists( 'vip_safe_wp_remote_request' ) ) {
			// vip_safe_wp_remote_get( $url, $fallback_value = '', $threshold = 3, $timeout = 1, $retry = 20, $args = [] )
			$response = vip_safe_wp_remote_request( $request_url, '', 3, 5, 20, $args );
		} else {
			$response = wp_safe_remote_request( $request_url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$_body  = trim( wp_remote_retrieve_body( $response ) );
		$body   = json_decode( $_body, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$body = $_body;
			unset( $_body );
		}
		if ( 200 !== $status ) {
			$code = isset( $body['code'] ) ? $body['code'] : $status;
			if ( ! is_array( $body ) ) {
				$message = $body;
			} elseif ( isset( $body['message'] ) ) {
				$message = $body['message'];
			} else {
				$message = wp_remote_retrieve_response_message( $response );
			}

			if ( ! $message ) {
				$message = __( 'Something went wrong', 'rwp-site-mon' );
			}

			return new WP_Error( $code, $message, $body );
		}

		return $body;
	}

	/**
	 * Generate HMAC Signature for RoxWP Api auth header.
	 *
	 * @param string|array $data Request payload.
	 * @param string $method Request Method.
	 *
	 * @return array
	 */
	protected function signature( $data, $method ) {
		$method = strtolower( $method );

		if ( empty( $data ) ) {
			$data = '';
		} else {
			if ( ! is_string( $data ) ) {
				$data = wp_json_encode( $data );
			}
		}

		// Signature Timestamp.
		$timestamp = time();

		// Signature Hash.
		$hash = hash_hmac( 'sha256', "{$this->api_key}{$method}{$data}{$timestamp}", $this->api_secret );

		return [ 'sha256', $timestamp, $hash ];
	}
}

// End of file RoxWP_Client.php.
