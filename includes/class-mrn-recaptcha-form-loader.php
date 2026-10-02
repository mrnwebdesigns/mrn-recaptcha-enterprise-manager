<?php
/** Optional, version-scoped WPForms v3 loading adapter. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MRN_Recaptcha_Form_Loader {
	private static $form_id = 0;

	public static function init() {
		add_action( 'wpforms_wp_footer', array( __CLASS__, 'prepare' ), 1 );
	}

	/** Activate only for one explicitly opted-in, protected form. */
	public static function prepare( $forms ) {
		if ( is_admin() || is_customize_preview() || ! defined( 'WPFORMS_VERSION' ) || ! in_array( WPFORMS_VERSION, array( '2.0.1.1', '2.0.2.1' ), true ) || ! is_array( $forms ) || 1 !== count( $forms ) ) {
			return;
		}
		$form = reset( $forms );
		if ( empty( $form['id'] ) || empty( $form['settings']['recaptcha'] ) || ! apply_filters( 'mrn_recaptcha_form_aware_loading', false, absint( $form['id'] ) ) ) {
			return;
		}
		self::$form_id = absint( $form['id'] );
		add_filter( 'wpforms_frontend_captcha_inline_script', array( __CLASS__, 'adapt' ), 100 );
	}

	/** Swap only the known classic v3 integration; otherwise keep vendor loading. */
	public static function adapt( $inline ) {
		$scripts = wp_scripts();
		$script  = $scripts->registered['wpforms-recaptcha'] ?? null;
		if ( ! self::$form_id || ! $script || ! function_exists( 'wpforms_get_captcha_settings' ) ) {
			return $inline;
		}
		$settings = wpforms_get_captcha_settings();
		if ( 'recaptcha' !== ( $settings['provider'] ?? '' ) || 'v3' !== ( $settings['recaptcha_type'] ?? '' ) || empty( $settings['site_key'] ) ) {
			return $inline;
		}
		$api = 'https://www.google.com/recaptcha/api.js?render=' . $settings['site_key'];
		if ( $api !== $script->src || false === strpos( $inline, 'var wpformsRecaptchaV3Execute = function' ) || false === strpos( $inline, 'wpformsRecaptchaLoaded' ) ) {
			return $inline;
		}
		$manifest_file = MRN_RECAPTCHA_ENTERPRISE_MANAGER_DIR . 'assets/manifest.json';
		if ( ! is_readable( $manifest_file ) ) {
			return $inline;
		}
		$manifest = json_decode( file_get_contents( $manifest_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release metadata.
		$asset    = $manifest['assets']['mrn-recaptcha-form-loader']['minified'] ?? array();
		$path     = $asset['path'] ?? '';
		if ( ! preg_match( '#^assets/generated/wpforms-form-loader\.[a-f0-9]{64}\.min\.js$#', $path ) || ! is_readable( MRN_RECAPTCHA_ENTERPRISE_MANAGER_DIR . $path ) || ! hash_equals( $asset['sha256'] ?? '', hash_file( 'sha256', MRN_RECAPTCHA_ENTERPRISE_MANAGER_DIR . $path ) ) ) {
			return $inline;
		}
		$config = array(
			'api'     => $api,
			'siteKey' => $settings['site_key'],
			'formId'  => self::$form_id,
			'error'   => __( 'Spam protection could not load. Please check your connection and try submitting again.', 'mrn-recaptcha-enterprise-manager' ),
		);
		$script->src    = plugins_url( $path, MRN_RECAPTCHA_ENTERPRISE_MANAGER_FILE );
		$script->deps[] = 'jquery';
		return 'window.mrnRecaptchaFormLoader.init(' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ');';
	}
}
