<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compatibility endpoint for pending PIX/Boleto widget responses.
 */
class Sumup_API_Store_Payment_Instructions_Handler extends Sumup_Api_Handler {

	public function __construct() {
		add_filter( 'sumup_api_handlers', array( $this, 'add_handlers' ) );
	}

	public function add_handlers( $handlers ) {
		$handlers['store_payment_instructions'] = array(
			'callback' => array( $this, 'handle' ),
			'method'   => 'POST',
		);

		return $handlers;
	}

	/**
	 * Handle the request.
	 *
	 * @return void
	 */
	public function handle() {
		$post_data = $this->get_request_data();

		if ( ! is_array( $post_data ) ) {
			$this->send_response( 'error', __( 'Invalid request payload.', 'sumup-payment-gateway-for-woocommerce' ), array(), 400 );
		}

		$nonce = is_string( $post_data['nonce'] ?? null ) ? sanitize_text_field( wp_unslash( $post_data['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'sumup-store-payment-instructions' ) ) {
			$this->send_response( 'error', __( 'Invalid request nonce.', 'sumup-payment-gateway-for-woocommerce' ), array(), 403 );
		}

		$order_id = is_scalar( $post_data['order_id'] ?? null ) ? absint( $post_data['order_id'] ) : 0;
		if ( ! $order_id ) {
			$this->send_response( 'error', __( 'Invalid order ID.', 'sumup-payment-gateway-for-woocommerce' ), array(), 400 );
		}

		$order_key = is_string( $post_data['order_key'] ?? null ) ? wc_clean( wp_unslash( $post_data['order_key'] ) ) : '';
		if ( empty( $order_key ) ) {
			$this->send_response( 'error', __( 'Invalid order key.', 'sumup-payment-gateway-for-woocommerce' ), array(), 400 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			$this->send_response( 'error', __( 'Order is not available.', 'sumup-payment-gateway-for-woocommerce' ), array(), 404 );
		}

		if ( ! hash_equals( $order->get_order_key(), $order_key ) ) {
			WC_SUMUP_LOGGER::log( 'Rejected payment instructions storage because order key validation failed. Order ID: ' . $order_id );
			$this->send_response( 'error', __( 'Order validation failed.', 'sumup-payment-gateway-for-woocommerce' ), array(), 403 );
		}

		if ( is_user_logged_in() ) {
			$order_customer_id   = (int) $order->get_customer_id();
			$current_customer_id = get_current_user_id();

			if ( $order_customer_id > 0 && $order_customer_id !== $current_customer_id ) {
				WC_SUMUP_LOGGER::log( 'Rejected payment instructions storage because the order belongs to a different customer. Order ID: ' . $order_id );
				$this->send_response( 'error', __( 'Order validation failed.', 'sumup-payment-gateway-for-woocommerce' ), array(), 403 );
			}
		}

		// A webhook may settle the order before pending instructions arrive.
		if ( $order->is_paid() ) {
			$this->send_response( 'success', '', array( 'stored' => false ) );
		}

		$gateway = $this->get_sumup_gateway( 'sumup' );
		$result  = $gateway->verify_pending_payment_widget( $order, $post_data );
		if ( is_wp_error( $result ) ) {
			$this->send_response( 'error', $result->get_error_message(), array(), 409 );
		}
		$this->send_response( 'success', '', $result );
	}

	protected function get_request_data() {
		return json_decode( file_get_contents( 'php://input' ), true );
	}

	/**
	 * Get the registered SumUp gateway.
	 *
	 * @param string $gateway Gateway ID.
	 * @return WC_Gateway_SumUp
	 * @throws Exception When the gateway is unavailable.
	 */
	protected function get_sumup_gateway( $gateway ) {
		$payment_gateways = WC()->payment_gateways()->payment_gateways();

		if ( isset( $payment_gateways[ $gateway ] ) ) {
			return $payment_gateways[ $gateway ];
		}

		throw new Exception( 'SumUp payment method not found' );
	}
}

new Sumup_API_Store_Payment_Instructions_Handler();
