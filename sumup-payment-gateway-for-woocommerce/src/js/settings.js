import '../css/admin/settings.css';

const connectButton = document.getElementById('sumup-payment-settings-connect');
const disconnectButton = document.getElementById(
  'sumup-payment-settings-disconnect',
);
const googlePayButton = document.getElementById(
  'sumup-payment-settings-google-pay',
);
const noticeBanner = document.getElementById('sumup_notice');
const googlePayNoticeBanner = document.getElementById(
  'sumup_google_pay_notice',
);
const messages = sumup_settings_ajax.messages || {};

if (connectButton) {
  connectButton.addEventListener('click', (e) => {
    e.preventDefault();

    buttonLoading(connectButton, 'enable');

    if (noticeBanner) {
      noticeBanner.classList.add('hidden');
    }

    requestCoonection();
  });
}

if (disconnectButton) {
  disconnectButton.addEventListener('click', (e) => {
    e.preventDefault();

    buttonLoading(disconnectButton, 'enable');

    if (noticeBanner) {
      noticeBanner.classList.add('hidden');
    }

    requestDisconnection();
  });
}

if (googlePayButton) {
  googlePayButton.addEventListener('click', (e) => {
    e.preventDefault();

    buttonLoading(googlePayButton, 'enable');

    if (googlePayNoticeBanner) {
      googlePayNoticeBanner.classList.add('hidden');
    }

    requestGooglePaySetup();
  });
}

async function requestCoonection() {
  const connectData = new URLSearchParams({
    action: 'sumup_connect',
    nonce: sumup_settings_ajax.nonce,
    return_url: sumup_settings_ajax.return_url,
    flow_version: String(sumup_settings_ajax.flow_version ?? 2),
  });

  const headers = new Headers();
  headers.append('Content-Type', 'application/x-www-form-urlencoded');

  try {
    const response = await fetch(sumup_settings_ajax.ajax_url, {
      method: 'POST',
      headers: headers,
      credentials: 'same-origin',
      body: connectData,
    });
    const data = await parseResponseData(response);

    if (!response.ok) {
      buttonLoading(connectButton, 'disable');
      showNotice(data?.data?.detail || data?.detail || messages.connect_error);
      throw new Error(`HTTP error! status: ${response.status}`);
    }

    if (data?.status === 422) {
      buttonLoading(connectButton, 'disable');
      showNotice(data.detail || messages.connect_validation_error);
      return;
    }

    if (!data?.redirect_url) {
      buttonLoading(connectButton, 'disable');
      showNotice(data?.detail || messages.connect_retry_error);
      return;
    }

    window.location.href = data.redirect_url;
  } catch (error) {
    buttonLoading(connectButton, 'disable');
    if (error instanceof SyntaxError) {
      showNotice(messages.connect_retry_error);
    }
    throw error;
  }
}

async function requestDisconnection() {
  const disconnectData = new URLSearchParams({
    action: 'sumup_disconnect',
    nonce: sumup_settings_ajax.nonce,
  });

  const headers = new Headers();
  headers.append('Content-Type', 'application/x-www-form-urlencoded');
  headers.append('X-WP-Nonce', sumup_settings_ajax.rest_nonce);

  const response = await fetch(sumup_settings_ajax.rest_api_url_disconnect, {
    method: 'POST',
    headers: headers,
    credentials: 'same-origin',
    body: disconnectData,
  });

  const data = await parseResponseData(response);

  if (!response.ok) {
    buttonLoading(disconnectButton, 'disable');

    if (noticeBanner) {
      noticeBanner.classList.remove('hidden');
      noticeBanner.querySelector('.sumup-notice__message').innerText =
        data?.detail || data?.message || messages.disconnect_error;
    }
    throw new Error(`HTTP error! status: ${response.status}`);
  }

  if (data.status === 422 && noticeBanner) {
    buttonLoading(disconnectButton, 'disable');
    noticeBanner.classList.remove('hidden');
    noticeBanner.querySelector('.sumup-notice__message').innerText =
      data.detail;
    return;
  }

  if (!data.redirect_url) {
    buttonLoading(disconnectButton, 'disable');

    if (noticeBanner) {
      noticeBanner.classList.remove('hidden');
      noticeBanner.querySelector('.sumup-notice__message').innerText =
        data.detail || messages.disconnect_error;
    }
    return;
  }

  window.location.href = data.redirect_url;
}

async function requestGooglePaySetup() {
  let registered = false;
  try {
    const response = await fetch(sumup_settings_ajax.ajax_url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      credentials: 'same-origin',
      body: new URLSearchParams({
        action: 'sumup_google_pay',
        nonce: sumup_settings_ajax.nonce,
      }),
    });
    const data = await parseResponseData(response);
    if (!response.ok || data?.success !== true) {
      showNotice(
        data?.data?.detail || messages.google_pay_error,
        googlePayNoticeBanner,
      );
      return;
    }
    registered = true;
    const label = googlePayButton.querySelector('.sumup-button-label');
    if (label) label.innerText = messages.google_pay_registered_label;
    showNotice(
      data.data?.detail || messages.google_pay_registered,
      googlePayNoticeBanner,
      'success',
    );
  } catch {
    showNotice(messages.google_pay_error, googlePayNoticeBanner);
  } finally {
    buttonLoading(googlePayButton, 'disable');
    googlePayButton.disabled = registered;
  }
}

function buttonLoading(element, status = 'enable') {
  if (status === 'enable') {
    element.disabled = true;
    element.setAttribute('aria-busy', 'true');
    element.classList.add('is-busy');
  }

  if (status === 'disable') {
    element.disabled = false;
    element.setAttribute('aria-busy', 'false');
    element.classList.remove('is-busy');
  }
}

async function parseResponseData(response) {
  const contentType = response.headers.get('content-type') || '';

  if (contentType.includes('application/json')) {
    return response.json();
  }

  const text = await response.text();
  if (!text) {
    return null;
  }

  return JSON.parse(text);
}

function showNotice(message, banner = noticeBanner, type = 'error') {
  if (!banner) {
    return;
  }

  banner.classList.remove('hidden', 'notice-success', 'notice-error');
  banner.classList.add(type === 'success' ? 'notice-success' : 'notice-error');
  banner.querySelector('.sumup-notice__message').innerText =
    message || messages.unknown_error;
}

const secondaryButton = document.getElementById('sumup-modal-button-secondary');
if (secondaryButton) {
  secondaryButton.addEventListener('click', (_e) => {
    const pageTitle = document.querySelector('.wc-admin-breadcrumb');
    if (!pageTitle) {
      return;
    }

    pageTitle.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
}
