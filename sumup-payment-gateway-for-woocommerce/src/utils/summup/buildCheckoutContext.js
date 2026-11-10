const normalizePaymentDetails = (paymentDetails) => {
  if (!paymentDetails) {
    return {};
  }

  if (Array.isArray(paymentDetails)) {
    return paymentDetails.reduce((details, entry) => {
      if (entry?.key) {
        details[entry.key] = entry.value;
      }
      return details;
    }, {});
  }

  return paymentDetails;
};

const getOrderKeyFromRedirect = (redirectUrl = '') => {
  if (!redirectUrl) {
    return '';
  }

  try {
    return (
      new URL(redirectUrl, window.location.origin).searchParams.get('key') || ''
    );
  } catch {
    return '';
  }
};

const getProcessingResponse = (checkoutData) =>
  checkoutData?.processingResponse || checkoutData?.paymentResult || {};

const buildCheckoutContext = (checkoutData) => {
  const processingResponse = getProcessingResponse(checkoutData);
  const paymentDetails = normalizePaymentDetails(
    processingResponse.paymentDetails,
  );
  const redirectUrl =
    paymentDetails.redirectUrl ||
    paymentDetails.redirect ||
    processingResponse.redirectUrl ||
    processingResponse.redirect ||
    checkoutData?.redirectUrl ||
    checkoutData?.redirect ||
    '';

  return {
    checkoutId:
      paymentDetails.checkoutId ||
      processingResponse.checkoutId ||
      checkoutData?.checkoutId ||
      checkoutData?.checkout_id,
    orderId:
      paymentDetails.orderId || checkoutData?.orderId || checkoutData?.order_id,
    orderKey:
      paymentDetails.orderKey ||
      checkoutData?.orderKey ||
      checkoutData?.order_key ||
      getOrderKeyFromRedirect(redirectUrl),
    country: paymentDetails.country,
    firstName: paymentDetails.firstName,
    lastName: paymentDetails.lastName,
    email: paymentDetails.email,
    phoneNumber: paymentDetails.phoneNumber,
    city: paymentDetails.city,
    street: paymentDetails.street,
    streetNumber: paymentDetails.streetNumber,
    postalCode: paymentDetails.postalCode,
    stateName: paymentDetails.stateName,
    amount: paymentDetails.amount,
    currency: paymentDetails.currency,
    redirectUrl,
  };
};

export { getProcessingResponse, normalizePaymentDetails };
export default buildCheckoutContext;
