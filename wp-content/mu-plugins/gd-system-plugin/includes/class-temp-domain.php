<?php

namespace WPaaS;

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

final class Temp_Domain {

	/**
	 * Class constructor.
	 *
	 * @param API_Interface $api
	 */
	public function __construct( API_Interface $api ) {

		add_filter( 'option_blog_public', [ $this, 'option_blog_public' ], PHP_INT_MAX );

		/**
		 * Bail early if:
		 *
		 * 1. This is a front-end request.
		 * 2. The domain known to be custom.
		 * 3. The user has recently changed their domain.
		 *
		 * Checking the API should be the last conditional
		 * so we can keep those calls to a minimum.
		 */
		if ( ! is_admin() || ! Plugin::is_temp_domain() || $api->user_changed_domain() ) {

			return;

		}

		add_filter( 'pre_update_option_blog_public', [ $this, 'pre_update_option_blog_public' ], PHP_INT_MAX, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );

        if ( ! Plugin::is_staging_site() ) {

            add_action( 'admin_notices', [ $this, 'wpaas_temp_domain_notice' ] );

        }

    }

    /**
    * Simple admin notice example with GoDaddy styling
    *
    * @return mixed Markup or the admin notice.
    */
    public function wpaas_temp_domain_notice() {

        $rtl = !is_rtl() ? '' : '-rtl';
        $suffix = SCRIPT_DEBUG ? '' : '.min';

        wp_enqueue_style( 'wpaas-temp-domain', Plugin::assets_url( "css/admin-notice{$rtl}{$suffix}.css" ), [], Plugin::version() );

        $elem_class   = 'notice wpaas-domain-notice is-dismissible';
        $main_message = __( 'Look more professional and help people find your website with a custom domain.', 'gd-system-plugin' );
        $link_message = __( 'Change domain settings', 'gd-system-plugin' );
        $world_svg    = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M21.75 12C21.747 9.4152 20.7189 6.9371 18.8913 5.1093C17.0636 3.28149 14.5856 2.25323 12.0008 2.25006L12.0005 2.25H12C9.41414 2.25 6.93419 3.27723 5.10571 5.10571C3.27723 6.93419 2.25 9.41414 2.25 12C2.25 14.5859 3.27723 17.0658 5.10571 18.8943C6.93419 20.7228 9.41414 21.75 12 21.75H12.0005L12.0008 21.7499C14.5856 21.7468 17.0636 20.7185 18.8913 18.8907C20.7189 17.0629 21.747 14.5848 21.75 12ZM20.212 11.25H16.723C16.6173 8.79943 15.9568 6.4048 14.7913 4.24652C16.2665 4.77892 17.5596 5.72024 18.5195 6.96044C19.4794 8.20065 20.0665 9.68848 20.212 11.25ZM10.811 19.5547C9.58439 17.4878 8.88479 15.1509 8.7738 12.75H15.2264C15.1154 15.1513 14.4155 17.4885 13.1884 19.5557C13.0631 19.7583 12.8881 19.9255 12.68 20.0414C12.4719 20.1573 12.2377 20.2181 11.9994 20.218C11.7612 20.2179 11.527 20.1569 11.319 20.0408C11.111 19.9247 10.9361 19.7574 10.811 19.5547V19.5547ZM8.7738 11.25C8.88484 8.84892 9.58444 6.51196 10.811 4.44482C10.9297 4.234 11.1024 4.05854 11.3113 3.93645C11.5202 3.81435 11.7578 3.75 11.9997 3.75C12.2417 3.75 12.4793 3.81435 12.6882 3.93645C12.8971 4.05854 13.0697 4.234 13.1884 4.44482C14.4154 6.51182 15.1153 8.84883 15.2264 11.25H8.7738ZM9.208 4.24677C8.04265 6.405 7.38223 8.79953 7.27649 11.25H3.788C3.9335 9.68859 4.52045 8.20086 5.48024 6.96071C6.44003 5.72056 7.733 4.77924 9.208 4.24677ZM3.788 12.75H7.27649C7.38214 15.2004 8.04245 17.5949 9.2077 19.7531C7.73277 19.2206 6.43988 18.2793 5.48015 17.0392C4.52042 15.799 3.93351 14.3113 3.788 12.75ZM14.7917 19.7534C15.957 17.5951 16.6174 15.2005 16.723 12.75H20.212C20.0665 14.3115 19.4795 15.7992 18.5196 17.0394C17.5598 18.2796 16.2668 19.2209 14.7917 19.7534H14.7917Z" fill="#111111"/></svg>';
        $link_svg     = '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M13.3783 0.182781C13.3562 0.157599 13.3318 0.134604 13.3053 0.114114L13.3047 0.113787C13.2147 0.0445766 13.1081 0.0133967 12.9961 0.0102739H8.33333C8.20073 0.0102739 8.07355 0.0629523 7.97978 0.15672C7.88601 0.250489 7.83333 0.377666 7.83333 0.510274C7.83333 0.642882 7.88601 0.770059 7.97978 0.863827C8.07355 0.957595 8.20073 1.01027 8.33333 1.01027H11.793L6.77149 6.03176C6.67808 6.12449 6.62401 6.25404 6.62377 6.38565C6.62353 6.51727 6.67713 6.64701 6.7702 6.74008C6.86326 6.83314 6.99301 6.88675 7.12462 6.88651C7.25624 6.88627 7.38579 6.83219 7.47851 6.73879L12.5 1.71729V5.17692C12.5 5.30953 12.5527 5.43671 12.6464 5.53047C12.7402 5.62424 12.8674 5.67692 13 5.67692C13.1326 5.67692 13.2598 5.62424 13.3536 5.53047C13.4473 5.43671 13.5 5.30953 13.5 5.17692V0.504394C13.4986 0.386184 13.4555 0.272267 13.3783 0.182781Z" fill="#2271B1"/><path d="M13 7.34359C12.8674 7.34362 12.7402 7.39631 12.6465 7.49007C12.5527 7.58383 12.5 7.71099 12.5 7.84359V11.0103C12.4997 11.2754 12.3943 11.5296 12.2068 11.717C12.0193 11.9045 11.7651 12.01 11.5 12.0103H2.5C2.23487 12.01 1.98069 11.9045 1.79321 11.717C1.60574 11.5296 1.50029 11.2754 1.5 11.0103V2.01025C1.50029 1.74513 1.60574 1.49094 1.79321 1.30347C1.98069 1.11599 2.23487 1.01054 2.5 1.01025H5.66667C5.79927 1.01025 5.92645 0.957576 6.02022 0.863807C6.11399 0.770039 6.16667 0.642862 6.16667 0.510254C6.16667 0.377646 6.11399 0.250469 6.02022 0.1567C5.92645 0.0629323 5.79927 0.0102539 5.66667 0.0102539H2.5C1.96974 0.0108327 1.46137 0.221732 1.08643 0.59668C0.711478 0.971627 0.500579 1.48 0.5 2.01025V11.0103C0.500579 11.5405 0.711478 12.0489 1.08643 12.4238C1.46137 12.7988 1.96974 13.0097 2.5 13.0103H11.5C12.0303 13.0097 12.5386 12.7988 12.9136 12.4238C13.2885 12.0489 13.4994 11.5405 13.5 11.0103V7.84359C13.5 7.71099 13.4473 7.58383 13.3535 7.49007C13.2598 7.39631 13.1326 7.34362 13 7.34359Z" fill="#2271B1"/></svg>';

        printf(
            '<div class="%1$s"><p>%2$s%3$s<a href="%4$s" target="_blank">%5$s%6$s</a></p></div>',
            esc_attr( $elem_class ),
            $world_svg,
            esc_html( $main_message ),
            esc_url( Plugin::account_url( 'changedomain' ) ),
            esc_html( $link_message ),
            $link_svg,
        );
    }

