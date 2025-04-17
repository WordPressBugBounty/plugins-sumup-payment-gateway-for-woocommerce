<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

//TODO - Adicionar timeout na requisição.
add_action('rest_api_init', function () {
	register_rest_route( 'sumup_connection/v1', 'validate', array(
		'methods'  => 'GET',
		'callback' => 'sumup_validate_website',
		'permission_callback' => '__return_true',
	));
});

/**
 * Validate endpoint
 */
function sumup_validate_website( $request ) {
	WC_SUMUP_LOGGER::log( "Sending validate website");
	$reponse_body = array( 'status' => 'valid website' );
	$response = new WP_REST_Response( $reponse_body );
	$response->set_status( 200 );
	return $response;
}
