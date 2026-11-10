<?php
/**
 * Shared widget configuration for classic and Blocks checkout.
 *
 * @package SumUp
 */

defined( 'ABSPATH' ) || exit;

final class WC_Sumup_Widget_Settings {

	public static function get( $settings ) {
		$country      = WC()->countries->get_base_country();
		$installments = 'BR' === $country ? (int) ( $settings['number_of_installments'] ?? 0 ) : 0;
		return array(
			'googlePay'                => sumup_get_google_pay_config(),
			'showZipCode'              => 'US' === $country,
			'showInstallments'         => 'BR' === $country && 'yes' === ( $settings['enable_installments'] ?? 'no' ),
			'maxInstallments'          => $installments,
			'locale'                   => WC_Sumup_I18n::widget_locale( get_locale() ),
			'currency'                 => get_woocommerce_currency(),
			'country'                  => '',
			'firstName'                => '',
			'lastName'                 => '',
			'email'                    => '',
			'phoneNumber'              => '',
			'city'                     => '',
			'street'                   => '',
			'streetNumber'             => '',
			'postalCode'               => '',
			'stateName'                => '',
			'orderId'                  => 0,
			'orderKey'                 => '',
			'status'                   => '',
			'paymentMethod'            => '',
			'redirectUrl'              => '',
			'paymentInstructionsNonce' => wp_create_nonce( 'sumup-store-payment-instructions' ),
			'validateRedirectNonce'    => wp_create_nonce( 'sumup-validate-redirect' ),
			'sumup_handler_url'        => add_query_arg( 'wc-api', 'sumup_api_handler', home_url( '/' ) ),
			'enablePix'                => $settings['enable_pix'] ?? 'no',
			'enableApplePay'           => Wc_Sumup_Apple_Pay::should_offer_apple_pay( $settings ) ? 'yes' : 'no',
			'enableGooglePay'          => sumup_should_offer_google_pay( $settings ) ? 'yes' : 'no',
			'openPaymentInModal'       => $settings['open_payment_modal'] ?? 'yes',
			'modal'                    => array(
				'label' => __( 'SumUp payment', 'sumup-payment-gateway-for-woocommerce' ),
				'close' => __( 'Close payment modal', 'sumup-payment-gateway-for-woocommerce' ),
			),
			'errors'                   => array(
				'general_error'       => __( 'Transaction was unsuccessful. Please check the minimum amount or use another valid card.', 'sumup-payment-gateway-for-woocommerce' ),
				'invalid_form'        => __( 'Fill in all required details.', 'sumup-payment-gateway-for-woocommerce' ),
				'instructions_error'  => __( 'Unable to verify payment status. Please try again.', 'sumup-payment-gateway-for-woocommerce' ),
				'stale_checkout'      => __( 'Order validation failed. Refresh checkout and try again.', 'sumup-payment-gateway-for-woocommerce' ),
				'payment_failed'      => __( 'Payment failed, please try again.', 'sumup-payment-gateway-for-woocommerce' ),
				'payment_pending'     => __( 'We are still waiting for SumUp to confirm this payment. Please wait a moment and refresh the page if the order is not updated automatically.', 'sumup-payment-gateway-for-woocommerce' ),
				'payment_cancelled'   => __( 'Payment was cancelled.', 'sumup-payment-gateway-for-woocommerce' ),
				'checkout_init_error' => __( 'Unable to initialize SumUp checkout.', 'sumup-payment-gateway-for-woocommerce' ),
				'payment_error'       => '',
			),
		);
	}
}
