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
function get_post_type( $id ) { return array( 1 => 'post', 2 => 'product', 3 => 'page' )[ $id ] ?? 'attachment'; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value, $domain = '' ) { return $value; }
function esc_html__( $value, $domain = '' ) { return $value; }
function wp_is_rest_endpoint() { return false; }
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
foreach ( array( 1, 2 ) as $id ) {
	$error = MRN_Recaptcha_Comments::validate_comment( 1, array( 'comment_post_ID' => $id ) );
	check_pause( $error instanceof WP_Error && 'mrn_recaptcha_migration_paused' === $error->code && 503 === $error->data, 'Pause blocks native creation on target ' . $id . ' even with protection disabled' );
}
check_pause( 0 === MRN_Recaptcha_Comments::validate_comment( 0, array( 'comment_post_ID' => 3 ) ), 'Pages and other post types retain approval' );
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
