<?php

/**
 * Plugin Name: SumUp Payment Gateway For WooCommerce
 * Plugin URI: https://wordpress.org/plugins/sumup-payment-gateway-for-woocommerce/
 * Description: Take credit card payments on your store using SumUp.
 * Author: SumUp
 * Author URI: https://sumup.com
 * Version: 2.18.0
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 10.6
 * WC tested up to: 11.2
 * Text Domain: sumup-payment-gateway-for-woocommerce
 * Domain Path: /languages
 * License: Apache-2.0
 * License URI: https://www.apache.org/licenses/LICENSE-2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WC_SUMUP_MAIN_FILE', __FILE__ );
define( 'WC_SUMUP_PLUGIN_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'WC_SUMUP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WC_SUMUP_VERSION', '2.18.0' );
define( 'WC_SUMUP_MINIMUM_PHP_VERSION', '7.4' );
define( 'WC_SUMUP_MINIMUM_WP_VERSION', '6.9' );
define( 'WC_SUMUP_PLUGIN_SLUG', 'sumup-payment-gateway-for-woocommerce' );

/**
 * Normalize the detected operating system name for request headers.
 *
 * @return string
 */
function sumup_get_runtime_os_name() {
	$os = strtolower( (string) php_uname( 's' ) );

	if ( 0 === strpos( $os, 'win' ) ) {
		return 'windows';
	}

	if ( 0 === strpos( $os, 'linux' ) ) {
		return 'linux';
	}

	if ( 0 === strpos( $os, 'darwin' ) ) {
		return 'darwin';
	}

	return '' !== $os ? $os : 'unknown';
}

/**
 * Normalize the detected CPU architecture name for request headers.
 *
 * @return string
 */
function sumup_get_runtime_arch_name() {
	$arch = strtolower( (string) php_uname( 'm' ) );
	$map  = array(
		'x86_64'  => 'x86_64',
		'x64'     => 'x86_64',
		'amd64'   => 'x86_64',
		'x86'     => 'x86',
		'i386'    => 'x86',
		'i686'    => 'x86',
		'ia32'    => 'x86',
		'x32'     => 'x86',
		'aarch64' => 'arm64',
		'arm64'   => 'arm64',
		'arm'     => 'arm',
	);

	if ( isset( $map[ $arch ] ) ) {
		return $map[ $arch ];
	}

	return '' !== $arch ? $arch : 'unknown';
}

/**
 * Build the shared SumUp request headers used for outbound API calls.
 *
 * @param array $headers Additional headers to merge into the request.
 * @param string $access_token Optional bearer token.
 * @return array
 */
function sumup_get_api_request_headers( $headers = array(), $access_token = '' ) {
	static $runtime_headers = null;

	if ( null === $runtime_headers ) {
		$runtime_headers = array(
			'User-Agent'              => sprintf(
				'%s/v%s (WordPress/%s)',
				WC_SUMUP_PLUGIN_SLUG,
				WC_SUMUP_VERSION,
				get_bloginfo( 'version' )
			),
			'X-Sumup-Lang'            => 'php',
			'X-Sumup-Plugin-Version'  => WC_SUMUP_VERSION,
			'X-Sumup-OS'              => sumup_get_runtime_os_name(),
			'X-Sumup-Arch'            => sumup_get_runtime_arch_name(),
			'X-Sumup-Runtime'         => 'wordpress',
			'X-Sumup-Runtime-Version' => PHP_VERSION,
		);
	}

	$request_headers = array_merge(
		array(
			'Accept' => 'application/json',
		),
		$runtime_headers,
		is_array( $headers ) ? $headers : array()
	);

	if ( '' !== $access_token ) {
		$request_headers['Authorization'] = 'Bearer ' . $access_token;
	}

	return $request_headers;
}

/**
 * Get the stored SumUp gateway settings.
 *
 * @return array
 */
function sumup_get_gateway_settings() {
	$settings = get_option( 'woocommerce_sumup_settings', array() );

	return is_array( $settings ) ? $settings : array();
}

/**
 * Check whether the stored settings include enough account details to connect.
 *
 * @param array|null $settings Optional settings array.
 * @return bool
 */
