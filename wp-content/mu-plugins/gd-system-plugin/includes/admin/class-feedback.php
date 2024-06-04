<?php

namespace WPaaS\Admin;

use \WPaaS\Plugin;


if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

/**
 * Class to handle NPS feedback to WPNUX servers.
 *
 * To reset behavior in this class run the following 2 cli commands:
 * wp-env run cli "wp option delete _site_transient_wpaas_nux_feedback_dismiss"
 * wp-env run cli "wp option delete _site_transient_wpaas_nux_feedback_data"
 */
final class Feedback {

	/**
	 * Holds the class name the JS script will bind to.
	 */
	const CONTAINER_ID = 'wpaas-feedback-container';

	/**
	 * Holds the option cache key from the API.
	 */
	const NUX_CACHE_KEY = 'wpaas_nux_feedback_data';

	/**
	 * Dismiss feedback option name.
	 */
	const DISSMIS_KEY = 'wpaas_nux_feedback_dismiss';

	/**
	 * Holds the base endpoint for interacting with the Feedback API.
	 */
	const API_BASE = 'wpaas/v1/feedback';

	/**
	 * Add debug param for forcing NPS on a customer's site.
	 */
	const DEBUG_PARAM = 'wpaas-nps-debug';

	/**
	 * Holds the list of WP pages we don't want this feature to appear on.
	 */
	const EXCLUDED_PAGES = [
		'update-core.php', // update screen
		'post-new.php', // new posts/page/custom-post-type
		'post.php', // post edit
		'customize.php', // customizer
		'nav-menus.php', // menu edit
		'upload.php', // upload media
	];

	/**
	 * Holds the NPS data obtained from the API.
	 *
	 * @var [type]
	 */
	private $nux_payload;

	/**
	 * Holds debugging state on instanciation.
	 *
	 * @var boolean
	 */
	private $is_debug_mode_on = false;

	/**
	 * Constructor.
	 */
	public function __construct() {

		if ( isset( $_GET[ self::DEBUG_PARAM ] ) ) {

			$this->is_debug_mode_on = true;

		}

		// Because this is a MU-Plugins, is_user_logged() will always return false if we don't check after init.
		add_action( 'init', [ $this, 'init'] );

	}

	public function init() {

		if ( $GLOBALS['wpaas_feature_flag']->get_feature_flag_value('nps_survey', false) ) {
		    add_action('admin_print_footer_scripts', [ $this, 'get_nps_survey' ], PHP_INT_MAX);
		    return;
		}

		add_action( 'rest_api_init', [ $this, 'survey_available_endpoint'] );

		if ( ! $this->should_render() ) {

			return;

		}

		add_action('rest_api_init', [$this, 'register_endpoints']);

		add_action( is_admin() ? 'admin_enqueue_scripts' : 'wp_enqueue_scripts', [ $this, 'enqueue_scripts'] );

		add_action( is_admin() ? 'admin_print_footer_scripts' : 'wp_footer', function() {

			printf( '<div id="%s"></div>', self::CONTAINER_ID );
			printf( '<script id="wpaas-feedback-js" src="%s"></script>', esc_url( Plugin::assets_url( 'js/wpaas-feedback.min.js' ) ) );

		}, PHP_INT_MAX );

	}

	/**
	 * 60 Days after they created the site. We'll show the feedback form.
	 * If they completed the form, give another 90 days and show it again.
	 *
	 * If they dimiss the form, also hide for 90 days.
	 */
	private function should_render() {

		global $pagenow;

		if (
			(
				! $this->can_see_survey()
				|| empty( $pagenow )
				|| in_array( $pagenow, self::EXCLUDED_PAGES, true )
			)
			&& ! $this->is_debug_mode_on
		) {

			return false;

		}

		$this->nux_payload = $this->get_nux_payload();

		if ( ! $this->nux_payload ) {

			return false;

		}

		return true;
	}

