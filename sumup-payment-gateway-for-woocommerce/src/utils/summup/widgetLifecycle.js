let widget = null;
let mountedContext;
let generation = 0;

const getWidgetGeneration = () => generation;

const unmountWidget = () => {
  generation += 1;
  const mounted = widget;
  widget = null;
  mountedContext = undefined;
  mounted?.unmount?.();
  const container = document.getElementById('sumup-card');
  const close = document.getElementById('wc-sumup-payment-modal-close');
  container?.replaceChildren(...(close ? [close] : []));
};

const mountWidget = (options, context, reuse = false) => {
  if (widget && reuse && mountedContext === context) return true;
  unmountWidget();
  const mounting = generation;
  const mounted = SumUpCard.mount(options);
  // A synchronous SDK failure can unmount while mount() is still running.
  if (mounting !== generation) {
    mounted?.unmount?.();
    return false;
  }
  widget = mounted;
  mountedContext = context;
  return true;
};

export { getWidgetGeneration, mountWidget, unmountWidget };
