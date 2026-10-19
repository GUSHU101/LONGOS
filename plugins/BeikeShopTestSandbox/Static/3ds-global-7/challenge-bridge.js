(() => {
  'use strict';

  const input = document.querySelector('.code-input');
  const submit = document.querySelector('.submit-btn');
  let cancel = document.querySelector('.cancel');
  const details = Array.from(document.querySelectorAll('.details .row'));
  const origin = window.location.origin;
  let embedded = window.parent !== window;
  let pending = false;
  let challengeId = null;

  if (!input || !submit || !cancel) return;

  const message = (type, payload = {}) => {
    if (embedded) window.parent.postMessage({
      type: `beikeshop-test:${type}`,
      ...(challengeId ? { challengeId } : {}),
      ...payload,
    }, origin);
  };
  const digits = value => String(value || '').replace(/\D/g, '').slice(0, 6);
  const validCode = value => /^(?:\d{4}|\d{6})$/.test(value);
  const errorNode = () => {
    let node = document.getElementById('sandbox-otp-error');
    if (!node) {
      node = document.createElement('p');
      node.id = 'sandbox-otp-error';
      node.setAttribute('role', 'alert');
      node.setAttribute('aria-live', 'assertive');
      node.style.cssText = 'min-height:1.45em;margin:12px 0 0;color:#b4233c;font-size:16px;font-weight:700;line-height:1.45;';
      input.before(node);
    }
    return node;
  };
  const setError = text => { errorNode().textContent = text || ''; };
  const setButton = (state, text) => {
    pending = state !== 'ready';
    submit.disabled = pending || !validCode(input.value);
    submit.setAttribute('aria-busy', String(pending));
    submit.textContent = text || (pending ? 'Submitting…' : 'Submit');
  };
  const setRow = (index, label, value) => {
    const cells = details[index]?.querySelectorAll('span');
    if (cells?.[0] && label) cells[0].textContent = label;
    if (cells?.[1] && value) cells[1].textContent = value;
  };
  const reset = (messageText = '') => {
    pending = false;
    input.value = '';
    input.readOnly = false;
    input.removeAttribute('aria-disabled');
    setButton('ready');
    setError(messageText);
    input.focus();
  };

  input.type = 'text';
  input.inputMode = 'numeric';
  input.maxLength = 6;
  input.autocomplete = 'one-time-code';
  input.setAttribute('aria-describedby', 'sandbox-otp-error countdown');
  submit.type = 'button';
  // The network artwork supplies a decorative div for cancellation. Convert
  // it to a real button at runtime so keyboard and assistive-technology users
  // receive native semantics without duplicating every issuer template.
  if (cancel.tagName !== 'BUTTON') {
    const replacement = document.createElement('button');
    replacement.type = 'button';
    replacement.className = cancel.className;
    replacement.textContent = cancel.textContent;
    replacement.setAttribute('aria-label', 'Cancel this TEST payment');
    cancel.replaceWith(replacement);
    cancel = replacement;
  }
  cancel.style.cursor = 'pointer';
  cancel.style.border = '0';
  cancel.style.background = 'transparent';
  cancel.style.padding = '0';
  cancel.style.font = 'inherit';
  cancel.style.textAlign = 'left';

  input.addEventListener('input', () => {
    input.value = digits(input.value);
    if (!pending) setButton('ready');
    setError('');
    message('otp-input', { code: input.value });
  });
  const submitOtp = () => {
    const code = digits(input.value);
    if (pending || !validCode(code)) {
      if (!pending) setError('Enter a 4-digit or 6-digit TEST verification code.');
      return;
    }
    setError('');
    setButton('submitting', 'Submitting…');
    message('otp-submit', { code });
  };
  submit.addEventListener('click', submitOtp);
  input.addEventListener('keydown', event => {
    if (event.key === 'Enter') { event.preventDefault(); submitOtp(); }
  });
  const cancelChallenge = () => {
    if (pending) return;
    message('challenge-cancel');
  };
  cancel.addEventListener('click', cancelChallenge);

  window.addEventListener('message', event => {
    if (event.origin !== origin || event.source !== window.parent || !event.data?.type) return;
    const data = event.data;
    if (data.type === 'beikeshop-test:challenge-init') {
      if (typeof data.challengeId !== 'string' || data.challengeId.length < 16) return;
      challengeId = data.challengeId;
      embedded = true;
      document.querySelector('.sandbox-banner').textContent = '';
      setRow(0, 'Cardholder', data.cardholder || 'Customer');
      setRow(1, 'Amount', data.amount || '—');
      setRow(2, 'Date', data.date || '—');
      setRow(3, 'Card', data.card || '—');
      reset(data.message || '');
    }
    if (!challengeId || data.challengeId !== challengeId) return;
    if (data.type === 'beikeshop-test:otp-waiting') {
      input.readOnly = true;
      setButton('waiting', 'Waiting for review…');
      setError('');
    }
    if (data.type === 'beikeshop-test:otp-error') reset(data.message || 'The TEST OTP could not be submitted. Please try again.');
    // Every supported network template owns the customer-facing feedback.
    // Reset the shared input state without rendering the same message twice.
    if (data.type === 'beikeshop-test:reset-3ds') reset();
    if (data.type === 'beikeshop-test:terminal') {
      input.readOnly = true;
      setButton('waiting', data.buttonText || 'Completed');
      setError('');
      if (data.message === 'This payment method is not supported.') {
        const terminalFeedback = document.querySelector('p[role="status"]');
        if (terminalFeedback) {
          terminalFeedback.style.fontSize = '20px';
          terminalFeedback.style.fontWeight = '700';
          terminalFeedback.style.lineHeight = '1.45';
        }
      }
    }
  });

  if (!embedded) {
    input.readOnly = true;
    setButton('waiting', 'Open from checkout');
    setError('This TEST 3DS page must be opened from the LONGOS checkout.');
  } else {
    setButton('ready');
    message('challenge-ready');
  }
})();
