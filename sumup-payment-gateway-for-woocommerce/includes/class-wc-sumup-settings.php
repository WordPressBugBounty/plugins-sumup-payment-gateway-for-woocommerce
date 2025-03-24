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
$fields = array(
	'enabled' => array(
		'title'       => __( 'Enable/Disable', 'sumup-payment-gateway-for-woocommerce' ),
		'label'       => __( 'Enable SumUp', 'sumup-payment-gateway-for-woocommerce' ),
		'type'        => 'checkbox',
		'description' => '',
		'default'     => 'no',
	),
	'title' => array(
		'title'       => __( 'Title', 'sumup-payment-gateway-for-woocommerce' ),
		'type'        => 'text',
		'description' => __( 'This controls the title the user sees during checkout.', 'sumup-payment-gateway-for-woocommerce' ),
		'default'     => __( 'Pay with SumUp', 'sumup-payment-gateway-for-woocommerce' ),
		'desc_tip'    => true,
	),
	'description' => array(
		'title'       => __( 'Description', 'sumup-payment-gateway-for-woocommerce' ),
		'type'        => 'text',
		'description' => __( 'This controls the description the user sees during checkout.', 'sumup-payment-gateway-for-woocommerce' ),
		'default'     => __( "You can choose how you'd like to pay after you place your order.", 'sumup-payment-gateway-for-woocommerce' ),
		'desc_tip'    => true,
	),
	'logging' => array(
		'title'       => __( 'Logging', 'sumup-payment-gateway-for-woocommerce' ),
		'label'       => __( 'Log debug messages', 'sumup-payment-gateway-for-woocommerce' ),
		'type'        => 'checkbox',
		'description' => __( 'Save debug messages to the WooCommerce System Status log.', 'sumup-payment-gateway-for-woocommerce' ),
		'default'     => 'yes',
		'desc_tip'    => true,
	),
);

/**
 * Just add it if store is based on BR
 *
 * @since 2.0
 */
$shop_base_country = WC()->countries->get_base_country();
if ( $shop_base_country === 'BR' ) {
	$fields['enable_installments'] = array(
		'title'       => __( 'Installments', 'sumup-payment-gateway-for-woocommerce' ),
		'label'       => __( 'Enable Installments?', 'sumup-payment-gateway-for-woocommerce' ),
		'type'        => 'checkbox',
		'description' => __( 'Accept installments in your card payment method.', 'sumup-payment-gateway-for-woocommerce' ),
		'default'     => 'yes',
		'desc_tip'    => true,
	);

	$fields['number_of_installments'] = array(
		'title'       => __( 'Number of Installments', 'sumup-payment-gateway-for-woocommerce' ),
		'type'        => 'select',
		'description' => __( 'Define the number of installments you will accept.', 'sumup-payment-gateway-for-woocommerce' ),
		'options' 	  => array(
			'select' => 'Select',
			'1' => '1',
			'2' => '2',
			'3' => '3',
			'4' => '4',
			'5' => '5',
			'6' => '6',
			'7' => '7',
			'8' => '8',
			'9' => '9',
			'10' => '10',
			'11' => '11',
			'12' => '12',
		),
		'default'     => '10',
		'desc_tip'    => true,
	);

	$fields['enable_pix'] = array(
		'title'       => __( 'Enable PIX?', 'sumup-payment-gateway-for-woocommerce' ),
		'label'       => __( 'Enable', 'sumup-payment-gateway-for-woocommerce' ),
		'type'        => 'checkbox',
		'description' => __( 'Accept PIX as your payment method.', 'sumup-payment-gateway-for-woocommerce' ),
		'default'     => 'yes',
		'desc_tip'    => true,
	);
}

$fields['open_payment_modal'] = array(
	'title'       => __( 'Open Payment in modal?', 'sumup-payment-gateway-for-woocommerce' ),
	'label'       => __( 'Yes', 'sumup-payment-gateway-for-woocommerce' ),
	'type'        => 'checkbox',
	'description' => __( 'Open the Payment options inside a modal (popup)', 'sumup-payment-gateway-for-woocommerce' ),
	'default'     => 'yes',
	'desc_tip'    => true,
);

return apply_filters( 'sumup_gateway_settings', $fields );
