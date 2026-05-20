<?php
/**
 * Plugin Name: MRN reCAPTCHA Enterprise Manager
 * Description: Create and manage Google reCAPTCHA Enterprise website keys inside WordPress, with optional WPForms key sync.
 * Version: 0.1.0
 * Author: MRN Web Designs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MRN_RECAPTCHA_ENTERPRISE_MANAGER_FILE', __FILE__ );
define( 'MRN_RECAPTCHA_ENTERPRISE_MANAGER_DIR', plugin_dir_path( __FILE__ ) );

if ( ! is_admin() ) {
	return;
}

require_once MRN_RECAPTCHA_ENTERPRISE_MANAGER_DIR . 'includes/class-mrn-recaptcha-enterprise-manager.php';

MRN_Recaptcha_Enterprise_Manager::init();
