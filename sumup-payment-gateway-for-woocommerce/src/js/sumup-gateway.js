import buildWidgetOptions from '../utils/summup/buildWidgetOptions';
import { mountWidget, unmountWidget } from '../utils/summup/widgetLifecycle';

/**
 * Listener to manage Woo jQuery events
 */
jQuery(($) => {
  $(document.body).on('sumupCardInit', (_event, mountOptions = {}) =>
    initSumUpGateway(mountOptions),
  );

  /**
   * Used on order-pay page.
   * TODO: check if is possible merge two flows.
   */
  var payOrderButton = $('form#order_review #place_order');
  payOrderButton.on('click', showSumupPayment);
});

function getWidgetEmail(widgetMountOptions) {
  const billingEmailInput = document.getElementById('billing_email');
  const billingEmail =
    billingEmailInput && typeof billingEmailInput.value === 'string'
      ? billingEmailInput.value.trim()
      : '';

  return billingEmail || widgetMountOptions.email || '';
}

function buildSumUpCardWidgetOptions(mountOptions = {}) {
  const widgetMountOptions = {
    ...sumup_gateway_params,
    ...mountOptions,
  };

  widgetMountOptions.email = getWidgetEmail(widgetMountOptions);
  const sumupCardWidgetOptions = buildWidgetOptions(
    widgetMountOptions,
    {
      onSuccess: onSubmitSumup,
      onFailure: (message, invalidateContext = false) => {
        displayError(message);
        if (invalidateContext && document.querySelector('form#order_review')) {
          cleanupMountedSumUpCard();
          sumup_gateway_params.status = 'stale-checkout';
        }
        window.dispatchEvent(new Event('sumup_payment_failed'));
      },
      onLoad: sumUpOnLoad,
      onValidationError: displayError,
      onInstructionsError: displayError,
      onPaymentMethod: (method) => {
        sumup_gateway_params.paymentMethod = method;
      },
      getPaymentMethod: () => sumup_gateway_params.paymentMethod || '',
    },
    widgetMountOptions,
  );

  return sumupCardWidgetOptions;
}

function recoverFailedWidget() {
  cleanupMountedSumUpCard();
  sumup_gateway_params.status = '';
  const modal = document.getElementById('wc-sumup-payment-modal');
  if (modal) modal.style.display = '';
}

window.addEventListener('sumup_payment_failed', () => {
  if (document.querySelector('form.checkout')) recoverFailedWidget();
});

function cleanupMountedSumUpCard() {
  resetSumUpModal();

  unmountWidget();
}

/**
 * Mount SumUp card widget + add events to manage modal
 */
function initSumUpGateway(mountOptions = {}) {
  const widgetMountOptions = {
    ...sumup_gateway_params,
    ...mountOptions,
  };
  const sumupCardWidgetOptions = buildSumUpCardWidgetOptions(mountOptions);

  if (
    typeof sumupCardWidgetOptions.checkoutId !== 'string' ||
    sumupCardWidgetOptions.checkoutId.trim() === ''
  ) {
    console.error('Missing SumUp checkoutId for widget mount', {
      orderId: widgetMountOptions.orderId || null,
      checkoutIdType: typeof widgetMountOptions.checkoutId,
      optionKeys: Object.keys(widgetMountOptions).sort(),
    });
    displayError(sumup_gateway_params.errors.general_error);
    return;
  }

  // PATCH keeps the ID, but the mounted widget caches payment and billing details.
  const context = JSON.stringify({
    ...sumupCardWidgetOptions,
    amount: Number(widgetMountOptions.amount || 0),
    currency: widgetMountOptions.currency || '',
  });
  const modal = document.getElementById('wc-sumup-payment-modal');
  if (modal?.style.display === 'none') modal.style.display = '';
  if (!mountWidget(sumupCardWidgetOptions, context, true)) return;

  const sumUpPaymentModal = document.getElementById('wc-sumup-payment-modal');
  if (sumUpPaymentModal && sumUpPaymentModal.dataset.sumupListener !== 'true') {
    sumUpPaymentModal.dataset.sumupListener = 'true';
    sumUpPaymentModal.addEventListener('click', () => {
      if (!sumUpPaymentModal.classList.contains('no-modal')) {
        sumUpPaymentModal.classList.add('disabled');
        window.dispatchEvent(new Event('sumup_modal_closed'));
      }
    });

    const sumUpPaymentWrapper = document.getElementById('sumup-card');
    if (
      sumUpPaymentWrapper &&
      sumUpPaymentWrapper.dataset.sumupListener !== 'true'
    ) {
      sumUpPaymentWrapper.dataset.sumupListener = 'true';
      sumUpPaymentWrapper.addEventListener('click', (e) => {
        e.stopPropagation();
      });
    }

    const sumUpPaymentModalClose = document.getElementById(
      'wc-sumup-payment-modal-close',
    );
    if (
      sumUpPaymentModalClose &&
      sumUpPaymentModalClose.dataset.sumupListener !== 'true'
    ) {
      sumUpPaymentModalClose.dataset.sumupListener = 'true';
      sumUpPaymentModalClose.addEventListener('click', () => {
        sumUpPaymentModal.classList.add('disabled');
        window.dispatchEvent(new Event('sumup_modal_closed'));
      });
    }
  }
}

