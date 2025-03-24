<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class to validate credentials form settings
 */
class Wc_Sumup_Credentials {
	/**
	 * Attr to store option after time that get options
	 *
	 * @var array
	 */
	public static $integration_settings = array();

	/**
	 * Get SumUp settings from database options
	 *
	 * @return array
	 */
	public static function get_integration_settings() {
		if ( ! empty( self::$integration_settings ) ) {
			return self::$integration_settings;
		}

		$sumup_settings = get_option( 'woocommerce_sumup_settings' );
		self::$integration_settings = array(
			'client_id' => $sumup_settings['client_id'] ?? '',
			'client_secret' => $sumup_settings['client_secret'] ?? '',
			'api_key' => $sumup_settings['api_key'] ?? '',
			'merchant_id' => $sumup_settings['merchant_id'] ?? '',
			'pay_to_email' => $sumup_settings['pay_to_email'] ?? '',
		);

		return self::$integration_settings;
	}

	/**
	 * Validate credentials
	 *
	 * @return boolean
	 */
	public static function validate() {
		$settings = self::get_integration_settings();

		$access_token = Wc_Sumup_Access_Token::get( $settings['client_id'], $settings['client_secret'], $settings['api_key'], true );
		if ( ! isset( $access_token['access_token'] ) ) {
			WC_SUMUP_LOGGER::log( 'Error on settings to create access token. Merchant Id: ' . $settings['merchant_id'] );
			update_option( 'sumup_valid_credentials', 0, false );

			return false;
		}

		$checkout_data = array(
			'checkout_reference' => 'WC_SUMUP_SETTINGS_VALIDATE_' . time() . wp_generate_uuid4(),
			'amount' => 1.00,
			'currency' => get_woocommerce_currency(),
			'description' => 'WooCommerce settings validate ' . time(),
			'merchant_code' => $settings['merchant_id'],
			'pay_to_email' => $settings['pay_to_email'],
			'redirect_url' => wc_get_checkout_url(),
			'return_url' => wc_get_checkout_url(),
		);

		$sumup_checkout = Wc_Sumup_Checkout::create( $access_token['access_token'], $checkout_data );
		if ( empty( $sumup_checkout ) || ! isset( $sumup_checkout['id'] ) ) {
			if ( isset( $sumup_checkout['error_code'] ) ) {
				$error_message = isset( $sumup_checkout['error_message'] ) ? $sumup_checkout['error_message'] : $sumup_checkout['message'] ?? '';
				WC_SUMUP_LOGGER::log( $sumup_checkout['error_code'] . ': ' . $error_message );
				update_option( 'sumup_valid_credentials', 0, false );

				if ( isset( $sumup_checkout['message'] ) && (strpos( $sumup_checkout['message'], 'payments' ) || $sumup_checkout['error_code'] == 'INSUFFICIENT_SCOPES') ) {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Missing scope: payments. Please contact support team.', 'sumup-payment-gateway-for-woocommerce' ) . '</p></div>';

					echo '<div class="notice notice-error"><p>' . esc_html__( 'Credentials are not valid. Please check the plugin log and try again.', 'sumup-payment-gateway-for-woocommerce' ) . '</p></div>';

					return false;
				}

				if (isset($sumup_checkout['message']) && $sumup_checkout['error_code'] == "INVALID" && $sumup_checkout['param'] == "currency") {
					update_option( 'sumup_valid_currency', 0, true );
					update_option( 'sumup_valid_credentials', 1, false );
					return true;
				}

				if ($sumup_checkout['error_code'] == "DUPLICATED_CHECKOUT" ) {
					update_option( 'sumup_valid_currency', 1, true );
					update_option( 'sumup_valid_credentials', 1, false );
					return true;
				}

				return false;
			}

			return false;
		}

		update_option( 'sumup_valid_credentials', 1, false );
		update_option( 'sumup_valid_currency', 1, true );

		return true;
	}

}
