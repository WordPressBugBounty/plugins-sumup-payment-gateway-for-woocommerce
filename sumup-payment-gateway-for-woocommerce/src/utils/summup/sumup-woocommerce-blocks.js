import { createPortal } from '@wordpress/element';
import '../../css/checkout/modal.css';

const SumupModal = ({ isOpen, onClose, isModal }) => {
  if (!isModal && !isOpen) {
    return null;
  }

  const Modal = window.wp?.components?.Modal;
  const labels = window.sumup_gateway_params?.modal || {};

  const modalClass = isOpen
    ? isModal
      ? 'wc-sumup-modal'
      : 'wc-sumup-modal no-modal'
    : 'wc-sumup-modal disabled';

  const content = (
    <div
      id="wc-sumup-payment-modal"
      className={modalClass}
      aria-hidden={!isOpen}
    >
      <div id="sumup-card">
        {isModal && (
          <button
            id="wc-sumup-payment-modal-close"
            type="button"
            aria-label={labels.close || 'Close payment modal'}
            onClick={onClose}
          >
            <span id="wc-sumup-payment-modal-close-btn" aria-hidden="true">
              X
            </span>
          </button>
        )}
      </div>
    </div>
  );

  if (!isModal) {
    return content;
  }

  if (!Modal && document?.body) {
    return createPortal(content, document.body);
  }

  if (!Modal) {
    return content;
  }

  if (!isOpen) {
    return null;
  }

  return (
    <Modal
      id="wc-sumup-payment-modal"
      className="wc-sumup-modal__content"
      overlayClassName="wc-sumup-modal"
      bodyOpenClassName="sumup-modal-open"
      contentLabel={labels.label || 'SumUp payment'}
      onRequestClose={onClose}
      shouldCloseOnClickOutside
      shouldCloseOnEsc
    >
      <div id="sumup-card" />
    </Modal>
  );
};

export default SumupModal;
