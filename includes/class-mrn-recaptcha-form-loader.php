<?php
/** Optional, version-scoped WPForms v3 loading adapter. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MRN_Recaptcha_Form_Loader {
	/**
	 * Vendor integrations covered by the adapter and native frontend checks.
	 *
	 * @var string[]
	 */
	private const SUPPORTED_WPFORMS_VERSIONS = array( '2.0.1.1', '2.0.2.1', '2.0.2.2', '2.0.4' );

	private static $form_id = 0;

	/** Only official reCAPTCHA frontend origins may be selected by a site. */
	private const API_HOSTS = array( 'www.google.com', 'www.recaptcha.net' );

	public static function init() {
		add_action( 'wpforms_wp_footer', array( __CLASS__, 'prepare' ), 1 );
	}

	/** Activate only for one explicitly opted-in, protected form. */
	public static function prepare( $forms ) {
		if ( is_admin() || is_customize_preview() || ! defined( 'WPFORMS_VERSION' ) || ! in_array( WPFORMS_VERSION, self::SUPPORTED_WPFORMS_VERSIONS, true ) || ! is_array( $forms ) || 1 !== count( $forms ) ) {
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
		$native_api = 'https://www.google.com/recaptcha/api.js?render=' . $settings['site_key'];
		if ( $native_api !== $script->src || false === strpos( $inline, 'var wpformsRecaptchaV3Execute = function' ) || false === strpos( $inline, 'wpformsRecaptchaLoaded' ) ) {
			return $inline;
		}
		$host = apply_filters( 'mrn_recaptcha_form_aware_api_host', 'www.google.com', self::$form_id );
		$host = is_string( $host ) && in_array( $host, self::API_HOSTS, true ) ? $host : 'www.google.com';
		$api  = 'https://' . $host . '/recaptcha/api.js?render=' . $settings['site_key'];
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
			'helpUrl' => self::get_help_url(),
			'helpText' => __( 'Use another contact method', 'mrn-recaptcha-enterprise-manager' ),
		);
		$script->src    = plugins_url( $path, MRN_RECAPTCHA_ENTERPRISE_MANAGER_FILE );
		$script->deps[] = 'jquery';
		return 'window.mrnRecaptchaFormLoader.init(' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ');';
	}

	/**
	 * Site-owned fallback destination. Keep the plugin neutral unless a site opts in.
	 *
	 * @return string
	 */
	private static function get_help_url() {
		$url = apply_filters( 'mrn_recaptcha_form_aware_help_url', '', self::$form_id );
		if ( ! is_string( $url ) || ! preg_match( '#^(?:https://|mailto:|tel:)#i', $url ) ) {
			return '';
		}
		return $url;
	}
}
