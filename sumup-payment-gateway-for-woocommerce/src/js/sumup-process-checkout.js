import '../css/checkout/modal.css';

// WooCommerce owns validation, order creation, AJAX and checkout error handling.
jQuery(($) => {
  if (
    typeof wc_checkout_params === 'undefined' ||
    typeof sumup_gateway_params === 'undefined'
  )
    return;

  const form = $('form.checkout');
  if (!form.length) return;
  let hiddenButton;
  let previousButtonDisplay;
  const reset = () => {
    sumup_gateway_params.status = '';
    sumup_gateway_params.paymentMethod = '';
    const modal = document.getElementById('wc-sumup-payment-modal');
    modal?.classList.add('disabled');
    if (modal) modal.style.display = '';
    if (hiddenButton) {
      hiddenButton.style.display = previousButtonDisplay;
      hiddenButton = null;
    }
  };

  form.on('checkout_place_order_sumup.sumup', (event) => {
    if (event.result === false) return false;
    if (sumup_gateway_params.status === 'order-created') {
      window.showSumupPayment();
      return false;
    }
    reset();
  });

  form.on('checkout_place_order_success.sumup', (event, result) => {
    if (event.result === false) return false;
    if (!$('#payment_method_sumup').is(':checked') || result.openModal !== true)
      return;

    const redirectUrl = result.redirectUrl || result.redirect;
    if (
      typeof result.checkoutId !== 'string' ||
      !result.checkoutId.trim() ||
      !result.orderId ||
      typeof result.orderKey !== 'string' ||
      !result.orderKey.trim() ||
      typeof redirectUrl !== 'string' ||
      !/^https?:\/\//.test(redirectUrl)
    ) {
      reset();
      return false;
    }

    const oldCheckoutId = sumup_gateway_params.checkoutId;
    Object.assign(sumup_gateway_params, result, {
      checkoutIdOld: oldCheckoutId,
      paymentMethod: '',
      redirectUrl,
      status: 'order-created',
    });

    try {
      window.initSumUpGateway();
      // SDK errors can report failure during mount.
      if (sumup_gateway_params.status !== 'order-created') {
        reset();
        return false;
      }
      window.showSumupPayment();
      form.removeClass('processing').unblock();
      // Core follows this fragment while the widget collects payment. The
      // original order return URL stays in the widget context for completion.
      result.redirect = '#sumup-card';
      if (sumup_gateway_params.openPaymentInModal !== 'yes') {
        const button = document.getElementById('place_order');
        if (button) {
          hiddenButton = button;
          previousButtonDisplay = button.style.display;
          button.style.display = 'none';
        }
        document
          .getElementById('sumup-card')
          ?.scrollIntoView?.({ behavior: 'smooth' });
      }
      return true;
    } catch (error) {
      reset();
      console.error(error);
      // Core displays its checkout error and leaves the form available to retry.
      return false;
    }
  });

  $(document.body).on(
    'update_checkout.sumup checkout_error.sumup payment_method_selected.sumup',
    reset,
  );
  form.on('input.sumup change.sumup', 'input, select, textarea', (event) => {
    if (
      sumup_gateway_params.status === 'order-created' &&
      !event.target.closest('#sumup-card')
    )
      reset();
  });
  window.addEventListener('sumup_payment_failed', reset);
});
