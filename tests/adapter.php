<?php
/** Standalone adapter gates; no database, Google request, or form submission. */
define( 'ABSPATH', __DIR__ );
define( 'WPFORMS_VERSION', $argv[1] ?? '2.0.2.1' );
define( 'MRN_RECAPTCHA_ENTERPRISE_MANAGER_DIR', dirname( __DIR__ ) . '/' );
define( 'MRN_RECAPTCHA_ENTERPRISE_MANAGER_FILE', dirname( __DIR__ ) . '/mrn-recaptcha-enterprise-manager.php' );
$flags = array( 'admin' => false, 'preview' => false, 'optin' => true );
$settings = array( 'provider' => 'recaptcha', 'recaptcha_type' => 'v3', 'site_key' => 'public-key' );
$scripts = (object) array( 'registered' => array() );
$hooks = array();
function is_admin() { global $flags; return $flags['admin']; }
function is_customize_preview() { global $flags; return $flags['preview']; }
function absint( $n ) { return abs( (int) $n ); }
function apply_filters( $name, $value, ...$args ) { global $flags; return $flags['optin']; }
function add_action( ...$args ) {}
function add_filter( ...$args ) { global $hooks; $hooks[] = $args; }
function wp_scripts() { global $scripts; return $scripts; }
function wpforms_get_captcha_settings() { global $settings; return $settings; }
function plugins_url( $path, $file ) { return 'https://example.test/plugin/' . $path; }
function __( $text, $domain ) { return $text; }
function wp_json_encode( ...$args ) { return json_encode( ...$args ); }
require dirname( __DIR__ ) . '/includes/class-mrn-recaptcha-form-loader.php';
function check( $ok, $label ) { if ( ! $ok ) { throw new Exception( $label ); } echo "PASS $label\n"; }
$form = array( 'id' => 68, 'settings' => array( 'recaptcha' => '1' ) );
foreach ( array( 'admin', 'preview' ) as $flag ) {
  $flags[$flag] = true; $hooks = array(); MRN_Recaptcha_Form_Loader::prepare( array( $form ) );
  check( ! $hooks, $flag . ' keeps vendor integration' ); $flags[$flag] = false;
}
$flags['optin'] = false; MRN_Recaptcha_Form_Loader::prepare( array( $form ) ); check( ! $hooks, 'default off' ); $flags['optin'] = true;
MRN_Recaptcha_Form_Loader::prepare( array( $form, $form ) ); check( ! $hooks, 'multiple forms keep vendor integration' );
MRN_Recaptcha_Form_Loader::prepare( array( array( 'id' => 68 ) ) ); check( ! $hooks, 'unprotected form unchanged' );
MRN_Recaptcha_Form_Loader::prepare( array( $form ) );
if ( '2.0.2.1' !== WPFORMS_VERSION ) { check( ! $hooks, 'unqualified vendor version unchanged' ); exit; }
check( count( $hooks ) === 1, 'qualified form enables adapter' );
$native = 'var wpformsRecaptchaV3Execute = function () {}; /* wpformsRecaptchaLoaded */';
foreach ( array( 'turnstile', 'hcaptcha' ) as $provider ) {
  $settings['provider'] = $provider;
  $scripts->registered['wpforms-recaptcha'] = (object) array( 'src' => 'https://www.google.com/recaptcha/api.js?render=public-key', 'deps' => array() );
  check( MRN_Recaptcha_Form_Loader::adapt( $native ) === $native, $provider . ' untouched' );
}
$settings['provider'] = 'recaptcha'; $settings['recaptcha_type'] = 'v2';
check( MRN_Recaptcha_Form_Loader::adapt( $native ) === $native, 'v2 untouched' ); $settings['recaptcha_type'] = 'v3';
check( MRN_Recaptcha_Form_Loader::adapt( 'changed vendor contract' ) === 'changed vendor contract', 'unknown inline contract untouched' );
$scripts->registered['wpforms-recaptcha']->src = 'https://other.test/api.js';
check( MRN_Recaptcha_Form_Loader::adapt( $native ) === $native, 'custom API untouched' );
$scripts->registered['wpforms-recaptcha']->src = 'https://www.google.com/recaptcha/api.js?render=public-key';
$adapted = MRN_Recaptcha_Form_Loader::adapt( $native );
check( str_starts_with( $adapted, 'window.mrnRecaptchaFormLoader.init(' ), 'qualified adapter config' );
check( str_contains( $scripts->registered['wpforms-recaptcha']->src, 'assets/generated/wpforms-form-loader.' ), 'immutable local URL' );
check( in_array( 'jquery', $scripts->registered['wpforms-recaptcha']->deps, true ), 'recovery dependency retained' );
check( str_contains( $adapted, '"formId":68' ), 'exact form identity' );