	/**
	 * Always disallow indexing on temp domains.
	 *
	 * @filter option_blog_public
	 *
	 * @param  string $value
	 *
	 * @return string
	 */
	public function option_blog_public( $value ) {

		return ( $value && Plugin::is_temp_domain() ) ? '0' : $value;

	}

	/**
	 * Prevent updating the value on temp domains.
	 *
	 * @filter pre_update_option_blog_public
	 *
	 * @param  string $new_value
	 * @param  string $old_value
	 *
	 * @return string
	 */
	public function pre_update_option_blog_public( $new_value, $old_value ) {

		return $old_value;

	}

	/**
	 * Enqueue small JS to disable blog_public checkbox.
	 *
	 * @action admin_enqueue_scripts
	 *
	 * @param string $hook
	 */
	public function admin_enqueue_scripts( $hook ) {

		if ( 'options-reading.php' !== $hook ) {

			return;

		}

		$suffix = SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_script(
			'wpaas-options-reading',
			Plugin::assets_url( "js/options-reading{$suffix}.js" ),
			[ 'jquery' ],
			Plugin::version(),
			false
		);

		if ( Plugin::is_staging_site() ) {

			$notice = sprintf(
				/* translators: 1: "Note:" wrapped in strong tags */
				__( '%s This is your staging site and it cannot be indexed by search engines.', 'gd-system-plugin' ),
				sprintf( '<strong>%s</strong>', __( 'Note:', 'gd-system-plugin' ) )
			);

		} else {

			$notice = sprintf(
				/* translators: 1: "Note:" wrapped in strong tags */
				__( '%s Your site is using a temporary domain that cannot be indexed by search engines.', 'gd-system-plugin' ),
				sprintf( '<strong>%s</strong>', __( 'Note:', 'gd-system-plugin' ) )
			);

			// Append a link where the domain can be changed
			$notice .= sprintf(
				' <a href="%1$s">%2$s</a>',
				esc_url( Plugin::account_url( 'changedomain' ) ),
				__( 'Change domain', 'gd-system-plugin' )
			);

		}

		wp_localize_script(
			'wpaas-options-reading',
			'wpaas_options_reading_vars',
			[
				'blog_public_notice_text' => esc_js( $notice ),
			]
		);

	}

}
