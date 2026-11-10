<?php
/**
 * SumUp checkout requests through the WordPress HTTP API.
 *
 * @package SumUp
 */

defined( 'ABSPATH' ) || exit;

class Wc_Sumup_Checkout {
	private const REQUEST_TIMEOUT = 30;

	private static function build_log_context( $signup_data, $log_context = array() ) {
		$context = is_array( $log_context ) ? $log_context : array();

		if ( ! is_array( $signup_data ) ) {
			return $context;
		}

		if ( isset( $signup_data['checkout_reference'] ) ) {
			$context['checkout_reference'] = sanitize_text_field( (string) $signup_data['checkout_reference'] );
		}

		if ( isset( $signup_data['amount'] ) ) {
			$context['amount'] = (string) $signup_data['amount'];
		}

		if ( isset( $signup_data['currency'] ) ) {
			$context['currency'] = sanitize_text_field( (string) $signup_data['currency'] );
		}

		if ( isset( $signup_data['_sumup_context'] ) && is_array( $signup_data['_sumup_context'] ) ) {
			$local_context = $signup_data['_sumup_context'];

			if ( ! empty( $local_context['order_id'] ) ) {
				$context['order_id'] = absint( $local_context['order_id'] );
			}

			if ( ! empty( $local_context['flow'] ) ) {
				$context['flow'] = sanitize_text_field( (string) $local_context['flow'] );
			}
		}

		return $context;
	}


	public static function create( $access_token = '', $signup_data = array(), $log_context = array() ) {
		$body = is_array( $signup_data ) ? $signup_data : array();
		unset( $body['_sumup_context'] );
		return self::request( 'POST', '', $access_token, $body, self::build_log_context( $signup_data, $log_context ) );
	}

	public static function update( $checkout_id = '', $access_token = '', $checkout_data = array(), $log_context = array() ) {
		$checkout_id = sanitize_text_field( (string) $checkout_id );
		$body        = is_array( $checkout_data ) ? array_intersect_key(
			$checkout_data,
			array_flip(
				array(
					'amount',
					'currency',
					'description',
					'checkout_reference',
					'valid_until',
					'customer_id',
				)
			)
		) : array();
		if ( empty( $checkout_id ) || empty( $body ) ) {
			return array();
		}
		return self::request( 'PATCH', $checkout_id, $access_token, $body, self::build_log_context( $checkout_data, $log_context ) );
	}

	public static function get( $checkout_id = '', $access_token = '', $log_context = array() ) {
		if ( empty( $checkout_id ) ) {
			return array();
		}
		$response = self::request( 'GET', $checkout_id, $access_token, null, $log_context );
		// Preserve the public lookup contract: failed reads never look like checkout data.
		return isset( $response['error_code'] ) ? array() : $response;
	}

	/** One transport, response parser, and error policy for all checkout operations. */
	private static function request( $method, $checkout_id, $access_token, $body, $context ) {
		if ( empty( $access_token ) ) {
			return array();
		}
		$context           = is_array( $context ) ? $context : array();
		$context['method'] = $method;
		if ( '' !== $checkout_id ) {
			$context['checkout_id'] = sanitize_text_field( (string) $checkout_id );
		}
		$args = array(
			'method'      => $method,
			'timeout'     => self::REQUEST_TIMEOUT,
			'redirection' => 0,
			'headers'     => sumup_get_api_request_headers( array(), $access_token ),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$url  = 'https://api.sumup.com/v0.1/checkouts' . ( '' !== $checkout_id ? '/' . rawurlencode( $checkout_id ) : '' );
		$http = wp_remote_request( $url, $args );
		if ( is_wp_error( $http ) ) {
			$context['error'] = sanitize_text_field( $http->get_error_message() );
			WC_SUMUP_LOGGER::log( 'SumUp checkout request failed.', $context, 'error' );
			return array();
		}
		$code                       = wp_remote_retrieve_response_code( $http );
		$response                   = json_decode( wp_remote_retrieve_body( $http ), true );
		$success                    = in_array( $code, 'POST' === $method ? array( 200, 201 ) : array( 200 ), true );
		$context['http_code']       = $code;
		$context['checkout_status'] = is_array( $response ) ? ( $response['status'] ?? '' ) : '';
		$message                    = is_array( $response ) && isset( $response['message'] ) ? sanitize_text_field( $response['message'] ) : '';
		if ( '' !== $message ) {
			$context['error'] = $message;
		}
		WC_SUMUP_LOGGER::log( 'SumUp checkout request completed.', $context, $success ? 'info' : 'warning' );
		if ( $success && is_array( $response ) ) {
			return $response;
		}
		if ( '' !== $message ) {
			return array(
				'message'    => $message,
				'param'      => isset( $response['param'] ) ? sanitize_text_field( $response['param'] ) : '',
				'error_code' => isset( $response['error_code'] ) ? sanitize_text_field( $response['error_code'] ) : ( 'PATCH' === $method ? 'sumup_checkout_update_error' : 'sumup_checkout_error' ),
			);
		}
		return array();
	}
}
