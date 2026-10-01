<?php
/** Explicit Enterprise protection for public blog comments and product reviews. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MRN_Recaptcha_Comments {
	const OPTION = 'mrn_recaptcha_comment_protection';
	const PAGE = 'mrn-recaptcha-comment-protection';
	const FIELD = 'mrn_recaptcha_token';
	private static $validated = array();

	/** Register frontend and server checks outside the manager's admin-only hooks. */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		// Runs once inside each guest/logged-in form with that form's actual target.
		add_action( 'comment_form', array( __CLASS__, 'render_field' ) );
		add_filter( 'pre_comment_approved', array( __CLASS__, 'validate_comment' ), 99, 2 );
		// Core calls approval twice; memoize only until this insertion completes.
		add_action( 'wp_insert_comment', array( __CLASS__, 'clear_validation' ) );
		// REST can bypass wp_allow_comment for certain types. Check creations again.
		add_filter( 'rest_pre_insert_comment', array( __CLASS__, 'validate_rest' ), 99, 2 );
	}

	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return array_replace(
			array( 'blog_enabled' => false, 'reviews_enabled' => false, 'site_key' => '', 'hostnames' => array(), 'minimum_score' => 0.5, 'verification' => '' ),
			is_array( $saved ) ? $saved : array()
		);
	}

	public static function admin_menu() {
		add_options_page( __( 'Comment reCAPTCHA', 'mrn-recaptcha-enterprise-manager' ), __( 'Comment reCAPTCHA', 'mrn-recaptcha-enterprise-manager' ), 'manage_options', self::PAGE, array( __CLASS__, 'render_settings' ) );
	}

	public static function register_settings() {
		register_setting( self::OPTION, self::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ) ) );
	}

	/** Normalize explicit hosts; never derive authorization from the request Host header. */
	private static function parse_hosts( $input ) {
		if ( ! is_string( $input ) ) {
			return array();
		}
		$hosts = array_unique( preg_split( '/[\s,]+/', strtolower( trim( $input ) ), -1, PREG_SPLIT_NO_EMPTY ) );
		foreach ( $hosts as $host ) {
			if ( ! filter_var( $host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) || false === strpos( $host, '.' ) ) {
				return array();
			}
		}
		sort( $hosts );
		return array_values( $hosts );
	}

	private static function fingerprint( $settings ) {
		return hash( 'sha256', wp_json_encode( array( MRN_Recaptcha_Enterprise_Manager::comment_project_id(), $settings['site_key'], $settings['hostnames'] ) ) );
	}

	/** Read-only Google metadata verification; no automatic key or WPForms mutations. */
	public static function verify_key( $settings ) {
		$key = MRN_Recaptcha_Enterprise_Manager::comment_api_request( 'keys/' . $settings['site_key'] );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$expected = 'projects/' . MRN_Recaptcha_Enterprise_Manager::comment_project_id() . '/keys/' . $settings['site_key'];
		$web = $key['webSettings'] ?? array();
		if ( $expected !== ( $key['name'] ?? '' ) || 'SCORE' !== ( $web['integrationType'] ?? '' ) || ! empty( $web['allowAllDomains'] ) || ! empty( $key['testingOptions'] ) || ! empty( $key['wafSettings'] ) ) {
			return new WP_Error( 'mrn_recaptcha_key_type', __( 'Use a production Enterprise SCORE website key in the configured project with domain verification enabled.', 'mrn-recaptcha-enterprise-manager' ) );
		}
		$allowed = $web['allowedDomains'] ?? array();
		foreach ( $settings['hostnames'] as $host ) {
			$found = false;
			foreach ( $allowed as $domain ) {
				$domain = strtolower( (string) $domain );
				// Google authorizes subdomains of an allowed parent domain.
				if ( '' !== $domain && ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) ) {
					$found = true;
				}
			}
			if ( ! $found ) {
				return new WP_Error( 'mrn_recaptcha_domain', __( 'Google has not authorized every configured hostname for this key.', 'mrn-recaptcha-enterprise-manager' ) );
			}
		}
		return true;
	}

	/** Settings API supplies the options.php nonce/capability checks. */
	public static function sanitize_settings( $input ) {
		$old = self::settings();
		if ( ! current_user_can( 'manage_options' ) || ! is_array( $input ) ) {
			return $old;
		}
		$new = $old;
		$new['blog_enabled'] = ! empty( $input['blog_enabled'] );
		$new['reviews_enabled'] = ! empty( $input['reviews_enabled'] );
		// Emergency disable must remain available when Google or credentials fail.
		if ( ! $new['blog_enabled'] && ! $new['reviews_enabled'] ) {
			return $new;
		}
		$new['site_key'] = isset( $input['site_key'] ) && is_string( $input['site_key'] ) ? trim( $input['site_key'] ) : '';
		$new['hostnames'] = self::parse_hosts( $input['hostnames'] ?? '' );
		$score = $input['minimum_score'] ?? '';
		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{20,100}$/D', $new['site_key'] ) || empty( $new['hostnames'] ) || ! in_array( $home_host, $new['hostnames'], true ) || ! is_numeric( $score ) || (float) $score < 0.1 || (float) $score > 1 ) {
			add_settings_error( self::OPTION, 'invalid_configuration', __( 'Settings kept: supply a valid key, explicit hostnames including the site home hostname, and a score from 0.1 to 1.0.', 'mrn-recaptcha-enterprise-manager' ) );
			return $old;
		}
		$new['minimum_score'] = (float) $score;
		$verified = self::verify_key( $new );
		if ( is_wp_error( $verified ) ) {
			add_settings_error( self::OPTION, 'key_not_verified', __( 'Settings kept. Key verification failed: ', 'mrn-recaptcha-enterprise-manager' ) . $verified->get_error_message() );
			return $old;
		}
		$new['verification'] = self::fingerprint( $new );
		$new['verified_at'] = gmdate( 'c' );
		return $new;
	}

	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = self::settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Comment and review reCAPTCHA', 'mrn-recaptcha-enterprise-manager' ); ?></h1>
			<?php settings_errors( self::OPTION ); ?>
			<p><?php esc_html_e( 'Protect guests and logged-in customers. Administrators with manage_options, WP-CLI and cron are exempt. Existing moderation, purchaser checks, ratings and email routing remain in place.', 'mrn-recaptcha-enterprise-manager' ); ?></p>
			<p><?php esc_html_e( 'Use an Enterprise SCORE website key. Saving an enabled protection verifies the key and domains with Google. WPForms settings are separate and are never changed here. Both protections start disabled.', 'mrn-recaptcha-enterprise-manager' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION ); ?>
				<p><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[blog_enabled]" value="1" <?php checked( $s['blog_enabled'] ); ?>> <?php esc_html_e( 'Protect blog comments', 'mrn-recaptcha-enterprise-manager' ); ?></label></p>
				<p><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[reviews_enabled]" value="1" <?php checked( $s['reviews_enabled'] ); ?>> <?php esc_html_e( 'Protect WooCommerce product reviews', 'mrn-recaptcha-enterprise-manager' ); ?></label></p>
				<p><label for="mrn-comment-key"><?php esc_html_e( 'Enterprise site key', 'mrn-recaptcha-enterprise-manager' ); ?></label><br><input class="regular-text" id="mrn-comment-key" name="<?php echo esc_attr( self::OPTION ); ?>[site_key]" value="<?php echo esc_attr( $s['site_key'] ); ?>" autocomplete="off"></p>
				<p><label for="mrn-comment-hosts"><?php esc_html_e( 'Exact accepted hostnames (comma separated)', 'mrn-recaptcha-enterprise-manager' ); ?></label><br><input class="regular-text" id="mrn-comment-hosts" name="<?php echo esc_attr( self::OPTION ); ?>[hostnames]" value="<?php echo esc_attr( implode( ', ', $s['hostnames'] ) ); ?>" placeholder="example.com, www.example.com"></p>
				<p><label for="mrn-comment-score"><?php esc_html_e( 'Minimum score', 'mrn-recaptcha-enterprise-manager' ); ?></label><br><input type="number" min="0.1" max="1" step="0.1" id="mrn-comment-score" name="<?php echo esc_attr( self::OPTION ); ?>[minimum_score]" value="<?php echo esc_attr( $s['minimum_score'] ); ?>"></p>
				<p><?php esc_html_e( 'If verification is unavailable or fails, submissions are rejected with a retry message; they are never silently saved. Disable both protections to save an emergency shutdown without contacting Google.', 'mrn-recaptcha-enterprise-manager' ); ?></p>
				<?php submit_button( __( 'Verify key and save protection', 'mrn-recaptcha-enterprise-manager' ) ); ?>
			</form>
		</div>
		<?php
	}

	private static function exempt() {
		return current_user_can( 'manage_options' ) || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron();
	}

	/** Post type comes from WordPress, never a submitted type or claimed user ID. */
	private static function action_for( $post_id ) {
		$s = self::settings();
		$type = get_post_type( $post_id );
		if ( 'post' === $type && ! empty( $s['blog_enabled'] ) ) {
			return 'mrn_blog_comment';
		}
		if ( 'product' === $type && ! empty( $s['reviews_enabled'] ) && class_exists( 'WooCommerce' ) ) {
			return 'mrn_product_review';
		}
		return '';
	}

	public static function render_field( $post_id ) {
		$action = self::action_for( $post_id );
		if ( '' === $action || self::exempt() ) {
			return;
		}
		$s = self::settings();
		wp_enqueue_script( 'mrn-recaptcha-comments', plugins_url( 'assets/comment-protection.js', MRN_RECAPTCHA_ENTERPRISE_MANAGER_FILE ), array(), MRN_Recaptcha_Enterprise_Manager::VERSION, true );
		?>
		<div class="mrn-recaptcha-comment" data-site-key="<?php echo esc_attr( $s['site_key'] ); ?>" data-action="<?php echo esc_attr( $action ); ?>" data-wait="<?php esc_attr_e( 'Checking spam protection…', 'mrn-recaptcha-enterprise-manager' ); ?>" data-error="<?php esc_attr_e( 'Spam protection could not verify this submission. Your text is still here. Please try again, or contact the site if this continues.', 'mrn-recaptcha-enterprise-manager' ); ?>">
			<input type="hidden" name="<?php echo esc_attr( self::FIELD ); ?>" value="">
			<p class="mrn-recaptcha-status" role="status" aria-live="polite" aria-atomic="true" tabindex="-1"><?php esc_html_e( 'Spam protection runs when you submit.', 'mrn-recaptcha-enterprise-manager' ); ?></p>
			<noscript><p><?php esc_html_e( 'JavaScript is required for spam verification. Enable it and reload before submitting.', 'mrn-recaptcha-enterprise-manager' ); ?></p></noscript>
		</div>
		<?php
	}

	public static function clear_validation() {
		self::$validated = array();
	}

	/** Also covers REST types which skip core approval. Editing comments is unaffected. */
	public static function validate_rest( $prepared, $request ) {
		$route = $request->get_url_params();
		if ( is_wp_error( $prepared ) || ! empty( $route['id'] ) ) {
			return $prepared;
		}
		$result = self::validate_comment( 0, (array) $prepared );
		return is_wp_error( $result ) ? $result : $prepared;
	}

	/** Reject before insertion; preserve the exact previous approval/moderation result. */
	public static function validate_comment( $approved, $data ) {
		if ( is_wp_error( $approved ) || self::exempt() ) {
			return $approved;
		}
		$post_id = absint( $data['comment_post_ID'] ?? 0 );
		$action = self::action_for( $post_id );
		if ( '' === $action ) {
			return $approved;
		}
		// Ping/trackbacks on blog posts do not use a browser comment form.
		// Product targets always require validation regardless of claimed comment type.
		$ping_endpoint = ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( isset( $GLOBALS['pagenow'] ) && 'wp-trackback.php' === $GLOBALS['pagenow'] );
		if ( $ping_endpoint && 'mrn_blog_comment' === $action && in_array( $data['comment_type'] ?? '', array( 'pingback', 'trackback' ), true ) ) {
			return $approved;
		}
		// Preserve the native verified-purchaser rule on alternate submission routes too.
		if ( 'mrn_product_review' === $action && 'yes' === get_option( 'woocommerce_review_rating_verification_required' ) && ( ! get_current_user_id() || ! wc_customer_bought_product( '', get_current_user_id(), $post_id ) ) ) {
			return new WP_Error( 'mrn_recaptcha_purchaser', __( 'Only logged-in customers who purchased this product may leave a review.', 'mrn-recaptcha-enterprise-manager' ), self::error_data( 403 ) );
		}
		$s = self::settings();
		if ( ! is_string( $s['verification'] ) || ! hash_equals( self::fingerprint( $s ), $s['verification'] ) || ! in_array( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ), $s['hostnames'], true ) ) {
			return self::error( 'configuration', 503 );
		}
		// Public form tokens are validated by Google, not a WordPress guest nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = $_POST[ self::FIELD ] ?? ( $_SERVER['HTTP_X_MRN_RECAPTCHA_TOKEN'] ?? '' );
		$token = is_string( $raw ) ? sanitize_text_field( wp_unslash( $raw ) ) : '';
		if ( '' === $token || strlen( $token ) > 8192 ) {
			return self::error( 'missing', 403 );
		}
		$cache = hash( 'sha256', $token . '|' . $action . '|' . $post_id . '|' . get_current_user_id() );
		if ( isset( self::$validated[ $cache ] ) ) {
			return $approved;
		}
		$result = MRN_Recaptcha_Enterprise_Manager::comment_api_request(
			'assessments',
			array( 'event' => array( 'token' => $token, 'siteKey' => $s['site_key'], 'expectedAction' => $action ) )
		);
		if ( is_wp_error( $result ) ) {
			return self::error( 'unavailable', 503 );
		}
		$p = $result['tokenProperties'] ?? array();
		$score = $result['riskAnalysis']['score'] ?? null;
		$created = isset( $p['createTime'] ) && is_string( $p['createTime'] ) ? strtotime( $p['createTime'] ) : false;
		if ( true !== ( $p['valid'] ?? false ) || $action !== ( $p['action'] ?? '' ) || ! in_array( strtolower( (string) ( $p['hostname'] ?? '' ) ), $s['hostnames'], true ) || ! is_numeric( $score ) || (float) $score < (float) $s['minimum_score'] || (float) $score > 1 || false === $created || time() - $created > 120 || $created > time() + 10 ) {
			return self::error( 'rejected', 403 );
		}
		self::$validated[ $cache ] = true;
		return $approved;
	}

	private static function error( $reason, $status ) {
		return new WP_Error( 'mrn_recaptcha_' . $reason, __( 'Spam verification failed or is unavailable. Return to the form and submit again for a fresh check. If this continues, please contact the site. Your submission was not saved.', 'mrn-recaptcha-enterprise-manager' ), self::error_data( $status ) );
	}

	/** wp-comments-post.php expects an integer; REST expects a status map. */
	private static function error_data( $status ) {
		return wp_is_rest_endpoint() ? array( 'status' => $status ) : $status;
	}
}
