<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @class    WC_Gateway_SumUp
 * @since    1.0.0
 * @version  1.0.0
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Preserve this established WooCommerce gateway class name for compatibility with installed integrations.
class WC_Gateway_SumUp extends \WC_Payment_Gateway {

	/**
	 * Merchant code
	 *
	 * @since 2.0
	 */
	protected $merchant_id;

	/**
	 * API Key
	 *
	 * @since 2.0
	 */
	protected $api_key;

	/**
	 * Client ID
	 *
	 * @since 2.0
	 */
	protected $client_id;

	/**
	 * Client secret
	 *
	 * @since 2.0
	 */
	protected $client_secret;



	/**
	 * Merchant mail
	 *
	 * @since 2.o
	 */
	protected $pay_to_email;



	/**
	 * Return URL
	 *
	 * @since 2.0
	 */
	protected $open_payment_in_modal;

	/**
	 * Webhook retry attempts
	 *
	 * @since 2.0
	 */
	protected $webhook_retry_attempts;

	/**
	 * Constructor.
	 */
	public function __construct() {
		/* Init options */
		$this->init_options();

		/* Load the form fields */
		$this->init_form_fields();

		/* Load the settings */
		$this->init_settings();

		/* Load actions */
		$this->init_actions();
	}

	/**
	 * Initialize all options and properties.
	 *
	 * @since    1.0.0
	 * @version  1.0.0
	 */
	public function init_options() {
		$this->id                    = 'sumup';
		$this->method_title          = __( 'SumUp', 'sumup-payment-gateway-for-woocommerce' );
		$this->icon                  = WC_SUMUP_PLUGIN_URL . '/assets/images/sumup-logo.svg';
		$this->method_description    = __( 'Accept credit and debit cards with SumUp, plus eligible payment methods such as Apple Pay, Google Pay, PayPal, Bancontact, and iDEAL.', 'sumup-payment-gateway-for-woocommerce' );
		$this->has_fields            = true;
		$this->supports              = array(
			'products',
		);
		$this->title                 = $this->get_option( 'title' );
		$this->description           = $this->get_option( 'description' );
		$this->enabled               = 'yes' === $this->get_option( 'enabled' ) && sumup_gateway_is_configured( $this->settings ) ? 'yes' : 'no';
		$this->merchant_id           = $this->get_option( 'merchant_id' );
		$this->api_key               = $this->get_option( 'api_key' );
		$this->client_id             = $this->get_option( 'client_id' );
		$this->client_secret         = $this->get_option( 'client_secret' );
		$this->pay_to_email          = $this->get_option( 'pay_to_email' );
		$this->open_payment_in_modal = $this->get_option( 'open_payment_modal', 'yes' );

		// Advanced webhook settings
		$this->webhook_retry_attempts = 5;
	}

	/**
	 * Initialize action hooks.
	 *
	 * @since    1.0.0
	 * @version  1.0.0
	 */
	public function init_actions() {
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'verify_credentials' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
		add_action( 'woocommerce_before_thankyou', array( $this, 'add_payment_instructions_thankyou_page' ) );
		add_action( 'woocommerce_api_wc_gateway_sumup', array( $this, 'webhook' ) );
		add_action( 'template_redirect', array( $this, 'check_redirect_flow' ), 99 );
		add_action( 'process_webhook_order', array( $this, 'handle_webhook_order' ) );
		add_action( 'process_webhook_order_priority', array( $this, 'handle_webhook_order_with_retry' ), 10, 2 );
	}

	/** Use the existing order's currency when rendering its payment page. */
	public function is_available() {
		global $wp;
		$currency = get_woocommerce_currency();
		if ( is_checkout_pay_page() && ! empty( $wp->query_vars['order-pay'] ) ) {
			$order = wc_get_order( absint( $wp->query_vars['order-pay'] ) );
			if ( $order instanceof WC_Order ) {
				$currency = $order->get_currency();
			}
		}
		return parent::is_available() && $this->can_pay_in_currency( $currency );
	}

	/**
	 * Check currency eligibility independently of the current cart.
	 *
	 * @param string $currency Order or cart currency.
	 * @return bool
	 */
	public function can_pay_in_currency( $currency ) {
		if ( ! sumup_gateway_is_configured( $this->settings ) ) {
			return false;
		}
		$connection_currency = Wc_Sumup_Credentials::get_connection_currency();
		return '' !== $connection_currency && (bool) get_option( 'sumup_valid_currency', false ) && strtoupper( $currency ) === strtoupper( $connection_currency );
	}

	public function needs_setup() {
		return ! sumup_gateway_is_configured( $this->settings );
	}

	public function is_account_connected() {
		return sumup_gateway_is_configured( $this->settings );
	}

	public function get_connection_url( $return_url = '' ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=sumup' );

		if ( ! empty( $return_url ) ) {
			$url = add_query_arg( 'return_url', rawurlencode( $return_url ), $url );
		}

		return $url;
	}

	public function admin_options() {
		if ( ! $this->needs_setup() ) {
			wp_enqueue_script( 'sumup-settings' );
			wp_enqueue_style( 'sumup-settings' );
			parent::admin_options();
			return;
		}

		global $hide_save_button;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce owns this settings-renderer save-button flag.
		$hide_save_button = true;
		$return_url       = admin_url( 'admin.php?page=wc-settings&tab=checkout' );
		$header           = $this->get_method_title();
		$return_text      = __( 'Return to payments', 'sumup-payment-gateway-for-woocommerce' );
		$onboarding       = new WC_Sumup_Onboarding();

		echo '<h2>';
		echo esc_html( $header );
		wc_back_link( $return_text, $return_url );
		echo '</h2>';

		$onboarding->render_setup_screen();
	}

