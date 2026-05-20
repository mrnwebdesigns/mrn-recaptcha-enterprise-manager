<?php
/**
 * MRN reCAPTCHA Enterprise manager plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MRN_Recaptcha_Enterprise_Manager {
	const VERSION                  = '0.1.0';
	const OPTION_KEY               = 'mrn_recaptcha_enterprise_manager_settings';
	const PAGE_SLUG                = 'mrn-recaptcha-enterprise-manager';
	const SETTINGS_GROUP           = 'mrn_recaptcha_enterprise_manager';
	const CREATE_KEY_ACTION        = 'mrn_recaptcha_enterprise_create_key';
	const CREATE_KEY_NONCE_ACTION  = 'mrn_recaptcha_enterprise_create_key';
	const FLASH_TRANSIENT_PREFIX   = 'mrn_recaptcha_enterprise_flash_';
	const TOKEN_SCOPE              = 'https://www.googleapis.com/auth/cloud-platform';
	const TOKEN_ENDPOINT           = 'https://oauth2.googleapis.com/token';
	const KEYS_API_BASE            = 'https://recaptchaenterprise.googleapis.com/v1';
	const INTEGRATION_TYPE_SCORE   = 'SCORE';
	const INTEGRATION_TYPE_CHECKBX = 'CHECKBOX';
	const CONFIG_PROJECT_ID        = 'MRN_RECAPTCHA_ENTERPRISE_PROJECT_ID';
	const CONFIG_SERVICE_EMAIL     = 'MRN_RECAPTCHA_ENTERPRISE_SERVICE_ACCOUNT_EMAIL';
	const CONFIG_PRIVATE_KEY       = 'MRN_RECAPTCHA_ENTERPRISE_PRIVATE_KEY';
	const CONFIG_ALLOWED_DOMAINS   = 'MRN_RECAPTCHA_ENTERPRISE_ALLOWED_DOMAINS';
	const CONFIG_INTEGRATION_TYPE  = 'MRN_RECAPTCHA_ENTERPRISE_DEFAULT_INTEGRATION_TYPE';
	const TAB_QUERY_ARG            = 'tab';
	const TAB_CREDENTIALS          = 'credentials';
	const TAB_CREATE_KEY           = 'create-key';

	/**
	 * Register plugin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! is_admin() ) {
			return;
		}

		self::maybe_load_sticky_toolbar_helper();

		add_action( 'admin_menu', array( __CLASS__, 'register_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_' . self::CREATE_KEY_ACTION, array( __CLASS__, 'handle_create_key' ) );
	}

	/**
	 * Register plugin option.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::get_default_settings(),
			)
		);
	}

	/**
	 * Add settings page under Settings.
	 *
	 * @return void
	 */
	public static function register_settings_page() {
		add_options_page(
			__( 'reCAPTCHA Enterprise', 'mrn-recaptcha-enterprise-manager' ),
			__( 'reCAPTCHA Enterprise', 'mrn-recaptcha-enterprise-manager' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Return settings defaults.
	 *
	 * @return array<string, string>
	 */
	private static function get_default_settings() {
		return array(
			'project_id'                            => '',
			'service_account_email'                 => '',
			'service_account_private_key_encrypted' => '',
			'default_allowed_domains'               => '',
			'default_integration_type'              => self::INTEGRATION_TYPE_SCORE,
		);
	}

	/**
	 * Return merged runtime settings.
	 *
	 * @return array<string, string>
	 */
	private static function get_settings() {
		$defaults = self::get_default_settings();
		$saved    = self::get_saved_settings_only();
		$merged   = array_replace( $defaults, $saved );

		return array_replace( $merged, self::get_locked_settings_overrides() );
	}

	/**
	 * Return saved settings without code-level overrides.
	 *
	 * @return array<string, string>
	 */
	private static function get_saved_settings_only() {
		$defaults = self::get_default_settings();
		$saved    = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $saved ) ) {
			return $defaults;
		}

		return array_replace( $defaults, $saved );
	}

	/**
	 * Return settings overrides provided via constants/env.
	 *
	 * @return array<string, string>
	 */
	private static function get_locked_settings_overrides() {
		$overrides = array();

		$project_id = self::sanitize_project_id( self::read_config_secret( self::CONFIG_PROJECT_ID ) );
		if ( '' !== $project_id ) {
			$overrides['project_id'] = $project_id;
		}

		$service_email = sanitize_email( self::read_config_secret( self::CONFIG_SERVICE_EMAIL ) );
		if ( '' !== $service_email ) {
			$overrides['service_account_email'] = $service_email;
		}

		$allowed_domains = self::sanitize_domain_list( self::read_config_secret( self::CONFIG_ALLOWED_DOMAINS ) );
		if ( ! empty( $allowed_domains ) ) {
			$overrides['default_allowed_domains'] = implode( ', ', $allowed_domains );
		}

		$integration_type = self::sanitize_integration_type( self::read_config_secret( self::CONFIG_INTEGRATION_TYPE ) );
		if ( '' !== trim( self::read_config_secret( self::CONFIG_INTEGRATION_TYPE ) ) ) {
			$overrides['default_integration_type'] = $integration_type;
		}

		return $overrides;
	}

	/**
	 * Return an array of fields currently locked by config.
	 *
	 * @return array<string, bool>
	 */
	private static function get_locked_credential_fields() {
		return array(
			'project_id'                => '' !== self::sanitize_project_id( self::read_config_secret( self::CONFIG_PROJECT_ID ) ),
			'service_account_email'     => '' !== sanitize_email( self::read_config_secret( self::CONFIG_SERVICE_EMAIL ) ),
			'service_account_private_key' => '' !== self::normalize_private_key( self::read_config_secret( self::CONFIG_PRIVATE_KEY ) ),
			'default_allowed_domains'   => '' !== trim( self::read_config_secret( self::CONFIG_ALLOWED_DOMAINS ) ),
			'default_integration_type'  => '' !== trim( self::read_config_secret( self::CONFIG_INTEGRATION_TYPE ) ),
		);
	}

	/**
	 * Read secret/config value from constant first, then env var.
	 *
	 * @param string $name Constant/env name.
	 * @return string
	 */
	private static function read_config_secret( $name ) {
		$raw = '';

		if ( defined( $name ) ) {
			$constant = constant( $name );
			if ( is_string( $constant ) ) {
				$raw = $constant;
			}
		}

		if ( '' === $raw ) {
			$env = getenv( $name );
			if ( false !== $env && is_string( $env ) ) {
				$raw = $env;
			}
		}

		return str_replace( "\r", '', $raw );
	}

	/**
	 * Resolve runtime private key from config or encrypted option.
	 *
	 * @param array<string, string> $settings Settings array.
	 * @return string
	 */
	private static function get_runtime_private_key( $settings ) {
		$config_private_key = self::normalize_private_key( self::read_config_secret( self::CONFIG_PRIVATE_KEY ) );
		if ( '' !== $config_private_key ) {
			return $config_private_key;
		}

		return self::decrypt_secret( (string) ( $settings['service_account_private_key_encrypted'] ?? '' ) );
	}

	/**
	 * Sanitize settings values.
	 *
	 * @param mixed $input Raw input value.
	 * @return array<string, string>
	 */
	public static function sanitize_settings( $input ) {
		$defaults = self::get_default_settings();
		$existing = self::get_saved_settings_only();
		$locked   = self::get_locked_credential_fields();
		$input    = is_array( $input ) ? $input : array();

		$project_id            = self::sanitize_project_id( $input['project_id'] ?? '' );
		$service_account_email = sanitize_email( (string) ( $input['service_account_email'] ?? '' ) );
		$domains               = self::sanitize_domain_list( (string) ( $input['default_allowed_domains'] ?? '' ) );
		$integration_type      = self::sanitize_integration_type( (string) ( $input['default_integration_type'] ?? '' ) );
		$encrypted_private_key = (string) $existing['service_account_private_key_encrypted'];

		$private_key_raw = isset( $input['service_account_private_key'] ) ? (string) $input['service_account_private_key'] : '';
		$private_key_raw = wp_unslash( $private_key_raw );

		$service_account_json_raw = isset( $input['service_account_json'] ) ? (string) $input['service_account_json'] : '';
		$service_account_json_raw = wp_unslash( $service_account_json_raw );

		if ( ! $locked['service_account_private_key'] && ! empty( $input['clear_private_key'] ) ) {
			$encrypted_private_key = '';
		}

		if ( ! $locked['service_account_private_key'] && ! $locked['project_id'] && ! $locked['service_account_email'] && '' !== trim( $service_account_json_raw ) ) {
			$parsed_json = json_decode( $service_account_json_raw, true );

			if ( ! is_array( $parsed_json ) ) {
				add_settings_error(
					self::OPTION_KEY,
					'mrn_recaptcha_json_invalid',
					__( 'Service account JSON could not be parsed. The previous private key was kept.', 'mrn-recaptcha-enterprise-manager' ),
					'error'
				);
			} else {
				$json_project_id = self::sanitize_project_id( $parsed_json['project_id'] ?? '' );
				$json_email      = sanitize_email( (string) ( $parsed_json['client_email'] ?? '' ) );
				$json_priv_key   = self::normalize_private_key( (string) ( $parsed_json['private_key'] ?? '' ) );

				if ( '' !== $json_project_id ) {
					$project_id = $json_project_id;
				}

				if ( '' !== $json_email ) {
					$service_account_email = $json_email;
				}

				if ( '' !== $json_priv_key ) {
					$json_encrypted_private_key = self::encrypt_secret( $json_priv_key );
					if ( '' !== $json_encrypted_private_key ) {
						$encrypted_private_key = $json_encrypted_private_key;
					} else {
						add_settings_error(
							self::OPTION_KEY,
							'mrn_recaptcha_json_encryption_failed',
							__( 'Private key encryption failed while importing JSON. The previous private key was kept.', 'mrn-recaptcha-enterprise-manager' ),
							'error'
						);
					}
				} else {
					add_settings_error(
						self::OPTION_KEY,
						'mrn_recaptcha_json_missing_private_key',
						__( 'The JSON payload did not include a valid private key. The previous private key was kept.', 'mrn-recaptcha-enterprise-manager' ),
						'error'
					);
				}
			}
		}

		if ( ! $locked['service_account_private_key'] && '' !== trim( $private_key_raw ) ) {
			$normalized_private_key = self::normalize_private_key( $private_key_raw );
			if ( '' !== $normalized_private_key ) {
				$manual_encrypted_private_key = self::encrypt_secret( $normalized_private_key );
				if ( '' !== $manual_encrypted_private_key ) {
					$encrypted_private_key = $manual_encrypted_private_key;
				} else {
					add_settings_error(
						self::OPTION_KEY,
						'mrn_recaptcha_manual_encryption_failed',
						__( 'Private key encryption failed. The previous private key was kept.', 'mrn-recaptcha-enterprise-manager' ),
						'error'
					);
				}
			} else {
				add_settings_error(
					self::OPTION_KEY,
					'mrn_recaptcha_private_key_invalid',
					__( 'The private key field is not valid. The previous private key was kept.', 'mrn-recaptcha-enterprise-manager' ),
					'error'
				);
			}
		}

		if ( $locked['project_id'] ) {
			$project_id = self::sanitize_project_id( self::read_config_secret( self::CONFIG_PROJECT_ID ) );
		}

		if ( $locked['service_account_email'] ) {
			$service_account_email = sanitize_email( self::read_config_secret( self::CONFIG_SERVICE_EMAIL ) );
		}

		if ( $locked['default_allowed_domains'] ) {
			$domains = self::sanitize_domain_list( self::read_config_secret( self::CONFIG_ALLOWED_DOMAINS ) );
		}

		if ( $locked['default_integration_type'] ) {
			$integration_type = self::sanitize_integration_type( self::read_config_secret( self::CONFIG_INTEGRATION_TYPE ) );
		}

		if ( $locked['service_account_private_key'] ) {
			$encrypted_private_key = '';
		}

		$sanitized = $defaults;
		$sanitized['project_id']                            = $project_id;
		$sanitized['service_account_email']                 = $service_account_email;
		$sanitized['service_account_private_key_encrypted'] = $encrypted_private_key;
		$sanitized['default_allowed_domains']               = implode( ', ', $domains );
		$sanitized['default_integration_type']              = $integration_type;

		return $sanitized;
	}

	/**
	 * Load the shared sticky toolbar helper when available.
	 *
	 * @return void
	 */
	private static function maybe_load_sticky_toolbar_helper() {
		$local_toolbar_helper = dirname( __FILE__ ) . '/mrn-sticky-settings-toolbar.php';
		if ( file_exists( $local_toolbar_helper ) ) {
			require_once $local_toolbar_helper;
		}
	}

	/**
	 * Resolve the active admin tab.
	 *
	 * @return string
	 */
	private static function get_active_tab_from_request() {
		$tab = filter_input( INPUT_GET, self::TAB_QUERY_ARG, FILTER_UNSAFE_RAW );
		$tab = sanitize_key( is_string( $tab ) ? wp_unslash( $tab ) : '' );

		if ( self::TAB_CREDENTIALS === $tab ) {
			return self::TAB_CREDENTIALS;
		}

		return self::TAB_CREATE_KEY;
	}

	/**
	 * Render fallback tab navigation.
	 *
	 * @param string $active_tab Active tab key.
	 * @return void
	 */
	private static function render_tab_navigation( $active_tab ) {
		$key_create_url  = self::get_settings_page_url( self::TAB_CREATE_KEY );
		$credentials_url = self::get_settings_page_url( self::TAB_CREDENTIALS );
		?>
		<h2 class="nav-tab-wrapper" role="tablist" aria-label="<?php echo esc_attr__( 'reCAPTCHA Enterprise tabs', 'mrn-recaptcha-enterprise-manager' ); ?>">
			<a href="<?php echo esc_url( $key_create_url ); ?>" class="nav-tab<?php echo self::TAB_CREATE_KEY === $active_tab ? ' nav-tab-active' : ''; ?>" role="tab" aria-selected="<?php echo self::TAB_CREATE_KEY === $active_tab ? 'true' : 'false'; ?>">
				<?php echo esc_html__( 'Create Key', 'mrn-recaptcha-enterprise-manager' ); ?>
			</a>
			<a href="<?php echo esc_url( $credentials_url ); ?>" class="nav-tab<?php echo self::TAB_CREDENTIALS === $active_tab ? ' nav-tab-active' : ''; ?>" role="tab" aria-selected="<?php echo self::TAB_CREDENTIALS === $active_tab ? 'true' : 'false'; ?>">
				<?php echo esc_html__( 'Credentials', 'mrn-recaptcha-enterprise-manager' ); ?>
			</a>
		</h2>
		<?php
	}

	/**
	 * Render optional sticky tab toolbar when helper is available.
	 *
	 * @param string $active_tab Active tab key.
	 * @return void
	 */
	private static function render_tabbed_toolbar( $active_tab ) {
		if ( ! function_exists( 'mrn_sticky_toolbar_render' ) ) {
			return;
		}

		mrn_sticky_toolbar_render(
			array(
				'toolbar_id' => 'mrn-recaptcha-enterprise-toolbar',
				'form_id'    => 'mrn-recaptcha-credentials-form',
				'title'      => 'reCAPTCHA Enterprise',
				'save_label' => 'Save Credentials',
				'aria_label' => 'reCAPTCHA Enterprise tabs',
				'tabs'       => array(
					array(
						'key'    => self::TAB_CREATE_KEY,
						'label'  => 'Create Key',
						'active' => self::TAB_CREATE_KEY === $active_tab,
					),
					array(
						'key'    => self::TAB_CREDENTIALS,
						'label'  => 'Credentials',
						'active' => self::TAB_CREDENTIALS === $active_tab,
					),
				),
			)
		);
	}

	/**
	 * Render styles/scripts for tabs and sticky toolbar behavior.
	 *
	 * @param string $active_tab Active tab key.
	 * @param bool   $has_sticky_toolbar Whether sticky toolbar helper is loaded.
	 * @return void
	 */
	private static function render_tabbed_ui_script( $active_tab, $has_sticky_toolbar ) {
		$credentials_url = self::get_settings_page_url( self::TAB_CREDENTIALS );
		$key_create_url  = self::get_settings_page_url( self::TAB_CREATE_KEY );
		?>
		<?php if ( function_exists( 'mrn_sticky_toolbar_universal_css' ) ) : ?>
			<style>
				<?php echo mrn_sticky_toolbar_universal_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</style>
		<?php endif; ?>
		<?php
		if ( function_exists( 'mrn_sticky_toolbar_render_css' ) ) {
			mrn_sticky_toolbar_render_css(
				array(
					'toolbar_id'            => 'mrn-recaptcha-enterprise-toolbar',
					'page_class'            => 'settings_page_' . self::PAGE_SLUG,
					'desktop_left'          => 196,
					'desktop_right'         => 0,
					'mobile_left'           => 10,
					'mobile_right'          => 10,
					'spacer_height'         => 88,
					'spacer_height_mobile'  => 120,
				)
			);
		}
		?>
		<style>
			.mrn-recaptcha-panel[hidden] {
				display: none !important;
			}
			<?php if ( ! $has_sticky_toolbar ) : ?>
			.mrn-recaptcha-enterprise-wrap .nav-tab-wrapper {
				margin: 14px 0 16px;
			}
			<?php endif; ?>
		</style>
		<script>
			(function () {
				var toolbar = document.getElementById('mrn-recaptcha-enterprise-toolbar');
				if (!toolbar || toolbar.dataset.mrnRecaptchaTabsInit === '1') {
					return;
				}

				toolbar.dataset.mrnRecaptchaTabsInit = '1';

				var saveButton = toolbar.querySelector('.mrn-settings-tab--save');
				var activeTab = <?php echo wp_json_encode( $active_tab ); ?>;
				var urls = {
					credentials: <?php echo wp_json_encode( $credentials_url ); ?>,
					'create-key': <?php echo wp_json_encode( $key_create_url ); ?>
				};

				function syncSaveButton(tabKey) {
					if (!saveButton) {
						return;
					}

					saveButton.hidden = tabKey !== 'credentials';
				}

				Array.prototype.slice.call(toolbar.querySelectorAll('[data-mrn-tab]')).forEach(function (button) {
					button.addEventListener('click', function (event) {
						event.preventDefault();
						var tabKey = button.getAttribute('data-mrn-tab') || 'credentials';
						if (urls[tabKey]) {
							window.location.href = urls[tabKey];
						}
					});
				});

				syncSaveButton(activeTab);
			})();
		</script>
		<?php
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings            = self::get_settings();
		$flash               = self::consume_flash_notice();
		$home_host           = wp_parse_url( home_url(), PHP_URL_HOST );
		$default_domains     = '' !== trim( (string) $settings['default_allowed_domains'] ) ? (string) $settings['default_allowed_domains'] : ( is_string( $home_host ) ? $home_host : '' );
		$credentials_ready   = self::has_required_credentials( $settings );
		$runtime_private_key = self::get_runtime_private_key( $settings );
		$has_private_key     = '' !== trim( $runtime_private_key );
		$locked_fields       = self::get_locked_credential_fields();
		$code_locked_mode    = in_array( true, $locked_fields, true );
		$active_tab          = self::get_active_tab_from_request();
		$has_sticky_toolbar  = function_exists( 'mrn_sticky_toolbar_render' ) && function_exists( 'mrn_sticky_toolbar_render_css' );
		?>
		<div class="wrap mrn-recaptcha-enterprise-wrap">
			<?php self::render_tabbed_toolbar( $active_tab ); ?>
			<h1><?php echo esc_html__( 'reCAPTCHA Enterprise Manager', 'mrn-recaptcha-enterprise-manager' ); ?></h1>
			<p><?php echo esc_html__( 'Create reCAPTCHA Enterprise keys and optionally sync them to WPForms without leaving WordPress.', 'mrn-recaptcha-enterprise-manager' ); ?></p>

			<?php settings_errors( self::OPTION_KEY ); ?>

			<?php if ( ! empty( $flash ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( isset( $flash['type'] ) ? (string) $flash['type'] : 'info' ); ?> is-dismissible">
					<p><?php echo esc_html( isset( $flash['message'] ) ? (string) $flash['message'] : '' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $code_locked_mode ) : ?>
				<div class="notice notice-info">
					<p><?php echo esc_html__( 'Code-locked mode is enabled. Any credential fields defined in constants or environment variables are read-only in this screen.', 'mrn-recaptcha-enterprise-manager' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! $has_sticky_toolbar ) : ?>
				<?php self::render_tab_navigation( $active_tab ); ?>
			<?php endif; ?>

			<div class="mrn-recaptcha-panel" data-mrn-recaptcha-panel="<?php echo esc_attr( self::TAB_CREDENTIALS ); ?>"<?php echo self::TAB_CREDENTIALS === $active_tab ? '' : ' hidden'; ?>>
				<form id="mrn-recaptcha-credentials-form" method="post" action="options.php" style="max-width:980px;">
				<?php settings_fields( self::SETTINGS_GROUP ); ?>

				<div class="card" style="padding:16px 20px;">
					<h2 style="margin-top:0;"><?php echo esc_html__( 'Google Project Credentials', 'mrn-recaptcha-enterprise-manager' ); ?></h2>
					<p><?php echo esc_html__( 'Paste a service account JSON file or fill fields manually. Credentials are stored encrypted in WordPress options.', 'mrn-recaptcha-enterprise-manager' ); ?></p>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="mrn-recaptcha-project-id"><?php echo esc_html__( 'Google Project ID', 'mrn-recaptcha-enterprise-manager' ); ?></label>
								</th>
								<td>
									<input type="text" id="mrn-recaptcha-project-id" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[project_id]" value="<?php echo esc_attr( (string) $settings['project_id'] ); ?>" <?php disabled( $locked_fields['project_id'] ); ?> />
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="mrn-recaptcha-sa-email"><?php echo esc_html__( 'Service Account Email', 'mrn-recaptcha-enterprise-manager' ); ?></label>
								</th>
								<td>
									<input type="email" id="mrn-recaptcha-sa-email" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[service_account_email]" value="<?php echo esc_attr( (string) $settings['service_account_email'] ); ?>" <?php disabled( $locked_fields['service_account_email'] ); ?> />
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="mrn-recaptcha-sa-json"><?php echo esc_html__( 'Service Account JSON (Optional)', 'mrn-recaptcha-enterprise-manager' ); ?></label>
								</th>
								<td>
									<textarea id="mrn-recaptcha-sa-json" rows="8" class="large-text code" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[service_account_json]" placeholder="{ ... }" <?php disabled( $locked_fields['project_id'] || $locked_fields['service_account_email'] || $locked_fields['service_account_private_key'] ); ?>></textarea>
									<p class="description"><?php echo esc_html__( 'If provided, this overwrites project ID, service account email, and private key from the JSON payload.', 'mrn-recaptcha-enterprise-manager' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="mrn-recaptcha-sa-private-key"><?php echo esc_html__( 'Service Account Private Key (Optional)', 'mrn-recaptcha-enterprise-manager' ); ?></label>
								</th>
								<td>
									<textarea id="mrn-recaptcha-sa-private-key" rows="8" class="large-text code" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[service_account_private_key]" placeholder="-----BEGIN PRIVATE KEY-----" <?php disabled( $locked_fields['service_account_private_key'] ); ?>></textarea>
									<p class="description">
										<?php
										echo esc_html(
											$has_private_key
												? __( 'A private key is available for runtime. Leave this blank to keep it.', 'mrn-recaptcha-enterprise-manager' )
												: __( 'No private key is currently available.', 'mrn-recaptcha-enterprise-manager' )
										);
										?>
									</p>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[clear_private_key]" value="1" <?php disabled( $locked_fields['service_account_private_key'] ); ?> />
										<?php echo esc_html__( 'Clear stored private key on save', 'mrn-recaptcha-enterprise-manager' ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="mrn-recaptcha-default-domains"><?php echo esc_html__( 'Default Allowed Domains', 'mrn-recaptcha-enterprise-manager' ); ?></label>
								</th>
								<td>
									<input type="text" id="mrn-recaptcha-default-domains" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[default_allowed_domains]" value="<?php echo esc_attr( $default_domains ); ?>" <?php disabled( $locked_fields['default_allowed_domains'] ); ?> />
									<p class="description"><?php echo esc_html__( 'Comma-separated hosts only (example.com, www.example.com).', 'mrn-recaptcha-enterprise-manager' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="mrn-recaptcha-default-integration"><?php echo esc_html__( 'Default Integration Type', 'mrn-recaptcha-enterprise-manager' ); ?></label>
								</th>
								<td>
									<select id="mrn-recaptcha-default-integration" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[default_integration_type]" <?php disabled( $locked_fields['default_integration_type'] ); ?>>
										<option value="<?php echo esc_attr( self::INTEGRATION_TYPE_SCORE ); ?>" <?php selected( self::INTEGRATION_TYPE_SCORE, (string) $settings['default_integration_type'] ); ?>><?php echo esc_html__( 'Score-based (recommended)', 'mrn-recaptcha-enterprise-manager' ); ?></option>
										<option value="<?php echo esc_attr( self::INTEGRATION_TYPE_CHECKBX ); ?>" <?php selected( self::INTEGRATION_TYPE_CHECKBX, (string) $settings['default_integration_type'] ); ?>><?php echo esc_html__( 'Checkbox challenge', 'mrn-recaptcha-enterprise-manager' ); ?></option>
									</select>
								</td>
							</tr>
						</tbody>
					</table>

					<?php if ( ! $has_sticky_toolbar ) : ?>
						<?php submit_button( __( 'Save Credentials', 'mrn-recaptcha-enterprise-manager' ) ); ?>
					<?php endif; ?>
				</div>
				</form>
			</div>

			<div class="mrn-recaptcha-panel" data-mrn-recaptcha-panel="<?php echo esc_attr( self::TAB_CREATE_KEY ); ?>"<?php echo self::TAB_CREATE_KEY === $active_tab ? '' : ' hidden'; ?>>
				<?php if ( ! empty( $flash['site_key'] ) || ! empty( $flash['legacy_secret_key'] ) ) : ?>
					<div class="card" style="max-width:980px;padding:16px 20px;">
						<h2 style="margin-top:0;"><?php echo esc_html__( 'Last Generated Key', 'mrn-recaptcha-enterprise-manager' ); ?></h2>
						<?php if ( ! empty( $flash['key_resource_name'] ) ) : ?>
							<p><strong><?php echo esc_html__( 'Google Key Resource:', 'mrn-recaptcha-enterprise-manager' ); ?></strong> <code><?php echo esc_html( $flash['key_resource_name'] ); ?></code></p>
						<?php endif; ?>
						<?php if ( ! empty( $flash['site_key'] ) ) : ?>
							<p><strong><?php echo esc_html__( 'Site Key:', 'mrn-recaptcha-enterprise-manager' ); ?></strong> <code><?php echo esc_html( $flash['site_key'] ); ?></code></p>
						<?php endif; ?>
						<?php if ( ! empty( $flash['legacy_secret_key'] ) ) : ?>
							<p><strong><?php echo esc_html__( 'Legacy Secret Key:', 'mrn-recaptcha-enterprise-manager' ); ?></strong> <code><?php echo esc_html( $flash['legacy_secret_key'] ); ?></code></p>
						<?php endif; ?>
						<?php if ( ! empty( $flash['wpforms_message'] ) ) : ?>
							<p><strong><?php echo esc_html__( 'WPForms:', 'mrn-recaptcha-enterprise-manager' ); ?></strong> <?php echo esc_html( $flash['wpforms_message'] ); ?></p>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<div class="card" style="max-width:980px;padding:16px 20px;margin-top:16px;">
					<h2 style="margin-top:0;"><?php echo esc_html__( 'Create reCAPTCHA Enterprise Key', 'mrn-recaptcha-enterprise-manager' ); ?></h2>
					<?php if ( ! $credentials_ready ) : ?>
						<p><?php echo esc_html__( 'Save valid Google project credentials first to enable key creation.', 'mrn-recaptcha-enterprise-manager' ); ?></p>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( self::CREATE_KEY_ACTION ); ?>" />
							<?php wp_nonce_field( self::CREATE_KEY_NONCE_ACTION ); ?>

							<table class="form-table" role="presentation">
								<tbody>
									<tr>
										<th scope="row">
											<label for="mrn-recaptcha-key-display-name"><?php echo esc_html__( 'Key Display Name', 'mrn-recaptcha-enterprise-manager' ); ?></label>
										</th>
										<td>
											<input type="text" id="mrn-recaptcha-key-display-name" class="regular-text" name="display_name" value="<?php echo esc_attr( 'WPForms ' . ( is_string( $home_host ) ? $home_host : 'Website' ) ); ?>" required />
										</td>
									</tr>
									<tr>
										<th scope="row">
											<label for="mrn-recaptcha-key-domains"><?php echo esc_html__( 'Allowed Domains', 'mrn-recaptcha-enterprise-manager' ); ?></label>
										</th>
										<td>
											<input type="text" id="mrn-recaptcha-key-domains" class="regular-text" name="allowed_domains" value="<?php echo esc_attr( $default_domains ); ?>" required />
											<p class="description"><?php echo esc_html__( 'Hosts only. Do not include protocol, path, or port.', 'mrn-recaptcha-enterprise-manager' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row">
											<label for="mrn-recaptcha-key-integration"><?php echo esc_html__( 'Integration Type', 'mrn-recaptcha-enterprise-manager' ); ?></label>
										</th>
										<td>
											<select id="mrn-recaptcha-key-integration" name="integration_type">
												<option value="<?php echo esc_attr( self::INTEGRATION_TYPE_SCORE ); ?>" <?php selected( self::INTEGRATION_TYPE_SCORE, (string) $settings['default_integration_type'] ); ?>><?php echo esc_html__( 'Score-based (recommended)', 'mrn-recaptcha-enterprise-manager' ); ?></option>
												<option value="<?php echo esc_attr( self::INTEGRATION_TYPE_CHECKBX ); ?>" <?php selected( self::INTEGRATION_TYPE_CHECKBX, (string) $settings['default_integration_type'] ); ?>><?php echo esc_html__( 'Checkbox challenge', 'mrn-recaptcha-enterprise-manager' ); ?></option>
											</select>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php echo esc_html__( 'WPForms Sync', 'mrn-recaptcha-enterprise-manager' ); ?></th>
										<td>
											<label>
												<input type="checkbox" name="apply_to_wpforms" value="1" checked />
												<?php echo esc_html__( 'Apply generated site key + legacy secret to WPForms CAPTCHA settings', 'mrn-recaptcha-enterprise-manager' ); ?>
											</label>
										</td>
									</tr>
								</tbody>
							</table>

							<?php submit_button( __( 'Create Key and Retrieve Legacy Secret', 'mrn-recaptcha-enterprise-manager' ), 'primary', 'submit', false ); ?>
						</form>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php self::render_tabbed_ui_script( $active_tab, $has_sticky_toolbar ); ?>
		<?php
	}

	/**
	 * Handle create-key form post.
	 *
	 * @return void
	 */
	public static function handle_create_key() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'mrn-recaptcha-enterprise-manager' ) );
		}

		check_admin_referer( self::CREATE_KEY_NONCE_ACTION );

		$settings = self::get_settings();
		if ( ! self::has_required_credentials( $settings ) ) {
			self::set_flash_notice(
				array(
					'type'    => 'error',
					'message' => __( 'Missing credentials. Save project ID, service account email, and private key first.', 'mrn-recaptcha-enterprise-manager' ),
				)
			);
			self::safe_redirect_to_settings( self::TAB_CREATE_KEY );
		}

		$project_id    = (string) $settings['project_id'];
		$account_email = (string) $settings['service_account_email'];
		$private_key   = self::get_runtime_private_key( $settings );
		if ( '' === $private_key ) {
			self::set_flash_notice(
				array(
					'type'    => 'error',
					'message' => __( 'Stored private key is missing or unreadable. Save it again and retry.', 'mrn-recaptcha-enterprise-manager' ),
				)
			);
			self::safe_redirect_to_settings( self::TAB_CREATE_KEY );
		}

		$display_name = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['display_name'] ) ) : '';
		if ( '' === $display_name ) {
			$display_name = 'WPForms ' . $project_id;
		}

		$integration_input = isset( $_POST['integration_type'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['integration_type'] ) ) : '';
		$domains_input     = isset( $_POST['allowed_domains'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['allowed_domains'] ) ) : '';
		$integration_type  = '' !== $integration_input ? self::sanitize_integration_type( $integration_input ) : self::INTEGRATION_TYPE_SCORE;
		$allowed_domains   = '' !== $domains_input ? self::sanitize_domain_list( $domains_input ) : array();
		if ( empty( $allowed_domains ) ) {
			$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( is_string( $home_host ) && '' !== $home_host ) {
				$allowed_domains[] = $home_host;
			}
		}

		$token_response = self::request_google_access_token( $account_email, $private_key );
		if ( is_wp_error( $token_response ) ) {
			self::set_flash_notice(
				array(
					'type'    => 'error',
					'message' => sprintf(
						/* translators: %s: error message. */
						__( 'Google authentication failed: %s', 'mrn-recaptcha-enterprise-manager' ),
						$token_response->get_error_message()
					),
				)
			);
			self::safe_redirect_to_settings( self::TAB_CREATE_KEY );
		}

		$access_token = (string) $token_response['access_token'];
		$key_response = self::create_recaptcha_key( $project_id, $access_token, $display_name, $allowed_domains, $integration_type );
		if ( is_wp_error( $key_response ) ) {
			self::set_flash_notice(
				array(
					'type'    => 'error',
					'message' => sprintf(
						/* translators: %s: error message. */
						__( 'Key creation failed: %s', 'mrn-recaptcha-enterprise-manager' ),
						$key_response->get_error_message()
					),
				)
			);
			self::safe_redirect_to_settings( self::TAB_CREATE_KEY );
		}

		$key_resource_name = (string) $key_response['key_resource_name'];
		$site_key          = (string) $key_response['site_key'];

		$secret_response = self::retrieve_legacy_secret_key( $key_resource_name, $access_token );
		if ( is_wp_error( $secret_response ) ) {
			self::set_flash_notice(
				array(
					'type'              => 'error',
					'message'           => sprintf(
						/* translators: %s: error message. */
						__( 'Key created, but legacy secret retrieval failed: %s', 'mrn-recaptcha-enterprise-manager' ),
						$secret_response->get_error_message()
					),
					'key_resource_name' => $key_resource_name,
					'site_key'          => $site_key,
				)
			);
			self::safe_redirect_to_settings( self::TAB_CREATE_KEY );
		}

		$legacy_secret = (string) $secret_response['legacy_secret_key'];
		$wpforms_note  = '';

		if ( ! empty( $_POST['apply_to_wpforms'] ) ) {
			$wpforms_apply = self::apply_to_wpforms_settings( $site_key, $legacy_secret, $integration_type );
			$wpforms_note  = (string) $wpforms_apply['message'];
		}

		self::set_flash_notice(
			array(
				'type'              => 'success',
				'message'           => __( 'reCAPTCHA Enterprise key created successfully.', 'mrn-recaptcha-enterprise-manager' ),
				'key_resource_name' => $key_resource_name,
				'site_key'          => $site_key,
				'legacy_secret_key' => $legacy_secret,
				'wpforms_message'   => $wpforms_note,
			)
		);

		self::safe_redirect_to_settings( self::TAB_CREATE_KEY );
	}

	/**
	 * Create key using Google reCAPTCHA Enterprise API.
	 *
	 * @param string   $project_id Project ID.
	 * @param string   $access_token OAuth token.
	 * @param string   $display_name Key display name.
	 * @param string[] $allowed_domains Allowed domain list.
	 * @param string   $integration_type Integration type.
	 * @return array<string, string>|WP_Error
	 */
	private static function create_recaptcha_key( $project_id, $access_token, $display_name, $allowed_domains, $integration_type ) {
		$allowed_domains = array_values( array_filter( array_map( 'strval', $allowed_domains ) ) );
		if ( empty( $allowed_domains ) ) {
			return new WP_Error( 'mrn_recaptcha_domains_missing', __( 'At least one allowed domain is required.', 'mrn-recaptcha-enterprise-manager' ) );
		}

		$payload = array(
			'displayName' => $display_name,
			'webSettings' => array(
				'allowedDomains'  => $allowed_domains,
				'allowAllDomains' => false,
				'integrationType' => $integration_type,
			),
		);

		if ( self::INTEGRATION_TYPE_CHECKBX === $integration_type ) {
			$payload['webSettings']['challengeSecurityPreference'] = 'USABILITY';
		}

		$endpoint = self::KEYS_API_BASE . '/projects/' . rawurlencode( $project_id ) . '/keys';
		$request  = wp_remote_post(
			$endpoint,
			array(
				'method'  => 'POST',
				'timeout' => 25,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json; charset=utf-8',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		$response = self::normalize_json_response( $request, __( 'Unable to create key.', 'mrn-recaptcha-enterprise-manager' ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$key_resource_name = isset( $response['name'] ) ? (string) $response['name'] : '';
		if ( '' === $key_resource_name ) {
			return new WP_Error( 'mrn_recaptcha_key_name_missing', __( 'Google response did not include a key name.', 'mrn-recaptcha-enterprise-manager' ) );
		}

		$site_key = self::extract_site_key_from_resource_name( $key_resource_name );
		if ( '' === $site_key ) {
			return new WP_Error( 'mrn_recaptcha_site_key_missing', __( 'Google response did not include a usable site key.', 'mrn-recaptcha-enterprise-manager' ) );
		}

		return array(
			'key_resource_name' => $key_resource_name,
			'site_key'          => $site_key,
		);
	}

	/**
	 * Retrieve legacy secret key from Google API.
	 *
	 * @param string $key_resource_name Key resource path.
	 * @param string $access_token OAuth token.
	 * @return array<string, string>|WP_Error
	 */
	private static function retrieve_legacy_secret_key( $key_resource_name, $access_token ) {
		$encoded_key_path = self::encode_google_resource_path( $key_resource_name );
		$endpoint         = self::KEYS_API_BASE . '/' . $encoded_key_path . ':retrieveLegacySecretKey';
		$headers          = array(
			'Authorization' => 'Bearer ' . $access_token,
			'Content-Type'  => 'application/json; charset=utf-8',
		);

		$request = wp_remote_get(
			$endpoint,
			array(
				'timeout' => 20,
				'headers' => $headers,
			)
		);

		$response = self::normalize_json_response( $request, __( 'Unable to retrieve legacy secret key.', 'mrn-recaptcha-enterprise-manager' ) );

		if ( is_wp_error( $response ) ) {
			$fallback_request = wp_remote_post(
				$endpoint,
				array(
					'method'  => 'POST',
					'timeout' => 20,
					'headers' => $headers,
					'body'    => '{}',
				)
			);

			$response = self::normalize_json_response( $fallback_request, __( 'Unable to retrieve legacy secret key.', 'mrn-recaptcha-enterprise-manager' ) );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		$legacy_secret = '';
		if ( isset( $response['legacySecretKey'] ) ) {
			$legacy_secret = (string) $response['legacySecretKey'];
		} elseif ( isset( $response['legacy_secret_key'] ) ) {
			$legacy_secret = (string) $response['legacy_secret_key'];
		}

		if ( '' === $legacy_secret ) {
			return new WP_Error(
				'mrn_recaptcha_legacy_secret_missing',
				__(
					'Google response did not include a legacy secret key. Verify service account permissions (reCAPTCHA Enterprise Admin).',
					'mrn-recaptcha-enterprise-manager'
				)
			);
		}

		return array(
			'legacy_secret_key' => $legacy_secret,
		);
	}

	/**
	 * Apply key settings to WPForms global settings.
	 *
	 * @param string $site_key Site key.
	 * @param string $legacy_secret Legacy secret key.
	 * @param string $integration_type Google integration type.
	 * @return array<string, string|bool>
	 */
	private static function apply_to_wpforms_settings( $site_key, $legacy_secret, $integration_type ) {
		if ( ! function_exists( 'wpforms' ) ) {
			return array(
				'success' => false,
				'message' => __( 'WPForms is not active, so automatic sync was skipped.', 'mrn-recaptcha-enterprise-manager' ),
			);
		}

		$current = get_option( 'wpforms_settings', array() );
		$current = is_array( $current ) ? $current : array();

		$current['captcha-provider']     = 'recaptcha';
		$current['recaptcha-site-key']   = $site_key;
		$current['recaptcha-secret-key'] = $legacy_secret;
		$current['recaptcha-type']       = self::map_integration_type_to_wpforms( $integration_type );

		if ( function_exists( 'wpforms_update_settings' ) ) {
			wpforms_update_settings( $current );
		} else {
			update_option( 'wpforms_settings', $current );
		}

		return array(
			'success' => true,
			'message' => __( 'CAPTCHA provider, site key, secret key, and reCAPTCHA type were synced to WPForms settings.', 'mrn-recaptcha-enterprise-manager' ),
		);
	}

	/**
	 * Request OAuth access token using service account JWT flow.
	 *
	 * @param string $service_account_email Service account email.
	 * @param string $private_key Private key.
	 * @return array<string, string>|WP_Error
	 */
	private static function request_google_access_token( $service_account_email, $private_key ) {
		$private_key = self::normalize_private_key( $private_key );
		if ( '' === $private_key ) {
			return new WP_Error( 'mrn_recaptcha_private_key_missing', __( 'Service account private key is empty.', 'mrn-recaptcha-enterprise-manager' ) );
		}

		if ( ! function_exists( 'openssl_sign' ) ) {
			return new WP_Error( 'mrn_recaptcha_openssl_missing', __( 'OpenSSL is required to sign Google service account tokens.', 'mrn-recaptcha-enterprise-manager' ) );
		}

		$now     = time();
		$header  = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);
		$payload = array(
			'iss'   => $service_account_email,
			'scope' => self::TOKEN_SCOPE,
			'aud'   => self::TOKEN_ENDPOINT,
			'exp'   => $now + 3600,
			'iat'   => $now,
		);

		$jwt_header  = self::base64_url_encode( wp_json_encode( $header ) );
		$jwt_payload = self::base64_url_encode( wp_json_encode( $payload ) );
		$unsigned    = $jwt_header . '.' . $jwt_payload;

		$signature = '';
		$signed    = openssl_sign( $unsigned, $signature, $private_key, 'sha256WithRSAEncryption' );
		if ( ! $signed ) {
			return new WP_Error( 'mrn_recaptcha_jwt_sign_failed', __( 'Unable to sign JWT with the provided private key.', 'mrn-recaptcha-enterprise-manager' ) );
		}

		$assertion = $unsigned . '.' . self::base64_url_encode( $signature );
		$request   = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $assertion,
				),
			)
		);

		$response = self::normalize_json_response( $request, __( 'Google token endpoint request failed.', 'mrn-recaptcha-enterprise-manager' ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$access_token = isset( $response['access_token'] ) ? (string) $response['access_token'] : '';
		if ( '' === $access_token ) {
			return new WP_Error( 'mrn_recaptcha_token_missing', __( 'Google token response did not include an access token.', 'mrn-recaptcha-enterprise-manager' ) );
		}

		return array(
			'access_token' => $access_token,
		);
	}

	/**
	 * Parse API response and normalize errors.
	 *
	 * @param array<string, mixed>|WP_Error $request HTTP response.
	 * @param string                        $fallback_error Fallback error message.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function normalize_json_response( $request, $fallback_error ) {
		if ( is_wp_error( $request ) ) {
			return new WP_Error( 'mrn_recaptcha_http_error', $request->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $request );
		$body = wp_remote_retrieve_body( $request );
		$json = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$error_message = $fallback_error;
			if ( is_array( $json ) && isset( $json['error'] ) ) {
				if ( is_array( $json['error'] ) && ! empty( $json['error']['message'] ) ) {
					$error_message = (string) $json['error']['message'];
				} elseif ( is_string( $json['error'] ) && '' !== $json['error'] ) {
					$error_message = $json['error'];
				}
			}

			return new WP_Error( 'mrn_recaptcha_remote_status_' . $code, $error_message );
		}

		return is_array( $json ) ? $json : array();
	}

	/**
	 * Return whether all required credentials are present.
	 *
	 * @param array<string, string> $settings Settings array.
	 * @return bool
	 */
	private static function has_required_credentials( $settings ) {
		return '' !== trim( (string) ( $settings['project_id'] ?? '' ) )
			&& '' !== trim( (string) ( $settings['service_account_email'] ?? '' ) )
			&& '' !== trim( self::get_runtime_private_key( $settings ) );
	}

	/**
	 * Redirect back to plugin settings page.
	 *
	 * @param string $tab Optional target tab key.
	 * @return void
	 */
	private static function safe_redirect_to_settings( $tab = '' ) {
		wp_safe_redirect( self::get_settings_page_url( $tab ) );
		exit;
	}

	/**
	 * Return settings page URL.
	 *
	 * @param string $tab Optional tab key.
	 * @return string
	 */
	private static function get_settings_page_url( $tab = '' ) {
		$base_url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		$tab      = sanitize_key( (string) $tab );

		if ( self::TAB_CREDENTIALS === $tab || self::TAB_CREATE_KEY === $tab ) {
			return add_query_arg( self::TAB_QUERY_ARG, $tab, $base_url );
		}

		return $base_url;
	}

	/**
	 * Store per-user flash payload.
	 *
	 * @param array<string, string> $notice Notice payload.
	 * @return void
	 */
	private static function set_flash_notice( $notice ) {
		set_transient( self::FLASH_TRANSIENT_PREFIX . get_current_user_id(), $notice, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Fetch and clear per-user flash payload.
	 *
	 * @return array<string, string>
	 */
	private static function consume_flash_notice() {
		$key   = self::FLASH_TRANSIENT_PREFIX . get_current_user_id();
		$flash = get_transient( $key );
		delete_transient( $key );

		return is_array( $flash ) ? $flash : array();
	}

	/**
	 * Map Google integration type to WPForms recaptcha type.
	 *
	 * @param string $integration_type Integration type.
	 * @return string
	 */
	private static function map_integration_type_to_wpforms( $integration_type ) {
		if ( self::INTEGRATION_TYPE_CHECKBX === $integration_type ) {
			return 'v2';
		}

		return 'v3';
	}

	/**
	 * Normalize and validate private key.
	 *
	 * @param string $private_key Raw key.
	 * @return string
	 */
	private static function normalize_private_key( $private_key ) {
		$private_key = trim( str_replace( "\r", '', (string) $private_key ) );
		$private_key = str_replace( array( "\\n", "\n\n\n" ), array( "\n", "\n\n" ), $private_key );

		if ( '' === $private_key ) {
			return '';
		}

		if ( false === strpos( $private_key, '-----BEGIN PRIVATE KEY-----' ) || false === strpos( $private_key, '-----END PRIVATE KEY-----' ) ) {
			return '';
		}

		return $private_key;
	}

	/**
	 * Encrypt sensitive values before saving.
	 *
	 * @param string $plaintext Plain text value.
	 * @return string
	 */
	private static function encrypt_secret( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			return '';
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return '';
		}

		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = '';
		try {
			$iv = random_bytes( 16 );
		} catch ( Exception $exception ) {
			return '';
		}

		$cipher_text = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher_text ) {
			return '';
		}

		return base64_encode( $iv . $cipher_text ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Needed for encrypted binary storage.
	}

	/**
	 * Decrypt sensitive values from options.
	 *
	 * @param string $encrypted Cipher value.
	 * @return string
	 */
	private static function decrypt_secret( $encrypted ) {
		$encrypted = (string) $encrypted;
		if ( '' === $encrypted ) {
			return '';
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$decoded = base64_decode( $encrypted, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Needed for encrypted binary retrieval.
		if ( false === $decoded || strlen( $decoded ) <= 16 ) {
			return '';
		}

		$key         = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv          = substr( $decoded, 0, 16 );
		$cipher_text = substr( $decoded, 16 );

		$plaintext = openssl_decrypt( $cipher_text, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return is_string( $plaintext ) ? $plaintext : '';
	}

	/**
	 * Sanitize Google project ID.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function sanitize_project_id( $value ) {
		$value = strtolower( sanitize_text_field( (string) $value ) );
		$clean = preg_replace( '/[^a-z0-9\-]/', '', $value );
		return is_string( $clean ) ? $clean : '';
	}

	/**
	 * Sanitize integration type.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function sanitize_integration_type( $value ) {
		$value = strtoupper( sanitize_key( (string) $value ) );
		if ( self::INTEGRATION_TYPE_CHECKBX === $value ) {
			return self::INTEGRATION_TYPE_CHECKBX;
		}

		return self::INTEGRATION_TYPE_SCORE;
	}

	/**
	 * Sanitize comma/newline domain list.
	 *
	 * @param string $value Raw value.
	 * @return string[]
	 */
	private static function sanitize_domain_list( $value ) {
		$value   = str_replace( array( "\r\n", "\r" ), "\n", (string) $value );
		$tokens  = preg_split( '/[\n,]+/', $value );
		$domains = array();
		if ( ! is_array( $tokens ) ) {
			return $domains;
		}

		foreach ( $tokens as $token ) {
			$token = trim( (string) $token );
			if ( '' === $token ) {
				continue;
			}

			$parsed_host = wp_parse_url( $token, PHP_URL_HOST );
			if ( is_string( $parsed_host ) && '' !== $parsed_host ) {
				$token = $parsed_host;
			}

			$token = preg_replace( '/[^a-z0-9\.\-\*]/', '', $token );
			$token = is_string( $token ) ? strtolower( $token ) : '';
			$token = trim( $token, '.' );
			if ( '' === $token ) {
				continue;
			}

			$domains[] = $token;
		}

		return array_values( array_unique( $domains ) );
	}

	/**
	 * Encode resource path for API endpoints.
	 *
	 * @param string $resource_path Resource path.
	 * @return string
	 */
	private static function encode_google_resource_path( $resource_path ) {
		$segments = array_filter( explode( '/', (string) $resource_path ), 'strlen' );
		return implode( '/', array_map( 'rawurlencode', $segments ) );
	}

	/**
	 * Extract site key id from resource name.
	 *
	 * @param string $resource_name Resource name.
	 * @return string
	 */
	private static function extract_site_key_from_resource_name( $resource_name ) {
		$resource_name = trim( (string) $resource_name );
		if ( '' === $resource_name ) {
			return '';
		}

		$parts = explode( '/', $resource_name );
		return (string) end( $parts );
	}

	/**
	 * Base64 URL-safe encoding.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function base64_url_encode( $value ) {
		return rtrim( strtr( base64_encode( (string) $value ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWT segment encoding.
	}
}
