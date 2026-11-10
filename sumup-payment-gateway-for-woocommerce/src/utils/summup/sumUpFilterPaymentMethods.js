const sumUpFilterPaymentMethods = (
  response,
  settings = sumup_gateway_params,
) => {
  const shouldOfferPix =
    settings.enablePix === 'yes' && settings.country === 'BR';
  const shouldOfferApplePay = settings.enableApplePay === 'yes';
  const shouldOfferGooglePay = (settings.enableGooglePay ?? 'yes') === 'yes';

  if (response?.eligible?.length) {
    return response.eligible
      .filter(
        ({ id }) =>
          (shouldOfferPix || (id !== 'pix' && id !== 'qr_code_pix')) &&
          (shouldOfferApplePay || id !== 'apple_pay') &&
          (shouldOfferGooglePay || id !== 'google_pay'),
      )
      .map(({ id }) => id);
  }
};

export default sumUpFilterPaymentMethods;