function sumup_gateway_has_connection_details( $settings = null ) {
	if ( ! is_array( $settings ) ) {
		$settings = sumup_get_gateway_settings();
	}

	$has_auth_material     = ! empty( $settings['api_key'] ) || ( ! empty( $settings['client_id'] ) && ! empty( $settings['client_secret'] ) );
	$has_account_reference = ! empty( $settings['merchant_id'] ) || ! empty( $settings['pay_to_email'] );

	return $has_auth_material && $has_account_reference;
}

/**
 * Migrate legacy pay_to_email-only settings to merchant_code.
 *
 * @param array|null $settings Optional settings array.
 * @return array
 */
function sumup_maybe_migrate_merchant_code( $settings = null ) {
	if ( ! is_array( $settings ) ) {
		$settings = sumup_get_gateway_settings();
	}

	if (
		empty( $settings['pay_to_email'] ) ||
		! empty( $settings['merchant_id'] )
	) {
		return $settings;
	}

	$access_token = '';
	if ( class_exists( 'Wc_Sumup_Access_Token' ) ) {
		$access_token_data = Wc_Sumup_Access_Token::get(
			$settings['client_id'] ?? '',
			$settings['client_secret'] ?? '',
			$settings['api_key'] ?? '',
			false
		);
		$access_token      = isset( $access_token_data['access_token'] ) ? (string) $access_token_data['access_token'] : '';
	} elseif ( ! empty( $settings['api_key'] ) ) {
		$access_token = (string) $settings['api_key'];
	}

	if ( '' === $access_token ) {
		return $settings;
	}

	$response = wp_remote_get(
		'https://api.sumup.com/userinfo',
		array(
			'timeout'     => 15,
			'redirection' => 0,
			'headers'     => sumup_get_api_request_headers( array(), $access_token ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $settings;
	}

	if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return $settings;
	}

	$payload       = json_decode( wp_remote_retrieve_body( $response ), true );
	$merchant_code = is_array( $payload ) ? sanitize_text_field( (string) ( $payload['merchant_code'] ?? '' ) ) : '';

	if ( '' === $merchant_code ) {
		return $settings;
	}

	$settings['merchant_id'] = $merchant_code;
	update_option( 'woocommerce_sumup_settings', $settings );
	update_option( 'sumup_connection_status', 'connected', false );
	delete_option( 'sumup_google_pay_domain_status' );

	return $settings;
}

/**
 * Get the hostname WordPress is currently configured to use.
 *
 * @return string
 */
function sumup_get_site_hostname() {
	$site_url   = home_url();
	$parsed_url = wp_parse_url( $site_url );

	if ( ! is_array( $parsed_url ) || empty( $parsed_url['host'] ) ) {
		return '';
	}

	return strtolower( rtrim( $parsed_url['host'], '.' ) );
}

/**
 * Determine whether a hostname is public enough for the hosted onboarding flow.
 *
 * @param string|null $hostname Optional hostname override for tests.
 * @return bool
 */
function sumup_is_public_hostname( $hostname = null ) {
	if ( null === $hostname ) {
		$hostname = sumup_get_site_hostname();
	}

	$hostname = strtolower( trim( (string) $hostname ) );
	if ( '' === $hostname ) {
		return false;
	}

	if ( 'localhost' === $hostname || 'localhost.localdomain' === $hostname ) {
		return false;
	}

	if ( false === strpos( $hostname, '.' ) ) {
		return false;
	}

	if (
		'.localhost' === substr( $hostname, -10 ) ||
		'.local' === substr( $hostname, -6 ) ||
		'.test' === substr( $hostname, -5 ) ||
		'.invalid' === substr( $hostname, -8 ) ||
		'.example' === substr( $hostname, -8 )
	) {
		return false;
	}

	if ( filter_var( $hostname, FILTER_VALIDATE_IP ) ) {
		return (bool) filter_var( $hostname, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	return true;
}

/**
 * Explain how to make the store reachable for onboarding.
 *
 * @return string
 */
function sumup_get_onboarding_host_warning_message() {
	$site_host = sumup_get_site_hostname();

	if ( '' === $site_host ) {
		return __(
			'Use a publicly reachable HTTPS domain before connecting SumUp. If you are testing locally, use a tunnel such as ngrok or Cloudflare Tunnel and update WordPress Home and Site URL first.',
			'sumup-payment-gateway-for-woocommerce'
		);
	}

	return sprintf(
		/* translators: %s = current WordPress host */
		__(
			'Your store is currently using %1$s, which is not publicly reachable. Use a public HTTPS domain before connecting SumUp. If you are testing locally, use a tunnel such as ngrok or Cloudflare Tunnel and update WordPress Home and Site URL first.',
			'sumup-payment-gateway-for-woocommerce'
		),
		'<code>' . esc_html( $site_host ) . '</code>'
	);
}

/**
 * Get the current gateway connection status.
 *
 * @param array|null $settings Optional settings array.
 * @return string
 */
function sumup_get_gateway_connection_status( $settings = null ) {
	if ( ! is_array( $settings ) ) {
		$settings = sumup_get_gateway_settings();
	}

	if ( ! sumup_gateway_has_connection_details( $settings ) ) {
		return 'not_connected';
	}

	$status = get_option( 'sumup_connection_status', '' );
	if ( in_array( $status, array( 'connected', 'invalid', 'pending' ), true ) ) {
		return $status;
	}

	$legacy_status = get_option( 'sumup_valid_credentials', null );
	if ( '1' === (string) $legacy_status ) {
		return 'connected';
	}

	if ( '0' === (string) $legacy_status ) {
		return 'invalid';
	}

	return 'pending';
}

/**
 * Get the persisted pending onboarding connection IDs.
 *
 * Expiring options survive object-cache eviction. Transients are read only
 * for callbacks initiated before this storage migration.
 *
 * @return array<string, int>
 */
function sumup_get_pending_connection_ids() {
	$pending_connection_ids = get_option( 'sumup_pending_connection_ids', array() );
	if ( ! is_array( $pending_connection_ids ) ) {
		return array();
	}

	$now                               = time();
	$normalized_pending_connection_ids = array();

	foreach ( $pending_connection_ids as $connection_id => $expires_at ) {
		$connection_id = is_string( $connection_id ) ? sanitize_text_field( $connection_id ) : '';
		$expires_at    = is_numeric( $expires_at ) ? (int) $expires_at : 0;

		if ( '' === $connection_id || $expires_at <= $now ) {
			continue;
		}

		$normalized_pending_connection_ids[ $connection_id ] = $expires_at;
	}

	if ( $normalized_pending_connection_ids !== $pending_connection_ids ) {
		update_option( 'sumup_pending_connection_ids', $normalized_pending_connection_ids, false );
	}

	return $normalized_pending_connection_ids;
}

/**
 * Persist a pending onboarding connection ID.
 *
 * @param string $connection_id Connection identifier returned by onboarding.
 * @return void
 */
function sumup_store_pending_connection_id( $connection_id ) {
	$connection_id = sanitize_text_field( $connection_id );
	if ( '' === $connection_id ) {
		return;
	}

	$pending_connection_ids                   = sumup_get_pending_connection_ids();
	$pending_connection_ids[ $connection_id ] = time() + DAY_IN_SECONDS;
	update_option( 'sumup_pending_connection_ids', $pending_connection_ids, false );
}

/**
 * Check whether a pending onboarding connection ID is known.
 *
 * @param string $connection_id Connection identifier returned by onboarding.
 * @return bool
 */
function sumup_has_pending_connection_id( $connection_id ) {
	$connection_id = sanitize_text_field( $connection_id );
	if ( '' === $connection_id ) {
		return false;
	}

	// Compatibility for callbacks initiated by the previous release (two-hour TTL).
	$transient_value = get_transient( 'sumup-connection-id-' . $connection_id );
	if ( ! empty( $transient_value ) && $transient_value === $connection_id ) {
		return true;
	}

	$pending_connection_ids = sumup_get_pending_connection_ids();

	return isset( $pending_connection_ids[ $connection_id ] );
}

/**
 * Remove a pending onboarding connection ID from every local store.
 *
 * @param string $connection_id Connection identifier returned by onboarding.
 * @return void
 */
function sumup_delete_pending_connection_id( $connection_id ) {
	$connection_id = sanitize_text_field( $connection_id );
	if ( '' === $connection_id ) {
		return;
	}

	delete_transient( 'sumup-connection-id-' . $connection_id );

	$pending_connection_ids = sumup_get_pending_connection_ids();
	if ( ! isset( $pending_connection_ids[ $connection_id ] ) ) {
		return;
	}

	unset( $pending_connection_ids[ $connection_id ] );
	update_option( 'sumup_pending_connection_ids', $pending_connection_ids, false );
}

/**
 * Check whether the gateway has a valid connected account.
 *
 * @param array|null $settings Optional settings array.
 * @return bool
 */
function sumup_gateway_is_configured( $settings = null ) {
	return 'connected' === sumup_get_gateway_connection_status( $settings );
}

/**
 * Whether Google Pay is enabled, preserving existing stores' widget defaults.
 *
 * @param array|null $settings Optional gateway settings.
 * @return bool
 */
function sumup_should_offer_google_pay( $settings = null ) {
	$settings = is_array( $settings ) ? $settings : sumup_get_gateway_settings();
	return 'yes' === ( $settings['enable_google_pay'] ?? 'yes' );
}

/**
 * Return an optional Google Pay merchant override for the payment widget.
 *
 * @return array
 */
function sumup_get_google_pay_config() {
	$settings      = sumup_get_gateway_settings();
	$merchant_id   = sanitize_text_field( $settings['google_pay_merchant_id'] ?? '' );
	$merchant_name = sanitize_text_field( get_bloginfo( 'name' ) );
	return '' !== $merchant_id ? array(
		'merchantId'   => $merchant_id,
		'merchantName' => '' !== $merchant_name ? $merchant_name : __( 'Store', 'sumup-payment-gateway-for-woocommerce' ),
	) : array();
}

/**
 * Return registration status only for the current merchant and hostname.
 *
 * @return string
 */
function sumup_get_google_pay_domain_status() {
	$status   = get_option( 'sumup_google_pay_domain_status', array() );
	$settings = sumup_get_gateway_settings();
	if ( ! is_array( $status ) ||
		( $status['merchant_code'] ?? '' ) !== ( $settings['merchant_id'] ?? '' ) ||
		( $status['domain'] ?? '' ) !== sumup_get_site_hostname() ) {
		return '';
	}
	return 'registered' === ( $status['status'] ?? '' ) ? 'registered' : '';
}

/**
 * Normalize the stored enabled flag to match the connection status.
 *
 * @return void
 */
function sumup_sync_gateway_enabled_state() {
	$settings = sumup_get_gateway_settings();
	$status   = sumup_get_gateway_connection_status( $settings );
	$enabled  = isset( $settings['enabled'] ) ? $settings['enabled'] : 'no';

	if ( 'connected' !== $status && 'no' !== $enabled ) {
		$settings['enabled'] = 'no';
		update_option( 'woocommerce_sumup_settings', $settings );
	}
}

/**
 * Check PHP and WP version before start anything.
 *
 * @since 2.0
 */
if ( ! version_compare( PHP_VERSION, WC_SUMUP_MINIMUM_PHP_VERSION, '>=' ) ) {
	add_action( 'admin_notices', 'sumup_payment_admin_notice_php_version_fail' );
	return;
}

if ( ! version_compare( get_bloginfo( 'version' ), WC_SUMUP_MINIMUM_WP_VERSION, '>=' ) ) {
	add_action( 'admin_notices', 'sumup_payment_admin_notice_wp_version_fail' );
	return;
}

/**
 * Initialize the SumUp Gateway plugin.
 *
 * @since    1.0.0
 * @version  1.0.0
 */
function sumup_payment_gateway_for_woocommerce_init() {
	/**
	 * Display links next to the plugin's version.
	 *
	 * @since    1.0.0
	 * @version  1.0.0
	 */
	function sumup_plugin_row_meta( $links, $file ) {
		if ( plugin_basename( __FILE__ ) === $file ) {
			$row_meta = array(
				'docs' => '<a href="https://developer.sumup.com">' . esc_html__( 'Docs', 'sumup-payment-gateway-for-woocommerce' ) . '</a>',
			);
			return array_merge( $links, $row_meta );
		}
		return (array) $links;
	}

	add_filter( 'plugin_row_meta', 'sumup_plugin_row_meta', 10, 2 );

	/**
	 * Display admin notice when WooCommerce is not installed.
	 *
	 * @since    1.0.0
	 * @version  1.0.0
	 */
	if ( ! class_exists( 'WooCommerce' ) ) {
		function sumup_missing_wc_notice() {
			/* translators: %s: WooCommerce download link. */
			echo '<div class="notice notice-error"><p><strong>' . sprintf( esc_html__( 'SumUp requires WooCommerce to be installed and active. You can download %s here.', 'sumup-payment-gateway-for-woocommerce' ), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>' ) . '</strong></p></div>';
		}
		add_action( 'admin_notices', 'sumup_missing_wc_notice' );
		return;
	}

	/**
	 * Get plugin's setting page URL.
	 *
	 * @since    1.0.0
	 * @version  1.0.0
	 */
	function sumup_get_gateway_setup_link() {
		return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=sumup' );
	}

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Preserve the existing public helper for integrations using the published function name.
	function get_sumup_gateway_setup_link() {
		return sumup_get_gateway_setup_link();
	}

	/**
	 * Display admin notice if the plugin is not configured successfully.
	 *
	 * @since    1.0.0
	 * @version  1.0.0
	 */
	function sumup_plugin_not_configured_notice() {
		global $current_tab, $current_section;

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return; }
		$screen = get_current_screen();
		if ( ! $screen || 'woocommerce_page_wc-settings' !== $screen->id ) {
			return; }
		$plugin_options               = sumup_get_gateway_settings();
		$plugin_enabled               = isset( $plugin_options['enabled'] ) && 'yes' === $plugin_options['enabled'];
		$connection_status            = sumup_get_gateway_connection_status( $plugin_options );
		$is_plugin_settings_page      = 'checkout' === $current_tab && 'sumup' === $current_section;
		$is_valid_currency_configured = get_option( 'sumup_valid_currency' );

		if ( 'not_connected' === $connection_status ) {
			/* translators: %s = admin.php?page=wc-settings&tab=checkout&section=sumup */
			echo '<div class="notice notice-warning"><p><strong>' . wp_kses_post( sprintf( __( 'SumUp Gateway is almost ready. To get started, <a href="%s">set your SumUp account keys</a>.', 'sumup-payment-gateway-for-woocommerce' ), esc_url( sumup_get_gateway_setup_link() ) ) ) . '</strong></p></div>';

			return; /* don't display other notices about configurations */
		}

		if ( $plugin_enabled && 'invalid' === $connection_status && ! $is_plugin_settings_page ) {
			/* translators: %s = admin.php?page=wc-settings&tab=checkout&section=sumup */
			echo '<div class="notice notice-error"><p><strong>' . wp_kses_post( sprintf( __( 'SumUp Gateway is not configured properly. You can fix this from <a href="%s">here</a>.', 'sumup-payment-gateway-for-woocommerce' ), esc_url( sumup_get_gateway_setup_link() ) ) ) . '</strong></p></div>';
		}

		if ( 'connected' === $connection_status && Wc_Sumup_Credentials::has_currency_mismatch() ) {
			/* translators: 1: store currency, 2: connected merchant currency, 3: SumUp settings URL. */
			echo '<div class="notice notice-warning"><p>' . wp_kses_post( sprintf( __( 'Your store uses %1$s, but the connected SumUp merchant uses %2$s. Change the store currency back or <a href="%3$s">connect a matching SumUp account</a>.', 'sumup-payment-gateway-for-woocommerce' ), esc_html( get_option( 'woocommerce_currency', '' ) ), esc_html( Wc_Sumup_Credentials::get_connection_currency() ), esc_url( sumup_get_gateway_setup_link() ) ) ) . '</p></div>';
		}

		if ( $plugin_enabled && ! $is_valid_currency_configured ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'SumUp Gateway needs your attention. Currency is different from WooCommerce currency (WooCommerce->Settings->General->Currency).', 'sumup-payment-gateway-for-woocommerce' ) . '</strong></p></div>';
		}

		if ( isset( $plugin_options['merchant_id'] ) && empty( $plugin_options['merchant_id'] ) ) {
			$message = sprintf(
				'<div class="notice notice-error"><p>%1$s</p></div>',
				__( 'Please use the “Connect Account” button to start the configuration.', 'sumup-payment-gateway-for-woocommerce' )
			);

			echo wp_kses_post( $message );
		}
	}

	add_action( 'admin_notices', 'sumup_plugin_not_configured_notice' );

	/**
	 * Display links beneath the plugin's name
	 *
	 * @since    1.0.0
	 * @version  1.0.0
	 */
	function sumup_plugin_action_links( $links ) {
		$plugin_links = array(
			'<a href="' . sumup_get_gateway_setup_link() . '">' . esc_html__( 'Settings', 'sumup-payment-gateway-for-woocommerce' ) . '</a>',
		);
		return array_merge( $plugin_links, $links );
	}

	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'sumup_plugin_action_links' );

	include_once WC_SUMUP_PLUGIN_PATH . '/includes/class-wc-sumup-logger.php';
	require_once WC_SUMUP_PLUGIN_PATH . '/includes/class-wc-sumup-i18n.php';
	require_once WC_SUMUP_PLUGIN_PATH . '/includes/class-wc-sumup-widget-settings.php';
	include_once WC_SUMUP_PLUGIN_PATH . '/includes/class-wc-sumup-gateway.php';
	include_once WC_SUMUP_PLUGIN_PATH . '/includes/class-wc-sumup-access-token.php';
	include_once WC_SUMUP_PLUGIN_PATH . '/includes/class-wc-sumup-checkout.php';
	include_once WC_SUMUP_PLUGIN_PATH . '/includes/class-wc-sumup-checkout-service.php';
	require_once WC_SUMUP_PLUGIN_PATH . '/includes/class-wc-sumup-order-lock.php';
	include_once WC_SUMUP_PLUGIN_PATH . '/includes/class-wc-sumup-legacy-payment-return.php';
	include_once WC_SUMUP_PLUGIN_PATH . '/includes/class-wc-sumup-credentials.php';
	include_once WC_SUMUP_PLUGIN_PATH . '/includes/class-wc-sumup-apple-pay.php';

	require_once WC_SUMUP_PLUGIN_PATH . '/includes/class-wc-sumup-connection.php';
	include_once WC_SUMUP_PLUGIN_PATH . '/includes/api/class-sumup-validate.php';
	include_once WC_SUMUP_PLUGIN_PATH . '/includes/api/class-sumup-connect.php';
	include_once WC_SUMUP_PLUGIN_PATH . '/includes/api/class-sumup-disconnect.php';
	include_once WC_SUMUP_PLUGIN_PATH . '/includes/class-wc-sumup-onboarding.php';
	include_once WC_SUMUP_PLUGIN_PATH . '/includes/api/class-sumup-api-handler.php';

	sumup_maybe_migrate_merchant_code();
	sumup_sync_gateway_enabled_state();

	// Capture the original currency for legacy connections before the store changes it.
	add_filter(
		'pre_update_option_woocommerce_currency',
		static function ( $currency, $old_currency ) {
			if ( $currency !== $old_currency ) {
				Wc_Sumup_Credentials::get_connection_currency();
			}
			return $currency;
		},
		10,
		2
	);
	Wc_Sumup_Apple_Pay::init();

	$sumup_onbording = new WC_Sumup_Onboarding();
	$sumup_onbording->init_ajax_request();

	$sumup_handler_api = new Sumup_Api_Handler();
	include_once WC_SUMUP_PLUGIN_PATH . '/includes/class-wc-sumup-connection-diagnostics.php';
	new WC_Sumup_Connection_Diagnostics();

	add_filter(
		'woocommerce_checkout_redirect_empty_cart',
		static function ( $redirect ) {
			// Validate payment returns before WooCommerce redirects an empty order-pay cart.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only defers the empty-cart redirect; check_redirect_flow independently authorizes and verifies the payment return.
			return isset( $_GET['sumup-validate-order'] ) ? false : $redirect;
		}
	);

	function sumup_add_gateways( $methods ) {
		$methods[] = 'WC_Gateway_Sumup';

		return $methods;
	}

	add_filter( 'woocommerce_payment_gateways', 'sumup_add_gateways' );
}

add_action( 'plugins_loaded', 'sumup_payment_gateway_for_woocommerce_init' );

/**
 * Admin notice to PHP check version fail
 *
 * @since 2.0
 * @return void
 */
function sumup_payment_admin_notice_php_version_fail() {
	$message = sprintf(
		/* translators: 1: plugin name, 2: minimum PHP version. */
		esc_html__( '%1$s requires PHP version %2$s or greater.', 'sumup-payment-gateway-for-woocommerce' ),
		'<strong>SumUp Payment Gateway For WooCommerce</strong>',
		WC_SUMUP_MINIMUM_PHP_VERSION
	);

	$html_message = sprintf( '<div class="notice notice-error"><p>%1$s</p></div>', $message );

	echo wp_kses_post( $html_message );
}

/**
 * Admin notice to WP version check fail
 *
 * @since 2.0
 * @return void
 */
function sumup_payment_admin_notice_wp_version_fail() {
	$message = sprintf(
		/* translators: 1: plugin name, 2: minimum WordPress version. */
		esc_html__( '%1$s requires WordPress version %2$s or greater.', 'sumup-payment-gateway-for-woocommerce' ),
		'<strong>SumUp Payment Gateway For WooCommerce</strong>',
		WC_SUMUP_MINIMUM_WP_VERSION
	);

	$html_message = sprintf( '<div class="notice notice-error"><p>%1$s</p></div>', $message );

	echo wp_kses_post( $html_message );
}

/**
 * Add admin scripts (JS and CSS)
 */
function sumup_enqueue_admin_scripts() {
	$settings_asset = sumup_get_build_asset_metadata( 'build/settings.asset.php' );
	wp_register_script( 'sumup-settings', WC_SUMUP_PLUGIN_URL . 'build/settings.js', $settings_asset['dependencies'], $settings_asset['version'], true );
	wp_localize_script(
		'sumup-settings',
		'sumup_settings_ajax',
		array(
			'ajax_url'                => admin_url( 'admin-ajax.php' ),
			'nonce'                   => wp_create_nonce( 'sumup-settings-nonce' ),
			'rest_api_url_disconnect' => rest_url( 'sumup_disconnection/v1/disconnect' ),
			'rest_nonce'              => wp_create_nonce( 'wp_rest' ),
			'return_url'              => add_query_arg(
				'validate_settings',
				'true',
				sumup_get_gateway_setup_link()
			),
			'flow_version'            => 2,
			'is_public_hostname'      => sumup_is_public_hostname(),
			'onboarding_host_warning' => sumup_get_onboarding_host_warning_message(),
			'messages'                => array(
				'connect_error'               => __( 'Unable to start the SumUp connection.', 'sumup-payment-gateway-for-woocommerce' ),
				'connect_validation_error'    => __( 'Unable to validate this website.', 'sumup-payment-gateway-for-woocommerce' ),
				'connect_retry_error'         => __( 'Unable to start the SumUp connection. Please try again.', 'sumup-payment-gateway-for-woocommerce' ),
				'google_pay_error'            => __( 'Unable to continue with Google Pay. Please try again.', 'sumup-payment-gateway-for-woocommerce' ),
				'google_pay_registered'       => __( 'Your domain has been registered with SumUp for Google Pay.', 'sumup-payment-gateway-for-woocommerce' ),
				'google_pay_registered_label' => __( 'Registered with SumUp', 'sumup-payment-gateway-for-woocommerce' ),
				'disconnect_error'            => __( 'Unable to disconnect the SumUp account.', 'sumup-payment-gateway-for-woocommerce' ),
				'unknown_error'               => __( 'Unknown error occurred.', 'sumup-payment-gateway-for-woocommerce' ),
			),
		)
	);

	wp_register_style( 'sumup-settings', WC_SUMUP_PLUGIN_URL . 'build/settings.css', array(), $settings_asset['version'] );
}

add_action( 'admin_enqueue_scripts', 'sumup_enqueue_admin_scripts', 10 );

add_action( 'woocommerce_blocks_loaded', 'woocommerce_gateway_sumup_woocommerce_block_support' );

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Preserve the published callback identity so integrations can still call or remove it.
function woocommerce_gateway_sumup_woocommerce_block_support() {
	sumup_woocommerce_block_support();
}

function sumup_woocommerce_block_support() {

	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}

	// here we're including our "gateway block support class"
	require_once __DIR__ . '/includes/class-wc-sumup-block-gateway.php';

	// registering the PHP class we have just included
	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function ( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
			$payment_method_registry->register( new WC_Sumup_Blocks_Support() );
		}
	);
}

