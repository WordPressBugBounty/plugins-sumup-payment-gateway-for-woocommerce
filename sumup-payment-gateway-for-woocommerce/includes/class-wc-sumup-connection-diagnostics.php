<?php
/**
 * SumUp payment integration support.
 *
 * @package SumUp
 */

defined( 'ABSPATH' ) || exit;

final class WC_Sumup_Connection_Diagnostics {
	public function __construct() {
		add_action( 'admin_post_sumup_check_connection', array( $this, 'handle' ) ); }
	public static function account_scope( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : sumup_get_gateway_settings();
		$identity = array();
		foreach ( array( 'merchant_id', 'pay_to_email', 'api_key', 'client_id', 'client_secret' ) as $key ) {
			$identity[ $key ] = (string) ( $settings[ $key ] ?? '' );
		}
		return hash_hmac( 'sha256', wp_json_encode( $identity ), wp_salt( 'auth' ) );
	}
	public function check_account() {
		$settings = sumup_get_gateway_settings();
		$token    = Wc_Sumup_Access_Token::get( $settings['client_id'] ?? '', $settings['client_secret'] ?? '', $settings['api_key'] ?? '' );
		$result   = array(
			'checked_at'    => time(),
			'account_scope' => self::account_scope( $settings ),
			'account_ok'    => false,
			'merchant_code' => $settings['merchant_id'] ?? '',
		);
		if ( ! empty( $token['access_token'] ) ) {
			$response = wp_remote_get(
				'https://api.sumup.com/userinfo',
				array(
					'timeout'     => 15,
					'redirection' => 0,
					'headers'     => sumup_get_api_request_headers( array(), $token['access_token'] ),
				)
			);
			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				$data                 = json_decode( wp_remote_retrieve_body( $response ), true );
				$result['account_ok'] = ! empty( $data['merchant_code'] ) && ( $data['merchant_code'] === ( $settings['merchant_id'] ?? '' ) );
			}
		}
		update_option( 'sumup_account_diagnostics', $result, false );
		return $result;
	}
	public function handle() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You cannot manage payment settings.', 'sumup-payment-gateway-for-woocommerce' ), '', 403 ); }
		check_admin_referer( 'sumup_check_connection' );
		$settings = sumup_get_gateway_settings();
		$valid    = true;
		if ( sumup_gateway_has_connection_details( $settings ) && ! sumup_gateway_is_configured( $settings ) ) {
			// The legacy settings validator can print notices; this action redirects to its own failure banner.
			ob_start();
			try {
				$valid = Wc_Sumup_Credentials::validate();
			} finally {
				ob_end_clean();
			}
		}
		if ( $valid ) {
			$this->check_account();
		}
		$redirect = sumup_get_gateway_setup_link();
		if ( ! $valid ) {
			$redirect = add_query_arg( 'validate_settings', 'false', $redirect );
		}
		wp_safe_redirect( $redirect );
		exit;
	}
	public static function verified( $checkout_id, $scope = null ) {
		update_option(
			'sumup_callback_verified',
			array(
				'checked_at'    => time(),
				'account_scope' => $scope ?? self::account_scope(),
				'checkout_id'   => sanitize_text_field( $checkout_id ),
			),
			false
		); }
	public static function render() {
		$account  = get_option( 'sumup_account_diagnostics', array() );
		$callback = get_option( 'sumup_callback_verified', array() );
		$scope    = self::account_scope();
		if ( ( $account['account_scope'] ?? '' ) !== $scope ) {
			$account = array();
		}
		if ( ( $callback['account_scope'] ?? '' ) !== $scope ) {
			$callback = array();
		}
		echo '<p><strong>' . esc_html__( 'Account check', 'sumup-payment-gateway-for-woocommerce' ) . ':</strong> ' . esc_html( empty( $account['checked_at'] ) ? __( 'Not checked yet', 'sumup-payment-gateway-for-woocommerce' ) : ( ! empty( $account['account_ok'] ) ? __( 'Verified', 'sumup-payment-gateway-for-woocommerce' ) : __( 'Check connection and permissions', 'sumup-payment-gateway-for-woocommerce' ) ) ) . '</p>';
		if ( ! empty( $account['checked_at'] ) ) {
			echo '<p>' . esc_html( wp_date( 'Y-m-d H:i:s', $account['checked_at'] ) ) . '</p>'; }
		echo '<p><strong>' . esc_html__( 'Last verified notification', 'sumup-payment-gateway-for-woocommerce' ) . ':</strong> ' . esc_html( empty( $callback['checked_at'] ) ? __( 'No verified notifications yet. Complete a test payment to check delivery.', 'sumup-payment-gateway-for-woocommerce' ) : wp_date( 'Y-m-d H:i:s', $callback['checked_at'] ) ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sumup_check_connection' ), 'sumup_check_connection' ) ) . '">' . esc_html__( 'Check connection', 'sumup-payment-gateway-for-woocommerce' ) . '</a></p>';
	}
}
