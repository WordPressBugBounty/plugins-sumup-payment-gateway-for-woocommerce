<?php
/**
 * Shared onboarding callback validation and persistence.
 *
 * @package SumUp
 */

defined( 'ABSPATH' ) || exit;

final class WC_Sumup_Connection {

	/** Accept a connection only once, using a locally initiated onboarding ID. */
	public static function accept( $data ) {
		$id = isset( $data['id'] ) && is_string( $data['id'] ) ? sanitize_text_field( $data['id'] ) : '';
		if ( '' === $id || ! sumup_has_pending_connection_id( $id ) ) {
			return new WP_Error( 'invalid_connection', 'Invalid connection ID' );
		}
		$merchant = $data['merchant'] ?? array();
		foreach ( array(
			'email'         => 'Invalid merchant email',
			'api_key'       => 'Invalid API key',
			'merchant_code' => 'Invalid merchant code',
		) as $field => $message ) {
			if ( ! is_array( $merchant ) || ! isset( $merchant[ $field ] ) || ! is_string( $merchant[ $field ] ) || '' === trim( $merchant[ $field ] ) ) {
				return new WP_Error( 'invalid_merchant', $message );
			}
		}
		$settings                 = sumup_get_gateway_settings();
		$settings['pay_to_email'] = $merchant['email'];
		$settings['api_key']      = $merchant['api_key'];
		$settings['merchant_id']  = $merchant['merchant_code'];
		$settings['enabled']      = 'no';
		update_option( 'woocommerce_sumup_settings', $settings );
		Wc_Sumup_Credentials::$integration_settings = array();
		update_option( 'sumup_connection_status', 'pending', false );
		delete_option( 'sumup_google_pay_domain_status' );
		delete_option( 'sumup_connection_currency' );
		update_option( 'sumup_valid_currency', 0, false );
		sumup_delete_pending_connection_id( $id );
		return array( 'status' => 'connected' );
	}
}
