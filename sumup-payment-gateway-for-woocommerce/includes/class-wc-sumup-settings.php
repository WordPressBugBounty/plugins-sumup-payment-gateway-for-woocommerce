<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Administration settings configuration
 *
 * @since    1.0.0
 * @version  1.0.0
 */
$sumup_fields = array(
	'connection_section'     => array(
		'title'       => __( 'Connection', 'sumup-payment-gateway-for-woocommerce' ),
		'type'        => 'title',
		'description' => __( 'Manage the SumUp account connected to this store.', 'sumup-payment-gateway-for-woocommerce' ),
	),
	'connection_summary'     => array(
		'type' => 'sumup_connection_summary',
	),
	'connection_actions'     => array(
		'type' => 'sumup_connection_actions',
	),
	'google_pay_merchant_id' => array(
		'title'       => __( 'Google Pay merchant ID', 'sumup-payment-gateway-for-woocommerce' ),
		'type'        => 'text',
		'default'     => '',
		'description' => __( 'Optional. Leave blank to use the SumUp widget defaults. To use your own Google Pay merchant ID, enter the ID issued by Google, separate from your SumUp merchant code. Your store name will then appear on the Google Pay payment sheet. Wallet domain onboarding is still required.', 'sumup-payment-gateway-for-woocommerce' ),
	),
	'checkout_section'       => array(
		'title' => __( 'Checkout', 'sumup-payment-gateway-for-woocommerce' ),
		'type'  => 'title',
	),
	'enabled'                => array(
		'title'       => __( 'Enable/Disable', 'sumup-payment-gateway-for-woocommerce' ),
		'label'       => __( 'Enable SumUp', 'sumup-payment-gateway-for-woocommerce' ),
		'type'        => 'checkbox',
		'description' => '',
		'default'     => 'no',
	),
	'title'                  => array(
		'title'       => __( 'Title', 'sumup-payment-gateway-for-woocommerce' ),
		'type'        => 'text',
		'description' => __( 'This controls the title the user sees during checkout.', 'sumup-payment-gateway-for-woocommerce' ),
		'default'     => __( 'Credit / Debit Card', 'sumup-payment-gateway-for-woocommerce' ),
		'desc_tip'    => true,
	),
	'description'            => array(
		'title'       => __( 'Description', 'sumup-payment-gateway-for-woocommerce' ),
		'type'        => 'text',
		'description' => __( 'This controls the description the user sees during checkout.', 'sumup-payment-gateway-for-woocommerce' ),
		'default'     => __( 'Pay securely with your credit or debit card, or use other available payment methods.', 'sumup-payment-gateway-for-woocommerce' ),
		'desc_tip'    => true,
	),
	'enable_google_pay'      => array(
		'title'       => __( 'Enable Google Pay', 'sumup-payment-gateway-for-woocommerce' ),
		'label'       => __( 'Enable Google Pay', 'sumup-payment-gateway-for-woocommerce' ),
		'type'        => 'checkbox',
		'description' => __( 'Offer Google Pay when eligible. Complete domain setup below before accepting payments.', 'sumup-payment-gateway-for-woocommerce' ),
		'default'     => 'yes',
		'desc_tip'    => true,
	),
	'google_pay_setup'       => array(
		'type' => 'sumup_google_pay_setup',
	),
);

$sumup_fields['enable_apple_pay_domain_registration'] = array(
	'title'       => __( 'Apple Pay', 'sumup-payment-gateway-for-woocommerce' ),
	'label'       => __( 'Offer Apple Pay', 'sumup-payment-gateway-for-woocommerce' ),
	'type'        => 'checkbox',
	'description' => __( 'Enable Apple Pay at checkout.', 'sumup-payment-gateway-for-woocommerce' ),
	'default'     => 'no',
	'desc_tip'    => true,
);

$sumup_fields['open_payment_modal'] = array(
	'title'       => __( 'Open Payment in modal?', 'sumup-payment-gateway-for-woocommerce' ),
	'label'       => __( 'Yes', 'sumup-payment-gateway-for-woocommerce' ),
	'type'        => 'checkbox',
	'description' => __( 'Open the Payment options inside a modal (popup)', 'sumup-payment-gateway-for-woocommerce' ),
	'default'     => 'yes',
	'desc_tip'    => true,
);

$sumup_fields['logging'] = array(
	'title'       => __( 'Logging', 'sumup-payment-gateway-for-woocommerce' ),
	'label'       => __( 'Log debug messages', 'sumup-payment-gateway-for-woocommerce' ),
	'type'        => 'checkbox',
	'description' => __( 'Save debug messages to the WooCommerce System Status log.', 'sumup-payment-gateway-for-woocommerce' ),
	'default'     => 'no',
	'desc_tip'    => true,
);

/**
 * Just add it if store is based on BR
 *
 * @since 2.0
 */
$sumup_shop_base_country = isset( WC()->countries ) ? WC()->countries->get_base_country() : '';
if ( $sumup_shop_base_country === 'BR' ) {
	$sumup_fields['payment_options_section'] = array(
		'title' => __( 'Payment options', 'sumup-payment-gateway-for-woocommerce' ),
		'type'  => 'title',
	);

	$sumup_fields['enable_installments'] = array(
		'title'       => __( 'Installments', 'sumup-payment-gateway-for-woocommerce' ),
		'label'       => __( 'Enable Installments?', 'sumup-payment-gateway-for-woocommerce' ),
		'type'        => 'checkbox',
		'description' => __( 'Accept installments in your card payment method.', 'sumup-payment-gateway-for-woocommerce' ),
		'default'     => 'yes',
		'desc_tip'    => true,
	);

	$sumup_fields['number_of_installments'] = array(
		'title'       => __( 'Number of Installments', 'sumup-payment-gateway-for-woocommerce' ),
		'type'        => 'select',
		'description' => __( 'Define the number of installments you will accept.', 'sumup-payment-gateway-for-woocommerce' ),
		'options'     => array(
			'select' => 'Select',
			'1'      => '1',
			'2'      => '2',
			'3'      => '3',
			'4'      => '4',
			'5'      => '5',
			'6'      => '6',
			'7'      => '7',
			'8'      => '8',
			'9'      => '9',
			'10'     => '10',
			'11'     => '11',
			'12'     => '12',
		),
		'default'     => '10',
		'desc_tip'    => true,
	);

	$sumup_fields['enable_pix'] = array(
		'title'       => __( 'Enable PIX?', 'sumup-payment-gateway-for-woocommerce' ),
		'label'       => __( 'Enable', 'sumup-payment-gateway-for-woocommerce' ),
		'type'        => 'checkbox',
		'description' => __( 'Accept PIX as your payment method.', 'sumup-payment-gateway-for-woocommerce' ),
		'default'     => 'yes',
		'desc_tip'    => true,
	);
}

$sumup_logging = $sumup_fields['logging'];
unset( $sumup_fields['logging'] );

$sumup_fields['diagnostics_section'] = array(
	'title' => __( 'Diagnostics', 'sumup-payment-gateway-for-woocommerce' ),
	'type'  => 'title',
);

$sumup_fields['logging'] = $sumup_logging;

return apply_filters( 'sumup_gateway_settings', $sumup_fields );
