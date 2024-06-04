<?php

namespace WPaaS;

use WP_Application_Passwords;
use WP_Error;
use WP_Http_Cookie;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

final class REST_API {

	/**
	 * Instance of the API.
	 *
	 * @var API_Interface
	 */
	private $api;

	/**
	 * Array of REST API namespaces.
	 *
	 * @var array
	 */
	private $namespaces = [];

	/**
	 * Instance of the Cache.
	 *
	 * @var Cache_V2
	 */
	private $cache;

	/**
	 * Array of cached validated endpoints
	 * WordPress calls twice permission_callback, once for Allow headers,
	 * and second time for the callback
	 *
	 * @var array
	 */
	private $validated = [];

	public function __construct( API_Interface $api, Cache_V2 $cache ) {
		$this->api = $api;

		$this->namespaces['v1'] = 'wpaas/v1';
		$this->cache = $cache;
		add_action( 'rest_api_init', [ $this, 'toggle_xmlrpc' ] );
		add_action( 'rest_api_init', [ $this, 'flush_cache' ] );
		add_action( 'rest_api_init', [ $this, 'flush_cache_cdn_status' ] );
		add_action( 'rest_api_init', [ $this, 'dismiss_note' ] );
		add_action( 'rest_api_init', [ $this, 'backup_now' ] );
		add_action( 'rest_api_init', [ $this, 'diagnostics_data' ] );
		add_action( 'rest_api_init', [ $this, 'feature_flag' ] );
		add_action( 'rest_api_init', [ $this, 'activity_log' ] );
	}
    /**
     * Validate signature
     *
     * @param  array $headers
     *
     * @return bool
     */
    public function validate_signature( $headers ) {
		$api_url = sprintf('%s/validate', $this->api->wp_public_api_url());

		$response = wp_remote_request(
		  esc_url_raw(  $api_url ),
		  [
			  'method'   => 'POST',
			  'blocking' => true,
			  'headers'  => array_merge( [
				  'Accept'       => 'application/json',
				  'Content-Type' => 'application/json',
			  ], $headers ),
		  ]
		);

		$body = wp_remote_retrieve_body( $response );
		$body = json_decode( $body, true );

		return $body['validated'] ?? false;
    }

    public function is_administrator() {
        $user = wp_get_current_user();

        if ( in_array( 'administrator', $user->roles, true ) ) {
            return true;
        }

        return false;
    }

	/**
	 * POST route to flush cache.
	 */
	public function flush_cache() {
		register_rest_route( $this->namespaces['v1'], 'flush-cache', [
			'methods'             => 'POST',
			'permission_callback' => function($request) {
				if ( isset($this->validated['flush_cache']) ) {
					return $this->validated['flush_cache'];
				}
				$all_headers = $request->get_headers();
				$signature = [];
				$signature['x-wp-nonce'] = $all_headers['x_nonce'][0] ?? '';
				$signature['x-wp-origin'] = $all_headers['x_origin'][0] ?? '';
				$signature['x-wp-signature'] = $all_headers['x_signature'][0] ?? '';
				$signature['x-wp-bodyhash'] = $all_headers['x_bodyhash'][0] ?? '';

				if ($signature['x-wp-origin'] != GD_TEMP_DOMAIN) {
					return false;
				}
				$this->validated['flush_cache'] = $this->validate_signature($signature);

				return $this->validated['flush_cache'];
			},
			'callback'            => function () {
				$this->cache->do_ban();

				return [ 'success' => true ];
			},
		] );

	}

    /**
     * POST route to enable/disable XML-RPC
     */
    public function toggle_xmlrpc() {
        register_rest_route( $this->namespaces['v1'], 'toggle-xmlrpc', [
            'methods'             => 'POST',
            'permission_callback' => [ $this, 'is_administrator' ],
            'callback'            => function () {

                $is_xmlrpc_enabled = get_option( 'is_xmlrpc_enabled', 'enabled' );
                $status  = 'enabled';

                if ( 'enabled' === $is_xmlrpc_enabled ) {
                    $status = 'disabled';
                }

                update_option( 'is_xmlrpc_enabled', $status );
            },
        ] );
    }

	/**
	 * Post route to get flush cache status
	 */
	public function flush_cache_cdn_status() {
		register_rest_route(
			$this->namespaces['v1'],
			'flush-cache/status',
			[
				'methods'             => 'GET',
				'permission_callback' => [ $this, 'is_administrator' ],
				'callback'            => function () {
					$invalidation_id = get_option('gd_system_polling_invalidation_id');
					if ( $invalidation_id ) {
						$raw = $this->api->flush_cache_cdn_status( $invalidation_id );
						$status = $raw['status'] ?? 'PENDING';

						if ( $raw === null || $status === 'SUCCESS' || $status === 'FAILED') {
							update_option( 'gd_system_polling_invalidation_id', false );
						}

						if ( $raw === null || $status === 'SUCCESS' || $status === 'PENDING' ) {
							return new \WP_REST_Response(
								array(
									'code'    => 'OK',
									'message' => 'Flush is in ' . $status . ' status',
									'raw'	  => $raw,
									'data'    => array(
										'flush_status' => $status,
									),
								),
								200
							);
						} else {
							return new \WP_REST_Response(
								array(
									'code'    => 'Bad Request',
									'message' => 'Flush failed.',
									'raw'	  => $raw,
									'data'    => array(
										'flush_status' => $status,
									),
								),
								400
							);
						}
					}

					return new \WP_REST_Response(
						array(
							'code'    => 'NOT_FOUND',
							'message' => 'There is no invalidation id',
						),
						404
					);
				}
			] );
	}

