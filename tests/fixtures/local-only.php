<?php
/** Isolated runtime fixture. Never package or deploy this file. */
if ( ! defined( 'MRN_RECAPTCHA_ISOLATED_TEST' ) || true !== MRN_RECAPTCHA_ISOLATED_TEST || 'local' !== wp_get_environment_type() || 'http://127.0.0.1:8765' !== WP_HOME ) {
	throw new RuntimeException( 'Fixture requires the disposable local test runtime.' );
}
add_filter( 'pre_wp_mail', function () { $GLOBALS['fixture_mail_attempts'] = ( $GLOBALS['fixture_mail_attempts'] ?? 0 ) + 1; return true; }, PHP_INT_MAX );
add_filter( 'wp_is_application_passwords_available', '__return_false' );
// Keep repeated synthetic cases independent of core's flood timer.
add_filter( 'wp_is_comment_flood', '__return_false', PHP_INT_MAX );
$fixture_key = openssl_pkey_new( array( 'private_key_bits' => 2048 ) );
openssl_pkey_export( $fixture_key, $fixture_private_key );
define( 'MRN_RECAPTCHA_ENTERPRISE_PROJECT_ID', 'isolated-fixture-project' );
define( 'MRN_RECAPTCHA_ENTERPRISE_SERVICE_ACCOUNT_EMAIL', 'fixture@example.test' );
define( 'MRN_RECAPTCHA_ENTERPRISE_PRIVATE_KEY', $fixture_private_key );
define( 'DISABLE_WP_CRON', true );
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( 'https://oauth2.googleapis.com/token' === $url ) {
		$data = array( 'access_token' => 'fixture-access', 'expires_in' => 3600 );
	} elseif ( false !== strpos( $url, '/keys/' ) ) {
		$data = array( 'name' => 'projects/isolated-fixture-project/keys/fixture-site-key-1234567890', 'webSettings' => array( 'integrationType' => 'SCORE', 'allowedDomains' => array( '127.0.0.1' ), 'allowAllDomains' => false ) );
		if ( isset( $GLOBALS['fixture_key_override'] ) ) $data = $GLOBALS['fixture_key_override'];
	} elseif ( false !== strpos( $url, '/assessments' ) ) {
		$event = json_decode( $args['body'], true )['event'];
		$GLOBALS['fixture_assessments'][] = $event;
		$token = $event['token'];
		$mode = explode( ':', $token )[0];
		if ( 'outage' === $mode ) return new WP_Error( 'fixture_outage', 'Mock provider unavailable' );
		if ( 'http-error' === $mode ) return array( 'response' => array( 'code' => 503 ), 'body' => '{}' );
		if ( 'malformed' === $mode ) return array( 'response' => array( 'code' => 200 ), 'body' => 'invalid-json' );
		$used = get_option( 'fixture_used_tokens', array() );
		$data = array(
			'tokenProperties' => array( 'valid' => ! in_array( $mode, array( 'invalid', 'wrong-key', 'expired' ), true ) && ! in_array( $token, $used, true ), 'action' => $event['expectedAction'], 'hostname' => '127.0.0.1', 'createTime' => gmdate( 'c' ) ),
			'riskAnalysis' => array( 'score' => 0.9 ),
		);
		$used[] = $token;
		update_option( 'fixture_used_tokens', $used );
		if ( 'wrong-action' === $mode ) $data['tokenProperties']['action'] = 'wpforms';
		if ( 'wrong-host' === $mode ) $data['tokenProperties']['hostname'] = 'attacker.example.test';
		if ( 'old' === $mode ) $data['tokenProperties']['createTime'] = gmdate( 'c', time() - 121 );
		if ( 'future' === $mode ) $data['tokenProperties']['createTime'] = gmdate( 'c', time() + 60 );
		if ( 'low-score' === $mode ) $data['riskAnalysis']['score'] = 0.1;
		if ( 'no-score' === $mode ) unset( $data['riskAnalysis'] );
		if ( 'no-time' === $mode ) unset( $data['tokenProperties']['createTime'] );
	} else {
		return new WP_Error( 'fixture_network_blocked', 'All other outbound HTTP is blocked in this fixture.' );
	}
	return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $data ) );
}, PHP_INT_MAX, 3 );
