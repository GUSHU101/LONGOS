const initializeOrderTracking = (root = document) => {
  root.querySelectorAll('[data-order-tracking]').forEach((component) => {
    if (component.dataset.trackingInitialized === 'true') return;
    component.dataset.trackingInitialized = 'true';

    const form = component.querySelector('[data-tracking-form]');
    const input = component.querySelector('[data-tracking-input]');
    const button = component.querySelector('[data-tracking-button]');
    const buttonText = component.querySelector('[data-button-text]');
    const error = component.querySelector('[data-tracking-error]');
    const success = component.querySelector('[data-tracking-success]');
    const recent = component.querySelector('[data-tracking-recent]');
    const recentList = component.querySelector('[data-tracking-recent-list]');
    const clearButton = component.querySelector('[data-clear-tracking-history]');

    if (!form || !input || !button || !buttonText || !error || !success || !recent || !recentList || !clearButton) return;

    const providerUrl = component.dataset.trackingUrl || 'https://www.17track.net/en/track?nums=';
    const storageKey = `${component.dataset.storageKey || 'longos_order_tracking_history'}:${window.location.hostname}`;
    const invalidMessage = component.dataset.invalidMessage || '';
    const openingLabel = component.dataset.openingLabel || '';
    const defaultLabel = component.dataset.defaultLabel || buttonText.textContent;
    const historyLabel = component.dataset.historyLabel || ':number';
    const maxRecent = 4;

    const normalize = (value) => String(value || '').trim().replace(/[\s-]+/g, '').toUpperCase();
    const isValid = (value) => /^[A-Z0-9]{8,40}$/.test(value);

    const hideMessages = () => {
      error.hidden = true;
      success.hidden = true;
      input.setAttribute('aria-invalid', 'false');
    };

    const setLoading = (loading) => {
      button.disabled = loading;
      button.classList.toggle('is-loading', loading);
      button.setAttribute('aria-busy', String(loading));
      buttonText.textContent = loading ? openingLabel : defaultLabel;
    };

    const getHistory = () => {
      try {
        const stored = JSON.parse(window.localStorage.getItem(storageKey) || '[]');
        return Array.isArray(stored) ? stored.filter((item) => isValid(item)).slice(0, maxRecent) : [];
      } catch (exception) {
        return [];
      }
    };

    const openTrackingProvider = (trackingNumber) => {
      const url = `${providerUrl}${encodeURIComponent(trackingNumber)}`;
      const trackingWindow = window.open('about:blank', '_blank');

      if (trackingWindow) {
        trackingWindow.opener = null;
        trackingWindow.location.replace(url);
      } else {
        window.location.assign(url);
      }
    };

    const renderHistory = () => {
      const history = getHistory();
      recentList.replaceChildren();
      recent.hidden = history.length === 0;

      history.forEach((trackingNumber) => {
        const historyButton = document.createElement('button');
        historyButton.type = 'button';
        historyButton.className = 'order-tracking__recent-item';
        historyButton.textContent = trackingNumber;
        historyButton.setAttribute('aria-label', historyLabel.replace(':number', trackingNumber));
        historyButton.addEventListener('click', () => {
          input.value = trackingNumber;
          form.requestSubmit();
        });
        recentList.appendChild(historyButton);
      });
    };

    const saveHistory = (trackingNumber) => {
      try {
        const history = getHistory().filter((item) => item !== trackingNumber);
        history.unshift(trackingNumber);
        window.localStorage.setItem(storageKey, JSON.stringify(history.slice(0, maxRecent)));
      } catch (exception) {
        // Tracking remains available when storage is disabled or full.
      }
      renderHistory();
    };

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const trackingNumber = normalize(input.value);
      input.value = trackingNumber;

      if (!isValid(trackingNumber)) {
        error.textContent = invalidMessage;
        error.hidden = false;
        success.hidden = true;
        input.setAttribute('aria-invalid', 'true');
        input.focus();
        return;
      }

      hideMessages();
      setLoading(true);
      saveHistory(trackingNumber);
      success.hidden = false;
      openTrackingProvider(trackingNumber);
      window.setTimeout(() => setLoading(false), 700);
    });

    input.addEventListener('input', () => {
      input.value = input.value.toUpperCase();
      hideMessages();
    });

    input.addEventListener('paste', () => {
      window.setTimeout(() => {
        input.value = normalize(input.value);
      }, 0);
    });

    clearButton.addEventListener('click', () => {
      try {
        window.localStorage.removeItem(storageKey);
      } catch (exception) {
        // The visual history can still be cleared when storage is unavailable.
      }
      renderHistory();
      input.focus();
    });

    renderHistory();
  });
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => initializeOrderTracking(), { once: true });
} else {
  initializeOrderTracking();
}

window.LongosOrderTracking = { initialize: initializeOrderTracking };