add_action( 'before_woocommerce_init', 'sumup_cart_checkout_blocks_compatibility' );

function sumup_declare_woocommerce_compatibility() {
	if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		return;
	}

	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
		'cart_checkout_blocks',
		__FILE__,
		true
	);
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
		'custom_order_tables',
		__FILE__,
		true
	);
}

function sumup_get_build_asset_metadata( $relative_asset_path, $fallback_dependencies = array() ) {
	$normalized_asset_path = ltrim( $relative_asset_path, '/' );
	$build_directory       = realpath( WC_SUMUP_PLUGIN_PATH . '/build' );
	$asset_path            = realpath( WC_SUMUP_PLUGIN_PATH . '/' . $normalized_asset_path );
	$asset_data            = array();

	if (
		$build_directory &&
		$asset_path &&
		0 === strpos( $asset_path, $build_directory . DIRECTORY_SEPARATOR ) &&
		preg_match( '/\.asset\.php$/', $normalized_asset_path )
	) {
		$asset_data = include $asset_path;
	}

	if ( ! is_array( $asset_data ) ) {
		$asset_data = array();
	}

	return array(
		'dependencies' => array_values(
			array_unique(
				array_merge(
					$fallback_dependencies,
					isset( $asset_data['dependencies'] ) ? (array) $asset_data['dependencies'] : array()
				)
			)
		),
		'version'      => isset( $asset_data['version'] ) ? $asset_data['version'] : WC_SUMUP_VERSION,
	);
}

