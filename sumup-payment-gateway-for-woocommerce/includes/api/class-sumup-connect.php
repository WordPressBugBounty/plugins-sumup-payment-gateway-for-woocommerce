<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action('rest_api_init', function () {
	register_rest_route( 'sumup_connection/v1',
		'connect',
		array(
			'methods'  => array( 'POST' ),
			'callback' => 'sumup_connect',
			'permission_callback' => '__return_true',
		)
	);
});

/**
 * Connect endpoint
 */
function sumup_connect( $request ) {
	$post_data = json_decode( $request->get_body(), true );

	WC_SUMUP_LOGGER::log( "Receive connect data");

	$transient = get_transient('sumup-connection-id-' . sanitize_text_field($post_data['id']));

	if ( empty( $post_data['id'] ) || empty( $transient ) || $post_data['id'] !== $transient ) {
		$reponse_body = array( 'status' => 'error', 'message' => 'Invalid connection ID' );
		$response = new WP_REST_Response( $reponse_body );
		$response->set_status( 400 );
		return $response;
	}

	if ( ! isset( $post_data['merchant']['email'] ) ) {
		$reponse_body = array( 'status' => 'error', 'message' => 'Invalid merchant email' );
		$response = new WP_REST_Response( $reponse_body );
		$response->set_status( 400 );
		return $response;
	}

	if ( ! isset( $post_data['merchant']['api_key'] ) ) {
		$reponse_body = array( 'status' => 'error', 'message' => 'Invalid API key' );
		$response = new WP_REST_Response( $reponse_body );
		$response->set_status( 400 );
		return $response;
	}

	if ( ! isset( $post_data['merchant']['merchant_code'] ) ) {
		$reponse_body = array( 'status' => 'error', 'message' => 'Invalid merchant code' );
		$response = new WP_REST_Response( $reponse_body );
		$response->set_status( 400 );
		return $response;
	}

	$settings = get_option( 'woocommerce_sumup_settings' );
	$settings['pay_to_email'] = $post_data['merchant']['email'];
	$settings['api_key'] = $post_data['merchant']['api_key'];
	$settings['merchant_id'] = $post_data['merchant']['merchant_code'];
	update_option( 'woocommerce_sumup_settings', $settings );

	delete_transient('sumup-connection-id-' . $post_data['id']);

	$reponse_body = array( 'status' => 'connected' );
	$response = new WP_REST_Response( $reponse_body );
	$response->set_status( 200 );
	return $response;
}
