<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * @class    WC_Gateway_SumUp
 * @since    1.0.0
 * @version  1.0.0
 */
class WC_Gateway_SumUp extends \WC_Payment_Gateway
{
	/**
	 * Merchant ID
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
	 * Installments
	 *
	 * @since 2.0
	 */
	protected $installments_enabled;

	/**
	 * Number of installments
	 *
	 * @since 2.0
	 */
	protected $number_of_installments;

	/**
	 * Merchant mail
	 *
	 * @since 2.o
	 */
	protected $pay_to_email;

	/**
	 * Remove PIX
	 *
	 * @since 2.0
	 */
	protected $enable_pix;

	/**
	 * Currency
	 *
	 * @since 2.0
	 */
	protected $currency;

	/**
	 * Return URL
	 *
	 * @since 2.0
	 */
	protected $return_url;

	/**
	 * Return URL
	 *
	 * @since 2.0
	 */
	protected $open_payment_in_modal;

	/**
	 * Constructor.
	 */
	public function __construct()
	{
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
	public function init_options()
	{
		$this->id = 'sumup';
		$this->method_title = __('SumUp', 'sumup-payment-gateway-for-woocommerce');
		/* translators: %1$s = https://me.sumup.com/, %2$s = https://me.sumup.com/developers */
		$this->method_description = sprintf(__('SumUp works by adding payment fields on the checkout and then sending the details to SumUp for verification. <a href="%1$s" target="_blank">Sign up</a> for a SumUp account. After logging in, <a href="%2$s" target="_blank">get your SumUp account keys</a>.', 'sumup-payment-gateway-for-woocommerce'), 'https://me.sumup.com/', 'https://me.sumup.com/developers');
		$this->has_fields = true;
		$this->supports = array(
			'subscriptions',
			'products',
		);
		$this->title = $this->get_option('title');
		$this->description = $this->get_option('description');
		$this->enabled = 'yes' === $this->get_option('enabled');
		$this->merchant_id = $this->get_option('merchant_id');
		$this->installments_enabled = $this->get_option('enable_installments', false);
		$this->number_of_installments = $this->get_option('number_of_installments', false);
		$this->api_key = $this->get_option('api_key');
		$this->client_id = $this->get_option('client_id');
		$this->client_secret = $this->get_option('client_secret');
		$this->pay_to_email = $this->get_option('pay_to_email');
		$this->enable_pix = $this->get_option('enable_pix');
		$this->currency = get_woocommerce_currency();
		$this->return_url = WC()->api_request_url('wc_gateway_sumup');
		$this->open_payment_in_modal = $this->get_option('open_payment_modal');
	}

	/**
	 * Initialize action hooks.
	 *
	 * @since    1.0.0
	 * @version  1.0.0
	 */
	public function init_actions()
	{
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'verify_credentials'));
		add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
		add_action('woocommerce_before_thankyou', array($this, 'add_payment_instructions_thankyou_page'));
		add_action('woocommerce_api_wc_gateway_sumup', array($this, 'webhook'));
		add_action('template_redirect', array($this, 'check_redirect_flow'), 99);
		add_action('process_webhook_order', array($this, 'handle_webhook_order'));
		$this->admin_custom_url();
	}

	/**
	 * Add params in the url admin page.
	 * @return void
	 */
	public function admin_custom_url()
	{
		if (
			isset($_GET['page']) && $_GET['page'] === 'wc-settings' &&
			isset($_GET['tab']) && $_GET['tab'] === 'checkout' &&
			isset($_GET['section']) && $_GET['section'] === 'sumup' &&
			!isset($_GET['validate_settings'])
		) {

			$is_valid_onboarding_settings = Wc_Sumup_Credentials::validate();
			// If the params already exist, will don't add again.
			if (!isset($_GET['validate_settings'])) {
				$new_url = add_query_arg([
					'validate_settings' => $is_valid_onboarding_settings ? "true" : "false",
				], admin_url('admin.php?page=wc-settings&tab=checkout&section=sumup'));

				//redirect to new url.
				wp_safe_redirect($new_url);

				exit;
			}
		}

	}

	public function webhook()
	{

		$request_body = file_get_contents('php://input');
		$data = json_decode($request_body, true);

		if (!$data || !isset($data['event_type'])) {
			wp_send_json_error(['message' => 'Dados inválidos'], 400);
			return;
		}

		// Adds the webhook to the asynchronous processing queue
		as_enqueue_async_action('process_webhook_order', [$data]);

		wp_send_json_success(['message' => 'Webhook adicionado à fila']);
	}

	/**
	 * Webhook to manage order status after SumUp sent a notification
	 *
	 * @return void
	 */
	public function handle_webhook_order($data)
	{

		if (
			!isset($data['id']) ||
			empty($data['id']) ||
			!isset($data['event_type']) ||
			empty($data['event_type'])
		) {
			return;
		}

		$checkout_id = sanitize_text_field($data['id']);
		$event_type = sanitize_text_field($data['event_type']);

		if ($event_type !== 'CHECKOUT_STATUS_CHANGED') {
			WC_SUMUP_LOGGER::log('Invalid event type on Webhook. Event: ' . $event_type . '. Merchant Id: ' . $this->merchant_id . '. Checkout ID: ' . $checkout_id);
			return;
		}

		WC_SUMUP_LOGGER::log('Handling Webhook. Event: ' . $event_type . '. Merchant Id: ' . $this->merchant_id . '. Checkout ID: ' . $checkout_id);

		$access_token = Wc_Sumup_Access_Token::get($this->client_id, $this->client_secret, $this->api_key, true);
		$access_token = $access_token['access_token'] ?? '';
		if (empty($access_token)) {
			WC_SUMUP_LOGGER::log('Error to try get access token on Webhook. Merchant Id: ' . $this->merchant_id . '. Checkout ID: ' . $checkout_id);
			return;
		}

		$checkout_data = Wc_Sumup_Checkout::get($checkout_id, $access_token);
		if (empty($checkout_data)) {
			WC_SUMUP_LOGGER::log('Error to try get checkout on Webhook. Checkout: ' . $checkout_data . '. Merchant Id: ' . $this->merchant_id . '. Checkout ID: ' . $checkout_id);
			return;
		}

		$checkout_reference = $checkout_data['checkout_reference'] ?? '';
		$order_id = str_replace('WC_SUMUP_', '', $checkout_reference);
		$order_id = intval($order_id);
		$order = wc_get_order($order_id);
		if ($order === false) {
			WC_SUMUP_LOGGER::log('Order not found on Webhook request from SumUp. Merchant Id: ' . $this->merchant_id . '. Checkout ID: ' . $checkout_id);
			return;
		}

		$transaction_code = $checkout_data['transaction_code'] ?? '';
		if (empty($transaction_code)) {
			WC_SUMUP_LOGGER::log('Missing transaction code on Webhook request from SumUp. Checkout data: ' . $checkout_data . '. Merchant Id: ' . $this->merchant_id . '. Checkout ID: ' . $checkout_id);
			return;
		}

		$payment_status = $checkout_data['status'] ?? '';
		// Check if the current status isn't processing or completed.
		if (
			!in_array($order->get_status(), array(
				'processing',
				'completed',
				'refunded',
				'cancelled'
			), true)
		) {
			if ($payment_status === 'PAID') {
				$order->update_meta_data('_sumup_transaction_code', $transaction_code);

				// Updates current status unless it's a Virtual AND Downloadable product.
				if ($order->needs_processing()) {
					$order->update_status('processing');
				}

				$message = sprintf(
					__('SumUp charge complete. Transaction Code: %s', 'sumup-payment-gateway-for-woocommerce'),
					$transaction_code
				);
				$order->add_order_note($message);
				$order->payment_complete($transaction_code);
				do_action('sumup_gateway_payment_complete_from_hook', $order);
				do_action('sumup_gateway_payment_complete', $order);
				$order->save();
				return;
			}

			if ($payment_status === 'FAILED') {
				$order->update_status('failed');
				$message = __('SumUp payment failed.', 'sumup-payment-gateway-for-woocommerce');
				$order->add_order_note($message);
				$order->save();
				return;
			}
		}

	}

	/**
	 * Method used on flows with redirect (like 3Ds)
	 */
	public function check_redirect_flow()
	{
		if (!is_checkout()) {
			return;
		}

		if (!isset($_GET['sumup-validate-order'])) {
			return;
		}

		$order_id = (int) $_GET['sumup-validate-order'];
		$order = wc_get_order($order_id);
		if ($order === false) {
			WC_SUMUP_LOGGER::log('Order not found on validation after payment redirect. Merchant Id: ' . $this->merchant_id . '. Order ID: ' . $order_id);
			return;
		}

		$checkout_data = $order->get_meta('_sumup_checkout_data');
		if (!isset($checkout_data['id'])) {
			WC_SUMUP_LOGGER::log('Missed $checkout_data on validation after payment redirect. Merchant Id: ' . $this->merchant_id . '. Order ID: ' . $order_id);
			return;
		}

		$access_token = Wc_Sumup_Access_Token::get($this->client_id, $this->client_secret, $this->api_key);
		$access_token = $access_token['access_token'] ?? '';
		if (empty($access_token)) {
			WC_SUMUP_LOGGER::log('Error to try get access token on validation after payment redirect. Merchant Id: ' . $this->merchant_id . '. Order ID: ' . $order_id);
			return;
		}

		$sumup_checkout = Wc_Sumup_Checkout::get($checkout_data['id'], $access_token);
		if (empty($sumup_checkout)) {
			WC_SUMUP_LOGGER::log('Error to try get checkout on validation after payment redirect. Merchant Id: ' . $this->merchant_id . '. Order ID: ' . $order_id);
			return;
		}

		$payment_status = $sumup_checkout['status'] ?? '';

		if ($payment_status === 'PENDING') {
			$this->check_redirect_flow();
			return;
		}

		if ($payment_status === 'FAILED') {
			$order->update_status('failed');
			add_action('woocommerce_before_checkout_form', array($this, 'redirect_validation_failed_message'));
			return;
		}

		//Verify if the transaction is correct before check status PAID
		$transaction_code = $sumup_checkout['transaction_code'] ?? '';
		if (empty($transaction_code)) {
			WC_SUMUP_LOGGER::log('Missing transaction code on redirect payment flow from SumUp. Checkout data: ' . $checkout_data . '. Merchant Id: ' . $this->merchant_id . '. Checkout ID: ' . $checkout_data['id']);
			wp_redirect($this->get_return_url($order));
			exit;
		}

		if ($payment_status === 'PAID') {
			wp_redirect($this->get_return_url($order));
			exit;
		}
	}

	/**
	 * Redirect validation failed message
	 */
	public function redirect_validation_failed_message()
	{
		$failed_message = sprintf(
			'<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout woocommerce-error">%1$s</div>',
			__('Payment failed, please try again.', 'sumup-payment-gateway-for-woocommerce')
		);
		echo wp_kses_post($failed_message);

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
	 * Initialise gateway settings form fields
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	public function init_form_fields()
	{
		$this->form_fields = require WC_SUMUP_PLUGIN_PATH . '/includes/class-wc-sumup-settings.php';
	}

	/**
	 * Process the payment.
	 *
	 * @param int $order_id
	 * @since     1.0.0
	 * @version   1.0.0
	 */
	public function process_payment($order_id)
	{
		$order = wc_get_order($order_id);
		$sumup_checkout = $order->get_meta('_sumup_checkout_data');

		$access_token = Wc_Sumup_Access_Token::get($this->client_id, $this->client_secret, $this->api_key);
		if (!isset($access_token['access_token'])) {
			WC_SUMUP_LOGGER::log('Error on request (cURL) to get access token. Merchant Id: ' . $this->merchant_id);
			$message = current_user_can('manage_options') ? 'Error to generate SumUp access token.' : 'Sorry, SumUp is not available. Try again soon.';

			if (!get_option('sumup_valid_credentials')) {
				if (!empty($sumup_checkout) && isset($sumup_checkout['isCheckoutBlocks']) && $sumup_checkout['isCheckoutBlocks']) {
					throw new Exception($message);
				} else {
					return [
						'result' => 'failure',
						'redirect' => false,
						'openModal' => false,
						'messages' => $message,
					];
				}
			}
			return false; // Evita continuar a execução se não houver token
		}

		$sumup_settings = get_option('woocommerce_sumup_settings', []);
		$sumup_settings['sumup_access_token'] = $access_token['access_token'];
		$sumup_settings['sumup_token_fetched_date'] = date('Y/m/d H:i:s');
		update_option('woocommerce_sumup_settings', $sumup_settings);

		if (empty($sumup_checkout) || !isset($sumup_checkout['id'])) {
			$checkout_data = [
				'checkout_reference' => 'WC_SUMUP_' . $order_id,
				'amount' => $order->get_total(),
				'currency' => get_woocommerce_currency(),
				'description' => 'WooCommerce #' . $order_id,
				'redirect_url' => wc_get_checkout_url() . '?sumup-validate-order=' . $order_id,
				'return_url' => WC()->api_request_url('wc_gateway_sumup'),
			];

			if (!empty($this->merchant_id)) {
				$checkout_data['merchant_code'] = $this->merchant_id;
			} elseif (!empty($this->pay_to_email)) {
				$checkout_data['pay_to_email'] = $this->pay_to_email;
			}

			$sumup_checkout = Wc_Sumup_Checkout::create($sumup_settings['sumup_access_token'], $checkout_data);
			if (empty($sumup_checkout) || !isset($sumup_checkout['id'])) {
				$error_message = isset($sumup_checkout['error_code']) ?
					"{$sumup_checkout['error_code']} : {$sumup_checkout['message']}" :
					'Error on request (cURL) to create SumUp checkout ID during request to SumUp.';

				WC_SUMUP_LOGGER::log($error_message);

				$message = current_user_can('manage_options') ? 'Error to generate SumUp checkout ID.' : 'Sorry, SumUp is not available. Try again soon.';
				if (!empty($sumup_checkout) && isset($sumup_checkout['isCheckoutBlocks']) && $sumup_checkout['isCheckoutBlocks']) {
					throw new Exception($message);
				} else {
					return [
						'result' => 'failure',
						'redirect' => false,
						'openModal' => false,
						'messages' => $message,
					];
				}
			}

			$order->add_order_note('SumUp checkout ID: ' . $sumup_checkout['id']);
			$order->update_meta_data('_sumup_checkout_data', $sumup_checkout);
			$order->save();
		}

		/**
		 * Fallback to fill merchant code to "old" users.
		 * Temporary solution while SumUp team check other ways to enable request to get merchant_code.
		 */
		if (empty($this->merchant_id) && isset($sumup_checkout['merchant_code'])) {
			$this->update_option('merchant_id', $sumup_checkout['merchant_code']);
		}

		if (isset($sumup_checkout['id'])) {
			return [
				'result' => 'success',
				'redirect' => $this->get_return_url($order),
				'openModal' => true,
				'checkoutId' => $sumup_checkout['id'],
				'redirectUrl' => $this->get_return_url($order),
				'country' => $order->get_billing_country(),
			];
		}

		$message = 'Error to get checkout ID. Check plugin logs.';
		if (!empty($sumup_checkout) && isset($sumup_checkout['isCheckoutBlocks']) && $sumup_checkout['isCheckoutBlocks']) {
			throw new Exception($message);
		}

		return [
			'result' => 'failure',
			'redirect' => false,
			'openModal' => false,
			'messages' => $message,
		];
	}

	/**
	 * Builds our payment fields area. Initializes the SumUp's Card Widget.
	 *
	 * @since    1.0.0;
	 * @version  1.0.0;
	 */
	public function payment_fields()
	{
		if (!is_checkout()) {
			return;
		}

		if (!get_option('sumup_valid_credentials')) {
			esc_html_e('Error: Merchant account settings are incorrectly configured. Check the plugin settings page.', 'sumup-payment-gateway-for-woocommerce');
			return;
		}

		if (!is_wc_endpoint_url('order-pay')) {
			echo '<p>' . esc_html($this->description) . '</p>';
			$extra_class = $this->open_payment_in_modal === 'yes' ? 'modal' : 'no-modal';

			?>
			<style>
				.wc-sumup-modal {
					position: fixed;
					top: 0;
					bottom: auto;
					left: 0;
					right: 0;
					height: 100%;
					background: #000000bd;
					display: flex;
					justify-content: center;
					align-items: center;
					z-index: 9999;
					overflow: scroll
				}

				.wc-sumup-modal.disabled {
					display: none
				}

				.wc-sumup-modal #sumup-card {
					width: 700px;
					max-width: 90%;
					position: relative;
					max-height: 95%;
					background: #fff;
					border-radius: 16px;
					min-height: 140px
				}

				.wc-sumup-modal #wc-sumup-payment-modal-close {
					position: absolute;
					top: -10px;
					right: -5px;
					border-radius: 100%;
					height: 28px;
					width: 28px;
					display: flex;
					justify-content: center;
					align-items: center;
					color: #000;
					background: #fff;
					border: 1px solid #d8dde1;
					cursor: pointer;
					font-weight: 700
				}

				.wc-sumup-modal div[data-sumup-id=payment_option]>label {
					display: flex !important
				}

				.sumup-boleto-pending-screen {
					border: 1px dashed #000;
					padding: 10px;
					border-radius: 12px
				}

				div[data-testid=scannable-barcode]>img {
					height: 250px !important;
					max-height: 100% !important
				}

				.wc-sumup-modal.no-modal {
					position: relative;
					background: #fff
				}

				.wc-sumup-modal.no-modal #wc-sumup-payment-modal-close {
					display: none
				}

				.wc-sumup-modal section img[class*=' sumup-payment'],
				.wc-sumup-modal section img[class^=sumup-payment] {
					width: auto;
					top: 50%;
					transform: translateY(-55%)
				}
			</style>
			<div id="wc-sumup-payment-modal" class="wc-sumup-modal disabled <?php echo esc_attr($extra_class); ?>">
				<div id="sumup-card">
					<div id="wc-sumup-payment-modal-close">
						<span id="wc-sumup-payment-modal-close-btn">X</span>
					</div>
				</div>
			</div>
			<?php
			return;
		}

		/**
		 * Required fileds to request somethings to SumUp - Refator to meke the first verification more complete.
		 */
		if (empty($this->pay_to_email) && empty($this->merchant_id)) {
			WC_SUMUP_LOGGER::log('Please fill "Login Email" and "Merchant ID" on the plugin settings. Merchant Id: ' . $this->merchant_id);
			$message = current_user_can('manage_options') ? 'Please fill "Login Email" and "Merchant ID" on the plugin settings.' : 'Sorry, SumUp is not available. Try again soon.';
			echo $this->print_error_message($message);
			return;
		}

		$description = $this->get_description();
		if ($description) {
			echo wp_kses_post(wpautop(wptexturize($description)));
		}

		$total = WC_Payment_Gateway::get_order_total();

		$sumup_settings = get_option('woocommerce_sumup_settings', false);
		if (empty($sumup_settings)) {
			$unavaliable_message = sprintf(
				'<p>%s</p>',
				__('Sum up is temporarily unavailable. Please contact site admin for more information.', 'sumup-payment-gateway-for-woocommerce'),
			);

			echo wp_kses_post($unavaliable_message);
			return;
		}

		$access_token = Wc_Sumup_Access_Token::get($this->client_id, $this->client_secret, $this->api_key);
		if (!isset($access_token['access_token'])) {
			WC_SUMUP_LOGGER::log('Error on request (cURL) to get access token. Merchant Id: ' . $this->merchant_id);
			$message = current_user_can('manage_options') ? 'Error to generate SumUp access token.' : 'Sorry, SumUp is not available. Try again soon.';
			echo $this->print_error_message($message);
			return;
		}

		$sumup_settings['sumup_access_token'] = $access_token['access_token'];
		$sumup_settings['sumup_token_fetched_date'] = date('Y/m/d H:i:s');
		update_option('woocommerce_sumup_settings', $sumup_settings);

		$order_id = sanitize_text_field(get_query_var('order-pay'));
		$order = wc_get_order($order_id);
		if ($order === false) {
			echo '<p>' . __('Order ID is not available to make the payment. Try again soon or contact the website support.', 'sumup-payment-gateway-for-woocommerce') . '</p>';
			return;
		}

		$sumup_checkout = $order->get_meta('_sumup_checkout_data');
		if (empty($sumup_checkout)) {
			$checkout_data = array(
				'checkout_reference' => 'WC_SUMUP_' . $order_id,
				'amount' => $total,
				'currency' => get_woocommerce_currency(),
				'description' => 'WooCommerce #' . $order_id,
				'redirect_url' => wc_get_checkout_url() . '?sumup-validate-order=' . $order_id,
				'return_url' => WC()->api_request_url('wc_gateway_sumup'),
			);

			if (!empty($this->merchant_id)) {
				$checkout_data['merchant_code'] = $this->merchant_id;
			}

			if (!empty($this->pay_to_email) && empty($this->merchant_id)) {
				$checkout_data['pay_to_email'] = $this->pay_to_email;
			}

			$sumup_checkout = Wc_Sumup_Checkout::create($sumup_settings['sumup_access_token'], $checkout_data);
			if (empty($sumup_checkout)) {
				WC_SUMUP_LOGGER::log('Error on request (cURL) to create SumUp checkout ID. Merchant Id: ' . $this->merchant_id);
				$message = current_user_can('manage_options') ? 'Error to generate SumUp checkout id.' : 'Sorry, SumUp is not available. Try again soon.';
				echo $this->print_error_message($message);
				return;
			}
			$order->update_meta_data('_sumup_checkout_data', $sumup_checkout);
			$order->save();
		}

		/**
		 * Fallback to fill merchant code to "old" users. Temporary solution while SumUp team check other ways to enable request to get merchant_code.
		 */
		if (empty($this->merchant_id) && isset($sumup_checkout['merchant_code'])) {
			$this->update_option('merchant_id', $sumup_checkout['merchant_code']);
		}

		if (isset($sumup_checkout['id'])) {
			$extra_class = $this->open_payment_in_modal === 'yes' ? 'no-modal' : '';

			?>
			<style>
				.wc-sumup-modal {
					position: fixed;
					top: 0;
					bottom: auto;
					left: 0;
					right: 0;
					height: 100%;
					background: #000000bd;
					display: flex;
					justify-content: center;
					align-items: center;
					z-index: 9999;
					overflow: scroll
				}

				.wc-sumup-modal.disabled {
					display: none
				}

				.wc-sumup-modal #sumup-card {
					width: 700px;
					max-width: 90%;
					position: relative;
					max-height: 95%;
					background: #fff;
					border-radius: 16px;
					min-height: 140px
				}

				.wc-sumup-modal #wc-sumup-payment-modal-close {
					position: absolute;
					top: -10px;
					right: -5px;
					border-radius: 100%;
					height: 28px;
					width: 28px;
					display: flex;
					justify-content: center;
					align-items: center;
					color: #000;
					background: #fff;
					border: 1px solid #d8dde1;
					cursor: pointer;
					font-weight: 700
				}

				.wc-sumup-modal div[data-sumup-id=payment_option]>label {
					display: flex !important
				}

				.sumup-boleto-pending-screen {
					border: 1px dashed #000;
					padding: 10px;
					border-radius: 12px
				}

				div[data-testid=scannable-barcode]>img {
					height: 250px !important;
					max-height: 100% !important
				}

				.wc-sumup-modal.no-modal {
					position: relative;
					background: #fff
				}

				.wc-sumup-modal.no-modal #wc-sumup-payment-modal-close {
					display: none
				}

				.wc-sumup-modal section img[class*=' sumup-payment'],
				.wc-sumup-modal section img[class^=sumup-payment] {
					width: auto;
					top: 50%;
					transform: translateY(-55%)
				}
			</style>
			<div id="wc-sumup-payment-modal" class="wc-sumup-modal disabled <?php echo esc_attr($extra_class); ?>">
				<div id="sumup-card">
					<div id="wc-sumup-payment-modal-close">
						<span id="wc-sumup-payment-modal-close-btn">X</span>
					</div>
				</div>
			</div>

			<script type="text/javascript">
				if (typeof sumup_gateway_params !== 'undefined') {
					sumup_gateway_params.amount = '<?php echo esc_js($total); ?>';
					sumup_gateway_params.checkoutId = '<?php echo esc_js($sumup_checkout['id']); ?>';
					sumup_gateway_params.redirectUrl = '<?php echo esc_js($this->get_return_url($order)) ?>';
					sumup_gateway_params.country = '';
				}

				jQuery(function ($) {
					$(document.body).trigger('sumupCardInit');
				});
			</script>
			<?php
		}

		if (isset($sumup_checkout['error_code'])) {
			$error = isset($sumup_checkout['error_message']) ? $sumup_checkout['error_message'] : $sumup_checkout['message'];
			WC_SUMUP_LOGGER::log('SumUp create checkout request: ' . $error . '. Merchant Id: ' . $this->merchant_id);
			$message = current_user_can('manage_options') ? 'Error from response to create checkout on SumUp. Check the logs.' : 'Sorry, SumUp is not available. Try again soon.';
			echo $this->print_error_message($message);
		}
	}

	/**
	 * Template to print error message to user on checkout
	 *
	 * @param string $error_message
	 * @return string
	 */
	private function print_error_message($message)
	{
		$error_message = sprintf(
			'<p>Error: %s</p>',
			__($message, 'sumup-payment-gateway-for-woocommerce')
		);

		return wp_kses_post($error_message);
	}

	/**
	 * Register the JavaScript scripts to the checkout page.
	 *
	 * @since    1.0.0
	 * @version  1.0.0
	 */
	public function payment_scripts()
	{
		/* Add JavaScript only on the checkout page */
		if (!is_cart() && !is_checkout() && !isset($_GET['pay_for_order'])) {
			return;
		}

		/* Add JavaScript only if the plugin is enabled */
		if (!$this->enabled) {
			return;
		}

		/* Add JavaScript only if the plugin is set up correctly */
		if (!get_option('sumup_valid_credentials')) {
			return;
		}

		/*
		 * Use the SumUp's SDK for accepting card payments.
		 * Documentation can be found at https://developer.sumup.com/docs/widgets-card
		 */
		wp_enqueue_script('sumup_gateway_card_sdk', 'https://gateway.sumup.com/gateway/ecom/card/v2/sdk.js', array(), WC_SUMUP_VERSION, false);
		wp_register_script('sumup_gateway_front_script', WC_SUMUP_PLUGIN_URL . 'assets/js/sumup-gateway.min.js', array('sumup_gateway_card_sdk'), WC_SUMUP_VERSION, false);
		wp_register_script('sumup_gateway_process_checkout', WC_SUMUP_PLUGIN_URL . 'assets/js/sumup-process-checkout.min.js', array('jquery'), WC_SUMUP_VERSION, true);
		wp_enqueue_script('sumup_gateway_process_checkout');

		$shop_base_country = WC()->countries->get_base_country();
		$supported_countries = array(
			"AT",
			"AU",
			"BE",
			"BG",
			"BR",
			"CH",
			"CL",
			"CO",
			"CY",
			"CZ",
			"DE",
			"DK",
			"EE",
			"ES",
			"FI",
			"FR",
			"GB",
			"GR",
			"HR",
			"HU",
			"IE",
			"IT",
			"LT",
			"LU",
			"LV",
			"MT",
			"NL",
			"NO",
			"PL",
			"PT",
			"RO",
			"SE",
			"SI",
			"SK",
			"US",
		);
		$card_country = in_array($shop_base_country, $supported_countries) ? $shop_base_country : 'null';

		$show_zipcode = $card_country === 'US' ? 'true' : 'false';

		$card_locale = str_replace('_', '-', get_locale());
		$card_supported_locales = array(
			"bg-BG",
			"cs-CZ",
			"da-DK",
			"de-AT",
			"de-CH",
			"de-DE",
			"de-LU",
			"el-CY",
			"el-GR",
			"en-AU",
			"en-GB",
			"en-IE",
			"en-MT",
			"en-US",
			"es-CL",
			"es-ES",
			"et-EE",
			"fi-FI",
			"fr-BE",
			"fr-CH",
			"fr-FR",
			"fr-LU",
			"hu-HU",
			"it-CH",
			"it-IT",
			"lt-LT",
			"lv-LV",
			"nb-NO",
			"nl-BE",
			"nl-NL",
			"pt-BR",
			"pt-PT",
			"pl-PL",
			"sk-SK",
			"sl-SI",
			"sv-SE",
		);
		$card_locale = in_array($card_locale, $card_supported_locales) ? $card_locale : 'en-GB';

		/**
		 * Translators: the following error messages are shown to the end user
		 */
		$error_general = __('Transaction was unsuccessful. Please check the minimum amount or use another valid card.', 'sumup-payment-gateway-for-woocommerce');
		$error_invalid_form = __('Fill in all required details.', 'sumup-payment-gateway-for-woocommerce');

		$installments = "false";
		$number_of_installments = null;
		if ($card_country === 'BR') {
			$installments = $this->installments_enabled === 'yes' ? "true" : $installments;
			$number_of_installments = $this->number_of_installments !== false && $this->number_of_installments !== 'select' ? $this->number_of_installments : $number_of_installments;
		}

		$enable_pix = $this->enable_pix === 'yes' ? 'yes' : 'no';
		$open_payment_in_modal = $this->open_payment_in_modal === 'yes' ? 'yes' : 'no';

		wp_localize_script('sumup_gateway_front_script', 'sumup_gateway_params', array(
			'showZipCode' => "$show_zipcode",
			'showInstallments' => "$installments",
			'maxInstallments' => $number_of_installments,
			'locale' => "$card_locale",
			'country' => '',
			'status' => '',
			'errors' => array(
				'general_error' => "$error_general",
				'invalid_form' => "$error_invalid_form"
			),
			'enablePix' => "$enable_pix",
			'paymentMethod' => '',
			'currency' => "$this->currency",
			'openPaymentInModal' => "$open_payment_in_modal",
			'redirectUrl' => '',
		));

		wp_enqueue_script('sumup_gateway_front_script');
	}

	/**
	 * Verify if SumUp application credentials are valid when saving settings.
	 *
	 * @since    1.0.0
	 * @version  1.0.0
	 */
	public function verify_credentials()
	{
		Wc_Sumup_Credentials::validate();
	}

	/**
	 * Add Instruction to pay Boleto (BR only) on WooCommerce Thank You page.
	 *
	 * @return void
	 * @since 2.0
	 */
	public function add_payment_instructions_thankyou_page($order_id)
	{
		$order = wc_get_order($order_id);
		if ($order === false) {
			WC_SUMUP_LOGGER::log('Order not found on Thank You page request from SumUp. Merchant Id: ' . $this->merchant_id . '. Order ID: ' . $order_id);
			return;
		}

		$checkout_data = $order->get_meta('_sumup_checkout_data');
		if (!isset($checkout_data['id'])) {
			WC_SUMUP_LOGGER::log('Missed $checkout_data on Thank You. Merchant Id: ' . $this->merchant_id . '. Order ID: ' . $order_id . ' $checkout_data: ' . $checkout_data);
			return;
		}

		$checkout_id = $checkout_data['id'];

		$access_token = Wc_Sumup_Access_Token::get($this->client_id, $this->client_secret, $this->api_key);
		$access_token = $access_token['access_token'] ?? '';
		if (empty($access_token)) {
			WC_SUMUP_LOGGER::log('Error to try get access token on Thank You page. Merchant Id: ' . $this->merchant_id . '. Order ID: ' . $order_id);
			return;
		}

		$checkout_data = Wc_Sumup_Checkout::get($checkout_id, $access_token);
		if (empty($checkout_data)) {
			WC_SUMUP_LOGGER::log('Error to try get checkout on Thank You page. Merchant Id: ' . $this->merchant_id . '. Order ID: ' . $order_id);
			return;
		}

		$payment_status = $checkout_data['status'] ?? '';
		if ($payment_status === 'FAILED') {
			$order->update_status('failed');
		}
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
			<?php echo esc_html__('Payment Status: ', 'sumup-payment-gateway-for-woocommerce') . esc_html($payment_status); ?>
		</div>
		<?php

		$pix_code = sanitize_text_field($_GET['pix-code'] ?? '');
		$pix_image = sanitize_text_field($_GET['pix-image'] ?? '');

		?>
		<div id="pix-content"></div>
		<img id="pix-img" />

		<style>
			#sumup-boleto-code {
				background: #ececec;
				padding: 4px;
				font-weight: 700
			}
		</style>
		<div id="boleto-content"></div>
		<a id="pdf-boleto" target="_blank" href=""></a>
		<p id="barcode-boleto"></p>

		<script>
			const loadPix = () => {
				const paymentMethod = localStorage.getItem('paymentMethod');

				if (paymentMethod === 'pix' || paymentMethod === "qr_code_pix") {
					const pixContent = document.getElementById('pix-content');
					pixContent.innerHTML = `<h2 class="woocommerce-order-details__title"><?php esc_html_e('Payment instructions', 'sumup-payment-gateway-for-woocommerce'); ?></h2>
						<p><?php esc_html_e('PIX code: ', 'sumup-payment-gateway-for-woocommerce'); ?> <span id="sumup-boleto-code">${localStorage.getItem('pix-content')}</span></p>`;
					const pixImg = document.getElementById('pix-img');
					pixImg.src = localStorage.getItem('qrcode');
					pixImg.alt = "sumup-pix-qr-code";
					pixImg.style.maxWidth = "100%";
					pixImg.style.height = "auto";

				}
			};

			const loadBoleto = () => {
				const paymentMethod = localStorage.getItem('paymentMethod');

				if (paymentMethod === 'boleto') {
					const divBoleto = document.getElementById("boleto-content");
					divBoleto.innerHTML = `<h2 class="woocommerce-order-details__title"><?php esc_html_e('Payment instructions', 'sumup-payment-gateway-for-woocommerce'); ?></h2>`;
					const boletoDownload = document.getElementById('pdf-boleto');
					boletoDownload.text = '<?php esc_html_e('Download Boleto', 'sumup-payment-gateway-for-woocommerce'); ?>';
					boletoDownload.setAttribute("href", localStorage.getItem('boleto-pdf'));
					const elementBarcode = document.getElementById('barcode-boleto');
					const barcode = localStorage.getItem('boleto-barcode');
					elementBarcode.innerHTML = `<?php esc_html_e('Code to pay: ', 'sumup-payment-gateway-for-woocommerce'); ?> <span id="sumup-boleto-code">${barcode}</span>`;
				}
			};

			if (document.readyState === 'complete') {
				loadPix();
				loadBoleto();
			} else {
				window.addEventListener('load', () => {
					loadPix();
					loadBoleto();
				});
			}
		</script>
		<?php

		if (!empty($pix_code) && !empty($pix_image)) {
			?>
			<style>
				#sumup-boleto-code {
					background: #ececec;
					padding: 4px;
					font-weight: 700;
				}

				#sumup-pix-qr-code {
					max-width: 100%;
					height: auto;
				}
			</style>
			<h2 class="woocommerce-order-details__title">
				<?php esc_html_e('Payment instructions', 'sumup-payment-gateway-for-woocommerce'); ?></h2>
			<p><?php esc_html_e('PIX code: ', 'sumup-payment-gateway-for-woocommerce'); ?> <span
					id="sumup-boleto-code"><?php echo esc_html($pix_code); ?></span></p>
			<img id="sumup-pix-qr-code" src="<?php echo esc_attr($pix_image); ?>" alt="sumup-pix-qr-code" style="">
			<?php
		}

		$boleto_code = sanitize_text_field($_GET['boleto-code'] ?? '');
		$boleto_link = sanitize_text_field($_GET['boleto-link'] ?? '');

		if (!empty($boleto_code) && !empty($boleto_link)) {
			?>
			<style>
				#sumup-boleto-code {
					background: #ececec;
					padding: 4px;
					font-weight: 700
				}
			</style>
			<h2 class="woocommerce-order-details__title">
				<?php esc_html_e('Payment instructions', 'sumup-payment-gateway-for-woocommerce'); ?></h2>
			<p><?php esc_html_e('Code to pay: ', 'sumup-payment-gateway-for-woocommerce'); ?> <span
					id="sumup-boleto-code"><?php echo esc_html($boleto_code); ?></span></p>
			<a class="button" href="<?php echo esc_attr($boleto_link); ?>"
				target="_blank"><?php esc_html_e('Download Boleto', 'sumup-payment-gateway-for-woocommerce'); ?></a>
			<?php
		}
	}
}
