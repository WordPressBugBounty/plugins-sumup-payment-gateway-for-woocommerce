<?php
/**
 * Plugin Name: SumUp Payment Gateway For WooCommerce
 * Plugin URI: https://wordpress.org/plugins/sumup-payment-gateway-for-woocommerce/
 * Description: Take credit card payments on your store using SumUp.
 * Author: SumUp
 * Author URI: https://sumup.com
 * Version: 2.7.4
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * Text Domain: sumup-payment-gateway-for-woocommerce
 * Domain Path: /languages
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WC_SUMUP_MAIN_FILE', __FILE__ );
define( 'WC_SUMUP_PLUGIN_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'WC_SUMUP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WC_SUMUP_VERSION', '2.7.4' );
define( 'WC_SUMUP_MINIMUM_PHP_VERSION', '7.2' );
define( 'WC_SUMUP_MINIMUM_WP_VERSION', '5.0' );
define( 'WC_SUMUP_PLUGIN_SLUG', 'sumup-payment-gateway-for-woocommerce' );

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
	function plugin_row_meta( $links, $file ) {
		if ( plugin_basename( __FILE__ ) === $file ) {
			$row_meta = array(
				'docs'    => '<a href="https://developer.sumup.com">' . esc_html__( 'Docs', 'sumup-payment-gateway-for-woocommerce' ) . '</a>',
			);
			return array_merge( $links, $row_meta );
		}
		return (array) $links;
	}

	add_filter( 'plugin_row_meta', 'plugin_row_meta', 10, 2 );

	/**
	 * Display admin notice when WooCommerce is not installed.
	 *
	 * @since    1.0.0
	 * @version  1.0.0
	 */
	if ( ! class_exists( 'WooCommerce' ) ) {
		function sumup_missing_wc_notice() {
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
	function get_sumup_gateway_setup_link() {
		$validate_settings_param = Wc_Sumup_Credentials::validate() ? "true" : "false";
	return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=sumup&validate_settings=' . $validate_settings_param );
	}

	/**
	 * Display admin notice if the plugin is not configured successfully.
	 *
	 * @since    1.0.0
	 * @version  1.0.0
	 */
	function plugin_not_configured_notice() {
		add_option('sumup_valid_currency',true);
		$plugin_options          = get_option( 'woocommerce_sumup_settings' );
		$plugin_enabled          = isset( $plugin_options['enabled'] ) && 'yes' === $plugin_options['enabled'];
		$is_plugin_configured    = get_option( 'sumup_valid_credentials', 'not_configured' );
		$is_plugin_settings_page = !empty( $_GET['page'] ) && $_GET['page'] === 'wc-settings' && !empty( $_GET['tab'] ) && $_GET['tab'] === 'checkout' && !empty( $_GET['section'] ) && $_GET['section'] === 'sumup';
		$is_valid_currency_configured = get_option('sumup_valid_currency');

		if ( $is_plugin_configured === 'not_configured' ) {
			/* translators: %s = admin.php?page=wc-settings&tab=checkout&section=sumup */
			echo '<div class="notice notice-warning"><p><strong>' . sprintf( __( 'SumUp Gateway is almost ready. To get started, <a href="%s">set your SumUp account keys</a>.', 'sumup-payment-gateway-for-woocommerce' ), get_sumup_gateway_setup_link() ) . '</strong></p></div>';

			return; /* don't display other notices about configurations */
		}

		if ( $plugin_enabled && !$is_plugin_configured && !$is_plugin_settings_page ) {
			/* translators: %s = admin.php?page=wc-settings&tab=checkout&section=sumup */
			echo '<div class="notice notice-error"><p><strong>' . sprintf( __( 'SumUp Gateway is not configured properly. You can fix this from <a href="%s">here</a>.', 'sumup-payment-gateway-for-woocommerce' ), get_sumup_gateway_setup_link() ) . '</strong></p></div>';
		}

		if ( $plugin_enabled && ! $is_valid_currency_configured ) {
			echo '<div class="notice notice-warning"><p><strong>' . __( 'SumUp Gateway needs your attention. Currency is different from WooCommerce currency (WooCommerce->Settings->General->Currency).', 'sumup-payment-gateway-for-woocommerce' ) . '</strong></p></div>';
		}

		if ( isset( $plugin_options['merchant_id'] ) && empty( $plugin_options['merchant_id'] ) ) {
			$message = sprintf(
				'<div class="notice notice-error"><p>%1$s</p></div>',
				__( 'Please use the “Connect Account” button to start the configuration.', 'sumup-payment-gateway-for-woocommerce' )
			);

			echo wp_kses_post( $message );
		}

		return;
	}

	add_action( 'admin_notices', 'plugin_not_configured_notice' );

	/**
	 * Display links beneath the plugin's name
	 *
	 * @since    1.0.0
	 * @version  1.0.0
	 */
	function plugin_action_links( $links ) {
		$plugin_links = array(
			'<a href="' . get_sumup_gateway_setup_link() . '">' . esc_html__( 'Settings', 'sumup-payment-gateway-for-woocommerce' ) . '</a>',
		);
		return array_merge( $plugin_links, $links );
	}

	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'plugin_action_links' );

	include_once WC_SUMUP_PLUGIN_PATH . '/includes/class-wc-sumup-logger.php';
	include_once WC_SUMUP_PLUGIN_PATH . '/includes/class-wc-sumup-gateway.php';
	include_once WC_SUMUP_PLUGIN_PATH . '/includes/class-wc-sumup-access-token.php';
	include_once WC_SUMUP_PLUGIN_PATH . '/includes/class-wc-sumup-checkout.php';
	include_once WC_SUMUP_PLUGIN_PATH . '/includes/class-wc-sumup-credentials.php';

	include_once WC_SUMUP_PLUGIN_PATH . '/includes/api/class-sumup-validate.php';
	include_once WC_SUMUP_PLUGIN_PATH . '/includes/api/class-sumup-connect.php';
	include_once WC_SUMUP_PLUGIN_PATH . '/includes/api/class-sumup-disconnect.php';
	include_once WC_SUMUP_PLUGIN_PATH . '/includes/class-wc-sumup-onboarding.php';
	include_once WC_SUMUP_PLUGIN_PATH . '/includes/api/class-sumup-api-handler.php';

	$sumup_onbording = new WC_Sumup_Onboarding();
	$sumup_onbording->init_ajax_request();

	$sumup_handler_api = new Sumup_Api_Handler();

	function add_gateways( $methods ) {
		$methods[] = 'WC_Gateway_Sumup';

		return $methods;
	}

	add_filter( 'woocommerce_payment_gateways', 'add_gateways' );
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
	wp_register_script( 'sumup-settings', WC_SUMUP_PLUGIN_URL . 'assets/js/settings.min.js', array(), WC_SUMUP_VERSION, true );
	wp_localize_script( 'sumup-settings',
		'sumup_settings_ajax',
		array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'sumup-settings-nonce' ),
			'rest_api_url_disconnect' => home_url( "wp-json/sumup_disconnection/v1/disconnect")
		)
	);

	wp_register_style( 'sumup-settings', WC_SUMUP_PLUGIN_URL . 'assets/css/admin/settings.min.css', array(), WC_SUMUP_VERSION );
}

