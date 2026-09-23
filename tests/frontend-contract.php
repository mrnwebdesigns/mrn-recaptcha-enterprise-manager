<?php
/** Focused loader contract using the real WordPress HTML API. */

$core = getenv( 'WP_CORE_DIR' );
if ( ! $core || ! is_file( $core . '/wp-includes/html-api/class-wp-html-tag-processor.php' ) ) {
	fwrite( STDERR, "Set WP_CORE_DIR to a WordPress checkout.\n" );
	exit( 1 );
}
define( 'ABSPATH', $core . '/' );
define( 'WPINC', 'wp-includes' );
require $core . '/wp-includes/compat.php';
require $core . '/wp-includes/utf8.php';
require $core . '/wp-includes/plugin.php';
require $core . '/wp-includes/formatting.php';
require $core . '/wp-includes/kses.php';
require $core . '/wp-includes/class-wp-token-map.php';
foreach ( glob( $core . '/wp-includes/html-api/*.php' ) as $file ) {
	if ( basename( $file ) !== 'class-wp-html-processor.php' ) {
		require_once $file;
	}
}
require $core . '/wp-includes/script-loader.php';
function is_admin() { return $GLOBALS['test_admin'] ?? false; }
function wpforms_get_captcha_settings() { return $GLOBALS['test_captcha']; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function get_option( $name, $default = false ) { return 'blog_charset' === $name ? 'UTF-8' : $default; }
function contract( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}
require dirname( __DIR__ ) . '/includes/class-mrn-recaptcha-frontend.php';
$GLOBALS['test_captcha'] = array( 'provider' => 'recaptcha', 'recaptcha_type' => 'v3' );
$url = 'https://www.google.com/recaptcha/api.js?render=public-test-key&hl=en';
$tag = '<script id="before" nonce="preserved">window.before=1;</script><script src="' . htmlspecialchars( $url ) . '" id="wpforms-recaptcha-js" nonce="preserved"></script><script id="after" nonce="preserved">var wpformsRecaptchaV3Execute=function(callback){grecaptcha.execute("test",{action:"wpforms"}).then(callback);};grecaptcha.ready(window.readyCallback);</script>';
add_filter( 'wp_inline_script_attributes', function ( $attributes ) { $attributes['nonce'] = 'site-nonce'; return $attributes; } );
$filtered = MRN_Recaptcha_Frontend::async_wpforms_v3( $tag, 'wpforms-recaptcha', $url );
contract( strpos( $filtered, 'id="mrn-recaptcha-ready" nonce="site-nonce"' ) !== false, 'The readiness shim must use WordPress nonce filters.' );
contract( strpos( $filtered, 'id="mrn-recaptcha-ready"' ) < strpos( $filtered, 'id="before"' ), 'Queue must precede the external loader and existing inline code.' );
contract( strpos( $filtered, 'grecaptcha.ready(window.readyCallback);</script>' ) !== false && strpos( $filtered, 'id="after" nonce="preserved"' ) !== false, 'Existing inline callbacks and nonces must remain unchanged.' );
contract( strpos( $filtered, 'id="mrn-recaptcha-submit-ready"' ) > strpos( $filtered, 'id="after"' ), 'Submission readiness wrapper follows WPForms initialization.' );
$parser = new WP_HTML_Tag_Processor( $filtered );
$loaders = 0;
while ( $parser->next_tag( 'SCRIPT' ) ) {
	if ( $url === $parser->get_attribute( 'src' ) ) {
		++$loaders;
		contract( true === $parser->get_attribute( 'async' ), 'The Google loader must be asynchronous.' );
		contract( 'preserved' === $parser->get_attribute( 'nonce' ), 'Preserve existing loader attributes.' );
	}
}
contract( 1 === $loaders, 'Do not duplicate the Google loader.' );
foreach ( array( array( 'recaptcha', 'v2' ), array( 'recaptcha', 'invisible' ), array( 'hcaptcha', '' ), array( 'turnstile', '' ) ) as $settings ) {
	$GLOBALS['test_captcha'] = array( 'provider' => $settings[0], 'recaptcha_type' => $settings[1] );
	contract( $tag === MRN_Recaptcha_Frontend::async_wpforms_v3( $tag, 'wpforms-recaptcha', $url ), 'Leave other CAPTCHA modes unchanged.' );
}
$GLOBALS['test_captcha'] = array( 'provider' => 'recaptcha', 'recaptcha_type' => 'v3' );
foreach ( array( 'https://example.test/api.js', 'http://www.google.com/recaptcha/api.js', 'https://www.google.com/recaptcha/enterprise.js' ) as $other_url ) {
	contract( $tag === MRN_Recaptcha_Frontend::async_wpforms_v3( $tag, 'wpforms-recaptcha', $other_url ), 'Leave custom and Enterprise API loaders unchanged.' );
}
contract( $tag === MRN_Recaptcha_Frontend::async_wpforms_v3( $tag, 'another-handle', $url ), 'Leave unrelated handles unchanged.' );
contract( '<script></script>' === MRN_Recaptcha_Frontend::async_wpforms_v3( '<script></script>', 'wpforms-recaptcha', $url ), 'Leave unknown future WPForms initialization contracts unchanged.' );
$GLOBALS['test_admin'] = true;
contract( $tag === MRN_Recaptcha_Frontend::async_wpforms_v3( $tag, 'wpforms-recaptcha', $url ), 'Leave admin scripts unchanged.' );
$GLOBALS['test_admin'] = false;
remove_all_filters( 'wp_inline_script_attributes' );
if ( isset( $argv[1], $argv[2] ) ) {
	$html = file_get_contents( $argv[1] );
	contract( 1 === preg_match( '~<script\b[^>]*\bid=[\'"]wpforms-recaptcha-js[\'"][^>]*>.*?</script>\s*<script\b[^>]*\bid=[\'"]wpforms-recaptcha-js-after[\'"][^>]*>.*?</script>~s', $html, $matches ), 'Expected one WPForms loader and inline callback block.' );
	$block = $matches[0];
	$parser = new WP_HTML_Tag_Processor( $block );
	$parser->next_tag( 'SCRIPT' );
	$changed = MRN_Recaptcha_Frontend::async_wpforms_v3( $block, 'wpforms-recaptcha', $parser->get_attribute( 'src' ) );
	contract( $block !== $changed, 'Candidate should modify the loader block.' );
	file_put_contents( $argv[2], str_replace( $block, $changed, $html ) );
}
fwrite( STDOUT, "PASS: async v3 scope, ready queue ordering, nonce filters, and callback preservation.\n" );
