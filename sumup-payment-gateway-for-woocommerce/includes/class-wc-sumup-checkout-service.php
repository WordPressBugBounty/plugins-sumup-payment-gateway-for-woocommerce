<?php
/**
 * Shared checkout creation and recovery.
 *
 * @package SumUp
 */

defined( 'ABSPATH' ) || exit;

/** Creates and persists checkout context for all WooCommerce entry points. */
final class WC_Sumup_Checkout_Service {
	public static function prepare( $gateway, $order, $access_token, $is_blocks = false ) {
		if ( 'yes' !== $gateway->enabled || ! $order instanceof WC_Order || ! $order->needs_payment() ) {
			return array(
				'error_code' => 'order_not_payable',
				'message'    => __( 'This order does not need another payment.', 'sumup-payment-gateway-for-woocommerce' ),
			);
		}
		if ( ! $gateway->can_pay_in_currency( $order->get_currency() ) ) {
			return array(
				'error_code' => 'currency_unavailable',
				'message'    => __( 'SumUp is unavailable for this currency.', 'sumup-payment-gateway-for-woocommerce' ),
			);
		}
		$stored  = $order->get_meta( '_sumup_checkout_data' );
		$request = $gateway->build_checkout_request_data( $order );
		// Reconnecting an account must never redirect an existing payment to another merchant.
		if ( is_array( $stored ) && ! empty( $stored['id'] ) && ! self::matches_account( $stored, $request ) ) {
			return self::update_failed();
		}
		if ( ! $gateway->should_refresh_checkout_for_order( $order, $stored )
			&& ( empty( $stored['_sumup_request'] ) || self::callbacks_match( $stored, $request ) ) ) {
			return $gateway->enrich_checkout_data_for_order( $order, $stored, $is_blocks );
		}
		$context       = $gateway->get_order_log_context( $order );
		$updating      = self::can_update( $stored, $request );
		$order_context = $gateway->enrich_checkout_data_for_order( $order, array() )['_sumup_context'];
		if ( $updating ) {
			// Stored state may predate a payment attempt in another browser.
			$remote                   = Wc_Sumup_Checkout::get( $stored['id'], $access_token, $context );
			$remote['_sumup_request'] = array_intersect_key( $stored['_sumup_request'] ?? $stored, array_flip( array( 'return_url', 'redirect_url' ) ) );
			if ( ! self::belongs_to_order( $remote, $stored['id'], $request ) ) {
				return self::update_failed();
			}
			if ( in_array( $remote['status'] ?? '', array( 'EXPIRED', 'FAILED' ), true ) && empty( $remote['transactions'] ) && empty( $remote['transaction_id'] ) && empty( $remote['transaction_code'] ) ) {
				$updating = false;
			} elseif ( ! self::can_update( $remote, $request ) ) {
				return self::update_failed();
			}
		}
		if ( $updating ) {
			if ( ! self::unchanged_order( $gateway, $order->get_id(), $stored['id'], $request, $order_context ) ) {
				return self::update_failed();
			}
			$checkout = Wc_Sumup_Checkout::update( $stored['id'], $access_token, $request, $context );
			if ( empty( $checkout['id'] ) ) {
				// A timeout may follow an accepted PATCH. Verify rather than create another checkout.
				$checkout = Wc_Sumup_Checkout::get( $stored['id'], $access_token, $context );
			}
			$checkout['_sumup_request'] = $remote['_sumup_request'];
			if ( ! self::can_reuse( $checkout, $stored['id'], $request ) || ! self::callbacks_match( $checkout, $request ) ) {
				return self::update_failed();
			}
			$current = self::unchanged_order( $gateway, $order->get_id(), $stored['id'], $request, $order_context );
			if ( ! $current ) {
				return self::update_failed();
			}
			$order = $current;
			$order->delete_meta_data( '_sumup_pending_payment_instructions' );
		} else {
			if ( is_array( $stored ) && in_array( $stored['status'] ?? '', array( 'PENDING', 'PAID' ), true ) && ( ! empty( $stored['transactions'] ) || ! empty( $stored['transaction_id'] ) || ! empty( $stored['transaction_code'] ) ) ) {
				return self::update_failed();
			}
			$checkout = Wc_Sumup_Checkout::create( $access_token, $request, $context );
			if ( ! empty( $checkout['id'] ) ) {
				// PATCH cannot change callback URLs. Record only callbacks actually sent.
				$checkout['_sumup_request'] = array_intersect_key( $request, array_flip( array( 'return_url', 'redirect_url' ) ) );
			}
		}
		if ( 'DUPLICATED_CHECKOUT' === ( $checkout['error_code'] ?? '' ) && ! empty( $stored['id'] ) ) {
			$remote                   = Wc_Sumup_Checkout::get( $stored['id'], $access_token );
			$remote['_sumup_request'] = $stored['_sumup_request'] ?? array();
			if ( self::can_reuse( $remote, $stored['id'], $request )
				&& ( empty( $stored['_sumup_request'] ) || self::callbacks_match( $remote, $request ) ) ) {
				$checkout = $remote;
			}
		}
		if ( empty( $checkout['id'] ) ) {
			return $checkout; }
		$checkout = $gateway->enrich_checkout_data_for_order( $order, $checkout, $is_blocks );
		$order->update_meta_data( '_sumup_checkout_data', $checkout );
		$order->save();
		return $checkout;
	}