add_action( 'admin_enqueue_scripts', 'sumup_enqueue_admin_scripts', 10 );

add_action( 'woocommerce_blocks_loaded', 'woocommerce_gateway_sumup_woocommerce_block_support' );


function sumup_enqueue_scripts() {
	wp_enqueue_style( 'sumup-checkout', WC_SUMUP_PLUGIN_URL. 'assets/css/checkout/modal.min.css', array(), WC_SUMUP_VERSION );
}

add_action('wp_enqueue_scripts','sumup_enqueue_scripts');
function woocommerce_gateway_sumup_woocommerce_block_support() {

	 if( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
	 	return;
	 }

	// here we're including our "gateway block support class"
	require_once __DIR__ . '/includes/class-wc-sumup-block-gateway.php';

	// registering the PHP class we have just included
	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
			$payment_method_registry->register( new WC_Sumup_Blocks_Support );
		}
	);

}

add_action( 'before_woocommerce_init', 'sumup_cart_checkout_blocks_compatibility' );

function sumup_cart_checkout_blocks_compatibility() {

    if( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				__FILE__,
				false // true (compatible, default) or false (not compatible)
			);
    }

}

function sumup_gateway_load_textdomain() {
    load_plugin_textdomain( 'sumup-payment-gateway-for-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}

add_action( 'plugins_loaded', 'sumup_gateway_load_textdomain' );

