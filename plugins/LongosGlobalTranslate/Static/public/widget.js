(() => {
  'use strict';

  const root = document.getElementById('longos-global-translate');
  if (!root || root.dataset.ready) return;

  const toggle = root.querySelector('.lgt-widget__toggle');
  const close = root.querySelector('.lgt-widget__close');
  const panel = root.querySelector('.lgt-widget__panel');
  const options = root.querySelector('.lgt-widget__options');
  const status = root.querySelector('.lgt-widget__status');
  const current = root.querySelector('.lgt-widget__current');
  if (!toggle || !close || !panel || !options || !status || !current) {
    root.remove();
    return;
  }

  root.dataset.ready = '1';
  const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const contentKeyCache = new Map();
  let config = null;

  const label = locale => {
    try {
      return typeof Intl.DisplayNames === 'function'
        ? new Intl.DisplayNames([document.documentElement.lang || 'en'], {type: 'language'}).of(locale) || locale
        : locale;
    } catch (_) {
      return locale;
    }
  };

  const setOpen = open => {
    root.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    panel.setAttribute('aria-hidden', String(!open));
    if (open) options.querySelector('button')?.focus();
    else toggle.focus();
  };

  const request = (url, init = {}) => fetch(url, {
    credentials: 'same-origin',
    headers: {Accept: 'application/json', 'X-CSRF-TOKEN': csrf, ...(init.headers || {})},
    ...init,
  }).then(async response => {
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(body.message || 'Request failed');
    return body;
  });

  const contentKey = (kind, entity, field) => {
    if (!window.crypto?.subtle || typeof field !== 'string' || field === '') return Promise.resolve(null);
    const input = `lgt-content-v2:${kind}:${entity}:${field}:en`;
    if (!contentKeyCache.has(input)) {
      contentKeyCache.set(input, crypto.subtle.digest('SHA-256', new TextEncoder().encode(input))
        .then(hash => [...new Uint8Array(hash)].map(byte => byte.toString(16).padStart(2, '0')).join('')));
    }
    return contentKeyCache.get(input);
  };

  const regions = () => [...document.querySelectorAll('[data-lgt-root]')].slice(0, 30)
    .filter(region => {
      const kind = region.dataset.lgtKind;
      const entity = Number(region.dataset.lgtEntity);
      return ['product', 'category', 'page', 'builder'].includes(kind) && Number.isInteger(entity) && entity > 0;
    });

  const restoreSource = sourceRegions => {
    sourceRegions.forEach(region => region.querySelectorAll('[data-lgt-field]').forEach(node => {
      if (!Object.prototype.hasOwnProperty.call(node.dataset, 'lgtOriginal')) node.dataset.lgtOriginal = node.textContent || '';
      node.textContent = node.dataset.lgtOriginal;
    }));
  };

  const fetchDictionaries = async (sourceRegions, locale) => {
    const results = new Map();
    let cursor = 0;
    const worker = async () => {
      while (cursor < sourceRegions.length) {
        const index = cursor++;
        const region = sourceRegions[index];
        const kind = region.dataset.lgtKind;
        const entity = String(Number(region.dataset.lgtEntity));
        const url = config.dictionary_url
          .replace('__kind__', encodeURIComponent(kind))
          .replace('__entity__', entity) + `?locale=${encodeURIComponent(locale)}`;
        results.set(region, await request(url));
      }
    };
    // A page builder can contain many modules. Keep requests bounded so the
    // optional presentation layer cannot compete with commerce requests.
    await Promise.all(Array.from({length: Math.min(4, sourceRegions.length)}, worker));
    return results;
  };

  const replaceKnownNodes = async locale => {
    if (!config) return;
    const sourceRegions = regions();
    // Always revert every field before a new locale is applied. This avoids a
    // mixed language page when the new publication is only partially covered.
    restoreSource(sourceRegions);
    if (locale === config.source_locale || sourceRegions.length === 0) return;

    const dictionaries = await fetchDictionaries(sourceRegions, locale);
    for (const region of sourceRegions) {
      const dictionary = dictionaries.get(region);
      const kind = region.dataset.lgtKind;
      const entity = Number(region.dataset.lgtEntity);
      for (const node of region.querySelectorAll('[data-lgt-field]')) {
        const key = await contentKey(kind, entity, node.dataset.lgtField);
        if (key && typeof dictionary?.items?.[key] === 'string') node.textContent = dictionary.items[key];
      }
    }
  };

  const optionButton = locale => {
    const button = document.createElement('button');
    const name = document.createElement('span');
    const code = document.createElement('small');
    const selected = config.locale_source === 'explicit' ? config.locale : 'auto';
    button.type = 'button';
    button.className = 'lgt-widget__option';
    button.dataset.locale = locale;
    button.setAttribute('aria-pressed', String(locale === selected));
    name.textContent = locale === 'auto' ? 'Automatic' : label(locale);
    code.textContent = locale === 'auto' ? 'AUTO' : locale.toUpperCase();
    button.append(name, code);
    button.addEventListener('click', async () => {
      status.textContent = '';
      button.disabled = true;
      try {
        const saved = await request(root.dataset.localeUrl, {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({locale}),
        });
        if (locale === 'auto') {
          window.location.reload();
          return;
        }
        config.locale = saved.locale;
        config.locale_source = saved.source;
        render();
        try {
          await replaceKnownNodes(saved.locale);
          status.textContent = 'Language updated.';
        } catch (_) {
          // The preference was saved, but the source language is the only
          // safe display state while a publication cannot be read.
          status.textContent = 'Language saved. Showing original English while translations are unavailable.';
        }
      } catch (error) {
        status.textContent = error.message || 'Unable to save language preference.';
      } finally {
        button.disabled = false;
      }
    });
    return button;
  };

  const render = () => {
    options.replaceChildren();
    ['auto', ...new Set([config.source_locale, ...(config.locales || [])])]
      .forEach(locale => options.append(optionButton(locale)));
    current.textContent = (config.locale || config.source_locale).toUpperCase();
  };

  toggle.addEventListener('click', () => setOpen(!root.classList.contains('is-open')));
  close.addEventListener('click', () => setOpen(false));
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && root.classList.contains('is-open')) setOpen(false);
  });

  request(root.dataset.configUrl).then(async data => {
    if (!data.enabled) {
      root.remove();
      return;
    }
    config = data;
    render();
    root.hidden = false;
    try {
      await replaceKnownNodes(config.locale);
    } catch (_) {
      status.textContent = 'Showing original English while translations are unavailable.';
    }
  }).catch(() => root.remove());
})();
