<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'sumup_connection/v1',
			'connect',
			array(
				'methods'             => array( 'POST' ),
				'callback'            => 'sumup_connect',
				'permission_callback' => '__return_true',
			)
		);
	}
);


/**
 * Connect endpoint
 */
function sumup_connect( $request ) {
	$result = WC_Sumup_Connection::accept( json_decode( $request->get_body(), true ) );
	if ( is_wp_error( $result ) ) {
		return new WP_REST_Response(
			array(
				'status'  => 'error',
				'message' => $result->get_error_message(),
			),
			400
		);
	}
	return new WP_REST_Response( $result, 200 );
}