	/**
	 * Wether or not the current user can see the NPS survey.
	 *
	 * @return boolean
	 */
	private function can_see_survey() {

		return
			is_user_logged_in() &&
			! Plugin::is_staging_site() &&
			Plugin::is_gd() &&
			Plugin::is_rum_enabled() &&
			defined( 'GD_TEMP_DOMAIN' ) &&
			( ( time() - Plugin::site_created_date() ) > ( 60 * DAY_IN_SECONDS ) ) &&
			! Plugin::is_wds() &&
			! Plugin::get_persistent_site_transient( self::DISSMIS_KEY ) &&
			$this->is_first_admin();

	}

	/**
	 * This is an extra safety check when aggregation plugin are enqueueing the script on the page.
	 *
	 * @return boolean
	 */
	public function survey_available_endpoint() {

		register_rest_route(
			self::API_BASE,
			'available',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'permission_callback' => function() {

					$this->nux_payload = $this->get_nux_payload();

					return $this->can_see_survey() && $this->nux_payload;

				},
				'callback'            => function() {

					return rest_ensure_response( [
						'success' => true,
					] );

				},
				'show_in_rest'        => false,
			]
		);

	}

	/**
	 * Register the 2 API endpoints to deal with dismissal and submission.
	 *
	 * @return void
	 */
	public function register_endpoints() {

		$comment_max_length = $this->get_comment_max_length( $this->nux_payload['rules']['comment'] );

		register_rest_route(
			self::API_BASE,
			'score',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'permission_callback' => function() {

					return $this->is_first_admin();

				},
				'callback'            => [ $this, 'submit_feedback_to_nux'],
				'args' => [
					'endedAt' => [
						'required'          => true,
						'type'              => 'string',
						'format'            => 'date-time',
						'validate_callback' => function( $param ) {

							return strtotime( $param );

						},
						'sanitize_callback' => 'sanitize_text_field',
					],
					'score' => [
						'required'          => true,
						'type'              => 'integer',
						'minimum'           => 0,
						'maximum'           => 10,
						'validate_callback' => function( $param ) {

							return is_numeric( $param );

						},
						'sanitize_callback' => 'absint',
					],
					'startedAt' => [
						'required'          => true,
						'type'              => 'string',
						'format'            => 'date-time',
						'validate_callback' => function( $param ) {

							return strtotime( $param );

						},
						'sanitize_callback' => 'sanitize_text_field',
					],
					'comment' => [
						'required'          => true,
						'type'              => 'string',
						'maxLength'         => $comment_max_length,
						'validate_callback' => function( $param ) {

							return is_string( $param );

						},
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'canContact' => [
						'required'          => true,
						'type'              => 'boolean',
						'validate_callback' => function( $param ) {

							return is_bool( $param );

						},
						'sanitize_callback' => 'rest_sanitize_boolean',
					],
				],
				'show_in_rest'        => false,
			]
		);

		register_rest_route(
			self::API_BASE,
			'dismiss',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'permission_callback' => function() {

					return $this->is_first_admin();

				},
				'callback'            => function() {

					$this->dismiss( 90 * DAY_IN_SECONDS );

					return rest_ensure_response( [
						'success' => true,
					] );

				},
				'show_in_rest'        => false,
			]
		);

	}

	/**
	 * Send feedback Data to nux.
	 */
	public function submit_feedback_to_nux( \WP_REST_Request $req ) {

		$resp = wp_remote_post(
			Plugin::get_wpnux_url( '/v3/api/feedback/wpaas-nps' ),
			[
				'headers' => [
					'Content-Type' => 'application/json'
				],
				'body' => json_encode( [
					'coblocks_version'        => defined( 'COBLOCKS_VERSION' ) ? COBLOCKS_VERSION : null,
					'comment'                 => $req->get_param( 'comment' ),
					'customer_id'             => defined( 'GD_CUSTOMER_ID' ) ? GD_CUSTOMER_ID : null,
					'domain'                  => GD_TEMP_DOMAIN,
					'ended_at'                => $req->get_param( 'endedAt' ),
					'go_theme_version'        => defined( 'GO_THEME_VERSION' ) ?  GO_THEME_VERSION : null,
					'hostname'                => Plugin::get_env() === 'dev' ? GD_TEMP_DOMAIN : gethostname(),
					'is_fullpage_cdn_enabled' => defined( 'GD_CDN_FULLPAGE' ) ? GD_CDN_FULLPAGE : null,
					'is_migrated_site'        => defined( 'GD_MIGRATED_SITE' ) ? GD_MIGRATED_SITE : null,
					'is_wp_admin'             => $req->get_param( 'isWpAdmin' ),
					'language'                => get_user_locale(),
					'plan_name'               => defined( 'GD_PLAN_NAME' ) ? GD_PLAN_NAME : null,
					'score'                   => $req->get_param( 'score' ),
					'started_at'              => $req->get_param( 'startedAt' ),
					'system_plugin_version'   => Plugin::version(),
					'website_id'              => defined( 'GD_ACCOUNT_UID' ) ? GD_ACCOUNT_UID : null,
					'woocommerce_version'     => defined( 'WC_VERSION' ) ? WC_VERSION : null,
					'wp_uri'                  => $req->get_param( 'wpUri' ),
					'wp_user_id'              => get_current_user_id(),
					'wp_version'              => get_bloginfo( 'version' ),
					'can_contact'             => (bool) $req->get_param( 'canContact' ),
				] ),
			]
		);

		$error = function() {

			$this->dismiss( 90 * DAY_IN_SECONDS );

			return rest_ensure_response( ['success' => false ], 500 );

		};

		if ( is_wp_error( $resp ) ) {

			return $error();

		}

		$body = wp_remote_retrieve_body( $resp );
		$body = json_decode( $body, true );

		if ( empty( $body['success'] ) ) {

			// We got a validation error from what we sent to the NUX API.
			// This could mean malformed data present in the payload that is out of our control.
			// Let's dismiss this form for a while to avoid spamming the API and the User.
			return $error();

		}

		// This will basically make the plugin fetch new data on next load.
		Plugin::delete_persistent_site_transient( self::NUX_CACHE_KEY );

		return rest_ensure_response( [
			'success' => true,
		] );

	}

	public function get_nps_survey() {
		if ( is_admin() &&
		     current_user_can('administrator') &&
		     !Plugin::is_staging_site() &&
		     defined('GD_RUM_ENABLED') &&
		     GD_RUM_ENABLED &&
		     Plugin::is_gd() &&
		     ! Plugin::get_persistent_site_transient( self::DISSMIS_KEY )
		) {

			echo '<!--BEGIN QUALTRICS WEBSITE FEEDBACK SNIPPET-->
			  <script type=\'text/javascript\'>
			  (function(){var g=function(e,h,f,g){
			  this.get=function(a){for(var a=a+"=",c=document.cookie.split(";"),b=0,e=c.length;b<e;b++){for(var d=c[b];" "==d.charAt(0);)d=d.substring(1,d.length);if(0==d.indexOf(a))return d.substring(a.length,d.length)}return null};
			  this.set=function(a,c){var b="",b=new Date;b.setTime(b.getTime()+6048E5);b="; expires="+b.toGMTString();document.cookie=a+"="+c+b+"; path=/; "};
			  this.check=function(){var a=this.get(f);if(a)a=a.split(":");else if(100!=e)"v"==h&&(e=Math.random()>=e/100?0:100),a=[h,e,0],this.set(f,a.join(":"));else return!0;var c=a[1];if(100==c)return!0;switch(a[0]){case "v":return!1;case "r":return c=a[2]%Math.floor(100/c),a[2]++,this.set(f,a.join(":")),!c}return!0};
			  this.go=function(){if(this.check()){var a=document.createElement("script");a.type="text/javascript";a.src=g;document.body&&document.body.appendChild(a)}};
			  this.start=function(){var t=this;"complete"!==document.readyState?window.addEventListener?window.addEventListener("load",function(){t.go()},!1):window.attachEvent&&window.attachEvent("onload",function(){t.go()}):t.go()};};
			  try{(new g(100,"r","QSI_S_ZN_cCpILcXLHy2kXOd","https://znccpilcxlhy2kxod-godaddy.siteintercept.qualtrics.com/SIE/?Q_ZID=ZN_cCpILcXLHy2kXOd")).start()}catch(i){}})();
			  </script><div id=\'ZN_cCpILcXLHy2kXOd\'><!--DO NOT REMOVE-CONTENTS PLACED HERE--></div>
			  <!--END WEBSITE FEEDBACK SNIPPET-->';

			if ( defined( 'GD_SITE_CREATED' )) {
				$siteCreationDate = ( new  \DateTime() )->setTimestamp( GD_SITE_CREATED );
			}

			$data = json_encode( [
				'customerId' => defined( 'GD_CUSTOMER_ID' ) ? GD_CUSTOMER_ID : null,
				'guid' => defined( 'GD_ACCOUNT_UID' ) ? GD_ACCOUNT_UID : null,
				'productId' => defined( 'GD_ACCOUNT_UID' ) ? GD_ACCOUNT_UID : null,
				'product_name' => 'MWP',
				'coblocksVersion' => defined( 'COBLOCKS_VERSION' ) ? COBLOCKS_VERSION : null,
				'goThemeVersion' => defined( 'GO_THEME_VERSION' ) ?  GO_THEME_VERSION : null,
				'mwpSystemPluginVersion' => Plugin::version(),
				'wpUserId' => get_current_user_id(),
				'wpVersion' => get_bloginfo('version'),
				'mwpPlanName' => defined( 'GD_PLAN_NAME' ) ? GD_PLAN_NAME : null,
				'wpLocale' => get_locale(),
				'woocommerceVersion' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
				'isFullPageCDN' => defined( 'GD_CDN_FULLPAGE' ) ? GD_CDN_FULLPAGE : null,
				'siteCreatedAt' =>  defined( 'GD_SITE_CREATED' )? $siteCreationDate->format(\DateTime::ATOM) : null,
				'siteAgeDays' => defined( 'GD_SITE_CREATED' ) ? floor((time() - GD_SITE_CREATED) / 86400) : 0,

			] );

			echo '<script> var nps_survey_metadata = JSON.parse(\'' . $data . '\'); </script>';
			echo '<script> window.nps_survey_metadata = nps_survey_metadata; </script>';

		}
	}

	/**
	 * GET the payload we received from the API and conditionally refresh it.
	 *
	 * @return array|false;
	 */
	private function get_nux_payload() {

		$payload = Plugin::get_persistent_site_transient( self::NUX_CACHE_KEY );

		if ( $payload && $this->is_debug_mode_on ) {

			return $payload;

		}

		// We have a payload but the API won't accept new feedback yet.
		if ( isset( $payload['next_feedback_allowed_at'] ) && ( strtotime( $payload['next_feedback_allowed_at'] ) > time() ) ) {

			return false;

		}

		// No payload means fetch it on shut down and show it on next page load.
		// If the payload is set but older than 24 h we'll fetch it again.
		if (
			! $payload
			|| ( isset( $payload['updated_at'] ) && ( time() - $payload['updated_at'] ) > ( 24 * HOUR_IN_SECONDS ) )
		) {

			add_action( 'shutdown', [ $this, 'update_nux_payload'] );

			return false;

		}

		return $payload;

	}

	/**
	 * Update nux payload
	 */
	public function update_nux_payload() {

		$domain = defined( 'GD_TEMP_DOMAIN' ) ? GD_TEMP_DOMAIN : Plugin::domain();
		$lang   = get_user_locale();

		$resp = wp_remote_get(
			Plugin::get_wpnux_url( "/v3/api/feedback/wpaas-nps?domain=$domain&language=$lang" )
		);


		if ( is_wp_error( $resp ) ) {

			// If the API is down, let's not slowdown the loading of all subsequent pages. Dismiss the functionality for a couple of hours.
			// Dev-Test API will not work out of network.
			$this->dismiss( mt_rand( 1, 24 ) * HOUR_IN_SECONDS );

			return;

		}

		try {

			$payload = json_decode( wp_remote_retrieve_body( $resp ), true );

		} catch( \Exception $e ) {

			return;

		}

		// 7 days cache if for not checking for new languages all the time.
		if ( is_null( $payload['next_feedback_allowed_at'] ) ) {

			// Make the system check again in 7 days if new languages become available.
			$this->dismiss( 7 * DAY_IN_SECONDS );

			// No need to store this payload since it's incomplete.
			return;

		}

		// Keep track of how old this payload is.
		$payload['updated_at'] = time();

		Plugin::set_persistent_site_transient( self::NUX_CACHE_KEY, $payload );

	}

	/**
	 * Dismiss this plugin for 72 hours.
	 */
	public function dismiss( $time ) {

		Plugin::set_persistent_site_transient( self::DISSMIS_KEY, true, $time );

	}

	/**
	 * Scripts to enqueue the modal on the page.
	 */
	public function enqueue_scripts() {

		$asset_file = Plugin::assets_dir( 'js/wpaas-feedback.min.asset.php' );

		$asset_file = file_exists( $asset_file )
			? include $asset_file
			: [
				'dependencies' => [],
				'version'      => Plugin::version(),
			];

		foreach( $asset_file['dependencies'] as $dependency ) {

			wp_enqueue_script( $dependency );

		}

		$rtl    = is_rtl() ? '-rtl' : '';
		$suffix = SCRIPT_DEBUG ? '' : '.min';

		$css_url = Plugin::assets_url( "css/wpaas-feedback$rtl$suffix.css" );

		wp_localize_script(
			'react', // React is always a dependency.
			'wpaasFeedback',
			[
				'apiBase'        => add_query_arg( 'rest_route', '/' . self::API_BASE, home_url( '', 'admin' ) ),
				'commentLength'  => $this->get_comment_max_length( $this->nux_payload['rules']['comment'] ),
				'containerId'    => self::CONTAINER_ID,
				'css'            => $css_url,
				'isWpAdmin'      => is_admin(),
				'labels'         => $this->nux_payload['labels'],
				'scoreChoices'   => $this->get_score_choices( $this->nux_payload['rules']['score'] ),
				'excludedPages'  => self::EXCLUDED_PAGES,
				'debugParam'     => self::DEBUG_PARAM
			]
		);

		printf( '<link rel="preload" href="%s" as="style">', $css_url );

	}

	/**
	 * Valide current user is first admin.
	 *
	 * @return boolean
	 */
	private function is_first_admin() {

		$first_user = Plugin::get_first_admin_user();

		return $first_user && ( get_current_user_id() === $first_user->ID );

	}

	/**
	 * Get the score min and max choice.
	 *
	 * @return array
	 */
	private function get_score_choices( $score_rules ) {

		$callback = function( $min = 0, $max = 10 ) {

			return [
				'min' => ( int ) $min,
				'max' => ( int ) $max,
			];

		};

		return $this->get_rule_value( $score_rules, 'between:', [ 'min' => 0, 'max' => 10 ], $callback );

	}

	/**
	 * Get max length of the feedback textarea.
	 *
	 * @return int
	 */
	private function get_comment_max_length( $comment_rules ) {

		$callback = function( $max_length = 1024 ) {

			return ( int ) $max_length;

		};

		return $this->get_rule_value( $comment_rules, 'max:', 1024, $callback );

	}

	/**
	 * Extract rule value from the rules array.
	 *
	 * @return mixed
	 */
	private function get_rule_value( $rules, $key,  $default, $callback ) {

		list( $rule ) = array_values( array_filter( $rules, function( $rule ) use( $key ) {

			return strpos( $rule, $key ) !== false;

		} ) );

		if ( ! $rule ) {

			return $default;

		}

		return call_user_func_array( $callback, explode( ',', str_replace( $key, '', $rule ) ) );

	}

}
