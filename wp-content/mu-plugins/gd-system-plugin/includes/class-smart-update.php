<?php

namespace WPaaS;

final class Smart_Update {

	const LOG_DIR = 'wpaas-updates-log';
	/**
	 * Instance of the API.
	 *
	 * @var API_Interface
	 */
	private $api;

	/**
	 * @var string
	 */
	private $log_dir;

	/**
	 * Class constructor.
	 */
	public function __construct( API_Interface $api ) {
		$this->api = $api;
		$this->log_dir = WP_CONTENT_DIR . '/'.self::LOG_DIR;

		$this->set_custom_cron_interval();

		add_action( 'wpaas_smart_update_cleanup_hook', [ $this, 'smart_update_cleanup' ]);

		if ( ! wp_next_scheduled( 'wpaas_smart_update_cleanup_hook' ) ) {
			wp_schedule_event( time() , 'two_days_seconds', 'wpaas_smart_update_cleanup_hook');
		}

		if ( isset( $_COOKIE['wpaas_spu_wordpress_test'] ) ) {
			if ( get_transient( 'wpaas_smart_update_token' ) === $_COOKIE['wpaas_spu_wordpress_test'] ||  $this->is_test_http_request_valid() ) {
				$this->start_spu_test();
			}
		}
	}

	private function set_custom_cron_interval() {

		add_filter( 'cron_schedules', function ( $schedules ) {
			$schedules['two_days_seconds'] = array(
				'interval' => 2 * 24 * 60 * 60,
				'display' => esc_html__('Every 48 hours'),);
			return $schedules;
		} );

	}
	/**
	 * This function start logging php errors in file
	 * @return void
	 */
	private function start_spu_test() {
		if ( ! file_exists ( $this->log_dir ) ) {
			wp_mkdir_p( $this->log_dir );
		}

		register_shutdown_function( [ $this, 'handleError' ] );
		if ( ! get_transient( 'wpaas_smart_update_token' ) ) {
			set_transient( 'wpaas_smart_update_token', $_COOKIE['wpaas_spu_wordpress_test'], 24 * 60 * 60 );
		}
	}

	public function handleError() {
		$log_file      = $this->log_dir . '/' . get_transient( 'wpaas_smart_update_token' ) . '.log';
		$lastError     = error_get_last();
		$error_message = 'Time: ' . date('m/d/Y h:i:s a', time()) .  ' Type: ' . $lastError['type'] . ' Message: ' . $lastError['message'] . ' File: ' . $lastError['file'] . ' Line: ' . $lastError['line'] . PHP_EOL;

		error_log( $error_message, 3 , $log_file);
	}

	public function smart_update_cleanup() {
		if ( ! class_exists( 'WP_Filesystem' ) ) {

			require_once ABSPATH . 'wp-admin/includes/file.php';

		}
		$log_files = list_files( $this->log_dir );
		if ( ! $log_files ) {
			return;
		}

		for ( $i = 0; $i < count( $log_files ); $i++ ) {

			$file_creation_date = filectime( $log_files[$i] );

			if ( time() - $file_creation_date > 24 * 60 * 60 ) { // 24hours
				wp_delete_file( $log_files[$i] );
			}
		}
	}

	public function is_test_http_request_valid() {
		$headers                   = [];
		$headers['x-wp-nonce']     = $_SERVER['HTTP_X_WP_NONCE'];
		$headers['x-wp-origin']    = $_SERVER['HTTP_X_WP_ORIGIN'];
		$headers['x-wp-signature'] = $_SERVER['HTTP_X_WP_SIGNATURE'];
		$headers['x-wp-bodyhash']  = $_SERVER['HTTP_X_WP_BODYHASH'];

		if ( $headers['x-wp-origin'] != GD_TEMP_DOMAIN ) {
			return false;
		}

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
}