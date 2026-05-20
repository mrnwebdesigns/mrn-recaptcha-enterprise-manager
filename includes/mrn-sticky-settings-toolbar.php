<?php
defined( 'ABSPATH' ) || exit;

if (
	function_exists( 'mrn_sticky_toolbar_render' )
	&& function_exists( 'mrn_sticky_toolbar_render_css' )
) {
	return;
}

$mrn_sticky_toolbar_candidates = array(
	defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/shared/mrn-sticky-settings-toolbar.php' : '',
	dirname( __DIR__, 3 ) . '/shared/mrn-sticky-settings-toolbar.php',
	defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/mrn-universal-sticky-bar/includes/mrn-sticky-settings-toolbar.php' : '',
	dirname( __DIR__, 2 ) . '/mrn-universal-sticky-bar/includes/mrn-sticky-settings-toolbar.php',
);

foreach ( $mrn_sticky_toolbar_candidates as $mrn_sticky_toolbar_candidate ) {
	if ( $mrn_sticky_toolbar_candidate && file_exists( $mrn_sticky_toolbar_candidate ) ) {
		require_once $mrn_sticky_toolbar_candidate;
		break;
	}
}
