<?php
/**
 * Non-blocking WPForms reCAPTCHA v3 loading.
 *
 * @package MRN_Recaptcha_Enterprise_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MRN_Recaptcha_Frontend {

	/**
	 * Preserve WPForms' ready callback when its v3 loader is asynchronous.
	 *
	 * WordPress includes the handle's inline scripts in this filter. Prepending
	 * the readiness queue keeps both cached and slow Google responses safe.
	 *
	 * @param string $tag    Script markup, including attached inline scripts.
	 * @param string $handle Registered script handle.
	 * @param string $src    Script source URL.
	 * @return string
	 */
	public static function async_wpforms_v3( $tag, $handle, $src ) {
		if ( 'wpforms-recaptcha' !== $handle || is_admin() || ! function_exists( 'wpforms_get_captcha_settings' ) ) {
			return $tag;
		}

		$settings = wpforms_get_captcha_settings();
		if ( 'recaptcha' !== ( $settings['provider'] ?? '' ) || 'v3' !== ( $settings['recaptcha_type'] ?? '' ) ) {
			return $tag;
		}
		// Keep the synchronous fallback if WPForms changes its integration contract.
		if ( false === strpos( $tag, 'wpformsRecaptchaV3Execute' ) ) {
			return $tag;
		}

		$url = wp_parse_url( $src );
		if (
			! is_array( $url )
			|| 'https' !== ( $url['scheme'] ?? '' )
			|| ! in_array( $url['host'] ?? '', array( 'www.google.com', 'www.recaptcha.net' ), true )
			|| '/recaptcha/api.js' !== ( $url['path'] ?? '' )
		) {
			return $tag;
		}

		$processor = new WP_HTML_Tag_Processor( $tag );
		while ( $processor->next_tag( 'SCRIPT' ) ) {
			if ( $src !== $processor->get_attribute( 'src' ) ) {
				continue;
			}

			$processor->set_attribute( 'async', true );
			// Google's documented ready queue; preserve any already-loaded API.
			$ready = '(function(w){w.grecaptcha=w.grecaptcha||{};if(typeof w.grecaptcha.ready!=="function"){w.grecaptcha.ready=function(callback){var config=w.___grecaptcha_cfg=w.___grecaptcha_cfg||{};(config.fns=config.fns||[]).push(callback);};}})(window);';
			// An early submission must wait for Google before WPForms requests a token.
			$submit = '(function(w){var execute=w.wpformsRecaptchaV3Execute;if(typeof execute==="function"){w.wpformsRecaptchaV3Execute=function(){var context=this,args=arguments;w.grecaptcha.ready(function(){execute.apply(context,args);});};}})(window);';

			return wp_get_inline_script_tag( $ready, array( 'id' => 'mrn-recaptcha-ready' ) )
				. $processor->get_updated_html()
				. wp_get_inline_script_tag( $submit, array( 'id' => 'mrn-recaptcha-submit-ready' ) );
		}

		return $tag;
	}
}