	/** Discard an update when the order changed while waiting on the provider. */
	private static function unchanged_order( $gateway, $order_id, $checkout_id, $request, $context ) {
		$current = wc_get_order( $order_id );
		if ( ! $current instanceof WC_Order || ! $current->needs_payment() ) {
			return false;
		}
		$current->read_meta_data( true );
		if ( $gateway->build_checkout_request_data( $current ) !== $request
			|| ( $current->get_meta( '_sumup_checkout_data' )['id'] ?? '' ) !== $checkout_id
			|| $gateway->enrich_checkout_data_for_order( $current, array() )['_sumup_context'] !== $context ) {
			return false;
		}
		return $current;
	}

	/** Update only an unprocessed checkout with the same immutable payment context. */
	private static function can_update( $checkout, $request ) {
		return is_array( $checkout ) && ! empty( $checkout['id'] )
			&& self::belongs_to_order( $checkout, $checkout['id'], $request )
			&& self::is_pending( $checkout ) && self::callbacks_match( $checkout, $request );
	}

	private static function callbacks_match( $checkout, $request ) {
		foreach ( array( 'return_url', 'redirect_url' ) as $field ) {
			$sent = $checkout['_sumup_request'][ $field ] ?? $checkout[ $field ] ?? null;
			if ( ( null !== $sent && $sent !== $request[ $field ] ) || ( isset( $checkout[ $field ] ) && $checkout[ $field ] !== $request[ $field ] ) ) {
				return false;
			}
		}
		return true;
	}

	private static function is_pending( $checkout ) {
		if ( 'PENDING' !== ( $checkout['status'] ?? '' ) || ! empty( $checkout['transactions'] ) || ! empty( $checkout['transaction_id'] ) || ! empty( $checkout['transaction_code'] ) ) {
			return false;
		}
		if ( empty( $checkout['valid_until'] ) ) {
			return true;
		}
		$expires = is_string( $checkout['valid_until'] ) ? strtotime( $checkout['valid_until'] ) : false;
		return false !== $expires && $expires > time();
	}

	private static function update_failed() {
		return array(
			'error_code' => 'checkout_update_failed',
			'message'    => __( 'Unable to initialize SumUp payment. Please try again.', 'sumup-payment-gateway-for-woocommerce' ),
		);
	}

	/** Recover only a verified pending checkout belonging to this payable order. */
	private static function can_reuse( $checkout, $id, $request ) {
		return self::belongs_to_order( $checkout, $id, $request ) && self::is_pending( $checkout )
			&& ( $checkout['currency'] ?? '' ) === $request['currency']
			&& isset( $checkout['amount'] ) && is_numeric( $checkout['amount'] ) && is_finite( (float) $checkout['amount'] )
			&& abs( (float) $checkout['amount'] - (float) $request['amount'] ) < 0.00001;
	}

	private static function belongs_to_order( $checkout, $id, $request ) {
		if ( ( $checkout['id'] ?? '' ) !== $id || ( $checkout['checkout_reference'] ?? '' ) !== $request['checkout_reference'] ) {
			return false;
		}
		return self::matches_account( $checkout, $request );
	}

	/** Apply the same account binding to cached, updated, and recovered checkouts. */
	public static function matches_account( $checkout, $request ) {
		if ( ! empty( $request['merchant_code'] ) && ( $checkout['merchant_code'] ?? '' ) !== $request['merchant_code'] ) {
			return false;
		}
		if ( ! empty( $request['pay_to_email'] ) && strtolower( $checkout['pay_to_email'] ?? '' ) !== strtolower( $request['pay_to_email'] ) ) {
			return false;
		}
		return true;
	}
}
