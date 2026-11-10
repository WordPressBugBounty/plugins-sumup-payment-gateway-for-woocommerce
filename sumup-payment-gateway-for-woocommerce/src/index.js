import { decodeEntities } from '@wordpress/html-entities';
import { useEffect, useRef, useState } from 'react';
import { mountSummupCard, unmountSummupCard } from './utils/summup';
import buildCheckoutContext, {
  getProcessingResponse,
} from './utils/summup/buildCheckoutContext';
import handleRedirectValidation from './utils/summup/handleRedirectValidation';
import SumupModal from './utils/summup/sumup-woocommerce-blocks';

/**
 * @typedef {import('@woocommerce/type-defs/registered-payment-method-props').EmitResponseProps} EmitResponseProps
 */

const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;

const settings = getSetting('sumup_data', {});
const canPayInCurrency = ({ cartTotals } = {}) =>
  settings.available === true &&
  (!cartTotals?.currency_code ||
    cartTotals.currency_code === settings.currency);
const label = decodeEntities(settings.title);
const icon = settings.icon;
const normalizeMessage = (message) => {
  if (typeof message === 'string') {
    return message;
  }

  if (Array.isArray(message)) {
    return message.filter(Boolean).join('\n');
  }

  return '';
};

const waitForRender = () =>
  new Promise((resolve) => {
    window.requestAnimationFrame(() => {
      window.requestAnimationFrame(resolve);
    });
  });

const Content = ({ eventRegistration, activePaymentMethod, emitResponse }) => {
  const {
    onCheckoutFail,
    onCheckoutSuccess,
    onPaymentProcessing,
    onPaymentSetup,
  } = eventRegistration;
  const [isOpen, setIsOpen] = useState(false);
  const pendingPayment = useRef(null);
  const [isModal] = useState(sumup_gateway_params.openPaymentInModal === 'yes');

  const buildPaymentFailureResponse = (message) => {
    return {
      type: emitResponse.responseTypes.FAIL,
      message:
        normalizeMessage(message) ||
        sumup_gateway_params.errors?.general_error ||
        'Payment was unsuccessful.',
      messageContext: emitResponse.noticeContexts?.PAYMENTS,
      retry: true,
    };
  };

  const closeModal = () => {
    setIsOpen(false);
    pendingPayment.current?.resolve({
      type: 'error',
      message:
        sumup_gateway_params.errors?.payment_cancelled ||
        'Payment was cancelled.',
    });
  };

  useEffect(() => {
    handleRedirectValidation(emitResponse.noticeContexts?.PAYMENTS);
  }, [emitResponse.noticeContexts]);

  useEffect(() => {
    const subscribePaymentSetup = onPaymentSetup || onPaymentProcessing;
    const unsubscribeSetup = subscribePaymentSetup(() => {
      if (activePaymentMethod !== 'sumup') return undefined;
      return {
        type: emitResponse.responseTypes.SUCCESS,
        meta: {
          paymentMethodData: {
            isCheckoutBlocks: 'yes',
          },
        },
      };
    });

    return unsubscribeSetup;
  }, [
    onPaymentSetup,
    onPaymentProcessing,
    emitResponse.responseTypes.SUCCESS,
    activePaymentMethod,
  ]);

  useEffect(() => {
    const unsubscribe = onCheckoutSuccess(async (checkoutData) => {
      if (activePaymentMethod !== 'sumup') return undefined;
      const processingResponse = getProcessingResponse(checkoutData);
      const paymentStatus = processingResponse.paymentStatus;
      const paymentDetails = processingResponse.paymentDetails || {};

      if (paymentStatus === 'failure' || paymentStatus === 'error') {
        return buildPaymentFailureResponse(
          processingResponse.message ||
            paymentDetails.message ||
            paymentDetails.messages,
        );
      }

      const checkoutContext = buildCheckoutContext(checkoutData);

      if (
        !checkoutContext.checkoutId ||
        !checkoutContext.orderId ||
        !checkoutContext.orderKey
      ) {
        return buildPaymentFailureResponse(
          sumup_gateway_params.errors?.checkout_init_error,
        );
      }

      if (checkoutContext.redirectUrl) {
        sumup_gateway_params.redirectUrl = checkoutContext.redirectUrl;
      }

      // The result belongs to this component and this attempt, never a document event.
      const attempt = { cancelled: false };
      const resolution = new Promise((resolve) => {
        attempt.resolve = resolve;
      });
      pendingPayment.current?.resolve({ type: 'error' });
      pendingPayment.current = attempt;
      try {
        setIsOpen(true);
        await waitForRender();
        if (attempt.cancelled) return buildPaymentFailureResponse();
        const response = mountSummupCard(
          () => attempt.resolve({ type: 'success' }),
          checkoutContext,
          (message) => attempt.resolve({ type: 'error', message }),
        );
        if (response) return buildPaymentFailureResponse(response.message);
        const result = await resolution;
        if (result.type === 'error')
          return buildPaymentFailureResponse(result.message);
        return {
          type: emitResponse.responseTypes.SUCCESS,
          redirectUrl: checkoutContext.redirectUrl,
        };
      } catch (error) {
        console.error(error);
        return buildPaymentFailureResponse(
          sumup_gateway_params.errors?.checkout_init_error,
        );
      } finally {
        if (pendingPayment.current === attempt) {
          pendingPayment.current = null;
          if (!attempt.cancelled) setIsOpen(false);
          unmountSummupCard();
        }
      }
    });

    return () => {
      if (pendingPayment.current) {
        pendingPayment.current.cancelled = true;
        pendingPayment.current.resolve({ type: 'error' });
      }
      unmountSummupCard();
      unsubscribe();
    };
  }, [
    onCheckoutSuccess,
    activePaymentMethod,
    emitResponse.responseTypes.ERROR,
    emitResponse.responseTypes.SUCCESS,
  ]);

  useEffect(() => {
    const unsubscribe = onCheckoutFail((checkoutData) => {
      if (activePaymentMethod !== 'sumup') return undefined;
      const processingResponse = getProcessingResponse(checkoutData);
      const paymentDetails = processingResponse.paymentDetails || {};

      return buildPaymentFailureResponse(
        processingResponse.message ||
          paymentDetails.message ||
          paymentDetails.messages,
      );
    });

    return unsubscribe;
  }, [onCheckoutFail, emitResponse.responseTypes.FAIL, activePaymentMethod]);

  if (activePaymentMethod !== 'sumup') return null;
  return <SumupModal isOpen={isOpen} onClose={closeModal} isModal={isModal} />;
};

const Label = (props) => {
  const { PaymentMethodLabel } = props.components;
  const iconElement = icon ? (
    <img src={icon} alt="" className="wc-sumup-payment-method__icon" />
  ) : null;

  return <PaymentMethodLabel text={label} icon={iconElement} />;
};

registerPaymentMethod({
  name: 'sumup',
  label: <Label />,
  content: <Content />,
  edit: <Content />,
  canMakePayment: canPayInCurrency,
  ariaLabel: label,
  supports: {
    features: settings.supports,
  },
});
