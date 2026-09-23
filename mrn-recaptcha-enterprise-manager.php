<?php
/**
 * Plugin Name: MRN reCAPTCHA Enterprise Manager
 * Description: Create and manage Google reCAPTCHA Enterprise website keys inside WordPress, with optional WPForms key sync.
 * Version: 0.1.3
 * Author: MRN Web Designs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MRN_RECAPTCHA_ENTERPRISE_MANAGER_FILE', __FILE__ );
define( 'MRN_RECAPTCHA_ENTERPRISE_MANAGER_DIR', plugin_dir_path( __FILE__ ) );

require_once MRN_RECAPTCHA_ENTERPRISE_MANAGER_DIR . 'includes/class-mrn-recaptcha-enterprise-manager.php';
require_once MRN_RECAPTCHA_ENTERPRISE_MANAGER_DIR . 'includes/class-mrn-recaptcha-frontend.php';

MRN_Recaptcha_Enterprise_Manager::init();
add_filter( 'script_loader_tag', array( 'MRN_Recaptcha_Frontend', 'async_wpforms_v3' ), 20, 3 );
