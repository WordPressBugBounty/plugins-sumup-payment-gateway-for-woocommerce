<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Sumup_Blocks_Support extends AbstractPaymentMethodType {


	private $gateway;

	protected $name = 'sumup'; // payment gateway id

	public function initialize() {
		// get payment gateway settings
		$this->settings = get_option( "woocommerce_{$this->name}_settings", array() );

		// you can also initialize your payment gateway here
		$gateways      = WC()->payment_gateways->payment_gateways();
		$this->gateway = $gateways[ $this->name ];
	}

	public function is_active() {
		return ! empty( $this->settings['enabled'] )
			&& 'yes' === $this->settings['enabled']
			&& sumup_gateway_is_configured( $this->settings );
	}

	public function get_payment_method_script_handles() {

		if ( is_cart() || is_checkout() ) {
			$asset_data = sumup_get_build_asset_metadata(
				'build/index.asset.php',
				array(
					'wc-blocks-registry',
					'wc-settings',
					'wc-blocks-data-store',
					'wp-components',
					'sumup_gateway_card_sdk',
				)
			);

			/*
			 * Use the SumUp's SDK for accepting card payments.
			 * Documentation can be found at https://developer.sumup.com/docs/widgets-card
			 */
			wp_enqueue_script( 'sumup_gateway_card_sdk', 'https://gateway.sumup.com/gateway/ecom/card/v2/sdk.js', array(), WC_SUMUP_VERSION, false );
			wp_register_script(
				'wc-sumup-blocks-integration',
				plugin_dir_url( __DIR__ ) . 'build/index.js',
				$asset_data['dependencies'],
				$asset_data['version'],
				true
			);

			wp_register_style( 'wc_sumup_checkout', plugin_dir_url( __DIR__ ) . 'build/index.css', array( 'wp-components' ), $asset_data['version'] );
			wp_enqueue_style( 'wp-components' );
			wp_enqueue_style( 'wc_sumup_checkout' );
		}
		wp_localize_script( 'wc-sumup-blocks-integration', 'sumup_gateway_params', WC_Sumup_Widget_Settings::get( $this->settings ) );

		return array( 'wc-sumup-blocks-integration' );
	}

	public function get_payment_method_data() {
		$title = ! empty( $this->gateway->title )
			? $this->gateway->title
			: __( 'Credit / Debit Card', 'sumup-payment-gateway-for-woocommerce' );

		$description = ! empty( $this->gateway->description )
			? $this->gateway->description
			: __( 'Pay securely with your credit or debit card, or use other available payment methods.', 'sumup-payment-gateway-for-woocommerce' );
		$currency    = Wc_Sumup_Credentials::get_connection_currency();

		return array(
			'title'       => $title,
			'currency'    => $currency,
			'available'   => 'yes' === $this->gateway->enabled && $this->gateway->can_pay_in_currency( $currency ),
			'description' => $description,
			'icon'        => WC_SUMUP_PLUGIN_URL . '/assets/images/sumup-logomark.svg',
			'supports'    => array_filter( $this->gateway->supports, array( $this->gateway, 'supports' ) ),
		);
	}
}
