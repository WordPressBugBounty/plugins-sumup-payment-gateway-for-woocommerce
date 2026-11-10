const getStorePaymentInstructionsUrl = () => {
  const requestUrl = new URL(sumup_gateway_params.sumup_handler_url);
  requestUrl.searchParams.set('action', 'store_payment_instructions');

  return requestUrl.toString();
};

const storePendingPaymentInstructions = async ({
  orderId,
  orderKey,
  paymentMethod,
  checkoutId,
  amount,
  currency,
}) => {
  if (!orderId || !orderKey || !paymentMethod || !checkoutId) {
    throw new Error('Missing payment instructions context.');
  }

  const response = await fetch(getStorePaymentInstructionsUrl(), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      nonce: sumup_gateway_params.paymentInstructionsNonce,
      order_id: orderId,
      order_key: orderKey,
      payment_method: paymentMethod,
      checkout_id: checkoutId,
      amount,
      currency,
    }),
  });
  const payload = await response.json().catch(() => null);
  if (!response.ok || payload?.result !== 'success') {
    const error = new Error(
      payload?.message || 'Unable to save payment instructions.',
    );
    error.status = response.status;
    throw error;
  }
  return payload.data || {};
};

export default storePendingPaymentInstructions;
