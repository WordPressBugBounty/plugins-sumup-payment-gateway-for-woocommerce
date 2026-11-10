<?php
/**
 * Atomic payment operation lock.
 *
 * @package SumUp
 */

defined( 'ABSPATH' ) || exit;

/** Connection-owned locks cannot expire underneath an active payment worker. */
final class WC_Sumup_Order_Lock {
	private static $owners = array();

	private static function lock_name( $order_id ) {
		global $wpdb;
		return substr( 'sumup:' . hash( 'sha256', DB_NAME . '|' . $wpdb->prefix . '|' . absint( $order_id ) ), 0, 64 );
	}
	public static function acquire( $order_id ) {
		global $wpdb;
		$name = self::lock_name( $order_id );
		if ( isset( self::$owners[ $name ] ) ) {
			return false;
		}
		// MySQL/MariaDB release this lock when the owning connection closes.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$connection = $wpdb->get_var( $wpdb->prepare( 'SELECT IF(GET_LOCK(%s, 0) = 1, CONNECTION_ID(), NULL)', $name ) );
		if ( ! $connection ) {
			return false;
		}
		$owner                 = array(
			'name'       => $name,
			'token'      => wp_generate_uuid4(),
			'connection' => (string) $connection,
		);
		self::$owners[ $name ] = $owner;
		return $owner;
	}
	/** A reconnect releases named locks; in-memory ownership alone is insufficient. */
	public static function owns( $order_id, $owner ) {
		global $wpdb;
		$name = $owner['name'] ?? '';
		if ( ! $name || $name !== self::lock_name( $order_id ) || ( self::$owners[ $name ] ?? null ) !== $owner ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$session = $wpdb->get_row( $wpdb->prepare( 'SELECT CONNECTION_ID() AS current_id, IS_USED_LOCK(%s) AS owner_id', $name ) );
		return $session && (string) $session->current_id === $owner['connection'] && (string) $session->owner_id === $owner['connection'];
	}

	/** Discard request-local order caches after taking the payment lock. */
	public static function read_order( $order_id, $required = true ) {
		global $wpdb, $EZSQL_ERROR;
		$errors = count( $EZSQL_ERROR ?? array() );
		$owner  = self::$owners[ self::lock_name( $order_id ) ] ?? null;
		if ( $owner && ! self::owns( $order_id, $owner ) ) {
			throw new RuntimeException( 'Payment lock ownership changed.' );
		}
		clean_post_cache( $order_id );
		wp_cache_delete( $order_id, 'post_meta' );
		if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$store = WC_Data_Store::load( 'order' );
			$store->clear_cached_data( array( $order_id ) );
			wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $order_id );
		}
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->read_meta_data( true ); }
		if ( $wpdb->last_error || count( $EZSQL_ERROR ?? array() ) > $errors || ( $required && ! $order ) ) {
			throw new RuntimeException( 'Payment order lookup is temporarily unavailable.' );
		}
		if ( $owner && ! self::owns( $order_id, $owner ) ) {
			throw new RuntimeException( 'Payment lock ownership changed.' );
		}
		return $order;
	}
	public static function release( $order_id, $owner ) {
		global $wpdb;
		$name = $owner['name'] ?? '';
		if ( ! $name || ( self::$owners[ $name ] ?? null ) !== $owner ) {
			return;
		}
		// RELEASE_LOCK only releases locks held by this database connection.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		unset( self::$owners[ $name ] );
	}
}
