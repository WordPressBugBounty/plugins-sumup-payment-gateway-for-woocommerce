<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class to create SumUp access token
 *
 * @return array
 */
class Wc_Sumup_Checkout {
	/**
	 * Get access token
	 *
	 * @param string $client_id
	 * @param string $client_secret
	 * @param string $api_key
	 *
	 * @return array
	 */
	public static function create( $access_token = '', $signup_data = array() ) {
		$curl = curl_init();
		curl_setopt_array( $curl,
			array(
				CURLOPT_URL => 'https://api.sumup.com/v0.1/checkouts',
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_ENCODING => '',
				CURLOPT_MAXREDIRS => 10,
				CURLOPT_TIMEOUT => 0,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
				CURLOPT_CUSTOMREQUEST => 'POST',
				CURLOPT_POSTFIELDS => json_encode( $signup_data ),
				CURLOPT_HTTPHEADER => array(
					'Content-Type: application/json',
					'Accept: application/json',
					'Authorization: Bearer ' . $access_token,
				),
			)
		);

		$response = curl_exec( $curl );
		$response_http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close( $curl );

		$response = json_decode($response, true);
		if ($response != false && ($response_http_code === 201 || $response_http_code === 200)) {
			return $response;
		}

		if ($response != false && (isset($response['message']) && $response_http_code === 403)) {
			return $response['message'];
		}

		if ($response != false && (isset($response['message']) && ($response_http_code === 400 || $response_http_code === 409))) {
			return array(
				"message" => $response["message"],
				"param" => isset($response["param"]) ? $response["param"] : '',
				"error_code" => isset($response["error_code"]) ? $response["error_code"] : ''
			);
		}

		return array();
	}

	/**
	 * Get checkout based on ID
	 *
	 * @param string $checkout_id
	 * @param string $access_token
	 *
	 * @return array
	 */
	public static function get( $checkout_id = '', $access_token = '' ) {
		if( empty( $checkout_id ) || empty( $access_token ) ) {
			return array();
		}

		$curl = curl_init();
		curl_setopt_array( $curl,
			array(
				CURLOPT_URL => 'https://api.sumup.com/v0.1/checkouts/' . $checkout_id,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_ENCODING => '',
				CURLOPT_MAXREDIRS => 10,
				CURLOPT_TIMEOUT => 0,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
				CURLOPT_CUSTOMREQUEST => 'GET',
				CURLOPT_HTTPHEADER => array(
					'Accept: application/json',
					'Authorization: Bearer ' . $access_token,
				),
			)
		);

		$response = curl_exec( $curl );
		curl_close( $curl );
		return $response !== false ? json_decode( $response, true ) : array();
	}
}
