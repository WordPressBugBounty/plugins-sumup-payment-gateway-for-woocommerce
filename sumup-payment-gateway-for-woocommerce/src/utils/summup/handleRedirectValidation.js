const getRedirectMessage = (type) => {
  const errors = sumup_gateway_params?.errors || {};

  if (type === 'pending') {
    return (
      errors.payment_pending ||
      'We are still waiting for SumUp to confirm this payment. Please wait a moment and refresh the page if the order is not updated automatically.'
    );
  }

  return errors.payment_failed || 'Payment failed, please try again.';
};

const stripRedirectQueryArgs = (searchParams) => {
  const params = new URLSearchParams(searchParams.toString());
  for (const key of [
    'sumup-validate-order',
    'key',
    'checkout_id',
    'sumup_payment',
    'status',
  ]) {
    params.delete(key);
  }

  const query = params.toString();
  return query ? `?${query}` : '';
};

const showCheckoutNotice = (type, message, noticeContext) => {
  if (!message || !window.wp?.data?.dispatch) {
    return;
  }

  const noticeType = type === 'pending' ? 'warning' : 'error';

  window.wp.data.dispatch('core/notices').createNotice(noticeType, message, {
    id: `sumup-payment-${noticeType}-${Date.now()}`,
    context: noticeContext || 'wc/checkout/payments',
    isDismissible: true,
  });
};

const invalidateBlocksCart = () => {
  const { cartStore } = window.wc?.wcBlocksData || {};

  if (cartStore && window.wp?.data?.dispatch) {
    window.wp.data.dispatch(cartStore).invalidateResolutionForStore();
    return;
  }

  window.location.assign(
    `${window.location.pathname}${stripRedirectQueryArgs(
      new URLSearchParams(window.location.search),
    )}`,
  );
};

const getValidateRedirectUrl = () => {
  const requestUrl = new URL(sumup_gateway_params.sumup_handler_url);
  requestUrl.searchParams.set('action', 'validate_redirect');

  return requestUrl.toString();
};

const validateRedirectWithServer = async ({
  orderId,
  orderKey,
  checkoutId,
  noticeContext,
}) => {
  const response = await fetch(getValidateRedirectUrl(), {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      nonce: sumup_gateway_params.validateRedirectNonce,
      order_id: orderId,
      order_key: orderKey,
      checkout_id: checkoutId || '',
    }),
  });

  const payload = await response.json();
  const data = payload?.data || {};

  window.history.replaceState(
    {},
    '',
    `${window.location.pathname}${stripRedirectQueryArgs(
      new URLSearchParams(window.location.search),
    )}`,
  );

  if (
    payload?.result === 'success' &&
    data.status === 'paid' &&
    data.redirect_url
  ) {
    window.location.assign(data.redirect_url);
    return;
  }

  if (payload?.result !== 'success') {
    showCheckoutNotice(
      'failed',
      payload?.message || getRedirectMessage('failed'),
      noticeContext,
    );
    return;
  }

  if (data.message) {
    const noticeType = data.status === 'pending' ? 'pending' : 'failed';
    showCheckoutNotice(noticeType, data.message, noticeContext);
  }

  if (data.cart_restored) {
    invalidateBlocksCart();
  }
};

const handleRedirectValidation = async (noticeContext) => {
  const searchParams = new URLSearchParams(window.location.search);
  const paymentFlag = searchParams.get('sumup_payment');
  const orderId = searchParams.get('sumup-validate-order');
  const orderKey = searchParams.get('key') || '';
  const checkoutId = searchParams.get('checkout_id') || '';

  if (paymentFlag) {
    const message =
      paymentFlag === 'pending'
        ? getRedirectMessage('pending')
        : getRedirectMessage('failed');

    showCheckoutNotice(paymentFlag, message, noticeContext);

    window.history.replaceState(
      {},
      '',
      `${window.location.pathname}${stripRedirectQueryArgs(searchParams)}`,
    );

    if (paymentFlag === 'failed') {
      invalidateBlocksCart();
    }

    return;
  }

  if (!orderId) {
    return;
  }

  try {
    await validateRedirectWithServer({
      orderId,
      orderKey,
      checkoutId,
      noticeContext,
    });
  } catch (error) {
    console.error(error);
    showCheckoutNotice('failed', getRedirectMessage('failed'), noticeContext);
  }
};

export default handleRedirectValidation;
