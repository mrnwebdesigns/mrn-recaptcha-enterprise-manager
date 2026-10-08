<?php
/** PHP built-in server router for a marked, loopback-only disposable test site. */
$root = getenv( 'MRN_RECAPTCHA_TEST_ROOT' );
if ( ! $root || ! is_file( $root . '/.mrn-recaptcha-fixture' ) ) { http_response_code( 500 ); exit; }
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( is_file( $root . $path ) ) return false;
if ( 0 === strpos( $path, '/wp-json' ) ) {
	$_GET['rest_route'] = substr( $path, 8 ) ?: '/';
	require $root . '/index.php'; return;
}
require $root . '/wp-load.php';
if ( ! defined( 'MRN_RECAPTCHA_ISOLATED_TEST' ) || ! MRN_RECAPTCHA_ISOLATED_TEST || wp_get_environment_type() !== 'local' ) exit;
$ids = get_option( 'fixture_ids' );
if ( '/qa/product/' === $path ) wp_set_current_user( $ids['buyer'] );
if ( '/qa/subscriber/' === $path ) wp_set_current_user( $ids['subscriber'] );
if ( '/qa/admin/' === $path ) wp_set_current_user( 1 );
$targets = array( '/qa/product/' => 'product', '/qa/page/' => 'page', '/qa/attachment/' => 'attachment', '/qa/custom/' => 'custom', '/qa/no-woocommerce-product/' => 'no_woocommerce_product' );
$post = get_post( $ids[ $targets[ $path ] ?? 'post' ] );
setup_postdata( $post );
$wp_query->is_singular = true;
$wp_query->is_single = true;
$wp_query->queried_object = $post;
$wp_query->queried_object_id = $post->ID;
add_filter( 'comment_form_defaults', function ( $args ) { $args['title_reply_before'] = '<h2 id="reply-title" class="comment-reply-title">'; $args['title_reply_after'] = '</h2>'; return $args; } );
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Isolated comment protection QA</title><style>body{font:18px/1.6 system-ui;background:#fff;color:#111;max-width:760px;margin:2rem auto;padding:1rem}input,textarea,button{font:inherit;max-width:100%}textarea{width:95%}a{color:#004e8c}:focus{outline:3px solid #0068b5;outline-offset:3px}</style></head><body><main><h1>Isolated comment protection QA</h1>
<?php
if ( '/qa/unrelated/' === $path ) {
	echo '<p>No comment form on this page.</p>';
} elseif ( '/qa/product/' === $path ) {
	$product = wc_get_product( $ids['product'] );
	include WC_ABSPATH . 'templates/single-product-reviews.php';
} else {
	comment_form( array( 'title_reply' => 'Leave a comment' ), $post->ID );
	if ( '/qa/multiple/' === $path ) comment_form( array( 'id_form' => 'second-commentform', 'id_submit' => 'second-submit', 'title_reply' => 'Another comment form' ), $post->ID );
	if ( '/qa/mixed/' === $path ) comment_form( array( 'id_form' => 'second-commentform', 'id_submit' => 'second-submit', 'title_reply' => 'Review form' ), $ids['product'] );
}
?></main><?php wp_print_footer_scripts(); ?></body></html>
