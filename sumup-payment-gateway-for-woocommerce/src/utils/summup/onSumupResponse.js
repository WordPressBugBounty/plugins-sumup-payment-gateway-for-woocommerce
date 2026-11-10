import storePendingPaymentInstructions from '../../services/storePendingPaymentInstructions';
import { getWidgetGeneration } from './widgetLifecycle';

const getPaymentFailureMessage = (message) =>
  message ||
  sumup_gateway_params.errors?.general_error ||
  'Payment was unsuccessful.';

const onSumupResponse = (
  type,
  body,
  handleSubmit,
  checkoutData = {},
  handlePaymentFailure = () => {},
  selectedPaymentMethod = '',
  handleInstructionsFailure = handlePaymentFailure,
) => {
  const paymentMethod = body.payment_method || selectedPaymentMethod;
  const orderId = checkoutData.orderId;
  const orderKey = checkoutData.orderKey;

  switch (type) {
    case 'sent':
      break;
    case 'invalid':
      console.error('Error: ', body);
      handlePaymentFailure(getPaymentFailureMessage(body?.message));
      break;
    case 'auth-screen':
      break;
    case 'fail':
      console.error('Payment failed', body);
      handlePaymentFailure(getPaymentFailureMessage(body?.message));
      break;
    case 'error':
      console.error('Error', body);
      handlePaymentFailure(getPaymentFailureMessage(body?.message));
      break;
    case 'success':
      if (body.status === 'FAILED') {
        handlePaymentFailure(getPaymentFailureMessage(body?.message));
        break;
      }

      if (
        body.status === 'PENDING' &&
        ['boleto', 'pix', 'qr_code_pix'].includes(paymentMethod)
      ) {
        const generation = getWidgetGeneration();
        storePendingPaymentInstructions({
          orderId,
          orderKey,
          paymentMethod,
          checkoutId: checkoutData.checkoutId,
          amount: checkoutData.amount,
          currency: checkoutData.currency,
        })
          .then((result) => {
            if (generation !== getWidgetGeneration()) return;
            if (!result?.widget_only) handleSubmit();
          })
          .catch((error) => {
            if (generation !== getWidgetGeneration()) return;
            // A rejected binding must not leave its stale widget available for payment.
            if (error.status >= 400 && error.status < 500) {
              handlePaymentFailure(
                sumup_gateway_params.errors.stale_checkout ||
                  sumup_gateway_params.errors.instructions_error,
                true,
              );
            } else {
              handleInstructionsFailure(
                sumup_gateway_params.errors.instructions_error,
              );
            }
          });
        break;
      }

      if (body.status === 'PAID') {
        handleSubmit();
      }
      break;
  }
};

export default onSumupResponse;
