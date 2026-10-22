const announceOrderAction = (message) => {
  const feedback = document.querySelector('[data-order-feedback]')
    || document.querySelector('#storefront-feedback');

  if (feedback) feedback.textContent = message || '';
};

const copyOrderNumber = async (button) => {
  const value = button.dataset.copyOrder || '';
  if (!value) return;
  let fallbackInput = null;

  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(value);
    } else {
      fallbackInput = document.createElement('textarea');
      fallbackInput.value = value;
      fallbackInput.setAttribute('readonly', '');
      fallbackInput.style.position = 'fixed';
      fallbackInput.style.opacity = '0';
      document.body.appendChild(fallbackInput);
      fallbackInput.select();
      if (typeof document.execCommand !== 'function' || !document.execCommand('copy')) {
        throw new Error('Clipboard API unavailable');
      }
    }
    announceOrderAction(button.dataset.copySuccess || value);
  } catch (error) {
    announceOrderAction(button.dataset.copyError || value);
  } finally {
    fallbackInput?.remove();
  }
};

const runOrderAction = async (button) => {
  if (button.dataset.submitting === 'true') return;

  const confirmation = button.dataset.confirm;
  if (confirmation && !window.confirm(confirmation)) return;

  const original = button.innerHTML;
  button.dataset.submitting = 'true';
  button.disabled = true;
  button.setAttribute('aria-busy', 'true');
  button.textContent = button.dataset.processing || '…';

  try {
    const response = await window.$http.post(button.dataset.actionUrl, {});
    const message = response?.message || button.dataset.success || '';
    announceOrderAction(message);
    if (window.layer && message) window.layer.msg(message);
    window.setTimeout(() => window.location.reload(), 500);
  } catch (error) {
    const message = error?.response?.data?.message || button.dataset.error || '';
    announceOrderAction(message);
    button.innerHTML = original;
    button.disabled = false;
    button.removeAttribute('aria-busy');
    delete button.dataset.submitting;
  }
};

document.addEventListener('click', (event) => {
  const copyButton = event.target.closest('[data-copy-order]');
  if (copyButton) {
    event.preventDefault();
    copyOrderNumber(copyButton);
    return;
  }

  const actionButton = event.target.closest('[data-order-action]');
  if (!actionButton) return;

  event.preventDefault();
  runOrderAction(actionButton);
});
