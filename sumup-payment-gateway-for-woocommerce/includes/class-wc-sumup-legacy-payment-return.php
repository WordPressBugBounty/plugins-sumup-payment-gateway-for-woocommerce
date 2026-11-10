<?php
/**
 * Temporary authorization bridge for checkout URLs created without an order key.
 *
 * Remove this class, its include, and the resolver fallback once pre-upgrade
 * checkouts no longer need support. It never changes or recreates provider checkouts.
 *
 * @package SumUp
 */

defined( 'ABSPATH' ) || exit;

/** Keeps legacy return authorization separate from the permanent keyed flow. */
final class WC_Sumup_Legacy_Payment_Return {
	private const SESSION_KEY = 'sumup_legacy_payment_returns';

	/**
	 * Remember order-pay access after WooCommerce has verified the key and customer.
	 *
	 * WooCommerce establishes the order-pay session cookie on `wp`, before output.
	 * Unlike normal checkout, order-pay does not set `order_awaiting_payment`.
	 *
	 * @param WC_Order $order Order authorized by WooCommerce's order-pay form.
	 */
	public static function remember_order_pay( $order ) {
		$session  = WC()->session;
		$checkout = $order->get_meta( '_sumup_checkout_data' );
		if ( ! $session || ! $session->has_session() || ! is_array( $checkout ) || empty( $checkout['id'] ) ) {
			return;
		}

		$contexts                     = $session->get( self::SESSION_KEY, array() );
		$contexts                     = is_array( $contexts ) ? $contexts : array();
		$contexts[ $order->get_id() ] = array(
			'checkout_id' => $checkout['id'],
			'order_key'   => $order->get_order_key(),
		);
		$session->set( self::SESSION_KEY, $contexts );
	}

	/**
	 * Return a key only when existing customer or session state proves order access.
	 *
	 * @param WC_Order $order Order being returned to.
	 * @param string   $url_checkout_id Optional checkout ID from the return URL.
	 * @return string
	 */
	public static function get_order_key( $order, $url_checkout_id = '' ) {
		$checkout = $order->get_meta( '_sumup_checkout_data' );
		if ( ! is_array( $checkout ) || empty( $checkout['id'] ) || ( '' !== $url_checkout_id && $url_checkout_id !== $checkout['id'] ) ) {
			return '';
		}

		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id > 0 && get_current_user_id() === $customer_id ) {
			return $order->get_order_key();
		}

		$session = WC()->session;
		if ( ! $session || ! $session->has_session() ) {
			return '';
		}

		// Older Blocks versions retain store_api_draft_order through pending payment.
		if ( (int) $session->get( 'order_awaiting_payment' ) === $order->get_id() ||
			(int) $session->get( 'store_api_draft_order' ) === $order->get_id() ) {
			return $order->get_order_key();
		}

		$contexts = $session->get( self::SESSION_KEY, array() );
		$context  = is_array( $contexts ) ? ( $contexts[ $order->get_id() ] ?? null ) : null;
		if ( ! is_array( $context ) || ( $context['checkout_id'] ?? '' ) !== $checkout['id'] ) {
			return '';
		}

		return is_string( $context['order_key'] ?? null ) ? $context['order_key'] : '';
	}
}

add_action( 'before_woocommerce_pay_form', array( 'WC_Sumup_Legacy_Payment_Return', 'remember_order_pay' ) );
