import buildWidgetOptions from './buildWidgetOptions';

import { mountWidget, unmountWidget } from './widgetLifecycle';

const unmountSummupCard = unmountWidget;

const mountSummupCard = (
  handleSubmit,
  checkoutContext,
  handlePaymentFailure = () => {},
) => {
  if (!checkoutContext?.checkoutId) {
    return {
      result: 'error',
      message:
        sumup_gateway_params.errors?.checkout_init_error ||
        'SumUp checkout ID was not returned by the server.',
    };
  }

  sumup_gateway_params.country = checkoutContext.country;
  const sumupCardWidgetOptions = buildWidgetOptions(checkoutContext, {
    onSuccess: handleSubmit,
    onFailure: handlePaymentFailure,
  });

  mountWidget(sumupCardWidgetOptions);
};

export { unmountSummupCard };
export default mountSummupCard;
