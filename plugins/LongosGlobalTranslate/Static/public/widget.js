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
  let presentationEpoch = 0;
  let interfaceEpoch = 0;
  let interfaceTranslations = new Map();
  let interfaceObserver = null;
  let interfaceApplyQueued = false;
  let applyingInterface = false;
  const interfaceTextOriginals = new Map();
  const interfaceAttributeOriginals = new Map();

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

  const request = (url, init = {}) => {
    // Do not spread `init` after headers: doing so used to replace the CSRF
    // header for the POST locale preference request, which made the manual
    // language switch fail with a 419 response on normal Laravel sessions.
    const {headers = {}, ...options} = init;
    return fetch(url, {
      credentials: 'same-origin',
      ...options,
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrf,
        ...headers,
      },
    }).then(async response => {
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(body.message || 'Request failed');
    return body;
    });
  };

  const sourceLocale = () => config?.source_locale || 'en';
  const isPublished = locale => locale === sourceLocale()
    || (config?.published_locales || []).includes(locale);
  const shownLocale = locale => isPublished(locale) ? locale : sourceLocale();
  const autoExplanation = () => {
    if (!config || config.locale_source === 'explicit') return '';
    if (config.locale === sourceLocale()) return 'Showing the store’s original language.';
    if (!isPublished(config.locale)) {
      const country = config.country ? ` for ${config.country}` : '';
      return `Language suggested${country}, but no published translation is available yet. Showing the original language.`;
    }
    const country = config.country ? ` for ${config.country}` : '';
    return `Language selected automatically${country}.`;
  };

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

  const commerceUiEnabled = () => root.dataset.commerceUi === '1';
  const normalizeInterfaceText = text => String(text || '').replace(/\s+/g, ' ').trim();
  const safeInterfaceElement = element => {
    if (!element || !commerceUiEnabled()) return false;
    if (element.closest('#longos-global-translate, [data-lgt-root], script, style, noscript, template, svg, canvas, [contenteditable], [data-lgt-private], [data-lgt-transaction]')) return false;
    // Amounts, order/customer metadata, address values and payment-provider
    // controls must remain byte-for-byte untouched. Only known static UI
    // labels are eligible below.
    if (element.closest('.price, .money, .amount, .order-number, .order-id, .customer-data, .address-value, [data-price], [data-order], [data-customer], [data-payment-provider]')) return false;
    return true;
  };

  const restoreCommerceInterface = () => {
    applyingInterface = true;
    interfaceTextOriginals.forEach((source, node) => {
      if (node.isConnected) node.nodeValue = source;
    });
    interfaceAttributeOriginals.forEach((attributes, element) => {
      if (!element.isConnected) return;
      attributes.forEach((source, attribute) => element.setAttribute(attribute, source));
    });
    applyingInterface = false;
  };

  const applyCommerceInterfaceToDom = () => {
    if (!commerceUiEnabled() || interfaceTranslations.size === 0) return 0;
    let applied = 0;
    applyingInterface = true;
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
      acceptNode(node) {
        const source = normalizeInterfaceText(node.nodeValue);
        return source && safeInterfaceElement(node.parentElement) && interfaceTranslations.has(source)
          ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
      },
    });
    const nodes = [];
    while (walker.nextNode() && nodes.length < 500) nodes.push(walker.currentNode);
    nodes.forEach(node => {
      const source = interfaceTextOriginals.get(node) || node.nodeValue;
      const translated = interfaceTranslations.get(normalizeInterfaceText(source));
      if (!translated) return;
      interfaceTextOriginals.set(node, source);
      node.nodeValue = translated;
      applied++;
    });
    [...document.querySelectorAll('input[placeholder], textarea[placeholder], button[aria-label], [title]')].slice(0, 300).forEach(element => {
      if (!safeInterfaceElement(element)) return;
      ['placeholder', 'aria-label', 'title'].forEach(attribute => {
        if (!element.hasAttribute(attribute)) return;
        const saved = interfaceAttributeOriginals.get(element)?.get(attribute);
        const source = saved || element.getAttribute(attribute) || '';
        const translated = interfaceTranslations.get(normalizeInterfaceText(source));
        if (!translated) return;
        if (!interfaceAttributeOriginals.has(element)) interfaceAttributeOriginals.set(element, new Map());
        interfaceAttributeOriginals.get(element).set(attribute, source);
        element.setAttribute(attribute, translated);
        applied++;
      });
    });
    applyingInterface = false;
    return applied;
  };

  const observeCommerceInterface = () => {
    if (!commerceUiEnabled() || interfaceObserver) return;
    interfaceObserver = new MutationObserver(() => {
      if (applyingInterface || interfaceApplyQueued || interfaceTranslations.size === 0) return;
      interfaceApplyQueued = true;
      requestAnimationFrame(() => {
        interfaceApplyQueued = false;
        applyCommerceInterfaceToDom();
      });
    });
    interfaceObserver.observe(document.body, {childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['placeholder', 'aria-label', 'title']});
  };

  const replaceCommerceInterface = async locale => {
    const epoch = ++interfaceEpoch;
    restoreCommerceInterface();
    interfaceTranslations = new Map();
    if (!commerceUiEnabled() || locale === sourceLocale() || !config?.interface_dictionary_url) return {applied: 0, available: locale === sourceLocale()};
    const dictionary = await request(`${config.interface_dictionary_url}?locale=${encodeURIComponent(locale)}`);
    if (epoch !== interfaceEpoch) return {applied: 0, available: false};
    Object.values(dictionary?.items || {}).forEach(item => {
      if (item && typeof item.source === 'string' && typeof item.text === 'string' && item.text.trim() !== '') {
        interfaceTranslations.set(normalizeInterfaceText(item.source), item.text);
      }
    });
    const applied = applyCommerceInterfaceToDom();
    observeCommerceInterface();
    return {applied, available: applied > 0};
  };

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
    const epoch = ++presentationEpoch;
    const sourceRegions = regions();
    // Always revert every field before a new locale is applied. This avoids a
    // mixed language page when the new publication is only partially covered.
    restoreSource(sourceRegions);
    if (locale === config.source_locale || sourceRegions.length === 0) {
      if (epoch === presentationEpoch) document.documentElement.lang = config.source_locale;
      return {applied: 0, available: locale === config.source_locale};
    }

    const dictionaries = await fetchDictionaries(sourceRegions, locale);
    // An earlier initial load can finish after a visitor selects another
    // language.  Never let that old response overwrite the newest choice.
    if (epoch !== presentationEpoch) return {applied: 0, available: false};
    let applied = 0;
    for (const region of sourceRegions) {
      if (epoch !== presentationEpoch) return {applied: 0, available: false};
      const dictionary = dictionaries.get(region);
      const kind = region.dataset.lgtKind;
      const entity = Number(region.dataset.lgtEntity);
      for (const node of region.querySelectorAll('[data-lgt-field]')) {
        const key = await contentKey(kind, entity, node.dataset.lgtField);
        if (key && typeof dictionary?.items?.[key] === 'string') {
          node.textContent = dictionary.items[key];
          applied++;
        }
      }
    }
    // Reflect the language actually presented to assistive technologies only
    // after a current response has supplied translated text.  If a
    // publication is unavailable, the page remains truthfully English.
    if (epoch === presentationEpoch) document.documentElement.lang = applied > 0 ? locale : config.source_locale;

    return {applied, available: applied > 0};
  };

  const applyPresentation = async locale => {
    const [content, commerce] = await Promise.all([
      replaceKnownNodes(locale),
      replaceCommerceInterface(locale),
    ]);
    return {applied: content.applied + commerce.applied, available: content.available || commerce.available};
  };

  const optionButton = locale => {
    const button = document.createElement('button');
    const name = document.createElement('span');
    const code = document.createElement('small');
    const selected = config.locale_source === 'explicit' ? config.locale : 'auto';
    const available = locale === 'auto' || isPublished(locale);
    button.type = 'button';
    button.className = 'lgt-widget__option';
    button.dataset.locale = locale;
    button.setAttribute('aria-pressed', String(locale === selected));
    name.textContent = locale === 'auto' ? 'Automatic' : label(locale);
    code.textContent = locale === 'auto' ? 'AUTO' : available ? locale.toUpperCase() : 'Not published';
    button.disabled = !available;
    if (!available) button.title = 'Publish the reviewed translation before visitors can switch to this language.';
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
          const result = await applyPresentation(saved.locale);
          status.textContent = result?.available
            ? 'Language updated.'
            : 'Language saved. No published translation exists for the visible content yet.';
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
    current.textContent = shownLocale(config.locale || config.source_locale).toUpperCase();
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
      const result = await applyPresentation(shownLocale(config.locale));
      status.textContent = autoExplanation()
        || (result?.available ? '' : 'No published translation exists for the visible content yet.');
    } catch (_) {
      status.textContent = 'Showing original English while translations are unavailable.';
    }
  }).catch(() => root.remove());
})();
