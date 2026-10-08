<?php
/** Real WordPress checks; run with PHP, never WP-CLI, in the marked fixture. */
$root = getenv( 'MRN_RECAPTCHA_TEST_ROOT' );
if ( ! $root || ! is_file( $root . '/.mrn-recaptcha-fixture' ) ) {
	fwrite( STDERR, "A marked disposable WordPress runtime is required.\n" );
	exit( 1 );
}
$_SERVER['REMOTE_ADDR'] = '127.0.0.2';
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
if ( ! defined( 'MRN_RECAPTCHA_ISOLATED_TEST' ) || ! MRN_RECAPTCHA_ISOLATED_TEST || 'local' !== wp_get_environment_type() ) {
	exit( 1 );
}
$checks = 0;
function check_no_commerce( $ok, $message ) {
	$GLOBALS['checks']++;
	if ( ! $ok ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo 'PASS: ' . $message . "\n";
}
function no_commerce_token( $mode = 'valid' ) {
	MRN_Recaptcha_Comments::clear_validation();
	$_POST = 'missing' === $mode ? array() : array( 'mrn_recaptcha_token' => $mode . ':' . wp_generate_uuid4() );
}
function no_commerce_comment( $post ) {
	return array( 'comment_post_ID' => $post, 'comment_author' => 'Fixture', 'comment_author_email' => 'fixture@example.test', 'comment_author_url' => '', 'comment_content' => 'Fixture ' . wp_generate_uuid4(), 'comment_type' => 'comment', 'user_id' => get_current_user_id() );
}
function no_commerce_count() {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->comments" );
}
check_no_commerce( ! class_exists( 'WooCommerce' ) && ! function_exists( 'wc_get_product' ) && ! defined( 'WC_VERSION' ), 'WooCommerce classes, functions and constants are absent' );
$with_wpforms = '1' === getenv( 'MRN_RECAPTCHA_WPFORMS_EXPECTED' );
check_no_commerce( $with_wpforms === ( function_exists( 'wpforms' ) && defined( 'WPFORMS_VERSION' ) ), 'WPForms presence matches the explicit fixture scenario' );
$expected_plugins = array( 'mrn-recaptcha-enterprise-manager/mrn-recaptcha-enterprise-manager.php' );
if ( $with_wpforms ) {
	$expected_plugins[] = 'wpforms/wpforms.php';
}
$active_plugins = get_option( 'active_plugins' );
sort( $active_plugins );
check_no_commerce( $expected_plugins === $active_plugins, 'Only the exact scenario packages are active' );
wp_set_current_user( 1 );
$wpforms_before = false;
if ( $with_wpforms ) {
	$wpforms_before = array( 'captcha-provider' => 'recaptcha', 'recaptcha-type' => 'v3', 'recaptcha-site-key' => 'fixture-existing-site-key', 'recaptcha-secret-key' => 'fixture-existing-legacy-secret', 'fixture-preserve' => 'keep' );
	update_option( 'wpforms_settings', $wpforms_before );
}
ob_start();
MRN_Recaptcha_Enterprise_Manager::render_settings_page();
MRN_Recaptcha_Comments::render_settings();
$admin_html = ob_get_clean();
check_no_commerce( false !== strpos( $admin_html, 'Comment and review reCAPTCHA' ), 'Both native settings screens render without optional dependencies' );
$wpforms_bootstrap = MRN_Recaptcha_Enterprise_Manager::bootstrap_wpforms_recaptcha();
check_no_commerce( $with_wpforms ? ( ! is_wp_error( $wpforms_bootstrap ) && 'unchanged' === $wpforms_bootstrap['status'] ) : ( is_wp_error( $wpforms_bootstrap ) && 'mrn_recaptcha_wpforms_missing' === $wpforms_bootstrap->get_error_code() ), 'WPForms bootstrap handles the actual optional dependency without WooCommerce' );
update_option( 'comment_moderation', '1' );
register_post_type( 'mrn_fixture', array( 'public' => true, 'supports' => array( 'title', 'editor', 'comments' ), 'show_in_rest' => true ) );
register_post_type( 'product', array( 'public' => true, 'supports' => array( 'title', 'comments' ) ) );
$ids = array();
foreach ( array( 'post' => 'post', 'page' => 'page', 'custom' => 'mrn_fixture', 'no_woocommerce_product' => 'product' ) as $key => $type ) {
	$ids[ $key ] = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => 'Isolated ' . $key, 'comment_status' => 'open' ) );
}
$ids['attachment'] = wp_insert_attachment( array( 'post_title' => 'Isolated attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image/png', 'comment_status' => 'open' ), false, $ids['post'] );
$ids['subscriber'] = wp_insert_user( array( 'user_login' => 'fixture-subscriber', 'user_pass' => wp_generate_password(), 'user_email' => 'subscriber@example.test', 'role' => 'subscriber' ) );
if ( $with_wpforms ) {
	$form_data = array( 'fields' => array( 1 => array( 'id' => 1, 'type' => 'text', 'label' => 'Fixture message', 'required' => '1' ) ), 'settings' => array( 'notification_enable' => '0', 'form_class' => '', 'recaptcha' => '1' ) );
	$ids['wpforms'] = wpforms()->obj( 'form' )->add( 'Isolated non-commerce form', array( 'post_content' => wpforms_encode( $form_data ) ) );
	check_no_commerce( is_int( $ids['wpforms'] ) && $ids['wpforms'] > 0, 'Real WPForms creates a native form without WooCommerce' );
	$form_data['id'] = (string) $ids['wpforms'];
	check_no_commerce( (bool) wpforms()->obj( 'form' )->update( $ids['wpforms'], $form_data ), 'Native WPForms save persists the form identity and settings' );
}
update_option( 'fixture_ids', $ids );
delete_option( MRN_Recaptcha_Comments::OPTION );
check_no_commerce( ! MRN_Recaptcha_Comments::settings()['blog_enabled'] && ! MRN_Recaptcha_Comments::settings()['reviews_enabled'], 'Fresh activation leaves both protections off' );
wp_set_current_user( 0 );
$_POST = array();
$unprotected = wp_new_comment( no_commerce_comment( $ids['post'] ), true );
check_no_commerce( is_int( $unprotected ), 'Default-off preserves the ordinary WordPress submission path' );
wp_set_current_user( 1 );
$input = array( 'blog_enabled' => 1, 'reviews_enabled' => 1, 'site_key' => 'fixture-site-key-1234567890', 'hostnames' => '127.0.0.1', 'minimum_score' => 0.5 );
$settings = MRN_Recaptcha_Comments::sanitize_settings( $input );
check_no_commerce( ! empty( $settings['verification'] ), 'Setup verifies Enterprise access without either optional plugin' );
update_option( MRN_Recaptcha_Comments::OPTION, $settings );
check_no_commerce( $wpforms_before === get_option( 'wpforms_settings', false ), 'Comment setup preserves exact optional WPForms settings' );
wp_set_current_user( 0 );
$targets = array_intersect_key( $ids, array_flip( array( 'post', 'page', 'attachment', 'custom' ) ) );
foreach ( $targets as $type => $id ) {
	$before = no_commerce_count();
	$mail_before = $GLOBALS['fixture_mail_attempts'] ?? 0;
	foreach ( array( 'missing', 'invalid', 'expired', 'old', 'future', 'wrong-key', 'wrong-host', 'wrong-action', 'low-score', 'no-score', 'no-time', 'outage', 'http-error', 'malformed' ) as $mode ) {
		no_commerce_token( $mode );
		$result = wp_new_comment( no_commerce_comment( $id ), true );
		check_no_commerce( is_wp_error( $result ) && 0 === strpos( $result->get_error_code(), 'mrn_recaptcha_' ), $type . ' rejects ' . $mode );
	}
	check_no_commerce( $before === no_commerce_count() && $mail_before === ( $GLOBALS['fixture_mail_attempts'] ?? 0 ), $type . ' rejected submissions neither save nor enter notifications' );
	no_commerce_token();
	$n = count( $GLOBALS['fixture_assessments'] );
	$saved = wp_new_comment( no_commerce_comment( $id ), true );
	check_no_commerce( is_int( $saved ) && '0' === get_comment( $saved )->comment_approved, $type . ' valid submission saves with native moderation' );
	check_no_commerce( $n + 1 === count( $GLOBALS['fixture_assessments'] ), $type . ' double approval consumes one assessment' );
	$replay = wp_new_comment( no_commerce_comment( $id ), true );
	check_no_commerce( is_wp_error( $replay ), $type . ' rejects replay after insertion' );
	no_commerce_token( 'missing' );
	$spoof = no_commerce_comment( $id );
	$spoof['user_id'] = 1;
	$spoof['comment_type'] = 'pingback';
	check_no_commerce( is_wp_error( MRN_Recaptcha_Comments::validate_comment( 0, $spoof ) ), $type . ' cannot spoof an administrator or trusted ping endpoint' );
}
no_commerce_token( 'missing' );
check_no_commerce( 0 === MRN_Recaptcha_Comments::validate_comment( 0, no_commerce_comment( $ids['no_woocommerce_product'] ) ), 'Review switch is inert on product objects when WooCommerce is absent' );
wp_set_current_user( $ids['subscriber'] );
check_no_commerce( is_wp_error( MRN_Recaptcha_Comments::validate_comment( 0, no_commerce_comment( $ids['page'] ) ) ), 'Logged-in subscriber still needs a token' );
no_commerce_token();
check_no_commerce( is_int( wp_new_comment( no_commerce_comment( $ids['page'] ), true ) ), 'Logged-in subscriber can submit with a valid token' );
wp_set_current_user( 1 );
no_commerce_token( 'missing' );
check_no_commerce( 0 === MRN_Recaptcha_Comments::validate_comment( 0, no_commerce_comment( $ids['post'] ) ), 'Administrator remains exempt' );
wp_set_current_user( 0 );
add_filter( 'rest_allow_anonymous_comments', '__return_true' );
foreach ( $targets as $type => $id ) {
	$_POST = array();
	unset( $_SERVER['HTTP_X_MRN_RECAPTCHA_TOKEN'] );
	$request = new WP_REST_Request( 'POST', '/wp/v2/comments' );
	$request->set_body_params( array( 'post' => $id, 'content' => 'REST fixture ' . wp_generate_uuid4(), 'author_name' => 'Fixture', 'author_email' => 'fixture@example.test' ) );
	$before = no_commerce_count();
	$denied = rest_do_request( $request );
	check_no_commerce( 403 === $denied->get_status() && $before === no_commerce_count(), $type . ' public REST rejects missing token before insert' );
	$_SERVER['HTTP_X_MRN_RECAPTCHA_TOKEN'] = 'valid:' . wp_generate_uuid4();
	$accepted = rest_do_request( $request );
	check_no_commerce( 201 === $accepted->get_status(), $type . ' public REST accepts a fresh token header' );
}
unset( $_SERVER['HTTP_X_MRN_RECAPTCHA_TOKEN'] );
wp_set_current_user( 1 );
update_option( MRN_Recaptcha_Comments::PAUSE_OPTION, true );
wp_set_current_user( 0 );
foreach ( $ids as $type => $id ) {
	if ( in_array( $type, array( 'subscriber', 'wpforms' ), true ) ) {
		continue;
	}
	check_no_commerce( 'mrn_recaptcha_migration_paused' === MRN_Recaptcha_Comments::validate_comment( 0, no_commerce_comment( $id ) )->get_error_code(), $type . ' explicit migration pause works without WooCommerce' );
}
delete_option( MRN_Recaptcha_Comments::PAUSE_OPTION );
wp_set_current_user( 1 );
$before = count( $GLOBALS['fixture_assessments'] );
$GLOBALS['fixture_probe_override'] = new WP_Error( 'fixture_outage' );
$off = MRN_Recaptcha_Comments::sanitize_settings( array() );
check_no_commerce( ! $off['blog_enabled'] && ! $off['reviews_enabled'] && $before === count( $GLOBALS['fixture_assessments'] ), 'Emergency disable needs no provider request' );
unset( $GLOBALS['fixture_probe_override'] );
$only_review = array_replace( $settings, array( 'blog_enabled' => false ) );
update_option( MRN_Recaptcha_Comments::OPTION, $only_review );
wp_set_current_user( 0 );
check_no_commerce( 0 === MRN_Recaptcha_Comments::validate_comment( 0, no_commerce_comment( $ids['post'] ) ), 'Review toggle cannot turn on ordinary comment protection' );
update_option( MRN_Recaptcha_Comments::OPTION, $settings );
check_no_commerce( $wpforms_before === get_option( 'wpforms_settings', false ), 'All operations preserve exact optional WPForms settings' );
echo "PASS: $checks non-commerce integration assertions; external HTTP and mail intercepted.\n";
