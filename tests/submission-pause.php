<?php
/** Verify the bounded pause without a database or a Google connection. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['pause_options'] = array();
$GLOBALS['pause_admin'] = false;
$GLOBALS['pause_moderator'] = false;
$GLOBALS['pause_hooks'] = array();
class WP_Error {
	public $code;
	public $data;
	public function __construct( $code, $message = '', $data = null ) { $this->code = $code; $this->data = $data; }
}
function get_option( $name, $default = false ) { return $GLOBALS['pause_options'][ $name ] ?? $default; }
function current_user_can( $capability, ...$args ) { return $GLOBALS['pause_admin'] || ( 'edit_post' === $capability && $GLOBALS['pause_moderator'] ); }
function wp_doing_cron() { return false; }
function absint( $value ) { return abs( (int) $value ); }
function get_post_type( $id ) { return array( 1 => 'post', 2 => 'product', 3 => 'page', 4 => 'attachment', 5 => 'event' )[ $id ] ?? false; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value, $domain = '' ) { return $value; }
function esc_html__( $value, $domain = '' ) { return $value; }
function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
function wp_is_rest_endpoint() { return ! empty( $GLOBALS['pause_rest'] ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_parse_url( $value, $component ) { return parse_url( $value, $component ); }
function home_url() { return 'https://comment-fixture.test'; }
class WooCommerce {}
class MRN_Recaptcha_Enterprise_Manager { public static function comment_project_id() { return 'synthetic-project'; } }
function is_admin() { return $GLOBALS['pause_moderator']; }
function wp_doing_ajax() { return $GLOBALS['pause_moderator']; }
function doing_action( $action ) { return 'wp_ajax_replyto-comment' === $action && $GLOBALS['pause_moderator']; }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return trim( $value ); }
function wp_verify_nonce( $nonce, $action ) { return 'moderator-nonce' === $nonce && 'replyto-comment' === $action; }
function add_action( ...$args ) {}
function add_filter( ...$args ) { $GLOBALS['pause_hooks'][] = $args; }
require dirname( __DIR__ ) . '/includes/class-mrn-recaptcha-comments.php';
function check_pause( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	echo 'PASS: ' . $message . "\n";
}
MRN_Recaptcha_Comments::init();
check_pause( false === get_option( MRN_Recaptcha_Comments::PAUSE_OPTION ), 'Pause defaults off' );
check_pause( 1 === MRN_Recaptcha_Comments::validate_comment( 1, array( 'comment_post_ID' => 1 ) ), 'Disabled protection preserves ordinary approval' );
$GLOBALS['pause_options'][ MRN_Recaptcha_Comments::PAUSE_OPTION ] = true;
foreach ( array( 1, 2, 3, 4, 5 ) as $id ) {
	$error = MRN_Recaptcha_Comments::validate_comment( 1, array( 'comment_post_ID' => $id ) );
	check_pause( $error instanceof WP_Error && 'mrn_recaptcha_migration_paused' === $error->code && 503 === $error->data, 'Pause blocks native creation on target ' . $id . ' even with protection disabled' );
}
check_pause( 0 === MRN_Recaptcha_Comments::validate_comment( 0, array( 'comment_post_ID' => 999 ) ), 'Missing targets are left to core validation' );
$existing = new WP_Error( 'existing-rejection' );
check_pause( $existing === MRN_Recaptcha_Comments::validate_comment( $existing, array( 'comment_post_ID' => 1 ) ), 'An existing rejection is preserved' );
$request = new class { public function get_url_params() { return array(); } };
$prepared = (object) array( 'comment_post_ID' => 2 );
check_pause( MRN_Recaptcha_Comments::validate_rest( $prepared, $request ) instanceof WP_Error, 'REST creation cannot bypass the pause' );
$edit_request = new class { public function get_url_params() { return array( 'id' => 99 ); } };
check_pause( $prepared === MRN_Recaptcha_Comments::validate_rest( $prepared, $edit_request ), 'Editing an existing comment remains available' );
$GLOBALS['pause_admin'] = true;
check_pause( 1 === MRN_Recaptcha_Comments::validate_comment( 1, array( 'comment_post_ID' => 1 ) ), 'Administrator workflow is preserved' );
$GLOBALS['pause_admin'] = false;
$GLOBALS['pause_moderator'] = true;
$_POST['_ajax_nonce-replyto-comment'] = 'moderator-nonce';
check_pause( 1 === MRN_Recaptcha_Comments::validate_comment( 1, array( 'comment_post_ID' => 1 ) ), 'Authorized native moderator reply remains available' );
$_POST['_ajax_nonce-replyto-comment'] = 'incorrect';
check_pause( MRN_Recaptcha_Comments::validate_comment( 1, array( 'comment_post_ID' => 1 ) ) instanceof WP_Error, 'Claiming a moderator reply cannot bypass the pause' );
$GLOBALS['pause_moderator'] = false;
ob_start(); MRN_Recaptcha_Comments::render_field( 1 ); $markup = ob_get_clean();
check_pause( false !== strpos( $markup, 'role="alert"' ) && false !== strpos( $markup, 'briefly unavailable' ), 'The affected form explains the temporary pause accessibly' );
$GLOBALS['pause_options'][ MRN_Recaptcha_Comments::PAUSE_OPTION ] = false;
check_pause( 1 === MRN_Recaptcha_Comments::validate_comment( 1, array( 'comment_post_ID' => 1 ) ), 'Explicit reopening restores normal disabled-surface behavior' );

// Exercise the real enforcement entry points with verified synthetic settings.
$settings = array( 'blog_enabled' => true, 'reviews_enabled' => false, 'site_key' => 'synthetic-public-key', 'hostnames' => array( 'comment-fixture.test' ), 'minimum_score' => 0.5 );
$settings['verification'] = hash( 'sha256', wp_json_encode( array( 'synthetic-project', $settings['site_key'], $settings['hostnames'] ) ) );
$GLOBALS['pause_options'][ MRN_Recaptcha_Comments::OPTION ] = $settings;
foreach ( array( 1, 3, 4, 5 ) as $id ) {
	$error = MRN_Recaptcha_Comments::validate_comment( 1, array( 'comment_post_ID' => $id, 'comment_type' => 'review', 'user_id' => 1 ) );
	check_pause( $error instanceof WP_Error && 'mrn_recaptcha_missing' === $error->code && 403 === $error->data, 'WordPress protection rejects a missing token on target ' . $id . ' regardless of claimed type or user' );
}
check_pause( 1 === MRN_Recaptcha_Comments::validate_comment( 1, array( 'comment_post_ID' => 2 ) ), 'WordPress protection does not implicitly enable product reviews' );
check_pause( 1 === MRN_Recaptcha_Comments::validate_comment( 1, array( 'comment_post_ID' => 999 ) ), 'An invalid target remains subject to core validation' );
$GLOBALS['pause_options'][ MRN_Recaptcha_Comments::OPTION ]['blog_enabled'] = false;
$GLOBALS['pause_options'][ MRN_Recaptcha_Comments::OPTION ]['reviews_enabled'] = true;
foreach ( array( 1, 3, 4, 5 ) as $id ) {
	check_pause( 1 === MRN_Recaptcha_Comments::validate_comment( 1, array( 'comment_post_ID' => $id ) ), 'Review-only protection preserves disabled WordPress target ' . $id );
}
check_pause( 'mrn_recaptcha_missing' === MRN_Recaptcha_Comments::validate_comment( 1, array( 'comment_post_ID' => 2 ) )->code, 'Review protection independently rejects missing tokens' );
$GLOBALS['pause_options'][ MRN_Recaptcha_Comments::OPTION ]['blog_enabled'] = true;
$GLOBALS['pause_rest'] = true;
foreach ( array( 1, 2, 3, 4, 5 ) as $id ) {
	$error = MRN_Recaptcha_Comments::validate_rest( (object) array( 'comment_post_ID' => $id ), $request );
	check_pause( $error instanceof WP_Error && 'mrn_recaptcha_missing' === $error->code && array( 'status' => 403 ) === $error->data, 'REST creations enforce protection with the correct status on target ' . $id );
}
$GLOBALS['pause_admin'] = true;
check_pause( 1 === MRN_Recaptcha_Comments::validate_comment( 1, array( 'comment_post_ID' => 4 ) ), 'Enabled attachment protection retains the administrator exemption' );
