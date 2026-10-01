<?php
/** Run with php (not wp eval): MRN_RECAPTCHA_TEST_ROOT points at a disposable site. */
$root = getenv( 'MRN_RECAPTCHA_TEST_ROOT' );
if ( ! $root || ! is_file( $root . '/.mrn-recaptcha-fixture' ) ) {
	fwrite( STDERR, "A marked disposable WordPress runtime is required.\n" ); exit( 1 );
}
$_SERVER['REMOTE_ADDR'] = '127.0.0.2';
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
if ( ! defined( 'MRN_RECAPTCHA_ISOLATED_TEST' ) || ! MRN_RECAPTCHA_ISOLATED_TEST || wp_get_environment_type() !== 'local' ) exit( 1 );
$checks = 0;
function check( $ok, $message ) {
	$GLOBALS['checks']++;
	if ( ! $ok ) throw new RuntimeException( 'FAIL: ' . $message );
	echo 'PASS: ' . $message . "\n";
}
function token( $mode = 'valid' ) {
	MRN_Recaptcha_Comments::clear_validation();
	$_POST = array( 'mrn_recaptcha_token' => $mode . ':' . wp_generate_uuid4() );
}
function count_comments() { global $wpdb; return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->comments" ); }
function new_comment_data( $post ) {
	return array( 'comment_post_ID' => $post, 'comment_author' => 'Fixture', 'comment_author_email' => 'fixture@example.test', 'comment_author_url' => '', 'comment_content' => 'Fixture ' . wp_generate_uuid4(), 'comment_type' => 'comment', 'user_id' => get_current_user_id() );
}

wp_set_current_user( 1 );
update_option( 'comment_moderation', '1' );
update_option( 'woocommerce_enable_reviews', 'yes' );
update_option( 'woocommerce_enable_review_rating', 'yes' );
update_option( 'woocommerce_review_rating_required', 'yes' );
update_option( 'woocommerce_review_rating_verification_required', 'yes' );
remove_action( 'check_comment_flood', 'check_comment_flood_db', 10 );
add_filter( 'wp_is_comment_flood', '__return_false' );
$wpforms = array( 'captcha-provider' => 'recaptcha', 'recaptcha-type' => 'v3', 'recaptcha-site-key' => 'existing-wpforms-fixture', 'recaptcha-secret-key' => 'fixture-only', 'other' => 'keep' );
update_option( 'wpforms_settings', $wpforms );
delete_option( MRN_Recaptcha_Comments::OPTION );
check( ! MRN_Recaptcha_Comments::settings()['blog_enabled'] && ! MRN_Recaptcha_Comments::settings()['reviews_enabled'], 'Both protections default off' );
$input = array( 'blog_enabled' => 1, 'reviews_enabled' => 1, 'site_key' => 'fixture-site-key-1234567890', 'hostnames' => '127.0.0.1', 'minimum_score' => 0.5 );
$settings = MRN_Recaptcha_Comments::sanitize_settings( $input );
check( ! empty( $settings['verification'] ), 'Production SCORE metadata and hostname verified before enablement' );
update_option( MRN_Recaptcha_Comments::OPTION, $settings );
check( get_option( 'wpforms_settings' ) === $wpforms, 'Comment setup preserves every WPForms setting' );
$base_key = array( 'name' => 'projects/isolated-fixture-project/keys/fixture-site-key-1234567890', 'webSettings' => array( 'integrationType' => 'SCORE', 'allowedDomains' => array( '127.0.0.1' ), 'allowAllDomains' => false ) );
foreach ( array( 'checkbox', 'wrong-project', 'wrong-domain', 'all-domains', 'testing', 'waf', 'empty' ) as $mode ) {
	$key = $base_key;
	if ( 'checkbox' === $mode ) $key['webSettings']['integrationType'] = 'CHECKBOX';
	if ( 'wrong-project' === $mode ) $key['name'] = 'projects/other/keys/fixture-site-key-1234567890';
	if ( 'wrong-domain' === $mode ) $key['webSettings']['allowedDomains'] = array( 'other.test' );
	if ( 'all-domains' === $mode ) $key['webSettings']['allowAllDomains'] = true;
	if ( 'testing' === $mode ) $key['testingOptions'] = array( 'testingScore' => 1 );
	if ( 'waf' === $mode ) $key['wafSettings'] = array( 'wafService' => 'CA' );
	if ( 'empty' === $mode ) $key = array();
	$GLOBALS['fixture_key_override'] = $key;
	check( is_wp_error( MRN_Recaptcha_Comments::verify_key( $settings ) ), 'Reject key: ' . $mode );
	check( MRN_Recaptcha_Comments::sanitize_settings( $input ) === $settings, 'Failed key verification retains old configuration: ' . $mode );
}
unset( $GLOBALS['fixture_key_override'] );
$post = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Comment fixture', 'comment_status' => 'open' ) );
$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Unrelated fixture' ) );
$product = new WC_Product_Simple(); $product->set_name( 'Review fixture' ); $product->set_status( 'publish' ); $product->set_regular_price( '1' ); $product->set_reviews_allowed( true ); $product_id = $product->save();
$suffix = substr( wp_generate_uuid4(), 0, 8 );
$buyer = wp_insert_user( array( 'user_login' => 'buyer-' . $suffix, 'user_pass' => wp_generate_password(), 'user_email' => 'buyer-' . $suffix . '@example.test', 'role' => 'customer' ) );
$nonbuyer = wp_insert_user( array( 'user_login' => 'nonbuyer-' . $suffix, 'user_pass' => wp_generate_password(), 'user_email' => 'nonbuyer-' . $suffix . '@example.test', 'role' => 'customer' ) );
$order = wc_create_order( array( 'customer_id' => $buyer ) ); $order->set_billing_email( 'buyer-' . $suffix . '@example.test' ); $order->add_product( $product ); $order->set_status( 'completed' ); $order->save();
check( wc_customer_bought_product( '', $buyer, $product_id ), 'Real WooCommerce completed order establishes verified purchaser' );
wp_set_current_user( 0 );
$before = count_comments();
$mail_before = $GLOBALS['fixture_mail_attempts'] ?? 0;
foreach ( array( 'missing', 'invalid', 'expired', 'old', 'future', 'wrong-key', 'wrong-host', 'wrong-action', 'low-score', 'no-score', 'no-time', 'outage', 'http-error', 'malformed' ) as $mode ) {
	token( $mode ); if ( 'missing' === $mode ) $_POST = array();
	$result = wp_new_comment( new_comment_data( $post ), true );
	check( is_wp_error( $result ) && 0 === strpos( $result->get_error_code(), 'mrn_recaptcha_' ), 'Core rejects token: ' . $mode );
	check( count_comments() === $before, 'Nothing saved for ' . $mode );
}
check( $mail_before === ( $GLOBALS['fixture_mail_attempts'] ?? 0 ), 'Rejected tokens never enter the notification pipeline' );
token(); $valid_token = $_POST['mrn_recaptcha_token'];
$n = count( $GLOBALS['fixture_assessments'] );
$saved = wp_new_comment( new_comment_data( $post ), true );
check( is_int( $saved ) && $saved > 0, 'Valid guest blog submission saved' );
check( '0' === get_comment( $saved )->comment_approved, 'Blog moderation is preserved' );
check( count( $GLOBALS['fixture_assessments'] ) === $n + 1, 'Core double approval consumes one assessment' );
$again = wp_new_comment( new_comment_data( $post ), true );
check( is_wp_error( $again ), 'Replay rejected after successful insertion' );
$_POST = array(); $spoof = new_comment_data( $post ); $spoof['user_ID'] = 1; $spoof['user_id'] = 1;
check( is_wp_error( MRN_Recaptcha_Comments::validate_comment( 0, $spoof ) ), 'Claimed admin user ID does not exempt anonymous submissions' );
$spoof['comment_type'] = 'pingback';
check( is_wp_error( MRN_Recaptcha_Comments::validate_comment( 0, $spoof ) ), 'Claimed pingback outside ping endpoint cannot bypass validation' );
check( 0 === MRN_Recaptcha_Comments::validate_comment( 0, new_comment_data( $page ) ), 'Unrelated post types are unaffected' );
$error = new WP_Error( 'previous', 'Previous validation failed' );
check( $error === MRN_Recaptcha_Comments::validate_comment( $error, new_comment_data( $post ) ), 'Earlier validation errors preserved' );
wp_set_current_user( $buyer ); $_POST = array();
check( is_wp_error( MRN_Recaptcha_Comments::validate_comment( 0, new_comment_data( $product_id ) ) ), 'Logged-in purchaser still needs CAPTCHA' );
token(); $_POST['comment_post_ID'] = $product_id; $_POST['rating'] = '4';
$review = wp_new_comment( new_comment_data( $product_id ), true );
check( is_int( $review ), 'Valid purchaser review saved' );
check( 'review' === get_comment( $review )->comment_type && '4' === get_comment_meta( $review, 'rating', true ), 'WooCommerce review type and rating preserved' );
check( '0' === get_comment( $review )->comment_approved, 'Review moderation preserved' );
add_filter( 'comment_moderation_recipients', function ( $emails ) { $emails[] = 'routed-fixture@example.test'; return $emails; }, 100 );
$routed = apply_filters( 'comment_moderation_recipients', array( 'original@example.test' ), $review );
update_option( MRN_Recaptcha_Comments::OPTION, array_replace( $settings, array( 'blog_enabled' => false, 'reviews_enabled' => false ) ) );
check( $routed === apply_filters( 'comment_moderation_recipients', array( 'original@example.test' ), $review ) && in_array( 'routed-fixture@example.test', $routed, true ), 'WooCommerce and downstream recipient routing unchanged when protection toggles' );
update_option( MRN_Recaptcha_Comments::OPTION, $settings );
wp_set_current_user( $nonbuyer ); token();
check( 'mrn_recaptcha_purchaser' === MRN_Recaptcha_Comments::validate_comment( 0, new_comment_data( $product_id ) )->get_error_code(), 'Valid-token non-purchaser is rejected' );
check( $settings === MRN_Recaptcha_Comments::sanitize_settings( array() ), 'Customer cannot disable protection through the settings sanitizer' );
wp_set_current_user( 0 ); token();
check( 'mrn_recaptcha_purchaser' === MRN_Recaptcha_Comments::validate_comment( 0, new_comment_data( $product_id ) )->get_error_code(), 'Guest review cannot bypass purchaser requirement' );
wp_set_current_user( 1 ); $_POST = array();
check( 0 === MRN_Recaptcha_Comments::validate_comment( 0, new_comment_data( $post ) ), 'Administrator reply does not need CAPTCHA' );
wp_set_current_user( 0 );
$imported = wp_insert_comment( new_comment_data( $post ) );
check( is_int( $imported ), 'Trusted low-level import path remains unchanged' );
$one = $settings; $one['blog_enabled'] = false; update_option( MRN_Recaptcha_Comments::OPTION, $one );
check( 0 === MRN_Recaptcha_Comments::validate_comment( 0, new_comment_data( $post ) ), 'Blog protection independently disabled' );
wp_set_current_user( $buyer );
check( is_wp_error( MRN_Recaptcha_Comments::validate_comment( 0, new_comment_data( $product_id ) ) ), 'Review protection remains enabled' );
$one = $settings; $one['reviews_enabled'] = false; update_option( MRN_Recaptcha_Comments::OPTION, $one );
check( 0 === MRN_Recaptcha_Comments::validate_comment( 0, new_comment_data( $product_id ) ), 'Review protection independently disabled' );
check( is_wp_error( MRN_Recaptcha_Comments::validate_comment( 0, new_comment_data( $post ) ) ), 'Blog protection remains enabled' );
update_option( MRN_Recaptcha_Comments::OPTION, $settings );
wp_set_current_user( 0 ); $_POST = array();
$request = new WP_REST_Request( 'POST', '/wp/v2/comments' ); $request->set_body_params( array( 'post' => $post, 'content' => 'REST fixture ' . wp_generate_uuid4(), 'author_name' => 'REST fixture', 'author_email' => 'rest@example.test' ) );
add_filter( 'rest_allow_anonymous_comments', '__return_true' );
$request->set_param( 'id', 1 );
check( is_wp_error( MRN_Recaptcha_Comments::validate_rest( new_comment_data( $post ), $request ) ), 'Body-supplied REST id cannot impersonate an update' );
unset( $request['id'] );
$rest = rest_do_request( $request );
check( 403 === $rest->get_status() && 0 === strpos( $rest->get_data()['code'], 'mrn_recaptcha_' ), 'REST cannot bypass missing token enforcement' );
token(); $_SERVER['HTTP_X_MRN_RECAPTCHA_TOKEN'] = $_POST['mrn_recaptcha_token']; $_POST = array();
$rest = rest_do_request( $request );
check( 201 === $rest->get_status(), 'REST blog submission with fresh token is accepted' );
unset( $_SERVER['HTTP_X_MRN_RECAPTCHA_TOKEN'] );
wp_set_current_user( 1 );
$off = MRN_Recaptcha_Comments::sanitize_settings( array() );
check( ! $off['blog_enabled'] && ! $off['reviews_enabled'], 'Emergency disable retains key configuration without Google calls' );
check( $wpforms === get_option( 'wpforms_settings' ), 'All comment/review operations leave WPForms unchanged' );
update_option( 'fixture_ids', array( 'post' => $post, 'product' => $product_id, 'buyer' => $buyer, 'nonbuyer' => $nonbuyer ) );
wp_set_current_user( $buyer ); $_POST = array();
define( 'DOING_AJAX', true );
check( is_wp_error( MRN_Recaptcha_Comments::validate_comment( 0, new_comment_data( $post ) ) ), 'AJAX context does not exempt logged-in customers' );
define( 'DOING_CRON', true );
check( 0 === MRN_Recaptcha_Comments::validate_comment( 0, new_comment_data( $post ) ), 'Trusted cron operations remain exempt' );
echo "PASS: $checks integration assertions; all email and external HTTP blocked by fixture.\n";
