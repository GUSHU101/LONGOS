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
  const hint = root.querySelector('.lgt-widget__hint');
  const searchWrap = root.querySelector('.lgt-widget__search-wrap');
  const searchInput = root.querySelector('.lgt-widget__search');
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
  let switching = false;
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
  // Rich merchant bodies are not inserted as HTML. We keep the original text
  // node and replace it only with a reviewed dictionary string for the same
  // server-verified node path.
  const richTextOriginals = new Map();
  // Some compatible themes do not carry a field marker for every published
  // merchant field. A second exact-source pass covers those known roots
  // without ever querying a provider from the browser or touching a
  // form/payment/customer value.
  const publishedTextOriginals = new Map();
  let publicContentObserver = null;
  let publicRefreshQueued = false;

  const label = locale => {
    try {
      return typeof Intl.DisplayNames === 'function'
        ? new Intl.DisplayNames([locale], {type: 'language'}).of(locale) || locale
        : locale;
    } catch (_) {
      return locale;
    }
  };

  const setOpen = (open, restoreFocus = false) => {
    root.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    panel.setAttribute('aria-hidden', String(!open));
    if (open) (searchWrap?.hidden ? options.querySelector('button') : searchInput)?.focus();
    else if (restoreFocus) toggle.focus();
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
  const widgetRelease = '1.17.0';
  // Render every public root on a normal catalogue/listing page. Requests
  // remain bounded and are split into server-validated batches below, so a
  // long collection cannot silently stop translating after the first screen.
  const MAX_PUBLIC_REGIONS = 240;
  const DICTIONARY_BATCH_SIZE = 72;
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
    if (config.locale_source === 'accept_language') return 'Language selected from browser preferences.';
    if (config.locale_source === 'geo_browser') return 'Language selected from region and browser preferences.';
    return config.country ? `Language selected for ${config.country}.` : 'Language selected automatically.';
  };

  // Translation publications are immutable.  The server supplies their
  // locale-local revision in the HTML it already rendered for this visitor,
  // so a session cache is safe to use and naturally invalidates on publish or
  // rollback.  It is scoped to the exact storefront host and only contains
  // public, pre-approved dictionary text — never visitor inputs or orders.
  const publicRevision = locale => {
    const resolvedLocale = shownLocale(locale || config?.locale || sourceLocale());
    return Number(config?.published_revisions?.[resolvedLocale] || 0);
  };
  const cacheScope = locale => {
    const resolvedLocale = shownLocale(locale || config?.locale || sourceLocale());
    const revision = publicRevision(resolvedLocale);
    return Number.isInteger(revision) && revision > 0
      ? `lgt:${widgetRelease}:${window.location.host}:${resolvedLocale}:${revision}`
      : null;
  };
  const cacheKey = (scope, identity, locale) => {
    const base = cacheScope(locale);
    return base ? `${base}:${scope}:${identity}` : null;
  };
  const readCache = key => {
    if (!key) return null;
    try {
      const value = window.sessionStorage.getItem(key);
      return value ? JSON.parse(value) : null;
    } catch (_) {
      return null;
    }
  };
  const writeCache = (key, value) => {
    if (!key) return;
    try {
      const encoded = JSON.stringify(value);
      // Keep an unexpected huge rich-text response from exhausting browser
      // session storage. It can still be rendered from the bounded endpoint.
      if (encoded.length <= 96 * 1024) window.sessionStorage.setItem(key, encoded);
    } catch (_) { /* Storage can be disabled; networking remains a fallback. */ }
  };
  const isCurrentDictionary = (dictionary, locale) => {
    const revision = publicRevision(locale);
    return Number.isInteger(revision) && revision > 0
      && Number(dictionary?.revision) === revision
      && dictionary?.items && typeof dictionary.items === 'object';
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

  // A product grid, navigation/footer and a page-builder landing page can
  // legitimately contain more than 48 public roots. Keep the batch bounded
  // while covering a full desktop storefront rather than silently leaving
  // later cards in the source language.
  const regions = () => [...document.querySelectorAll('[data-lgt-root]')].slice(0, MAX_PUBLIC_REGIONS)
    .filter(region => {
      const kind = region.dataset.lgtKind;
      // Builder IDs are unsigned 60-bit integers, larger than JS's safe
      // Number range. Never coerce them through Number() or the URL/hash changes.
      const entity = region.dataset.lgtEntity || '';
      return ['product', 'category', 'page', 'page_category', 'builder', 'chrome'].includes(kind)
        && /^\d{1,20}$/.test(entity) && !/^0+$/.test(entity);
    });

  const commerceUiEnabled = () => root.dataset.commerceUi === '1';
  const normalizeInterfaceText = text => String(text || '').replace(/\s+/g, ' ').trim();
  const safeInterfaceElement = (element, attribute = '') => {
    if (!element || !commerceUiEnabled()) return false;
    // A public-content root can contain both a merchant field (for example a
    // product title) and fixed UI such as “Add to cart”. Protect the marked
    // dynamic field itself, not the whole region, so the adjacent reviewed
    // static labels still receive their published translations.
    if (element.closest('#longos-global-translate, [data-lgt-field], script, style, noscript, template, svg, canvas, [contenteditable], [data-lgt-private], [data-lgt-transaction]')) return false;
    // Native form values are always private. A text input or textarea is
    // eligible only for its authored placeholder, never for a value, option,
    // selected country, autocomplete result or merchant-provided default.
    if (element.matches('select, option, output, [role="textbox"]')) return false;
    if (element.matches('input, textarea') && attribute !== 'placeholder') return false;
    // Amounts, order/customer metadata, address values and payment-provider
    // controls must remain byte-for-byte untouched. Only known static UI
    // labels are eligible below.
    if (element.closest('.price, .money, .amount, .order-number, .order-id, .customer-data, .address-value, .product-name, .product-option, .payment-method, .payment-description, .payment-brand-list, .delivery-name, .delivery-description, [data-price], [data-order], [data-customer], [data-payment-provider], [data-address-value]')) return false;
    if (element.closest('a[href*="/products/"], a[href*="/orders/"], [data-lgt-dynamic], iframe')) return false;
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
    // The dictionary itself is finite and public. Use a generous hard cap so
    // a full desktop navigation/footer, checkout and account page is covered
    // without allowing an unbounded mutation workload on hostile markup.
    while (walker.nextNode() && nodes.length < 1200) nodes.push(walker.currentNode);
    nodes.forEach(node => {
      const source = interfaceTextOriginals.get(node) || node.nodeValue;
      const translated = interfaceTranslations.get(normalizeInterfaceText(source));
      if (!translated) return;
      interfaceTextOriginals.set(node, source);
      node.nodeValue = translated;
      applied++;
    });
    [...document.querySelectorAll('input[placeholder], textarea[placeholder], button[aria-label], [title]')].slice(0, 800).forEach(element => {
      ['placeholder', 'aria-label', 'title'].forEach(attribute => {
        if (!element.hasAttribute(attribute)) return;
        if (!safeInterfaceElement(element, attribute)) return;
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
    const key = cacheKey('interface', 'catalog', locale);
    let dictionary = readCache(key);
    // On a first visit this is the only dictionary request needed for every
    // checkout/account/payment label. On subsequent navigation it is a
    // synchronous session read, eliminating the visible language flash.
    if (!isCurrentDictionary(dictionary, locale)) {
      dictionary = await request(`${config.interface_dictionary_url}?locale=${encodeURIComponent(locale)}`);
      if (isCurrentDictionary(dictionary, locale)) writeCache(key, dictionary);
    }
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
    richTextOriginals.forEach((source, node) => {
      if (node.isConnected) node.nodeValue = source;
    });
    publishedTextOriginals.forEach((source, node) => {
      if (node.isConnected) node.nodeValue = source;
    });
    publishedTextOriginals.clear();
  };

  const richTextNodes = container => {
    const nodes = [];
    if (!container) return nodes;
    const walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT, {
      acceptNode(node) {
        const parent = node.parentElement;
        if (!parent || parent.closest('script, style, noscript, template, svg, canvas, [contenteditable], [data-lgt-private], [data-lgt-transaction]')) {
          return NodeFilter.FILTER_REJECT;
        }
        return normalizeText(node.nodeValue) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
      },
    });
    // Match the server dictionary ceiling. A long policy/product body must
    // not translate only its first 320 text nodes while the remaining
    // reviewed entries are already available locally.
    while (walker.nextNode() && nodes.length < 1200) nodes.push(walker.currentNode);
    return nodes;
  };

  const replaceRichTextNodes = (region, dictionary) => {
    let applied = 0;
    region.querySelectorAll('[data-lgt-richtext]').forEach(container => {
      const base = container.dataset.lgtRichtext;
      if (!base) return;
      richTextNodes(container).forEach((node, index) => {
        const field = `${base}.node.${index}`;
        const source = dictionary?.field_sources?.[field];
        const translated = dictionary?.fields?.[field];
        // The source check prevents an out-of-date publication from being
        // applied after a merchant changes HTML between scans.
        if (typeof source !== 'string' || typeof translated !== 'string' || !translated.trim()
          || normalizeText(node.nodeValue) !== normalizeText(source)) return;
        richTextOriginals.set(node, node.nodeValue || '');
        node.nodeValue = translated;
        applied++;
      });
    });
    return applied;
  };

  const safePublishedTextNode = (node, region) => {
    const parent = node?.parentElement;
    if (!parent || parent.closest('[data-lgt-field], [data-lgt-richtext], script, style, noscript, template, svg, canvas, iframe, form, input, textarea, select, option, output, [contenteditable], [data-lgt-private], [data-lgt-transaction], [data-payment-provider], [data-address-value], [data-customer], [data-order]')) return false;
    // A parent Builder root must never apply an exact text match inside a
    // nested product/category/page/chrome root. Each nested root has its own
    // checked server dictionary and visibility guard.
    const nearestRoot = parent.closest('[data-lgt-root]');
    if (nearestRoot && nearestRoot !== region) return false;
    // Action captions are deliberately translated only through the finite
    // interface catalog or an explicit field marker. Do not replace a
    // runtime/cart action by coincidence.
    if (parent.closest('button, [role="button"]')) return false;
    return true;
  };

  const replacePublishedTextNodes = (region, dictionary) => {
    const kind = region.dataset.lgtKind;
    if (!['product', 'category', 'page', 'page_category', 'builder', 'chrome'].includes(kind) || !dictionary?.field_sources || !dictionary?.fields) return 0;
    const matches = new Map();
    const ambiguous = new Set();
    Object.entries(dictionary.field_sources).forEach(([field, source]) => {
      const translated = dictionary.fields[field];
      const normalized = normalizeText(source);
      if ((kind === 'builder' && !field.startsWith('module.')) || typeof translated !== 'string' || !translated.trim() || normalized.length < 2 || normalized.length > 4000) return;
      if (matches.has(normalized) && matches.get(normalized) !== translated) ambiguous.add(normalized);
      else matches.set(normalized, translated);
    });
    ambiguous.forEach(source => matches.delete(source));
    if (!matches.size) return 0;

    const nodes = [];
    const walker = document.createTreeWalker(region, NodeFilter.SHOW_TEXT, {
      acceptNode(node) {
        return safePublishedTextNode(node, region) && matches.has(normalizeText(node.nodeValue))
          ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
      },
    });
    while (walker.nextNode() && nodes.length < 600) nodes.push(walker.currentNode);
    nodes.forEach(node => {
      const original = node.nodeValue || '';
      const translated = matches.get(normalizeText(original));
      if (!translated || original === translated) return;
      const leading = original.match(/^\s*/u)?.[0] || '';
      const trailing = original.match(/\s*$/u)?.[0] || '';
      publishedTextOriginals.set(node, original);
      node.nodeValue = `${leading}${translated}${trailing}`;
    });
    return nodes.length;
  };

  const fetchDictionaries = async (sourceRegions, locale) => {
    const results = new Map();
    if (!sourceRegions.length) return results;
    const keys = [...new Set(sourceRegions.map(region => `${region.dataset.lgtKind}:${region.dataset.lgtEntity}`))].slice(0, MAX_PUBLIC_REGIONS);
    if (config?.batch_dictionary_url) {
      const dictionaries = {};
      const missing = [];
      keys.forEach(key => {
        const cached = readCache(cacheKey('entity', key, locale));
        if (isCurrentDictionary(cached, locale)) dictionaries[key] = cached;
        else missing.push(key);
      });
      const batches = [];
      for (let offset = 0; offset < missing.length; offset += DICTIONARY_BATCH_SIZE) {
        batches.push(missing.slice(offset, offset + DICTIONARY_BATCH_SIZE));
      }
      // Keep only two in flight. This covers long product grids quickly while
      // remaining well below the public route's rate limit. Cached entities
      // never enter the network batches at all.
      const responses = [];
      for (let offset = 0; offset < batches.length; offset += 2) {
        const pair = await Promise.all(batches.slice(offset, offset + 2).map(batch => {
          const params = new URLSearchParams({locale, entities: batch.join(',')});
          return request(`${config.batch_dictionary_url}?${params}`).catch(() => null);
        }));
        responses.push(...pair);
      }
      Object.assign(dictionaries, ...responses
        .filter(batch => batch && typeof batch.dictionaries === 'object')
        .map(batch => batch.dictionaries));
      Object.entries(dictionaries).forEach(([key, dictionary]) => {
        if (isCurrentDictionary(dictionary, locale)) writeCache(cacheKey('entity', key, locale), dictionary);
      });
      if (Object.keys(dictionaries).length > 0) {
        sourceRegions.forEach(region => {
          results.set(region, dictionaries[`${region.dataset.lgtKind}:${region.dataset.lgtEntity}`] || null);
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
    if (sensitivePath()) return false;
    if (parent.closest('#longos-global-translate, [data-lgt-root], form, [contenteditable], script, style, noscript, template, svg, canvas, iframe, [data-lgt-private], [data-lgt-transaction], .price, .money, .amount, .review, .reviews, [data-review], [data-order], [data-payment-provider], button')) return false;
    return true;
  };

  const fallbackEntities = () => {
    const ids = new Map();
    const add = (kind, entity) => {
      const id = String(entity || '');
      if (!/^\d{1,20}$/.test(id) || /^0+$/.test(id) || ids.size >= MAX_PUBLIC_REGIONS) return;
      ids.set(`${kind}:${id}`, {kind, entity: id});
    };
    const path = pathname => pathname.match(/\/(products|categories|pages|page_categories)\/(\d+)(?:\/|$)/i);
    const register = pathname => {
      const match = path(pathname);
      if (match) add(({products:'product', categories:'category', pages:'page', page_categories:'page_category'})[match[1].toLowerCase()], match[2]);
    };
    register(window.location.pathname);
    // Category/search listings often contain links to many public products.
    // All queried IDs remain subject to a server-side visibility check.
    for (const anchor of [...document.querySelectorAll('a[href]')].slice(0, 600)) {
      try {
        const url = new URL(anchor.getAttribute('href'), window.location.href);
        if (url.origin === window.location.origin) register(url.pathname);
      } catch (_) { /* invalid href; ignore */ }
      if (ids.size >= 120) break;
    }
    (config?.builder_entities || []).slice(0, MAX_PUBLIC_REGIONS).forEach(id => add('builder', id));
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
    if (sensitivePath() || !entities.length || !config.batch_dictionary_url) return 0;
    const dictionaries = {};
    const missing = [];
    entities.forEach(entity => {
      const key = `${entity.kind}:${entity.entity}`;
      const cached = readCache(cacheKey('entity', key, locale));
      if (isCurrentDictionary(cached, locale)) dictionaries[key] = cached;
      else missing.push(entity);
    });
    const batches = [];
    for (let offset = 0; offset < missing.length; offset += DICTIONARY_BATCH_SIZE) {
      batches.push(missing.slice(offset, offset + DICTIONARY_BATCH_SIZE));
    }
    const responses = await Promise.all(batches.map(batch => {
      const params = new URLSearchParams({locale, entities: batch.map(e => `${e.kind}:${e.entity}`).join(',')});
      return request(`${config.batch_dictionary_url}?${params}`).catch(() => null);
    }));
    // Never inject an old response after a visitor switched languages.
    if (epoch !== presentationEpoch) return 0;
    Object.assign(dictionaries, ...responses
      .filter(response => response && typeof response.dictionaries === 'object')
      .map(response => response.dictionaries));
    Object.entries(dictionaries).forEach(([key, dictionary]) => {
      if (isCurrentDictionary(dictionary, locale)) writeCache(cacheKey('entity', key, locale), dictionary);
    });
    const matches = new Map(), ambiguous = new Set();
    Object.values(dictionaries).forEach(dictionary => {
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

  const scheduleFallback = (locale, epoch) => {
    const run = () => {
      // This compatibility scan is deliberately outside the critical paint
      // path. It covers residual unmarked nodes in older themes, while the
      // exact marked dictionaries above render immediately. Running it when
      // the browser is idle prevents a long catalogue DOM from competing with
      // first input, scrolling or the checkout button.
      void loadFallback(locale, epoch).then(fallbackApplied => {
        if (fallbackApplied > 0 && epoch === presentationEpoch) {
          document.documentElement.lang = locale;
          debug('background-fallback-applied', {locale, applied:fallbackApplied});
        }
      }).catch(error => debug('background-fallback-error', {message:String(error?.message || error)}));
    };
    if (typeof window.requestIdleCallback === 'function') {
      window.requestIdleCallback(run, {timeout: 1200});
      return;
    }
    window.setTimeout(run, 0);
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

    // Marked/known storefront regions are the critical paint path. The
    // compatibility fallback is intentionally scheduled after those exact
    // dictionaries are available; running both paths in parallel previously
    // issued duplicate batch requests for the same products and delayed the
    // first visible translated paint.
    const dictionaries = await fetchDictionaries(sourceRegions, locale);
    // An earlier initial load can finish after a visitor selects another
    // language.  Never let that old response overwrite the newest choice.
    if (epoch !== presentationEpoch) return {applied: 0, available: false};
    let applied = 0;
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
      applied += replaceRichTextNodes(region, dictionary);
      applied += replacePublishedTextNodes(region, dictionary);
    }

    // Older compatible themes can still have a few unmarked public nodes.
    // Fill them in after the primary paint using the already cached immutable
    // dictionaries. This is non-blocking, safe text-only replacement, never
    // reads values from a form or transaction screen, and needs no refresh.
    scheduleFallback(locale, epoch);

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
    return {applied, available: isPublished(locale)};
  };

  const observeNewPublicModules = () => {
    if (publicContentObserver || !document.body) return;
    publicContentObserver = new MutationObserver(records => {
      if (!config || shownLocale(config.locale) === sourceLocale() || publicRefreshQueued) return;
      // Only newly mounted, marked public modules. Rewriting a translated
      // text node also emits a mutation; ignoring text nodes avoids a loop.
      const newModule = records.some(record => [...record.addedNodes].some(node =>
        node.nodeType === Node.ELEMENT_NODE && (
          node.matches?.('[data-lgt-root], [data-lgt-field], [data-lgt-richtext]')
          || node.querySelector?.('[data-lgt-root], [data-lgt-field], [data-lgt-richtext]')
        ),
      ));
      if (!newModule) return;
      publicRefreshQueued = true;
      requestAnimationFrame(() => {
        publicRefreshQueued = false;
        if (config && shownLocale(config.locale) !== sourceLocale()) {
          void applyPresentation(shownLocale(config.locale)).catch(error =>
            debug('new-module-reapply-failed', {message: String(error?.message || error)}));
        }
      });
    });
    publicContentObserver.observe(document.body, {childList: true, subtree: true});
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
    name.textContent = locale === 'auto' ? 'Automatic (region)' : label(locale);
    code.textContent = locale === 'auto' ? 'AUTO' : locale.toUpperCase();
    button.disabled = !available;
    if (!available) button.title = 'Publish the reviewed translation before visitors can switch to this language.';
    button.append(name, code);
    button.addEventListener('click', async () => {
      if (switching) return;
      switching = true;
      status.textContent = '';
      options.querySelectorAll('button').forEach(option => { option.disabled = true; });
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
          current.textContent = shownLocale(saved.locale).toUpperCase();
          status.textContent = result?.applied > 0
            ? 'Language updated.'
            : 'Preference saved. Some content on this page is not translated yet.';
        } catch (_) {
          // The preference was saved, but the source language is the only
          // safe display state while a publication cannot be read.
          status.textContent = 'Language saved. Showing original English while translations are unavailable.';
          current.textContent = sourceLocale().toUpperCase();
        }
      } catch (error) {
        status.textContent = error.message || 'Unable to save language preference.';
      } finally {
        switching = false;
        render();
      }
    });
    return button;
  };

  const render = () => {
    options.replaceChildren();
    const availableLocales = [...new Set([config.source_locale, ...(config.locales || [])])]
      .filter(locale => isPublished(locale));
    ['auto', ...availableLocales]
      .forEach(locale => options.append(optionButton(locale)));
    if (hint) {
      hint.textContent = config.locale_source === 'explicit' ? 'Manual preference saved.' : autoExplanation();
      hint.hidden = !hint.textContent;
    }
    if (searchWrap && searchInput) {
      searchWrap.hidden = availableLocales.length < 8;
      searchInput.value = '';
    }
    current.textContent = shownLocale(config.locale || config.source_locale).toUpperCase();
  };

  searchInput?.addEventListener('input', () => {
    const query = searchInput.value.trim().toLocaleLowerCase();
    options.querySelectorAll('button').forEach(button => {
      button.hidden = Boolean(query) && !(button.textContent || '').toLocaleLowerCase().includes(query);
    });
  });

  toggle.addEventListener('click', () => setOpen(!root.classList.contains('is-open')));
  close.addEventListener('click', () => setOpen(false, true));
  document.addEventListener('pointerdown', event => {
    if (root.classList.contains('is-open') && !root.contains(event.target)) setOpen(false);
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && root.classList.contains('is-open')) setOpen(false, true);
  });

  const start = async data => {
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
    root.hidden = !data.widget_visible;
    observeNewPublicModules();
    try {
      const result = await applyPresentation(shownLocale(config.locale));
      current.textContent = shownLocale(config.locale).toUpperCase();
      status.textContent = (result?.applied === 0 && shownLocale(config.locale) !== sourceLocale())
        ? 'Some content on this page is not translated yet.' : (autoExplanation() || '');
    } catch (error) {
      debug('initial-translation-error', {message:String(error?.message||error)});
      status.textContent = 'Showing original English while translations are unavailable.';
      document.documentElement.lang = sourceLocale();
      current.textContent = sourceLocale().toUpperCase();
    }
  };

  // Locale decisions must be calculated per visitor, even when a CDN caches
  // the HTML. Never reuse another visitor's inlined country/cookie decision.
  const configPromise = request(root.dataset.configUrl, {cache: 'no-store'});
  configPromise.then(start).catch(error => {debug('widget-config-error',{message:String(error?.message||error)});root.remove();});
})();
