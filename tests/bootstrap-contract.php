<?php
/** Focused idempotent Stack-bootstrap contract checks. */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MRN_RECAPTCHA_ENTERPRISE_PROJECT_ID', 'test-project' );
define( 'MRN_RECAPTCHA_ENTERPRISE_SERVICE_ACCOUNT_EMAIL', 'test@example.test' );
$mrn_recaptcha_test_key_resource = openssl_pkey_new( array( 'private_key_bits' => 1024 ) );
$mrn_recaptcha_test_private_key  = '';
openssl_pkey_export( $mrn_recaptcha_test_key_resource, $mrn_recaptcha_test_private_key );
define( 'MRN_RECAPTCHA_ENTERPRISE_PRIVATE_KEY', $mrn_recaptcha_test_private_key );
define( 'MRN_RECAPTCHA_ENTERPRISE_DEFAULT_INTEGRATION_TYPE', 'SCORE' );

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_email( $value ) { return filter_var( (string) $value, FILTER_SANITIZE_EMAIL ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function __( $value ) { return $value; }
function home_url() { return 'https://example.test/'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_option( $name, $default = false ) { return $GLOBALS['mrn_recaptcha_test_options'][ $name ] ?? $default; }
function update_option( $name, $value ) { $GLOBALS['mrn_recaptcha_test_options'][ $name ] = $value; return true; }
function wpforms() { return true; }
function wpforms_update_settings( $value ) { return update_option( 'wpforms_settings', $value ); }
function get_posts() { return array(); }
function wp_remote_retrieve_response_code( $response ) { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( $response ) { return (string) ( $response['body'] ?? '' ); }
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['mrn_recaptcha_test_requests'][] = array( 'POST', $url, $args );
	if ( false !== strpos( $url, '/token' ) ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'access_token' => 'test-token' ) ) );
	}
	return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'name' => 'projects/test-project/keys/created-site-key' ) ) );
}
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['mrn_recaptcha_test_requests'][] = array( 'GET', $url, $args );
	if ( false !== strpos( $url, ':retrieveLegacySecretKey' ) ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'legacySecretKey' => 'test-legacy-secret' ) ) );
	}
	$matching_key = array(
			array(
				'name'        => 'projects/test-project/keys/existing-site-key',
				'webSettings' => array(
					'allowedDomains' => array( 'example.test' ),
					'integrationType' => 'SCORE',
				),
			),
		);
	$keys = 'reuse' === $GLOBALS['mrn_recaptcha_test_mode']
		? $matching_key
		: ( 'ambiguous' === $GLOBALS['mrn_recaptcha_test_mode'] ? array_merge( $matching_key, $matching_key ) : array() );
	return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'keys' => $keys ) ) );
}

require dirname( __DIR__ ) . '/includes/class-mrn-recaptcha-enterprise-manager.php';

function assert_recaptcha_bootstrap( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$GLOBALS['mrn_recaptcha_test_options'] = array(
	MRN_Recaptcha_Enterprise_Manager::OPTION_KEY => array(),
	'wpforms_settings' => array(),
);
$GLOBALS['mrn_recaptcha_test_requests'] = array();
$GLOBALS['mrn_recaptcha_test_mode'] = 'reuse';

$reused = MRN_Recaptcha_Enterprise_Manager::bootstrap_wpforms_recaptcha();
assert_recaptcha_bootstrap( ! is_wp_error( $reused ) && 'reused' === $reused['status'], 'Bootstrap did not reuse the one exact existing key.' );
assert_recaptcha_bootstrap( ! isset( $reused['site_key'], $reused['legacy_secret_key'] ), 'Bootstrap returned secret key material.' );
assert_recaptcha_bootstrap( 'existing-site-key' === $GLOBALS['mrn_recaptcha_test_options']['wpforms_settings']['recaptcha-site-key'], 'The reused site key was not synchronized to WPForms.' );
assert_recaptcha_bootstrap( 'test-legacy-secret' === $GLOBALS['mrn_recaptcha_test_options']['wpforms_settings']['recaptcha-secret-key'], 'The reused legacy secret was not synchronized to WPForms.' );

$request_count = count( $GLOBALS['mrn_recaptcha_test_requests'] );
$unchanged = MRN_Recaptcha_Enterprise_Manager::bootstrap_wpforms_recaptcha();
assert_recaptcha_bootstrap( 'unchanged' === $unchanged['status'], 'Configured WPForms keys were not treated idempotently.' );
assert_recaptcha_bootstrap( $request_count === count( $GLOBALS['mrn_recaptcha_test_requests'] ), 'Idempotent bootstrap made an unnecessary Google request.' );

$GLOBALS['mrn_recaptcha_test_options']['wpforms_settings'] = array();
$GLOBALS['mrn_recaptcha_test_mode'] = 'create';
$created = MRN_Recaptcha_Enterprise_Manager::bootstrap_wpforms_recaptcha();
assert_recaptcha_bootstrap( ! is_wp_error( $created ) && 'created' === $created['status'], 'Bootstrap did not create a key when no exact match existed.' );
assert_recaptcha_bootstrap( 'created-site-key' === $GLOBALS['mrn_recaptcha_test_options']['wpforms_settings']['recaptcha-site-key'], 'The created site key was not synchronized to WPForms.' );

$GLOBALS['mrn_recaptcha_test_options']['wpforms_settings'] = array();
$GLOBALS['mrn_recaptcha_test_mode'] = 'ambiguous';
$ambiguous = MRN_Recaptcha_Enterprise_Manager::bootstrap_wpforms_recaptcha();
assert_recaptcha_bootstrap( is_wp_error( $ambiguous ) && 'mrn_recaptcha_key_ambiguous' === $ambiguous->get_error_code(), 'Bootstrap did not fail closed when multiple exact Google keys matched.' );

fwrite( STDOUT, "PASS: reCAPTCHA Stack bootstrap is idempotent, reuses exact keys, and does not return secrets.\n" );
