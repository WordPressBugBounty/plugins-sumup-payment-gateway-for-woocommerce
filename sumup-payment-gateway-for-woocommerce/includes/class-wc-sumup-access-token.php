<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class to create SumUp access token
 *
 * @return array
 */
class Wc_Sumup_Access_Token {
	/**
	 * Get access token
	 *
	 * @param string $client_id
	 * @param string $client_secret
	 * @param string $api_key
	 *
	 * @return array
	 */
	public static function get( $client_id = '', $client_secret = '', $api_key = '', $force=false ) {
		/**
		 * After start using api_key we add this to prevent that any other proccess break for users that still use access token.
		 * This needs to be refactored when is possible
		 */
		if ( ! empty( $api_key ) ) {
			return array(
				'access_token' => $api_key,
			);
		}

		// Try to get the transient for access token.
		$access_token = get_transient('sumup_access_token');
		if($access_token && !$force){
			return array('access_token' => $access_token);
		}

		$ch = curl_init();
		curl_setopt_array( $ch,
			array(
				CURLOPT_URL => 'https://api.sumup.com/token',
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => http_build_query(
					array(
						'grant_type' => 'client_credentials',
						'client_id' => $client_id,
						'client_secret' => $client_secret,
					),
				),
				CURLOPT_HTTPHEADER => array(
					'Content-Type: application/x-www-form-urlencoded',
				),
			)
		);

		$response = curl_exec( $ch );
		$response_http_code = curl_getinfo($ch,CURLINFO_HTTP_CODE);
		curl_close( $ch );

		$response = json_decode( $response, true );
		if ($response) {
			if ($response_http_code === 200) {
				set_transient(
					'sumup_access_token',
					$response['access_token'],
					$response['expires_in']
				);

				return $response;
			}

			WC_SUMUP_LOGGER::log( 'Error on get access token. Message: ' . json_encode( $response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ));
		}


		return array();
	}
}