function sumup_cart_checkout_blocks_compatibility() {
	sumup_declare_woocommerce_compatibility();
}

function sumup_gateway_load_textdomain() {
	global $wp_textdomain_registry, $l10n;
	$domain = 'sumup-payment-gateway-for-woocommerce';
	// Register bundled catalogs as the fallback after WordPress.org language packs.
	$wp_textdomain_registry->set_custom_path( $domain, WC_SUMUP_PLUGIN_PATH . '/languages' );
	if ( isset( $l10n[ $domain ] ) && $l10n[ $domain ] instanceof NOOP_Translations ) {
		unset( $l10n[ $domain ] );
	}
}

add_action( 'plugins_loaded', 'sumup_gateway_load_textdomain' );

function sumup_add_privacy_policy_content() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return; }
	wp_add_privacy_policy_content(
		'SumUp',
		wp_kses_post(
			wpautop(
				__(
					'When you choose SumUp, payment and billing details are sent to SumUp to process your payment and prevent fraud. The payment widget connects directly to SumUp and may process browser and network information. Card numbers and security codes are handled by SumUp, not stored by this plugin. Orders retain payment identifiers and, where applicable, pending payment instructions. When logging is enabled, WooCommerce logs include order, checkout and transaction identifiers. Review your WooCommerce retention settings and the SumUp Privacy Policy when preparing your store privacy notice.',
					'sumup-payment-gateway-for-woocommerce'
				)
			)
		)
	);
}
add_action( 'admin_init', 'sumup_add_privacy_policy_content' );