	/**
	 * Render read-only connection details inside the gateway settings table.
	 *
	 * @param string $key Field key.
	 * @param array  $data Field configuration.
	 * @return string
	 */
	public function generate_sumup_connection_summary_html( $key, $data ) {
		$field_key         = $this->get_field_key( $key );
		$connection_status = sumup_get_gateway_connection_status( $this->settings );
		$status_label      = 'connected' === $connection_status
			? __( 'Connected', 'sumup-payment-gateway-for-woocommerce' )
			: ucfirst( str_replace( '_', ' ', $connection_status ) );
		$account_email     = ! empty( $this->pay_to_email ) ? $this->pay_to_email : __( 'Not available', 'sumup-payment-gateway-for-woocommerce' );
		$merchant_id       = ! empty( $this->merchant_id ) ? $this->merchant_id : __( 'Not available', 'sumup-payment-gateway-for-woocommerce' );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<?php echo esc_html__( 'Account details', 'sumup-payment-gateway-for-woocommerce' ); ?>
			</th>
			<td class="forminp">
				<fieldset>
					<div id="<?php echo esc_attr( $field_key ); ?>" class="sumup-connection-summary">
						<p class="sumup-connection-summary__row">
							<strong><?php echo esc_html__( 'Status', 'sumup-payment-gateway-for-woocommerce' ); ?>:</strong>
							<?php echo esc_html( $status_label ); ?>
						</p>
						<p class="sumup-connection-summary__row">
							<strong><?php echo esc_html__( 'Account email', 'sumup-payment-gateway-for-woocommerce' ); ?>:</strong>
							<?php echo esc_html( $account_email ); ?>
						</p>
						<p class="sumup-connection-summary__row">
							<strong><?php echo esc_html__( 'Merchant code', 'sumup-payment-gateway-for-woocommerce' ); ?>:</strong>
							<?php echo esc_html( $merchant_id ); ?>
						</p>
						<?php WC_Sumup_Connection_Diagnostics::render(); ?>
					</div>
				</fieldset>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render connection actions inside the gateway settings table.
	 *
	 * @param string $key Field key.
	 * @param array  $data Field configuration.
	 * @return string
	 */
	public function generate_sumup_connection_actions_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<?php echo esc_html__( 'Actions', 'sumup-payment-gateway-for-woocommerce' ); ?>
			</th>
			<td class="forminp">
				<fieldset>
					<div id="sumup_notice" class="notice notice-error inline hidden sumup-connection-actions__notice">
						<p class="sumup-notice__message"></p>
					</div>
					<p id="<?php echo esc_attr( $field_key ); ?>" class="sumup-connection-actions">
						<?php if ( Wc_Sumup_Credentials::has_currency_mismatch() || ! get_option( 'sumup_valid_currency', false ) ) : ?>
							<button
								id="sumup-payment-settings-connect"
								type="button"
								class="components-button is-primary"
								data-text="<?php esc_attr_e( 'Reconnect account', 'sumup-payment-gateway-for-woocommerce' ); ?>"
								<?php disabled( ! sumup_is_public_hostname() ); ?>
							>
								<span class="sumup-button-label"><?php esc_html_e( 'Reconnect account', 'sumup-payment-gateway-for-woocommerce' ); ?></span>
								<span class="sumup-button-loading" aria-hidden="true"></span>
							</button>
						<?php endif; ?>
						<button
							id="sumup-payment-settings-disconnect"
							type="button"
							class="components-button is-secondary is-destructive"
							data-text="<?php esc_attr_e( 'Disconnect account', 'sumup-payment-gateway-for-woocommerce' ); ?>"
						>
							<span class="sumup-button-label">
								<?php esc_html_e( 'Disconnect account', 'sumup-payment-gateway-for-woocommerce' ); ?>
							</span>
							<span class="sumup-button-loading" aria-hidden="true"></span>
						</button>
					</p>
				</fieldset>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render Google Pay domain validation details and action button.
	 *
	 * @param string $key Field key.
	 * @param array  $data Field configuration.
	 * @return string
	 */
	public function generate_sumup_google_pay_setup_html( $key, $data ) {
		$field_key             = $this->get_field_key( $key );
		$console_url           = esc_url( 'https://pay.google.com/business/console' );
		$is_google_pay_enabled = sumup_should_offer_google_pay( $this->settings );
		$google_pay_status     = sumup_get_google_pay_domain_status();

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<?php echo esc_html__( 'Google Pay setup', 'sumup-payment-gateway-for-woocommerce' ); ?>
			</th>
			<td class="forminp">
				<fieldset>
					<div class="sumup-google-pay-setup">
						<p class="sumup-google-pay-setup__intro">
							<?php
							echo wp_kses_post(
								sprintf(
									/* translators: %s = Google Pay console link */
									__(
										'Complete Google approval in the %s, then register your domain with SumUp using the button below. Registration with SumUp does not submit your website to Google for approval.',
										'sumup-payment-gateway-for-woocommerce'
									),
									'<a href="' . $console_url . '" target="_blank" rel="noreferrer noopener">' . esc_html__( 'Google Pay console', 'sumup-payment-gateway-for-woocommerce' ) . '</a>'
								)
							);
							?>
						</p>
						<ol class="sumup-google-pay-setup__steps">
							<li><?php esc_html_e( 'Create an account in the Google Pay console.', 'sumup-payment-gateway-for-woocommerce' ); ?></li>
							<li><?php esc_html_e( 'Go to the Google Pay API tab in your Google Pay console.', 'sumup-payment-gateway-for-woocommerce' ); ?></li>
							<li><?php esc_html_e( 'Navigate to the Integrate with your website section and click Add website.', 'sumup-payment-gateway-for-woocommerce' ); ?></li>
							<li><?php esc_html_e( 'Fill out the form with the requested information, including the domain name and buyflow screenshots.', 'sumup-payment-gateway-for-woocommerce' ); ?></li>
							<li><?php esc_html_e( 'At the top of the page, click Submit for approval.', 'sumup-payment-gateway-for-woocommerce' ); ?></li>
						</ol>

						<?php if ( ! sumup_gateway_is_configured( $this->settings ) ) : ?>
							<p class="description"><?php esc_html_e( 'Connect your SumUp account before continuing with Google Pay.', 'sumup-payment-gateway-for-woocommerce' ); ?></p>
						<?php elseif ( ! is_ssl() || ! sumup_is_public_hostname() ) : ?>
							<div class="notice notice-warning inline sumup-google-pay-setup__notice">
								<p><?php esc_html_e( 'Google Pay domain registration requires a publicly accessible HTTPS store.', 'sumup-payment-gateway-for-woocommerce' ); ?></p>
							</div>
						<?php elseif ( ! $is_google_pay_enabled ) : ?>
							<p class="description sumup-google-pay-setup__description">
								<?php esc_html_e( 'Enable Google Pay and save your changes, then return here to register your domain with SumUp.', 'sumup-payment-gateway-for-woocommerce' ); ?>
							</p>
						<?php elseif ( 'registered' === $google_pay_status ) : ?>
							<div class="notice notice-success inline sumup-google-pay-setup__notice">
								<p><?php esc_html_e( 'Your domain has been registered with SumUp for Google Pay.', 'sumup-payment-gateway-for-woocommerce' ); ?></p>
							</div>
						<?php else : ?>
							<div id="sumup_google_pay_notice" class="notice notice-error inline hidden sumup-google-pay-setup__notice">
								<p class="sumup-notice__message"></p>
							</div>
							<p id="<?php echo esc_attr( $field_key ); ?>" class="sumup-google-pay-setup__actions">
								<button
									id="sumup-payment-settings-google-pay"
									type="button"
									class="components-button is-primary"
									data-text="<?php esc_attr_e( 'Register domain with SumUp', 'sumup-payment-gateway-for-woocommerce' ); ?>"
								>
									<span class="sumup-button-label">
										<?php esc_html_e( 'Register domain with SumUp', 'sumup-payment-gateway-for-woocommerce' ); ?>
									</span>
									<span class="sumup-button-loading" aria-hidden="true"></span>
								</button>
							</p>
						<?php endif; ?>
					</div>
				</fieldset>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	public function webhook() {
		$data = json_decode( file_get_contents( 'php://input' ), true );
		if ( ! is_array( $data ) || empty( $data['id'] ) || empty( $data['event_type'] ) ) {
			wp_send_json_error( array( 'message' => 'Invalid data' ), 400 );
			return;
		}
		if ( 'CHECKOUT_STATUS_CHANGED' !== $data['event_type'] ) {
			wp_send_json_success( array( 'message' => 'Event ignored' ) );
			return;
		}
		try {
			$this->schedule_webhook( $data, 1 );
		} catch ( Throwable $error ) {
			wp_send_json_error( array( 'message' => 'Unable to queue webhook' ), 503 );
			return;
		}
		wp_send_json_success( array( 'message' => 'Webhook added to queue' ) );
	}

	/**
	 * Schedule webhook action in ActionScheduler
	 *
	 * @param array $payload Webhook data
	 * @return void
	 */
	private function schedule_webhook( $data, $attempt ) {
		$payload = array(
			'id'         => sanitize_text_field( $data['id'] ?? '' ),
			'event_type' => sanitize_text_field( $data['event_type'] ?? '' ),
		);
		$args    = array( $payload, $attempt );
		$delay   = 1 === $attempt ? 60 : (int) pow( 2, $attempt - 1 ) * 60;
		$action  = as_schedule_single_action( time() + $delay, 'process_webhook_order_priority', $args, 'sumup-webhooks-priority', true, 1 === $attempt ? 10 : 5 );
		if ( ! $action && ! as_has_scheduled_action( 'process_webhook_order_priority', $args, 'sumup-webhooks-priority' ) ) {
			throw new RuntimeException( 'Unable to schedule SumUp webhook.' );
		}
		WC_SUMUP_LOGGER::log(
			'Webhook scheduled.',
			array(
				'checkout_id'         => $payload['id'],
				'attempt'             => $attempt,
				'retry_delay_seconds' => $delay,
			),
			'info'
		);
	}

	/**
	 * Webhook to manage order status after SumUp sent a notification
	 *
	 * @return void
	 */
	public function handle_webhook_order( $data ) {
		// Keep already queued legacy actions on the same verified processing path.
		$this->handle_webhook_order_with_retry( $data );
	}

	/**
	 * Validate webhook data structure
	 *
	 * @param array $data
	 * @return bool
	 */
	private function validate_webhook_data( $data ) {
		return isset( $data['id'] ) &&
			! empty( $data['id'] ) &&
			isset( $data['event_type'] ) &&
			! empty( $data['event_type'] );
	}

	/**
	 * Validate event type
	 *
	 * @param string $event_type
	 * @return bool
	 */
	private function validate_event_type( $event_type ) {
		return $event_type === 'CHECKOUT_STATUS_CHANGED';
	}

	/**
	 * Get access token for SumUp API
	 *
	 * @return string
	 */
	private function get_access_token( $log_context = array() ) {
		$access_token = Wc_Sumup_Access_Token::get( $this->client_id, $this->client_secret, $this->api_key, true, $log_context );
		return $access_token['access_token'] ?? '';
	}

	/**
	 * Fetch checkout data from SumUp API
	 *
	 * @param string $checkout_id
	 * @param string $access_token
	 * @return array
	 */
	private function fetch_checkout_data( $checkout_id, $access_token, $log_context = array() ) {
		return Wc_Sumup_Checkout::get( $checkout_id, $access_token, $log_context );
	}

	/**
	 * Build the checkout payload sent to SumUp for a WooCommerce order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	public function build_checkout_request_data( $order ) {
		$checkout_data = array(
			'checkout_reference' => 'WC_SUMUP_' . $order->get_id(),
			'amount'             => (float) $order->get_total(),
			'currency'           => $order->get_currency(),
			'description'        => 'WooCommerce #' . $order->get_id(),
			'redirect_url'       => add_query_arg(
				array(
					'sumup-validate-order' => $order->get_id(),
					'key'                  => $order->get_order_key(),
				),
				wc_get_checkout_url()
			),
			'return_url'         => WC()->api_request_url( 'wc_gateway_sumup' ),
		);

		if ( ! empty( $this->merchant_id ) ) {
			$checkout_data['merchant_code'] = $this->merchant_id;
		} elseif ( ! empty( $this->pay_to_email ) ) {
			$checkout_data['pay_to_email'] = $this->pay_to_email;
		}

		return $checkout_data;
	}

	/**
	 * Validate that the current visitor is allowed to pay for the given order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $order_key WooCommerce order key from the current request.
	 * @return array{valid: bool, error: string}
	 */
	public function validate_order_access_for_checkout( $order, $order_key = '' ) {
		if ( ! $order instanceof WC_Order ) {
			return array(
				'valid' => false,
				'error' => __( 'Order ID is not available to make the payment. Try again soon or contact the website support.', 'sumup-payment-gateway-for-woocommerce' ),
			);
		}

		if ( empty( $order_key ) || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			return array(
				'valid' => false,
				'error' => __( 'Order validation failed. Refresh checkout and try again.', 'sumup-payment-gateway-for-woocommerce' ),
			);
		}

		if ( is_user_logged_in() ) {
			$order_customer_id   = (int) $order->get_customer_id();
			$current_customer_id = get_current_user_id();

			if ( $order_customer_id > 0 && $order_customer_id !== $current_customer_id ) {
				return array(
					'valid' => false,
					'error' => __( 'Order validation failed. Refresh checkout and try again.', 'sumup-payment-gateway-for-woocommerce' ),
				);
			}
		}

		return array(
			'valid' => true,
			'error' => '',
		);
	}

	/**
	 * Build widget context from an order so frontend state stays consistent across checkout flows.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	public function get_widget_context_for_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return array();
		}

		return array(
			'amount'       => wc_format_decimal( $order->get_total(), wc_get_price_decimals() ),
			'currency'     => $order->get_currency(),
			'country'      => strtoupper( (string) $order->get_billing_country() ),
			'firstName'    => sanitize_text_field( $order->get_billing_first_name() ),
			'lastName'     => sanitize_text_field( $order->get_billing_last_name() ),
			'email'        => sanitize_email( $order->get_billing_email() ),
			'phoneNumber'  => sanitize_text_field( $order->get_billing_phone() ),
			'city'         => sanitize_text_field( $order->get_billing_city() ),
			'street'       => sanitize_text_field( $order->get_billing_address_1() ),
			'streetNumber' => sanitize_text_field( $order->get_billing_address_2() ),
			'postalCode'   => sanitize_text_field( $order->get_billing_postcode() ),
			'stateName'    => sanitize_text_field( $order->get_billing_state() ),
			'redirectUrl'  => $this->get_return_url( $order ),
			'orderId'      => $order->get_id(),
			'orderKey'     => $order->get_order_key(),
		);
	}

	/**
	 * Whether the current payment request comes from Cart/Checkout Blocks.
	 *
	 * WooCommerce Store API exposes Blocks payment_method_data via $_POST while
	 * process_payment() runs.
	 *
	 * @param array $sumup_checkout Stored checkout payload.
	 * @return bool
	 */
	private function is_blocks_checkout_request( $sumup_checkout = array() ) {
		if ( is_array( $sumup_checkout ) && ! empty( $sumup_checkout['isCheckoutBlocks'] ) ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce authenticates checkout requests before process_payment(); this flag only selects the Blocks response format.
		return isset( $_POST['isCheckoutBlocks'] ) && wc_string_to_bool( sanitize_text_field( wp_unslash( $_POST['isCheckoutBlocks'] ) ) );
	}

	/**
	 * Attach local order context to stored checkout data.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param array    $sumup_checkout SumUp checkout payload.
	 * @param bool     $is_checkout_blocks Whether the checkout was created from Blocks.
	 * @return array
	 */
	public function enrich_checkout_data_for_order( $order, $sumup_checkout, $is_checkout_blocks = false ) {
		if ( ! is_array( $sumup_checkout ) ) {
			$sumup_checkout = array();
		}

		$sumup_checkout['_sumup_context'] = array(
			'order_total'     => (string) $order->get_total(),
			'currency'        => $order->get_currency(),
			'billing_country' => $order->get_billing_country(),
			'order_status'    => $order->get_status(),
		);

		if ( $is_checkout_blocks ) {
			$sumup_checkout['isCheckoutBlocks'] = true;
		}

		return $sumup_checkout;
	}

	/**
	 * Build a structured log context for a WooCommerce order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param array    $extra Additional context fields.
	 * @return array
	 */
	public function get_order_log_context( $order, $extra = array() ) {
		$context = is_array( $extra ) ? $extra : array();

		if ( $order instanceof WC_Order ) {
			$context['order_id'] = $order->get_id();
		}

		return $this->get_gateway_log_context( $context );
	}

	/**
	 * Build a structured log context for a checkout payload.
	 *
	 * @param array $checkout_data Checkout payload.
	 * @param array $extra Additional context fields.
	 * @return array
	 */
	public function get_checkout_log_context( $checkout_data, $extra = array() ) {
		$context       = is_array( $extra ) ? $extra : array();
		$checkout_data = is_array( $checkout_data ) ? $checkout_data : array();

		if ( ! empty( $checkout_data['id'] ) ) {
			$context['checkout_id'] = sanitize_text_field( (string) $checkout_data['id'] );
		}

		if ( ! empty( $checkout_data['checkout_reference'] ) ) {
			$context['checkout_reference'] = sanitize_text_field( (string) $checkout_data['checkout_reference'] );
		}

		return $this->get_gateway_log_context( $context );
	}

	/**
	 * Attach gateway-level identifiers to log context.
	 *
	 * @param array $context Structured context.
	 * @return array
	 */
	private function get_gateway_log_context( $context = array() ) {
		$context = is_array( $context ) ? $context : array();

		if ( ! empty( $this->merchant_id ) ) {
			$context['merchant_code'] = $this->merchant_id;
		}

		return $context;
	}

	/**
	 * Determine whether a stored checkout should be recreated because the order drifted.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param mixed    $sumup_checkout Stored SumUp checkout data.
	 * @return bool
	 */
	public function should_refresh_checkout_for_order( $order, $sumup_checkout ) {
		if ( ! is_array( $sumup_checkout ) || empty( $sumup_checkout['id'] ) ) {
			return true;
		}

		$expected_reference = 'WC_SUMUP_' . $order->get_id();
		$checkout_reference = isset( $sumup_checkout['checkout_reference'] ) ? sanitize_text_field( $sumup_checkout['checkout_reference'] ) : '';
		if ( $checkout_reference !== $expected_reference ) {
			return true;
		}

		$checkout_status = isset( $sumup_checkout['status'] ) ? sanitize_text_field( $sumup_checkout['status'] ) : '';
		if ( 'PAID' === $checkout_status ) {
			return false;
		}

		if ( in_array( $checkout_status, array( 'FAILED', 'EXPIRED' ), true ) ) {
			return true;
		}
		if ( ! empty( $sumup_checkout['valid_until'] ) ) {
			$expires = is_string( $sumup_checkout['valid_until'] ) ? strtotime( $sumup_checkout['valid_until'] ) : false;
			if ( false === $expires || $expires <= time() ) {
				return true;
			}
		}
		if ( ! empty( $sumup_checkout['transaction_code'] ) ) {
			return false;
		}

		$checkout_amount = isset( $sumup_checkout['amount'] ) ? (float) $sumup_checkout['amount'] : null;
		if ( null === $checkout_amount || abs( $checkout_amount - (float) $order->get_total() ) > 0.00001 ) {
			return true;
		}

		$checkout_currency = isset( $sumup_checkout['currency'] ) ? strtoupper( sanitize_text_field( $sumup_checkout['currency'] ) ) : '';
		if ( '' === $checkout_currency || $checkout_currency !== strtoupper( $order->get_currency() ) ) {
			return true;
		}

		if (
			empty( $sumup_checkout['_sumup_context'] ) ||
			! is_array( $sumup_checkout['_sumup_context'] )
		) {
			return true;
		}

		$stored_context         = $sumup_checkout['_sumup_context'];
		$stored_total           = isset( $stored_context['order_total'] ) ? (float) $stored_context['order_total'] : null;
		$stored_currency        = isset( $stored_context['currency'] ) ? strtoupper( sanitize_text_field( $stored_context['currency'] ) ) : '';
		$stored_billing_country = isset( $stored_context['billing_country'] ) ? strtoupper( sanitize_text_field( $stored_context['billing_country'] ) ) : '';
		$stored_order_status    = isset( $stored_context['order_status'] ) ? sanitize_text_field( $stored_context['order_status'] ) : '';

		if ( null === $stored_total || abs( $stored_total - (float) $order->get_total() ) > 0.00001 ) {
			return true;
		}

		if ( '' === $stored_currency || $stored_currency !== strtoupper( $order->get_currency() ) ) {
			return true;
		}

		if ( $stored_billing_country !== strtoupper( $order->get_billing_country() ) ) {
			return true;
		}

		if ( '' === $stored_order_status || $stored_order_status !== $order->get_status() ) {
			return true;
		}

		return false;
	}

	/** Order-pay can prepare SumUp before the form persists its selected gateway. */
	public function can_store_payment_instructions_for_order( $order ) {
		$stored = $order->get_meta( '_sumup_checkout_data' );
		return 'yes' === $this->enabled && ! empty( $stored['id'] )
			&& $order->has_status( array( 'pending', 'failed', 'on-hold' ) )
			&& $this->can_pay_in_currency( $order->get_currency() )
			&& $this->validate_checkout_for_order( $order, $stored, $stored['id'] )['valid'];
	}

	/**
	 * Keep pending vouchers inside SumUp's widget; browser artefacts are not payment proof.
	 *
	 * A provider GET verifies checkout state, but does not document recoverable PIX/Boleto
	 * instructions. Never persist or display the widget's browser-supplied payment content.
	 */
	public function verify_pending_payment_widget( $order, $context ) {
		$error    = new WP_Error( 'invalid_order', __( 'Order validation failed.', 'sumup-payment-gateway-for-woocommerce' ) );
		$id       = is_string( $context['checkout_id'] ?? null ) ? $context['checkout_id'] : '';
		$amount   = $context['amount'] ?? null;
		$currency = $context['currency'] ?? null;
		if ( ! $id || ! is_numeric( $amount ) || ! is_finite( (float) $amount ) || ! is_string( $currency )
			|| ! $this->can_store_payment_instructions_for_order( $order )
			|| ( $order->get_meta( '_sumup_checkout_data' )['id'] ?? '' ) !== $id
			|| $currency !== $order->get_currency() || abs( (float) $amount - (float) $order->get_total() ) > 0.00001 ) {
			return $error;
		}
		$settings = sumup_get_gateway_settings();
		$identity = array( $order->get_order_key(), $order->get_customer_id(), $order->get_payment_method(), $order->get_billing_country() );
		$token    = $this->get_access_token();
		$remote   = $token ? Wc_Sumup_Checkout::get( $id, $token, $this->get_order_log_context( $order ) ) : array();
		try {
			$fresh = WC_Sumup_Order_Lock::read_order( $order->get_id() );
		} catch ( Throwable $failure ) {
			return $error;
		}
		// Provider I/O must not authorize a widget whose customer, key or checkout changed.
		if ( ! $fresh || array( $fresh->get_order_key(), $fresh->get_customer_id(), $fresh->get_payment_method(), $fresh->get_billing_country() ) !== $identity
			|| sumup_get_gateway_settings() !== $settings ) {
			return $error;
		}
		if ( $fresh->is_paid() ) {
			return array( 'stored' => false );
		}
		$stored = $fresh->get_meta( '_sumup_checkout_data' );
		if ( ! $this->can_store_payment_instructions_for_order( $fresh ) || ( $stored['id'] ?? '' ) !== $id || 'PENDING' !== ( $stored['status'] ?? '' )
			|| $currency !== $fresh->get_currency() || abs( (float) $amount - (float) $fresh->get_total() ) > 0.00001 ) {
			return $error;
		}
		foreach ( array( $stored, $remote ) as $checkout ) {
			if ( ! empty( $checkout['valid_until'] ) ) {
				$expiry = is_string( $checkout['valid_until'] ) ? strtotime( $checkout['valid_until'] ) : false;
				if ( false === $expiry || $expiry <= time() ) {
					return $error;
				}
			}
		}
		if ( ! empty( $remote ) ) {
			if ( ! is_numeric( $remote['amount'] ?? null ) || ! is_finite( (float) $remote['amount'] )
				|| ! $this->validate_checkout_for_order( $fresh, $remote, $id )['valid']
				|| ! in_array( $remote['status'] ?? '', array( 'PENDING', 'PAID' ), true ) ) {
				return $error;
			}
			if ( 'PAID' === $remote['status'] ) {
				// The authorized return flow performs the existing idempotent reconciliation.
				return array( 'stored' => false );
			}
		}
		// An outage preserves only the still-bound provider widget, never payment artefacts.
		return array(
			'stored'      => false,
			'widget_only' => true,
		);
	}

	/** Compatibility no-op: browser payment instructions have no trusted persistence source. */
	public function store_pending_payment_instructions( $order, $instructions ) {
	}

	/** Historical metadata was supplied by browsers and must never be presented as trusted. */
	public function get_pending_payment_instructions( $order ) {
		return array();
	}

	/**
	 * Clear stored pending voucher-style payment instructions from the order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return void
	 */
	public function clear_pending_payment_instructions( $order ) {
		$order->delete_meta_data( '_sumup_pending_payment_instructions' );
		$order->save();
	}

	/**
	 * Extract the transaction code from a checkout payload.
	 *
	 * @param array $checkout_data SumUp checkout payload.
	 * @return string
	 */
	private function extract_transaction_code( $checkout_data ) {
		$transaction_code = isset( $checkout_data['transaction_code'] ) ? sanitize_text_field( $checkout_data['transaction_code'] ) : '';
		if ( ! empty( $transaction_code ) ) {
			return $transaction_code;
		}

		if ( empty( $checkout_data['transactions'] ) || ! is_array( $checkout_data['transactions'] ) ) {
			return '';
		}

		foreach ( $checkout_data['transactions'] as $transaction ) {
			if ( ! is_array( $transaction ) || empty( $transaction['transaction_code'] ) || 'SUCCESSFUL' !== ( $transaction['status'] ?? '' ) ) {
				continue;
			}

			return sanitize_text_field( $transaction['transaction_code'] );
		}

		return '';
	}

	/**
	 * Ensure the fetched SumUp checkout still matches the local WooCommerce order.
	 *
	 * Webhook payloads are treated as untrusted hints; order mutations only happen once
	 * the latest remote checkout state is bound back to the local order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param array    $checkout_data Latest checkout payload from SumUp.
	 * @param string   $checkout_id Checkout ID from the trigger path.
	 * @return array{valid: bool, error: string}
	 */
	private function validate_checkout_for_order( $order, $checkout_data, $checkout_id = '' ) {
		$checkout_id         = sanitize_text_field( (string) $checkout_id );
		$stored_checkout     = $order->get_meta( '_sumup_checkout_data' );
		$remote_checkout_id  = isset( $checkout_data['id'] ) ? sanitize_text_field( $checkout_data['id'] ) : '';
		$expected_reference  = 'WC_SUMUP_' . $order->get_id();
		$remote_reference    = isset( $checkout_data['checkout_reference'] ) ? sanitize_text_field( $checkout_data['checkout_reference'] ) : '';
		$remote_currency     = isset( $checkout_data['currency'] ) ? strtoupper( sanitize_text_field( $checkout_data['currency'] ) ) : '';
		$order_currency      = strtoupper( $order->get_currency() );
		$remote_amount       = isset( $checkout_data['amount'] ) ? (float) $checkout_data['amount'] : null;
		$order_amount        = (float) $order->get_total();
		$has_stored_checkout = is_array( $stored_checkout ) && ! empty( $stored_checkout['id'] );

		if ( '' === $remote_checkout_id ) {
			return array(
				'valid' => false,
				'error' => 'Fetched checkout ID is missing.',
			);
		}

		if ( $expected_reference !== $remote_reference ) {
			return array(
				'valid' => false,
				'error' => 'Fetched checkout reference does not match the order reference.',
			);
		}

		if ( '' === $remote_currency || $order_currency !== $remote_currency ) {
			return array(
				'valid' => false,
				'error' => 'Fetched checkout currency does not match the order currency.',
			);
		}

		if ( null === $remote_amount || abs( $remote_amount - $order_amount ) > 0.00001 ) {
			return array(
				'valid' => false,
				'error' => 'Fetched checkout amount does not match the order total.',
			);
		}

		if ( $has_stored_checkout ) {
			$stored_checkout_id = sanitize_text_field( $stored_checkout['id'] );

			if ( '' !== $checkout_id && $stored_checkout_id !== $checkout_id ) {
				return array(
					'valid' => false,
					'error' => 'Triggered checkout ID does not match the order checkout ID.',
				);
			}

			if ( $stored_checkout_id !== $remote_checkout_id ) {
				return array(
					'valid' => false,
					'error' => 'Fetched checkout ID does not match the order checkout ID.',
				);
			}
		} elseif ( '' !== $checkout_id && $remote_checkout_id !== $checkout_id ) {
			return array(
				'valid' => false,
				'error' => 'Triggered checkout ID does not match the fetched checkout ID.',
			);
		}

		if ( ! WC_Sumup_Checkout_Service::matches_account( $checkout_data, $this->build_checkout_request_data( $order ) ) ) {
			return array(
				'valid' => false,
				'error' => 'Fetched checkout account does not match the configured account.',
			);
		}

		if ( ! $has_stored_checkout ) {
			$rebound_checkout = $this->enrich_checkout_data_for_order(
				$order,
				$checkout_data,
				! empty( $checkout_data['isCheckoutBlocks'] )
			);
			$order->update_meta_data( '_sumup_checkout_data', $rebound_checkout );
			$order->save();
			WC_SUMUP_LOGGER::log(
				'Recovered missing SumUp checkout binding from remote checkout data.',
				$this->get_checkout_log_context(
					$rebound_checkout,
					$this->get_order_log_context(
						$order,
						array(
							'checkout_id' => $remote_checkout_id,
							'flow'        => 'webhook_rebind',
						)
					)
				),
				'warning'
			);
		}

		return array(
			'valid' => true,
			'error' => '',
		);
	}

	/**
	 * Update order status based on payment status
	 *
	 * @param WC_Order $order
	 * @param array $checkout_data
	 * @param string $transaction_code
	 * @return void
	 */
	private function update_order_status( $order, $checkout_data, $transaction_code ) {
		$payment_status = $checkout_data['status'] ?? '';

		// Check if the current status isn't processing or completed.
		if ( ! $this->should_update_order_status( $order ) ) {
			return;
		}

		if ( $payment_status === 'PAID' ) {
			$this->process_paid_order( $order, $transaction_code );
		} elseif ( $payment_status === 'FAILED' && ! $order->has_status( 'failed' ) ) {
			$this->process_failed_order( $order );
		}
	}

	/**
	 * Check if order status should be updated
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	private function should_update_order_status( $order ) {
		return ! in_array(
			$order->get_status(),
			array(
				'processing',
				'completed',
				'refunded',
				'cancelled',
			),
			true
		);
	}

	/**
	 * Process paid order
	 *
	 * @param WC_Order $order
	 * @param string $transaction_code
	 * @return void
	 */
	private function process_paid_order( $order, $transaction_code ) {
		$this->clear_pending_payment_instructions( $order );
		$order->update_meta_data( '_sumup_transaction_code', $transaction_code );

		$this->add_order_note( $order, $transaction_code );
		$order->payment_complete( $transaction_code );
		$this->execute_payment_complete_hooks( $order );
		$order->save();
	}

	/**
	 * Process failed order
	 *
	 * @param WC_Order $order
	 * @return void
	 */
	private function process_failed_order( $order ) {
		$this->clear_pending_payment_instructions( $order );
		$order->update_status( 'failed' );
		$message = __( 'SumUp payment failed.', 'sumup-payment-gateway-for-woocommerce' );
		$order->add_order_note( $message );
		$order->save();
	}

	/**
	 * Add order note for successful payment
	 *
	 * @param WC_Order $order
	 * @param string $transaction_code
	 * @return void
	 */
	private function add_order_note( $order, $transaction_code ) {
		$message = sprintf(
			/* translators: %s: transaction code. */
			__( 'SumUp charge complete. Transaction Code: %s', 'sumup-payment-gateway-for-woocommerce' ),
			$transaction_code
		);
		$order->add_order_note( $message );
	}

	/**
	 * Execute payment complete hooks
	 *
	 * @param WC_Order $order
	 * @return void
	 */
	private function execute_payment_complete_hooks( $order ) {
		do_action( 'sumup_gateway_payment_complete_from_hook', $order );
		do_action( 'sumup_gateway_payment_complete', $order );
	}


	private function log_checkout_validation_error( $order_id, $checkout_id, $error ) {
		$order   = wc_get_order( $order_id );
		$context = array(
			'flow'        => 'checkout_validation',
			'checkout_id' => $checkout_id,
			'error'       => $error,
		);

		if ( $order instanceof WC_Order ) {
			$context = $this->get_order_log_context( $order, $context );
		} else {
			$context['order_id'] = $order_id;
			$context             = $this->get_gateway_log_context( $context );
		}

		WC_SUMUP_LOGGER::log( 'Checkout validation failed.', $context, 'warning' );
	}

	/**
	 * Handle webhook with retry logic and exponential backoff
	 *
	 * @param array $data Webhook data
	 * @param int $attempt Current attempt number
	 * @return void
	 */
	public function handle_webhook_order_with_retry( $data, $attempt = 1 ) {
		$attempt = max( 1, is_numeric( $attempt ) ? (int) $attempt : 1 );
		$started = microtime( true );
		$context = array(
			'flow'        => 'webhook_retry',
			'checkout_id' => $data['id'] ?? '',
			'attempt'     => $attempt,
		);
		try {
			$result = $this->process_webhook_data( $data );
		} catch ( Throwable $error ) {
			$result = array(
				'success'        => false,
				'critical_error' => false,
				'error'          => 'Webhook processing failed.',
			);
		}
		$context['execution_time_seconds'] = microtime( true ) - $started;
		if ( $result['success'] ) {
			WC_Sumup_Connection_Diagnostics::verified(
				$context['checkout_id'],
				WC_Sumup_Connection_Diagnostics::account_scope(
					array(
						'merchant_id'   => $this->merchant_id,
						'pay_to_email'  => $this->pay_to_email,
						'api_key'       => $this->api_key,
						'client_id'     => $this->client_id,
						'client_secret' => $this->client_secret,
					)
				)
			);
			WC_SUMUP_LOGGER::log( 'Webhook processed successfully.', $context, 'info' );
			return;
		}
		$retry            = ! $result['critical_error'] && $attempt < $this->webhook_retry_attempts;
		$context['error'] = $result['error'];
		WC_SUMUP_LOGGER::log( $retry ? 'Retrying webhook.' : 'Webhook processing stopped.', $context, $retry ? 'warning' : 'error' );
		if ( $retry ) {
			$this->schedule_webhook( $data, $attempt + 1 );
		}
	}


	/**
	 * Process webhook data and return result
	 *
	 * @param array $data Webhook data
	 * @return array Result with success status and error info
	 */
	private function process_webhook_data( $data ) {
		// Validate webhook data structure
		$validation_result = $this->validate_webhook_request( $data );
		if ( ! $validation_result['valid'] ) {
			return array(
				'success'        => false,
				'critical_error' => $validation_result['critical'],
				'error'          => $validation_result['error'],
			);
		}

		$checkout_id = sanitize_text_field( $data['id'] );

		// Get access token
		$log_context = $this->get_gateway_log_context(
			array(
				'flow'        => 'webhook_retry',
				'checkout_id' => $checkout_id,
			)
		);

		$access_token = $this->get_access_token( $log_context );
		if ( empty( $access_token ) ) {
			return array(
				'success'        => false,
				'critical_error' => false,
				'error'          => 'Failed to obtain access token',
			);
		}

		// Fetch checkout data
		$checkout_data = $this->fetch_checkout_data( $checkout_id, $access_token, $log_context );
		if ( empty( $checkout_data ) ) {
			return array(
				'success'        => false,
				'critical_error' => false,
				'error'          => 'Failed to obtain checkout data',
			);
		}

		// Process the order
		return $this->process_order_from_checkout( $checkout_data, $checkout_id );
	}

	/**
	 * Validate webhook request data
	 *
	 * @param array $data
	 * @return array
	 */
	private function validate_webhook_request( $data ) {
		if ( ! $this->validate_webhook_data( $data ) ) {
			return array(
				'valid'    => false,
				'critical' => true,
				'error'    => 'Invalid webhook data',
			);
		}

		$event_type = sanitize_text_field( $data['event_type'] );
		if ( ! $this->validate_event_type( $event_type ) ) {
			return array(
				'valid'    => false,
				'critical' => true,
				'error'    => 'Invalid event type: ' . $event_type,
			);
		}

		return array(
			'valid'    => true,
			'critical' => false,
			'error'    => '',
		);
	}

	/**
	 * Process order from checkout data
	 *
	 * @param array $checkout_data Checkout data from SumUp API
	 * @param string $checkout_id Checkout ID
	 * @return array Result array
	 */
	private function process_order_from_checkout( $checkout_data, $checkout_id, $expected_order_id = 0 ) {
		$order_id = $this->extract_order_id( $checkout_data );
		if ( ! $order_id || ( $expected_order_id && $order_id !== (int) $expected_order_id ) ) {
			return array(
				'success'        => false,
				'critical_error' => true,
				'error'          => 'Checkout order reference does not match.',
			);
		}
		$lock = WC_Sumup_Order_Lock::acquire( $order_id );
		if ( ! $lock ) {
			return array(
				'success'        => false,
				'critical_error' => false,
				'error'          => 'Payment is already being reconciled.',
			);
		}
		try {
			$order = WC_Sumup_Order_Lock::read_order( $order_id, false );
			if ( ! $order instanceof WC_Order ) {
				return array(
					'success'        => false,
					'critical_error' => true,
					'error'          => 'Order not found.',
				);
			}
			$validation = $this->validate_checkout_for_order( $order, $checkout_data, $checkout_id );
			if ( ! $validation['valid'] ) {
				$this->log_checkout_validation_error( $order_id, $checkout_id, $validation['error'] );
				return array(
					'success'        => false,
					'critical_error' => true,
					'error'          => $validation['error'],
				);
			}
			$transaction_code = $this->extract_transaction_code( $checkout_data );
			if ( 'PAID' === ( $checkout_data['status'] ?? '' ) && empty( $transaction_code ) ) {
				return array(
					'success'        => false,
					'critical_error' => false,
					'error'          => 'Transaction code is missing.',
				);
			}
			if ( ! WC_Sumup_Order_Lock::owns( $order_id, $lock ) ) {
				return array(
					'success'        => false,
					'critical_error' => false,
					'error'          => 'Payment lock ownership changed.',
				);
			}
			$this->update_order_status( $order, $checkout_data, $transaction_code );
			return array(
				'success'        => true,
				'critical_error' => false,
				'error'          => '',
				'order'          => $order,
			);
		} catch ( Throwable $error ) {
			WC_SUMUP_LOGGER::log(
				'Payment reconciliation failed.',
				array(
					'order_id'    => $order_id,
					'checkout_id' => $checkout_id,
				),
				'error'
			);
			return array(
				'success'        => false,
				'critical_error' => false,
				'error'          => 'Unable to reconcile payment. Please try again.',
			);
		} finally {
			WC_Sumup_Order_Lock::release( $order_id, $lock );
		}
	}

	/**
	 * Extract order ID from checkout data
	 *
	 * @param array $checkout_data
	 * @return int
	 */
	private function extract_order_id( $checkout_data ) {
		$checkout_reference = $checkout_data['checkout_reference'] ?? '';
		$order_id           = str_replace( 'WC_SUMUP_', '', $checkout_reference );
		return intval( $order_id );
	}

	/**
	 * Method used on flows with redirect (like 3Ds)
	 */
	public function check_redirect_flow() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This return marker only selects the flow; the resolver authorizes the order and verifies provider status.
		if ( ! isset( $_GET['sumup-validate-order'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The resolver checks the order key and customer/session before provider access or order updates.
		$order_id = isset( $_GET['sumup-validate-order'] ) ? absint( wp_unslash( $_GET['sumup-validate-order'] ) ) : 0;
		if ( ! $order_id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Return authorization uses the order key, not a WordPress nonce.
		if ( isset( $_GET['key'] ) && ! is_scalar( $_GET['key'] ) ) {
			wp_die(
				esc_html__( 'Order validation failed. Refresh checkout and try again.', 'sumup-payment-gateway-for-woocommerce' ),
				'',
				array( 'response' => 400 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The resolver binds this ID to the authorized order before retrieving and verifying provider status.
		$url_checkout_id = isset( $_GET['checkout_id'] ) ? sanitize_text_field( wp_unslash( $_GET['checkout_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A provider return is authorized by its order key or verified legacy customer/session, not a browser nonce.
		$order_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';

		$result = $this->resolve_payment_redirect_validation( $order_id, $url_checkout_id, true, $order_key );
		if ( ! empty( $result['error'] ) ) {
			wc_add_notice( $result['error'], 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}
	}

	/**
	 * Resolve payment status after SumUp redirect (3DS and similar flows).
	 *
	 * @param int    $order_id Order ID from the redirect query string.
	 * @param string $url_checkout_id Optional checkout ID from the redirect query string.
	 * @param bool   $redirect Whether to redirect or return a result array.
	 * @param string $order_key WooCommerce order key authorizing this return.
	 * @return array<string, mixed>
	 */
	public function resolve_payment_redirect_validation( $order_id, $url_checkout_id = '', $redirect = true, $order_key = '' ) {
		$order_id        = absint( $order_id );
		$url_checkout_id = sanitize_text_field( (string) $url_checkout_id );
		$result          = array(
			'status'        => '',
			'message'       => '',
			'redirect_url'  => '',
			'is_blocks'     => false,
			'cart_restored' => false,
			'error'         => '',
		);

		if ( ! $order_id ) {
			$result['error'] = __( 'Invalid order ID.', 'sumup-payment-gateway-for-woocommerce' );
			return $result;
		}

		$order = wc_get_order( $order_id );
		if ( $order === false ) {
			WC_SUMUP_LOGGER::log(
				'Order not found during payment redirect validation.',
				$this->get_gateway_log_context(
					array(
						'flow'     => 'redirect_validation',
						'order_id' => $order_id,
					)
				),
				'error'
			);
			$result['error'] = __( 'Order not found.', 'sumup-payment-gateway-for-woocommerce' );
			return $result;
		}

		// A guest nonce or checkout ID does not authorize access to an order.
		if ( '' === $order_key ) {
			// Temporary support for pre-upgrade keyless URLs; see the bridge's removal note.
			$order_key = WC_Sumup_Legacy_Payment_Return::get_order_key( $order, $url_checkout_id );
		}
		$order_access = $this->validate_order_access_for_checkout( $order, is_string( $order_key ) ? $order_key : '' );
		if ( ! $order_access['valid'] ) {
			$result['error'] = __( 'Unable to verify access to this order. Please sign in or reopen the original payment link. If you have already paid, do not pay again.', 'sumup-payment-gateway-for-woocommerce' );
			return $result;
		}

		$log_context = $this->get_order_log_context( $order, array( 'flow' => 'redirect_validation' ) );

		$checkout_data = $order->get_meta( '_sumup_checkout_data' );
		if ( ! is_array( $checkout_data ) || empty( $checkout_data['id'] ) ) {
			WC_SUMUP_LOGGER::log( 'Stored checkout data is missing during payment redirect validation.', $log_context, 'warning' );
			$result['error'] = __( 'Payment details are missing for this order.', 'sumup-payment-gateway-for-woocommerce' );
			return $result;
		}

		$checkout_id = sanitize_text_field( (string) $checkout_data['id'] );
		if ( '' !== $url_checkout_id && $url_checkout_id !== $checkout_id ) {
			$result['error'] = __( 'Payment validation failed.', 'sumup-payment-gateway-for-woocommerce' );
			return $result;
		}
		$result['is_blocks'] = ! empty( $checkout_data['isCheckoutBlocks'] );

		$access_token = Wc_Sumup_Access_Token::get( $this->client_id, $this->client_secret, $this->api_key, false, array_merge( $log_context, array( 'checkout_id' => $checkout_id ) ) );
		$access_token = $access_token['access_token'] ?? '';
		if ( empty( $access_token ) ) {
			WC_SUMUP_LOGGER::log( 'Error while retrieving access token during payment redirect validation.', array_merge( $log_context, array( 'checkout_id' => $checkout_id ) ), 'error' );
			$result['error'] = __( 'SumUp is temporarily unavailable. Please try again.', 'sumup-payment-gateway-for-woocommerce' );
			return $result;
		}

		$sumup_checkout = Wc_Sumup_Checkout::get( $checkout_id, $access_token, array_merge( $log_context, array( 'checkout_id' => $checkout_id ) ) );
		if ( empty( $sumup_checkout ) ) {
			WC_SUMUP_LOGGER::log( 'Error while retrieving checkout during payment redirect validation.', array_merge( $log_context, array( 'checkout_id' => $checkout_id ) ), 'error' );
			$result['error'] = __( 'Unable to verify payment status. Please try again.', 'sumup-payment-gateway-for-woocommerce' );
			return $result;
		}

		$reconciled = $this->process_order_from_checkout( $sumup_checkout, $checkout_id, $order_id );
		if ( ! $reconciled['success'] && $reconciled['critical_error'] ) {
			$result['error'] = __( 'Payment validation failed.', 'sumup-payment-gateway-for-woocommerce' );
			return $result;
		}
		$order = $reconciled['order'] ?? $order;
		if ( $reconciled['success'] && ! $this->should_update_order_status( $order ) ) {
			$result['status']       = $order->is_paid() ? 'paid' : $order->get_status();
			$result['redirect_url'] = $this->get_return_url( $order );
			if ( $redirect ) {
				wp_safe_redirect( $result['redirect_url'] );
				exit;
			}
			return $result;
		}
		$payment_status   = $reconciled['success'] ? ( $sumup_checkout['status'] ?? '' ) : 'PENDING';
		$result['status'] = strtolower( (string) $payment_status );

		if ( $payment_status === 'PENDING' ) {
			$result['message'] = __( 'We are still waiting for SumUp to confirm this payment. Please wait a moment and refresh the page if the order is not updated automatically.', 'sumup-payment-gateway-for-woocommerce' );

			if ( $result['is_blocks'] ) {
				if ( $redirect ) {
					wp_safe_redirect(
						add_query_arg( 'sumup_payment', 'pending', wc_get_checkout_url() )
					);
					exit;
				}
				return $result;
			}

			if ( $redirect ) {
				add_action( 'woocommerce_before_checkout_form', array( $this, 'redirect_validation_pending_message' ) );
			}
			return $result;
		}

		if ( $payment_status === 'FAILED' ) {
			$result['message'] = __( 'Payment failed, please try again.', 'sumup-payment-gateway-for-woocommerce' );

			if ( $result['is_blocks'] ) {
				$result['cart_restored'] = $this->restore_cart_from_order( $order );

				if ( $redirect ) {
					wp_safe_redirect(
						add_query_arg( 'sumup_payment', 'failed', wc_get_checkout_url() )
					);
					exit;
				}

				return $result;
			}

			if ( $redirect ) {
				add_action( 'woocommerce_before_checkout_form', array( $this, 'redirect_validation_failed_message' ) );
			}
			return $result;
		}

		return $result;
	}

	/**
	 * Restore the current cart from a WooCommerce order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	public function restore_cart_from_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		if ( null === WC()->cart ) {
			wc_load_cart();
		}

		if ( ! WC()->cart ) {
			return false;
		}

		WC()->cart->empty_cart();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product_id   = (int) $item->get_product_id();
			$variation_id = (int) $item->get_variation_id();
			$quantity     = $item->get_quantity();

			if ( $product_id <= 0 || $quantity <= 0 ) {
				continue;
			}

			$variation_attributes = array();

			if ( $variation_id > 0 ) {
				$variation = wc_get_product( $variation_id );
				if ( $variation instanceof WC_Product_Variation ) {
					$variation_attributes = $variation->get_variation_attributes();
				}
			}

			WC()->cart->add_to_cart(
				$product_id,
				$quantity,
				$variation_id,
				$variation_attributes
			);
		}

		foreach ( $order->get_coupon_codes() as $code ) {
			WC()->cart->apply_coupon( $code );
		}

		WC()->cart->calculate_totals();

		return true;
	}

	/**
	 * Redirect validation failed message
	 */
	public function redirect_validation_failed_message() {
		$failed_message = sprintf(
			'<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout woocommerce-error">%1$s</div>',
			__( 'Payment failed, please try again.', 'sumup-payment-gateway-for-woocommerce' )
		);
		echo wp_kses_post( $failed_message );

		?>
		<script>
			if (document.readyState === 'complete') {
				sumUpSubmitOrderAfterRedirect();
			} else {
				window.addEventListener('load', () => {
					sumUpSubmitOrderAfterRedirect();
				});
			}

			function sumUpSubmitOrderAfterRedirect() {
				const submitOrderButton = document.querySelector('form button#place_order');
				if (submitOrderButton) {
					submitOrderButton.click();
				}
			}
		</script>
		<?php
	}

	/**
	 * Redirect validation pending message
	 *
	 * @return void
	 */
	public function redirect_validation_pending_message() {
		$pending_message = sprintf(
			'<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout woocommerce-info">%1$s</div>',
			__( 'We are still waiting for SumUp to confirm this payment. Please wait a moment and refresh the page if the order is not updated automatically.', 'sumup-payment-gateway-for-woocommerce' )
		);
		echo wp_kses_post( $pending_message );
	}

	/**
	 * Initialise gateway settings form fields
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	public function init_form_fields() {
		$this->form_fields = require WC_SUMUP_PLUGIN_PATH . '/includes/class-wc-sumup-settings.php';
	}

	/**
	 * Process the payment.
	 *
	 * @param int $order_id
	 * @since     1.0.0
	 * @version   1.0.0
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return array(
				'result'   => 'failure',
				'messages' => __( 'Order is unavailable.', 'sumup-payment-gateway-for-woocommerce' ),
			);
		}

		if ( 'yes' !== $this->enabled || ! $order->needs_payment() || ! $this->can_pay_in_currency( $order->get_currency() ) ) {
			wc_add_notice( __( 'SumUp is unavailable for this order.', 'sumup-payment-gateway-for-woocommerce' ), 'error' );
			return array(
				'result'    => 'failure',
				'openModal' => false,
			);
		}

		$is_blocks = $this->is_blocks_checkout_request( $order->get_meta( '_sumup_checkout_data' ) );
		$token     = Wc_Sumup_Access_Token::get( $this->client_id, $this->client_secret, $this->api_key, false, $this->get_order_log_context( $order ) );
		$checkout  = empty( $token['access_token'] ) ? array() : WC_Sumup_Checkout_Service::prepare( $this, $order, $token['access_token'], $is_blocks );
		if ( empty( $checkout['id'] ) ) {
			$message = __( 'Unable to initialize SumUp payment. Please try again.', 'sumup-payment-gateway-for-woocommerce' );
			if ( $is_blocks ) {
				throw new Exception( esc_html( $message ) ); }
			wc_add_notice( $message, 'error' );
			return array(
				'result'    => 'failure',
				'redirect'  => false,
				'openModal' => false,
				'messages'  => $message,
			);
		}
		return array_merge(
			$this->get_widget_context_for_order( $order ),
			array(
				'result'     => 'success',
				'redirect'   => $this->get_return_url( $order ),
				'openModal'  => true,
				'checkoutId' => $checkout['id'],
			)
		);
	}

	/**
	 * Builds our payment fields area. Initializes the SumUp's Card Widget.
	 *
	 * @since    1.0.0;
	 * @version  1.0.0;
	 */
	public function payment_fields() {
		if ( ! is_checkout() ) {
			return;
		}

		if ( ! sumup_gateway_is_configured( $this->settings ) ) {
			esc_html_e( 'Error: Merchant account settings are incorrectly configured. Check the plugin settings page.', 'sumup-payment-gateway-for-woocommerce' );
			return;
		}

		if ( ! is_wc_endpoint_url( 'order-pay' ) ) {
			echo '<p>' . esc_html( $this->description ) . '</p>';
			$extra_class = $this->open_payment_in_modal === 'yes' ? 'modal' : 'no-modal';

			?>
			<div id="wc-sumup-payment-modal" class="wc-sumup-modal disabled <?php echo esc_attr( $extra_class ); ?>">
				<div id="sumup-card">
					<button id="wc-sumup-payment-modal-close" type="button" aria-label="<?php esc_attr_e( 'Close payment modal', 'sumup-payment-gateway-for-woocommerce' ); ?>">
						<span id="wc-sumup-payment-modal-close-btn" aria-hidden="true"></span>
					</button>
				</div>
			</div>
			<?php
			return;
		}

		/**
		 * Required fileds to request somethings to SumUp - Refator to meke the first verification more complete.
		 */
		if ( empty( $this->merchant_id ) && empty( $this->pay_to_email ) ) {
			WC_SUMUP_LOGGER::log(
				'Gateway configuration is incomplete: missing Merchant code.',
				$this->get_gateway_log_context( array( 'flow' => 'order_pay' ) ),
				'error'
			);
			$message = current_user_can( 'manage_options' )
				? __( 'Please fill "Merchant code" on the plugin settings.', 'sumup-payment-gateway-for-woocommerce' )
				: __( 'Sorry, SumUp is not available. Try again soon.', 'sumup-payment-gateway-for-woocommerce' );
			echo wp_kses_post( $this->print_error_message( $message ) );
			return;
		}

		$description = $this->get_description();
		if ( $description ) {
			echo wp_kses_post( wpautop( wptexturize( $description ) ) );
		}

		$sumup_settings = get_option( 'woocommerce_sumup_settings', false );
		if ( empty( $sumup_settings ) ) {
			$unavaliable_message = sprintf(
				'<p>%s</p>',
				__( 'Sum up is temporarily unavailable. Please contact site admin for more information.', 'sumup-payment-gateway-for-woocommerce' ),
			);

			echo wp_kses_post( $unavaliable_message );
			return;
		}

		$order_id = absint( get_query_var( 'order-pay' ) );
		$order    = wc_get_order( $order_id );
		if ( $order === false ) {
			echo '<p>' . esc_html__( 'Order ID is not available to make the payment. Try again soon or contact the website support.', 'sumup-payment-gateway-for-woocommerce' ) . '</p>';
			return;
		}

		$log_context = $this->get_order_log_context( $order, array( 'flow' => 'order_pay' ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- validate_order_access_for_checkout checks this order key and customer before checkout creation or display.
		$order_key    = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$order_access = $this->validate_order_access_for_checkout( $order, $order_key );
		if ( ! $order_access['valid'] ) {
			WC_SUMUP_LOGGER::log( 'Rejected order-pay rendering because order validation failed.', $this->get_order_log_context( $order, array( 'flow' => 'order_pay' ) ), 'warning' );
			echo wp_kses_post( $this->print_error_message( $order_access['error'] ) );
			return;
		}

		if ( 'yes' !== $this->enabled || ! $order->needs_payment() || ! $this->can_pay_in_currency( $order->get_currency() ) ) {
			echo wp_kses_post( $this->print_error_message( __( 'SumUp is unavailable for this order currency.', 'sumup-payment-gateway-for-woocommerce' ) ) );
			return;
		}

		$access_token = Wc_Sumup_Access_Token::get( $this->client_id, $this->client_secret, $this->api_key, false, $log_context );
		if ( ! isset( $access_token['access_token'] ) ) {
			WC_SUMUP_LOGGER::log( 'Error on request to get access token.', $log_context, 'error' );
			$message = current_user_can( 'manage_options' ) ? 'Error to generate SumUp access token.' : 'Sorry, SumUp is not available. Try again soon.';
			echo wp_kses_post( $this->print_error_message( $message ) );
			return;
		}

		update_option( 'woocommerce_sumup_settings', $sumup_settings );

		$sumup_checkout = WC_Sumup_Checkout_Service::prepare( $this, $order, $access_token['access_token'] );
		if ( empty( $sumup_checkout['id'] ) ) {
			echo wp_kses_post( $this->print_error_message( __( 'Unable to initialize SumUp payment. Please try again.', 'sumup-payment-gateway-for-woocommerce' ) ) );
			return;
		}

		/**
		 * Fallback to fill merchant code to "old" users. Temporary solution while SumUp team check other ways to enable request to get merchant_code.
		 */
		if ( empty( $this->merchant_id ) && isset( $sumup_checkout['merchant_code'] ) ) {
			$this->update_option( 'merchant_id', $sumup_checkout['merchant_code'] );
		}

		if ( isset( $sumup_checkout['id'] ) ) {
			$extra_class    = $this->open_payment_in_modal === 'yes' ? '' : 'no-modal';
			$widget_context = $this->get_widget_context_for_order( $order );

			?>
			<div id="wc-sumup-payment-modal" class="wc-sumup-modal disabled <?php echo esc_attr( $extra_class ); ?>">
				<div id="sumup-card">
					<button id="wc-sumup-payment-modal-close" type="button" aria-label="<?php esc_attr_e( 'Close payment modal', 'sumup-payment-gateway-for-woocommerce' ); ?>">
						<span id="wc-sumup-payment-modal-close-btn" aria-hidden="true"></span>
					</button>
				</div>
			</div>

			<?php
			wc_enqueue_js(
				'const context = ' . wp_json_encode( array_merge( $widget_context, array( 'checkoutId' => $sumup_checkout['id'] ) ) ) . ';
				Object.assign(window.sumup_gateway_params, context);
				jQuery(document.body).trigger("sumupCardInit", [context]);'
			);
		}
	}

	/**
	 * Template to print error message to user on checkout
	 *
	 * @param string $error_message
	 * @return string
	 */
	private function print_error_message( $message ) {
		$error_message = sprintf(
			'<p>Error: %s</p>',
			esc_html( $message )
		);

		return wp_kses_post( $error_message );
	}

	/**
	 * Register the JavaScript scripts to the checkout page.
	 *
	 * @since    1.0.0
	 * @version  1.0.0
	 */
	public function payment_scripts() {
		/* Add JavaScript only on the checkout page */
		if ( ! is_cart() && ! is_checkout() ) {
			return;
		}

		/* Add JavaScript only if the plugin is enabled */
		if ( 'yes' !== $this->enabled ) {
			return;
		}

		/* Add JavaScript only if the plugin is set up correctly */
		if ( ! sumup_gateway_is_configured( $this->settings ) ) {
			return;
		}

		/*
		 * Use the SumUp's SDK for accepting card payments.
		 * Documentation can be found at https://developer.sumup.com/docs/widgets-card
		 */
		$front_script_asset     = sumup_get_build_asset_metadata(
			'build/sumup-gateway.asset.php',
			array( 'sumup_gateway_card_sdk' )
		);
		$process_checkout_asset = sumup_get_build_asset_metadata(
			'build/sumup-process-checkout.asset.php',
			array( 'jquery' )
		);
		wp_enqueue_script( 'sumup_gateway_card_sdk', 'https://gateway.sumup.com/gateway/ecom/card/v2/sdk.js', array(), WC_SUMUP_VERSION, false );
		wp_register_script( 'sumup_gateway_front_script', WC_SUMUP_PLUGIN_URL . 'build/sumup-gateway.js', $front_script_asset['dependencies'], $front_script_asset['version'], false );
		wp_register_script( 'sumup_gateway_process_checkout', WC_SUMUP_PLUGIN_URL . 'build/sumup-process-checkout.js', $process_checkout_asset['dependencies'], $process_checkout_asset['version'], true );
		wp_register_style( 'sumup-checkout', WC_SUMUP_PLUGIN_URL . 'build/sumup-process-checkout.css', array(), $process_checkout_asset['version'] );
		wp_enqueue_script( 'sumup_gateway_process_checkout' );
		wp_enqueue_style( 'sumup-checkout' );

		wp_localize_script( 'sumup_gateway_front_script', 'sumup_gateway_params', WC_Sumup_Widget_Settings::get( $this->settings ) );

		wp_enqueue_script( 'sumup_gateway_front_script' );
	}

	/**
	 * Verify if SumUp application credentials are valid when saving settings.
	 *
	 * @since    1.0.0
	 * @version  1.0.0
	 */
	public function verify_credentials() {
		$is_valid = Wc_Sumup_Credentials::validate();
		if ( ! $is_valid || ! Wc_Sumup_Apple_Pay::should_offer_apple_pay() ) {
			return;
		}

		$registered = Wc_Sumup_Apple_Pay::register_domain(
			array(
				'flow'              => 'settings_validation',
				'merchant_code'     => $this->merchant_id,
				'validation_result' => 'valid',
			)
		);

		if ( ! $registered ) {
			WC_Admin_Settings::add_error(
				__( 'Apple Pay domain registration could not be completed. Make sure your store is publicly accessible over HTTPS, check domain setup in SumUp Dashboard > Settings > For developers > Payment wallets, then save these settings to retry. Other SumUp payment settings have been saved.', 'sumup-payment-gateway-for-woocommerce' )
			);
		}
	}


	/**
	 * Add Instruction to pay Boleto (BR only) on WooCommerce Thank You page.
	 *
	 * @return void
	 * @since 2.0
	 */
	public function add_payment_instructions_thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order === false ) {
			WC_SUMUP_LOGGER::log(
				'Order not found on Thank You page request.',
				$this->get_gateway_log_context(
					array(
						'flow'     => 'thank_you',
						'order_id' => $order_id,
					)
				),
				'error'
			);
			return;
		}

		$log_context = $this->get_order_log_context( $order, array( 'flow' => 'thank_you' ) );

		$checkout_data = $order->get_meta( '_sumup_checkout_data' );
		if ( ! is_array( $checkout_data ) || empty( $checkout_data['id'] ) ) {
			WC_SUMUP_LOGGER::log(
				'Stored checkout data is missing on Thank You page request.',
				$log_context,
				'warning'
			);
			return;
		}

		$checkout_id = sanitize_text_field( (string) $checkout_data['id'] );

		$access_token = Wc_Sumup_Access_Token::get( $this->client_id, $this->client_secret, $this->api_key, false, array_merge( $log_context, array( 'checkout_id' => $checkout_id ) ) );
		$access_token = $access_token['access_token'] ?? '';
		if ( empty( $access_token ) ) {
			WC_SUMUP_LOGGER::log( 'Error while retrieving access token on Thank You page.', array_merge( $log_context, array( 'checkout_id' => $checkout_id ) ), 'error' );
			return;
		}

		$checkout_data = Wc_Sumup_Checkout::get( $checkout_id, $access_token, array_merge( $log_context, array( 'checkout_id' => $checkout_id ) ) );
		if ( empty( $checkout_data ) ) {
			WC_SUMUP_LOGGER::log( 'Error while retrieving checkout on Thank You page.', array_merge( $log_context, array( 'checkout_id' => $checkout_id ) ), 'error' );
			return;
		}

		$reconciled = $this->process_order_from_checkout( $checkout_data, $checkout_id, $order_id );
		if ( ! $reconciled['success'] && $reconciled['critical_error'] ) {
			return;
		}
		$order          = $reconciled['order'] ?? $order;
		$payment_status = $checkout_data['status'] ?? '';

		?>
		<style>
			#sumup-payment-status {
				margin-bottom: 20px;
				border: dashed 1px #d2d2d2;
				padding: 12px;
				border-radius: 4px;
				background: #f6f6f6;
			}
		</style>
		<div id="sumup-payment-status">
			<?php echo esc_html__( 'Payment Status: ', 'sumup-payment-gateway-for-woocommerce' ) . esc_html( $payment_status ); ?>
		</div>
		<?php
	}
}