	/**
	 * Post route to dismiss notification
	 *
	 * @return \WP_REST_Response
	 */
	public function dismiss_note() {
		register_rest_route(
			$this->namespaces['v1'],
			'dismiss-notice',
			[
				'methods'             => 'POST',
				'permission_callback' => [ $this, 'is_administrator' ],
				'args'                => array(
					'id' => array(
						'required'          => true,
						'description'       => 'Notice id',
						'type'              => 'string',
						'validate_callback' => function( $param, $request, $key ) {
							return is_string( $param );
						},
					),
				),
				'callback'            => function ($request) {

					$id = $request->get_param( 'id' );
					update_option( "wpaas_dismissed_$id", true );

					return new \WP_REST_Response(
						array(
							'status'    => 'OK',
						),
						200
					);
				}
			] );
	}
	/**
	 * POST route to run backup
	 */
	public function backup_now() {
		register_rest_route( $this->namespaces['v1'], 'backup', [
			'methods'             => 'POST',
			'permission_callback' => [ $this, 'is_administrator' ],
			'callback'            => function () {

			$success = $this->api->backup_now();
			if( $success ) {
				return new \WP_REST_Response(
					array(
						'code'    => 'NO_CONTENT',
						'message' => 'Backup is successful.',
					),
					204
				);
			}
			return new \WP_REST_Response(
				array(
					'code'    => 'SERVER_ERROR',
					'message' => 'Backup failed.',
				),
				500
			);
			},
		] );
	}

	/**
	 * GET route to collect all diagnostics data
	 */
	public function diagnostics_data() {
		register_rest_route( $this->namespaces['v1'], 'diagnostics', [
			'methods'             => 'POST',
			'permission_callback' => function($request) {

				if ( isset($this->validated['diagnostics']) ) {
					return $this->validated['diagnostics'];
				}
				$all_headers = $request->get_headers();
				$signature = [];
				$signature['x-wp-nonce'] = $all_headers['x_nonce'][0] ?? '';
				$signature['x-wp-origin'] = $all_headers['x_origin'][0] ?? '';
				$signature['x-wp-signature'] = $all_headers['x_signature'][0] ?? '';
				$signature['x-wp-bodyhash'] = $all_headers['x_bodyhash'][0] ?? '';

				if ( $signature['x-wp-origin'] != GD_TEMP_DOMAIN ) {
					return false;
				}
				$this->validated['diagnostics'] = $this->validate_signature($signature);

				return $this->validated['diagnostics'];
			},
			'callback'            => function () {
				$diagnostics = new Diagnostics();

				return $diagnostics->get_diagnostics_data();
			},
		] );
	}

	public function feature_flag() {
		register_rest_route( $this->namespaces['v1'], 'feature_flag', [
			'methods'             => 'GET',
			'args' => [
				'name' => [
					'validate_callback' => function($param, $request, $key) {
						return is_string( $param );
					}
				],
				'default_value' => [
					'validate_callback' => function($param, $request, $key) {
						return is_bool(filter_var($param, FILTER_VALIDATE_BOOLEAN));
					}
				],
			],
			'permission_callback' => function(){
				return is_user_logged_in();
			} ,
			'callback'            => function ($request) {

				$name = $request->get_param( 'name' );
				$default_value = filter_var($request->get_param( 'default_value' ), FILTER_VALIDATE_BOOLEAN);;
				$value = $GLOBALS['wpaas_feature_flag']->get_feature_flag_value($name, $default_value);

				return new \WP_REST_Response(
					array(
						'name' => $name,
						'value' => $value,
					),
					200
				);

			},
		] );
	}

    public function activity_log() {
        // Register a new REST endpoint to retrieve data from the user_activity_log table
        register_rest_route( $this->namespaces['v1'], '/activity-logs', array(
            'methods' => 'GET',
            'permission_callback' => function($request) {
                if ( isset($this->validated['get_activity_log']) ) {
                    return $this->validated['get_activity_log'];
                }
                $all_headers = $request->get_headers();
                $signature = [];
                $signature['x-wp-nonce'] = $all_headers['x_nonce'][0] ?? '';
                $signature['x-wp-origin'] = $all_headers['x_origin'][0] ?? '';
                $signature['x-wp-signature'] = $all_headers['x_signature'][0] ?? '';
                $signature['x-wp-bodyhash'] = $all_headers['x_bodyhash'][0] ?? '';

                if ($signature['x-wp-origin'] != GD_TEMP_DOMAIN) {
                    return false;
                }
                $this->validated['get_activity_log'] = $this->validate_signature($signature);

                return $this->validated['get_activity_log'];
            },
            'args' => array(
                'number' => array(
                    'validate_callback' => function ($param, $request, $key) {
                        // Validate that the number parameter is an integer between 1 and 1000
                        return is_numeric( $param ) && intval( $param ) >= 1 && intval( $param ) <= 1000;
                    },
                    'default' => 100, // Default to returning 100 records if the number parameter is not provided or is invalid
                ),
            ),
            'callback' => function ( WP_REST_Request $request ) {
                // Get the number of records to return from the request parameters
                $number = $request->get_param( 'number' );

                global $wpdb;
                // Query the user_activity_log table to get the specified number of records, ordered by timestamp in descending order
                $results = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}wpaas_activity_log ORDER BY timestamp DESC LIMIT %d",
                        intval( $number )
                    )
                );

                // Return the results
                return new \WP_REST_Response(
                    array(
                        'data' => $results,
                    ),
                    200
                );
            }
        ) );
    }
}
