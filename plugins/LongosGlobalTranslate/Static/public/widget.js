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
  let xsrf = '';
  try {
    xsrf = document.cookie.split('; ').find(item => item.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=') || '';
  } catch (_) { /* Strict privacy contexts can block cookie reads entirely. */ }
  const contentKeyCache = new Map();
  let config = null;
  let presentationEpoch = 0;
  let applyEpoch = 0;
  const fallbackTranslations = new Map();
  const fallbackOriginals = new Map();
  let fallbackObserver = null;
  let fallbackQueued = false;
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
        ...(csrf ? {'X-CSRF-TOKEN': csrf} : (xsrf ? {'X-XSRF-TOKEN': decodeURIComponent(xsrf)} : {})),
        ...headers,
      },
    }).then(async response => {
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(body.message || 'Request failed');
    return body;
    });
  };

  const debugEnabled = new URLSearchParams(window.location.search).get('lgt_debug') === '1';
  const widgetRelease = '1.5.0';
  const debug = (event, details = {}) => { if (debugEnabled) console.info('[LONGOS Translate]', event, details); };
  const sensitivePath = () => /(?:^|\/)(?:cart|carts|checkout|checkouts|account|accounts|orders|payment|pay|login|register|password)(?:\/|$)/i.test(window.location.pathname);
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
      // Builder IDs are unsigned 60-bit integers, larger than JS's safe
      // Number range. Never coerce them through Number() or the URL/hash changes.
      const entity = region.dataset.lgtEntity || '';
      return ['product', 'category', 'page', 'builder'].includes(kind)
        && /^\d{1,20}$/.test(entity) && !/^0+$/.test(entity);
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
    if (!sourceRegions.length) return results;
    const keys = [...new Set(sourceRegions.map(region => `${region.dataset.lgtKind}:${region.dataset.lgtEntity}`))].slice(0, 30);
    if (config?.batch_dictionary_url) {
      const params = new URLSearchParams({locale, entities:keys.join(',')});
      const batch = await request(`${config.batch_dictionary_url}?${params}`).catch(() => null);
      if (batch?.dictionaries) {
        sourceRegions.forEach(region => {
          results.set(region, batch.dictionaries[`${region.dataset.lgtKind}:${region.dataset.lgtEntity}`] || null);
        });
        return results;
      }
    }
    // Graceful compatibility path if a deployment is temporarily upgrading.
    let cursor = 0;
    const worker = async () => {
      while (cursor < sourceRegions.length) {
        const region = sourceRegions[cursor++];
        const url = config.dictionary_url.replace('__kind__', encodeURIComponent(region.dataset.lgtKind))
          .replace('__entity__', region.dataset.lgtEntity) + `?locale=${encodeURIComponent(locale)}`;
        results.set(region, await request(url).catch(() => null));
      }
    };
    await Promise.all(Array.from({length:Math.min(4,sourceRegions.length)}, worker));
    return results;
  };

  // Theme-independent fallback: translate only an exact, public, published
  // source string tied to a product/category/page link or a published Builder
  // module. No arbitrary DOM text is sent to any external translation service.
  const normalizeText = value => String(value || '').replace(/\s+/gu, ' ').trim();
  const safeFallbackParent = parent => {
    if (!parent || !parent.matches('h1,h2,h3,h4,h5,h6,p,span,strong,a,small,div,li,label')) return false;
    if (commerceUiEnabled() || sensitivePath()) return false;
    if (parent.closest('#longos-global-translate, [data-lgt-root], form, [contenteditable], script, style, noscript, template, svg, canvas, iframe, [data-lgt-private], [data-lgt-transaction], .price, .money, .amount, .review, .reviews, [data-review], [data-order], [data-payment-provider], button')) return false;
    return true;
  };

  const fallbackEntities = () => {
    const ids = new Map();
    const add = (kind, entity) => {
      const id = String(entity || '');
      if (!/^\d{1,20}$/.test(id) || /^0+$/.test(id) || ids.size >= 48) return;
      ids.set(`${kind}:${id}`, {kind, entity: id});
    };
    const path = pathname => pathname.match(/\/(products|categories|pages)\/(\d+)(?:\/|$)/i);
    const register = pathname => {
      const match = path(pathname);
      if (match) add(({products:'product', categories:'category', pages:'page'})[match[1].toLowerCase()], match[2]);
    };
    register(window.location.pathname);
    // Category/search listings often contain links to many public products.
    // All queried IDs remain subject to a server-side visibility check.
    for (const anchor of [...document.querySelectorAll('a[href]')].slice(0, 220)) {
      try {
        const url = new URL(anchor.getAttribute('href'), window.location.href);
        if (url.origin === window.location.origin) register(url.pathname);
      } catch (_) { /* invalid href; ignore */ }
      if (ids.size >= 32) break;
    }
    (config?.builder_entities || []).slice(0, 16).forEach(id => add('builder', id));
    return [...ids.values()];
  };

  const restoreFallback = () => {
    fallbackTranslations.clear();
    fallbackOriginals.forEach((saved, node) => {
      // If the theme replaced text in the meantime, do not overwrite it.
      if (node.isConnected && node.nodeValue === saved.translated) node.nodeValue = saved.original;
    });
    fallbackOriginals.clear();
  };

  const applyFallbackToDom = () => {
    if (!fallbackTranslations.size || !document.body) return 0;
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
      acceptNode(node) {
        const source = normalizeText(node.nodeValue);
        return source && safeFallbackParent(node.parentElement) && fallbackTranslations.has(source)
          ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
      },
    });
    let applied = 0, inspected = 0;
    while (walker.nextNode() && inspected++ < 700 && applied < 160) {
      const node = walker.currentNode;
      const original = node.nodeValue || '';
      const translated = fallbackTranslations.get(normalizeText(original));
      if (!translated || original === translated) continue;
      fallbackOriginals.set(node, {original, translated});
      node.nodeValue = translated; // Never innerHTML; text-only replacement.
      applied++;
    }
    return applied;
  };

  const observeFallback = () => {
    if (fallbackObserver || !document.body) return;
    fallbackObserver = new MutationObserver(() => {
      if (!fallbackTranslations.size || fallbackQueued) return;
      fallbackQueued = true;
      requestAnimationFrame(() => {
        fallbackQueued = false;
        applyFallbackToDom();
      });
    });
    fallbackObserver.observe(document.body, {childList:true, subtree:true, characterData:true});
  };

  const loadFallback = async (locale, epoch) => {
    const entities = fallbackEntities();
    if (commerceUiEnabled() || sensitivePath() || !entities.length || !config.batch_dictionary_url) return 0;
    const params = new URLSearchParams({locale, entities: entities.map(e => `${e.kind}:${e.entity}`).join(',')});
    const response = await request(`${config.batch_dictionary_url}?${params}`).catch(() => null);
    // Never inject an old response after a visitor switched languages.
    if (epoch !== presentationEpoch) return 0;
    const matches = new Map(), ambiguous = new Set();
    Object.values(response?.dictionaries || {}).forEach(dictionary => {
      Object.entries(dictionary?.sources || {}).forEach(([key, source]) => {
        const text = dictionary?.items?.[key];
        const normalized = normalizeText(source);
        if (typeof text !== 'string' || !text.trim() || normalized.length < 3 || normalized.length > 180) return;
        if (matches.has(normalized) && matches.get(normalized) !== text) ambiguous.add(normalized);
        else matches.set(normalized, text);
      });
    });
    ambiguous.forEach(source => matches.delete(source));
    matches.forEach((value, key) => fallbackTranslations.set(key, value));
    const applied = applyFallbackToDom();
    observeFallback();
    return applied;
  };

  const replaceKnownNodes = async locale => {
    if (!config) return;
    const epoch = ++presentationEpoch;
    const sourceRegions = regions();
    // Always revert every field before a new locale is applied. This avoids a
    // mixed language page when the new publication is only partially covered.
    restoreSource(sourceRegions);
    restoreFallback();
    if (locale === config.source_locale) {
      return {applied: 0, available: true};
    }

    const [dictionaries, fallbackApplied] = await Promise.all([
      fetchDictionaries(sourceRegions, locale),
      loadFallback(locale, epoch),
    ]);
    // An earlier initial load can finish after a visitor selects another
    // language.  Never let that old response overwrite the newest choice.
    if (epoch !== presentationEpoch) return {applied: 0, available: false};
    let applied = fallbackApplied;
    for (const region of sourceRegions) {
      if (epoch !== presentationEpoch) return {applied: 0, available: false};
      const dictionary = dictionaries.get(region);
      const kind = region.dataset.lgtKind;
      const entity = region.dataset.lgtEntity;
      for (const node of region.querySelectorAll('[data-lgt-field]')) {
        // Prefer plain field lookup. It also works on HTTP development
        // environments where WebCrypto.subtle is intentionally unavailable.
        const field = node.dataset.lgtField;
        const direct = dictionary?.fields?.[field];
        const key = direct ? null : await contentKey(kind, entity, field);
        const translated = direct || (key ? dictionary?.items?.[key] : null);
        if (epoch !== presentationEpoch) return {applied: 0, available: false};
        if (typeof translated === 'string' && translated.trim() !== '') {
          node.textContent = translated;
          applied++;
        }
      }
    }

    return {applied, available: applied > 0};
  };

  const applyPresentation = async locale => {
    const epoch = ++applyEpoch;
    const [content, commerce] = await Promise.all([
      replaceKnownNodes(locale),
      replaceCommerceInterface(locale),
    ]);
    const applied = content.applied + commerce.applied;
    debug('presentation', {locale, applied, markedRegions: regions().length, publicFallbackSources: fallbackTranslations.size, commerceInterface:commerceUiEnabled()});
    // A pending old locale request must not change the current page language.
    if (epoch === applyEpoch) document.documentElement.lang = applied || locale === sourceLocale() ? locale : sourceLocale();
    return {applied, available: content.available || commerce.available};
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
          current.textContent = (result?.available ? shownLocale(saved.locale) : sourceLocale()).toUpperCase();
          status.textContent = result?.available
            ? 'Language updated.'
            : 'Language saved. No published translation exists for the visible content yet.';
        } catch (_) {
          // The preference was saved, but the source language is the only
          // safe display state while a publication cannot be read.
          status.textContent = 'Language saved. Showing original English while translations are unavailable.';
          current.textContent = sourceLocale().toUpperCase();
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
    debug('config-loaded', {release:widgetRelease, serverRelease:data.plugin_release || 'unknown', enabled: !!data.enabled, configuredLocales:(data.locales||[]).length, publishedLocales:(data.published_locales||[]).length});
    if (data.plugin_release && data.plugin_release !== widgetRelease) {
      // The immutable image and the published asset can come from different
      // releases; warn without suppressing otherwise valid published content.
      console.warn('[LONGOS Translate] Frontend/backend release mismatch', {frontend:widgetRelease, backend:data.plugin_release});
    }
    if (!data.enabled) {
      root.remove();
      return;
    }
    config = data;
    render();
    root.hidden = false;
    try {
      const result = await applyPresentation(shownLocale(config.locale));
      current.textContent = (result?.available ? shownLocale(config.locale) : sourceLocale()).toUpperCase();
      status.textContent = (!result?.available && shownLocale(config.locale) !== sourceLocale())
        ? 'This page has no matching published translations. Showing the original language.'
        : (autoExplanation() || '');
    } catch (error) {
      debug('initial-translation-error', {message:String(error?.message||error)});
      status.textContent = 'Showing original English while translations are unavailable.';
      document.documentElement.lang = sourceLocale();
      current.textContent = sourceLocale().toUpperCase();
    }
  }).catch(error => {debug('widget-config-error',{message:String(error?.message||error)});root.remove();});
})();