function resetSumUpModal() {
  const sumupModal = document.querySelector('.wc-sumup-modal');
  if (sumupModal) {
    sumupModal.style.display = 'none';
    document.body.classList.remove('sumup-modal-open');
  }
}

/**
 * Manage respnses and action from SumUp card widget
 *
 * @param {*} type
 * @param {*} body
 */
function sumUpOnLoad() {
  const sumUpForm = document.querySelector('#sumup-card form');
  if (sumUpForm && sumUpForm.dataset.sumupListener !== 'true') {
    sumUpForm.dataset.sumupListener = 'true';
    sumUpForm.addEventListener('submit', (_e) => {
      const paymentMethod = sumUpForm.querySelector(
        'input[name="payment_type"]',
      );
      if (paymentMethod) {
        sumup_gateway_params.paymentMethod = paymentMethod.value;
      }
    });
  }
  window.dispatchEvent(new Event('woo_sumup_loaded'));
}

/**
 * Process the order after card width confirm transaction
 */
function onSubmitSumup() {
  /**
   * Check pattern of url
   */
  const redirectUrl = sumup_gateway_params.redirectUrl;
  const isValidUrl = redirectUrl.match(/^(https|http):\/\//);
  if (isValidUrl !== null) {
    window.location = redirectUrl;
  }
  return;
}

/**
 * Manage payment modal
 *
 * @returns {bool}
 */
function showSumupPayment() {
  if (!isSumUpGatewaySelected()) {
    return true; /* return true so not to prevent other plugins from submitting */
  }

  if (sumup_gateway_params.status === 'paid') {
    return true;
  }

  if (sumup_gateway_params.status === 'stale-checkout') {
    displayError(sumup_gateway_params.errors.stale_checkout);
    return false;
  }

  const sumUpPaymentModal = document.getElementById('wc-sumup-payment-modal');
  if (sumUpPaymentModal) {
    sumUpPaymentModal.classList.remove('disabled');
  }

  return false;
}

/**
 * Display error message
 */
function displayError(error_message) {
  const checkoutForm =
    document.querySelector('form.checkout') ||
    document.querySelector('form#order_review');
  const oldErrorMessage = document.querySelector(
    '.woocommerce-NoticeGroup.woocommerce-NoticeGroup-checkout.woocommerce-error',
  );
  if (oldErrorMessage) {
    oldErrorMessage.remove();
  }
  const newErrorMessage = document.createElement('div');
  newErrorMessage.classList.add(
    'woocommerce-NoticeGroup',
    'woocommerce-NoticeGroup-checkout',
    'woocommerce-error',
  );
  newErrorMessage.innerText = error_message;

  if (checkoutForm) {
    checkoutForm.prepend(newErrorMessage);
  } else {
    document.body.prepend(newErrorMessage);
  }

  window.scrollTo({
    top: 0,
    behavior: 'smooth',
  });
}

/**
 * Check if SumUp Gateway is selected
 */
function isSumUpGatewaySelected() {
  const sumUpPaymentInput = document.getElementById('payment_method_sumup');
  if (sumUpPaymentInput) {
    return sumUpPaymentInput.checked;
  }
}

window.initSumUpGateway = initSumUpGateway;
window.showSumupPayment = showSumupPayment;
