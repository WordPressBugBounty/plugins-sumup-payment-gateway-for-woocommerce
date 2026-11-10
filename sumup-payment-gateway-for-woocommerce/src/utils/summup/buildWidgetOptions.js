import onSumupResponse from './onSumupResponse';
import sumUpFilterPaymentMethods from './sumUpFilterPaymentMethods';

const buildWidgetOptions = (
  checkoutContext,
  {
    onSuccess,
    onFailure = () => {},
    onLoad,
    getPaymentMethod = () => '',
    onPaymentMethod = () => {},
    onValidationError = onFailure,
    onInstructionsError = onFailure,
  },
  settings = sumup_gateway_params,
) => {
  const options = {
    checkoutId: checkoutContext.checkoutId,
    onResponse: (type, body) => {
      if (body?.payment_method) onPaymentMethod(body.payment_method);
      onSumupResponse(
        type,
        body,
        onSuccess,
        checkoutContext,
        type === 'invalid' ? onValidationError : onFailure,
        getPaymentMethod(),
        onInstructionsError,
      );
    },
    showZipCode:
      settings.showZipCode === true || settings.showZipCode === 'true',
    locale: settings.locale,
    showAmount: true,
    showFooter: true,
    showInstallments:
      settings.showInstallments === true ||
      settings.showInstallments === 'true',
    onPaymentMethodsLoad: (response) =>
      sumUpFilterPaymentMethods(response, settings),
  };
  for (const key of [
    'firstName',
    'lastName',
    'email',
    'phoneNumber',
    'city',
    'street',
    'streetNumber',
    'postalCode',
    'stateName',
    'country',
  ]) {
    options[key] = checkoutContext[key] || settings[key] || '';
  }
  if (settings.maxInstallments)
    options.maxInstallments = settings.maxInstallments;
  if (settings.googlePay?.merchantId) options.googlePay = settings.googlePay;
  if (onLoad) options.onLoad = onLoad;
  return options;
};

export default buildWidgetOptions;
