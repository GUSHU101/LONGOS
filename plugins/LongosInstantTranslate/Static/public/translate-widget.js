(function () {
  'use strict';

  var MOUNT_ID = 'longos-instant-translate';
  var CONFIG_ID = 'longos-instant-translate-config';
  var SOURCE_LANGUAGE = 'english';
  var EDGE_HOST = 'edge.microsoft.com';
  var EDGE_PATH = '/translate/translatetext';
  var DENY_SELECTOR = [
    '.lit-notranslate', '.notranslate', '[translate="no"]', '[data-no-translate]',
    'form', 'input', 'textarea', 'select', 'option', 'button', 'label', '[contenteditable]',
    'script', 'style', 'template', 'noscript', 'pre', 'code', 'kbd', 'samp', 'iframe',
    'svg', 'canvas', 'video', 'audio', 'object', 'embed', '[hidden]', '[aria-hidden="true"]',
    '.price', '[class*="price"]', '[data-price]', '[data-currency]', '.currency',
    '.sku', '[class*="sku"]', '[data-sku]', '.stock', '[class*="stock"]', '.inventory',
    '.quantity', '[class*="quantity"]', '.cart', '[class*="cart"]', '.checkout', '[class*="checkout"]',
    '.account', '[class*="account"]', '.order', '[class*="order"]', '.payment', '[class*="payment"]',
    '.address', '[class*="address"]', '.customer', '[class*="customer"]', '.auth', '[class*="auth"]',
    '.login', '.register', '.rma', '.wishlist', '.contact', '[data-token]', '[data-order]', '[data-customer]'
  ].join(',');
  var SENSITIVE_PATTERNS = [
    /\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i,
    /(?:\+?\d[\s().-]*){7,}/,
    /\b(?:\d[ -]*?){13,19}\b/,
    /\b(?:token|secret|password|authorization|bearer|order\s*(?:id|number|#))\b/i,
    /\b[a-f0-9]{32,}\b/i
  ];

  var mount = document.getElementById(MOUNT_ID);
  var configElement = document.getElementById(CONFIG_ID);
  if (!mount || !configElement || window.__longosInstantTranslateMounted) return;
  window.__longosInstantTranslateMounted = true;

  var config;
  try {
    config = JSON.parse(configElement.textContent || '{}');
  } catch (error) {
    mount.remove();
    return;
  }

  var languages = Array.isArray(config.languages) ? config.languages.filter(function (language) {
    return language && typeof language.id === 'string' && typeof language.native === 'string';
  }) : [];
  var languageMap = new Map(languages.map(function (language) { return [language.id, language]; }));
  if (!languageMap.has(SOURCE_LANGUAGE)) {
    languages.unshift({id: SOURCE_LANGUAGE, native: 'English', short: 'EN'});
    languageMap.set(SOURCE_LANGUAGE, languages[0]);
  }

  var prefix = typeof config.storage_prefix === 'string' ? config.storage_prefix : 'longos_instant_translate_v1:';
  var manualSessionKey = prefix + 'manual_language_session';
  var healthKey = prefix + 'health';
  var enginePrefix = prefix + 'xnx3:';
  var engine = null;
  var roots = [];
  var busy = false;
  var desiredLanguage = SOURCE_LANGUAGE;
  var actualLanguage = SOURCE_LANGUAGE;
  var runTarget = SOURCE_LANGUAGE;
  var pendingLanguage = null;
  var runHadFailure = false;
  var failureReason = '';
  var runLibraryHits = 0;
  var runNetworkRequests = 0;
  var runProtectedTexts = 0;
  var selectionSource = 'source';
  var selectionCountry = '';
  var networkPolicy = {allowed: false, to: '', texts: new Set()};
  var lastLibraryRenderAt = 0;
  var dynamicNodes = new Set();
  var dynamicTimer = 0;
  var originalDocumentLanguage = document.documentElement.getAttribute('lang') || 'en';
  var originalDocumentDirection = document.documentElement.getAttribute('dir');

  var toggle = mount.querySelector('.lit-widget__toggle');
  var panel = mount.querySelector('.lit-widget__panel');
  var closeButton = mount.querySelector('.lit-widget__close');
  var current = mount.querySelector('.lit-widget__current');
  var list = mount.querySelector('.lit-widget__languages');
  var searchWrap = mount.querySelector('.lit-widget__search-wrap');
  var search = mount.querySelector('.lit-widget__search');
  var status = mount.querySelector('.lit-widget__status');

  function safeStorageGet(key) {
    try { return window.localStorage.getItem(key); } catch (error) { return null; }
  }

  function safeStorageSet(key, value) {
    try { window.localStorage.setItem(key, String(value)); return true; } catch (error) { return false; }
  }

  function safeSessionGet(key) {
    try { return window.sessionStorage.getItem(key); } catch (error) { return null; }
  }

  function safeSessionSet(key, value) {
    try { window.sessionStorage.setItem(key, String(value)); return true; } catch (error) { return false; }
  }

  function writeHealth(state, error) {
    safeStorageSet(healthKey, JSON.stringify({
      status: state,
      at: new Date().toISOString(),
      engine: String(config.engine_version || ''),
      service: 'client.edge',
      language: actualLanguage,
      selection_source: selectionSource,
      country: selectionCountry,
      error: String(error || '').slice(0, 120)
    }));
  }

  function setStatus(message) {
    status.textContent = message || '';
  }

  function languageLabel(language) {
    return config.label_mode === 'name' ? language.native : language.short;
  }

  function updateDocumentLanguage(languageId) {
    var language = languageMap.get(languageId) || languageMap.get(SOURCE_LANGUAGE);
    if (languageId === SOURCE_LANGUAGE) {
      document.documentElement.setAttribute('lang', originalDocumentLanguage);
      if (originalDocumentDirection === null) document.documentElement.removeAttribute('dir');
      else document.documentElement.setAttribute('dir', originalDocumentDirection);
      return;
    }
    document.documentElement.setAttribute('lang', language.bcp47 || originalDocumentLanguage);
    document.documentElement.setAttribute('dir', language.dir === 'rtl' ? 'rtl' : 'ltr');
  }

  function updateSelection(languageId) {
    var language = languageMap.get(languageId) || languageMap.get(SOURCE_LANGUAGE);
    updateDocumentLanguage(languageId);
    current.textContent = languageLabel(language);
    toggle.setAttribute('aria-label', 'Choose language. Current language: ' + language.native);
    list.querySelectorAll('[data-language]').forEach(function (button) {
      button.setAttribute('aria-pressed', button.dataset.language === languageId ? 'true' : 'false');
    });
  }

  function renderLanguages() {
    list.textContent = '';
    languages.forEach(function (language) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'lit-widget__language';
      button.dataset.language = language.id;
      button.setAttribute('aria-pressed', language.id === SOURCE_LANGUAGE ? 'true' : 'false');
      var name = document.createElement('span');
      name.textContent = language.native;
      var code = document.createElement('small');
      code.textContent = language.short;
      button.append(name, code);
      button.addEventListener('click', function () {
        chooseLanguage(language.id, true);
        closePanel(true);
      });
      list.appendChild(button);
    });
    searchWrap.hidden = languages.length < 9;
  }

  function openPanel() {
    panel.hidden = false;
    toggle.setAttribute('aria-expanded', 'true');
    (searchWrap.hidden ? list.querySelector('button') : search).focus();
  }

  function closePanel(restoreFocus) {
    panel.hidden = true;
    toggle.setAttribute('aria-expanded', 'false');
    search.value = '';
    filterLanguages('');
    if (restoreFocus) toggle.focus();
  }

  function filterLanguages(value) {
    var query = String(value || '').trim().toLocaleLowerCase();
    list.querySelectorAll('[data-language]').forEach(function (button) {
      button.hidden = query !== '' && button.textContent.toLocaleLowerCase().indexOf(query) === -1;
    });
  }

  toggle.addEventListener('click', function () { panel.hidden ? openPanel() : closePanel(false); });
  closeButton.addEventListener('click', function () { closePanel(true); });
  search.addEventListener('input', function () { filterLanguages(search.value); });
  document.addEventListener('pointerdown', function (event) {
    if (!panel.hidden && !mount.contains(event.target)) closePanel(false);
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !panel.hidden) closePanel(true);
  });

  function looksSensitive(text) {
    text = String(text || '').trim();
    return text !== '' && SENSITIVE_PATTERNS.some(function (pattern) { return pattern.test(text); });
  }

  function markDenied(root) {
    if (!root || root.nodeType !== Node.ELEMENT_NODE) return;
    if (root.matches(DENY_SELECTOR)) root.classList.add('lit-notranslate');
    root.querySelectorAll(DENY_SELECTOR).forEach(function (element) { element.classList.add('lit-notranslate'); });
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    var node;
    while ((node = walker.nextNode())) {
      if (looksSensitive(node.nodeValue) && node.parentElement) node.parentElement.classList.add('lit-notranslate');
    }
  }

  function insideSafeRoot(node) {
    var element = node && node.nodeType === Node.ATTRIBUTE_NODE
      ? node.ownerElement
      : (node && node.nodeType === Node.ELEMENT_NODE ? node : node && node.parentElement);
    return !!element && roots.some(function (root) { return root === element || root.contains(element); });
  }

  function isSafeNode(node) {
    var element = node && node.nodeType === Node.ATTRIBUTE_NODE
      ? node.ownerElement
      : (node && node.nodeType === Node.ELEMENT_NODE ? node : node && node.parentElement);
    if (!element || !insideSafeRoot(element) || element.closest(DENY_SELECTOR)) return false;
    if (node.nodeType === Node.ATTRIBUTE_NODE && ['alt', 'title'].indexOf(String(node.name || '').toLowerCase()) === -1) return false;
    var text = node.nodeType === Node.TEXT_NODE || node.nodeType === Node.ATTRIBUTE_NODE
      ? node.nodeValue
      : element.textContent;
    return !looksSensitive(text || '');
  }

  function nodeSourceText(node) {
    if (!node) return '';
    if (node.nodeType === Node.TEXT_NODE || node.nodeType === Node.ATTRIBUTE_NODE) return String(node.nodeValue || '');
    return String(node.textContent || '');
  }

  function collectRoots() {
    var selectors = Array.isArray(config.root_selectors) ? config.root_selectors : [];
    roots = [];
    selectors.forEach(function (selector) {
      if (typeof selector !== 'string') return;
      document.querySelectorAll(selector).forEach(function (root) {
        if (!roots.includes(root)) roots.push(root);
      });
    });
    roots.forEach(markDenied);
    return roots;
  }

  function endpointAllowed(url) {
    try {
      var parsed = new URL(String(url), window.location.href);
      return parsed.protocol === 'https:' && parsed.hostname === EDGE_HOST && parsed.pathname === EDGE_PATH;
    } catch (error) {
      return false;
    }
  }

  function configureEngine() {
    if (!window.translate || typeof window.translate.execute !== 'function') return false;
    engine = window.translate;
    engine.storage.get = function (key) { return safeStorageGet(enginePrefix + String(key)); };
    engine.storage.set = function (key, value) { safeStorageSet(enginePrefix + String(key), value); };
    engine.selectLanguageTag.show = false;
    engine.language.setLocal(SOURCE_LANGUAGE);
    engine.language.translateLanguagesRange = [SOURCE_LANGUAGE];
    engine.element.tagAttribute = {};
    engine.service.use('client.edge');
    ['input', 'textarea', 'select', 'option', 'button', 'form', 'label', 'iframe', 'svg', 'canvas', 'video', 'audio', 'object', 'embed', 'meta'].forEach(function (tag) {
      if (engine.ignore.tag.indexOf(tag) === -1) engine.ignore.tag.push(tag);
    });
    ['lit-notranslate', 'notranslate', 'lit-widget'].forEach(function (className) {
      if (engine.ignore.class.data.indexOf(className) === -1) engine.ignore.class.push(className, function () { return true; });
    });
    engine.setDocuments(roots);

    engine.lifecycle.execute.start.push(function () { busy = true; });
    engine.lifecycle.execute.translateNetworkBefore.push(function (data) {
      var nodes = data && Array.isArray(data.nodes) ? data.nodes : [];
      var texts = data && Array.isArray(data.texts) ? data.texts : [];
      var safeTexts = new Set();
      nodes.forEach(function (node) {
        if (isSafeNode(node)) safeTexts.add(nodeSourceText(node));
      });
      var permittedTexts = new Set(texts.filter(function (text) {
        return safeTexts.has(String(text)) && !looksSensitive(text);
      }).map(String));
      var allowed = data && data.to === runTarget && data.to !== SOURCE_LANGUAGE && texts.length > 0;
      networkPolicy = {allowed: !!allowed, to: data ? data.to : '', texts: permittedTexts};
      if (!allowed) {
        runHadFailure = true;
        failureReason = 'privacy_guard';
      }
    });
    engine.lifecycle.execute.renderFinish.push(function (uuid, to) {
      if (to === runTarget && !runHadFailure) {
        actualLanguage = to;
        lastLibraryRenderAt = Date.now();
        updateSelection(to);
      }
    });
    engine.lifecycle.execute.finally.push(function () { window.setTimeout(finishRun, 0); });

    var originalSend = engine.request.send;
    function inertRequest(url) {
      return {
        status: 0, readyState: 4, responseText: '', response: '', requestURL: String(url || ''),
        abort: function () {}, addEventListener: function () {}, removeEventListener: function () {}
      };
    }
    function untranslatedEdgeResponse(text) {
      return {translations: [{text: String(text)}]};
    }
    engine.request.send = function () {
      var args = Array.prototype.slice.call(arguments);
      var url = args[0];
      var success = args[3];
      var payload = null;
      try {
        payload = JSON.parse(String(args[1] || ''));
      } catch (error) {}
      if (!Array.isArray(payload) || typeof success !== 'function') {
        runHadFailure = true;
        failureReason = 'engine_contract_error';
        return inertRequest(url);
      }
      if (!networkPolicy.allowed || networkPolicy.to !== runTarget || !endpointAllowed(url)) {
        runHadFailure = true;
        failureReason = endpointAllowed(url) ? (failureReason || 'privacy_guard') : 'endpoint_blocked';
        var blocked = inertRequest(url);
        window.setTimeout(function () {
          success(payload.map(untranslatedEdgeResponse), args[2], blocked);
        }, 0);
        return blocked;
      }

      var safeMask = payload.map(function (text) {
        return networkPolicy.texts.has(String(text)) && !looksSensitive(text);
      });
      var safePayload = payload.filter(function (text, index) { return safeMask[index]; });
      runProtectedTexts += payload.length - safePayload.length;
      if (!safePayload.length) {
        var protectedRequest = inertRequest(url);
        window.setTimeout(function () {
          success(payload.map(untranslatedEdgeResponse), args[2], protectedRequest);
        }, 0);
        return protectedRequest;
      }

      var completed = false;
      function finishWithOriginal(xhr, reason) {
        if (completed) return;
        completed = true;
        runHadFailure = true;
        failureReason = reason;
        success(payload.map(untranslatedEdgeResponse), args[2], xhr || inertRequest(url));
      }
      args[1] = JSON.stringify(safePayload);
      args[3] = function (response, data, xhr) {
        if (!Array.isArray(response) || response.length < safePayload.length) {
          finishWithOriginal(xhr, 'invalid_response');
          return;
        }
        completed = true;
        var responseIndex = 0;
        var expanded = payload.map(function (text, index) {
          return safeMask[index] ? response[responseIndex++] : untranslatedEdgeResponse(text);
        });
        success(expanded, data, xhr);
      };
      var xhr = originalSend.apply(engine.request, args);
      if (xhr && typeof xhr === 'object') {
        xhr.timeout = Math.max(3000, Math.min(15000, Number(config.request_timeout) || 8000));
        runNetworkRequests += 1;
        if (typeof xhr.addEventListener === 'function') {
          xhr.addEventListener('error', function () { finishWithOriginal(xhr, 'network_error'); }, {once: true});
          xhr.addEventListener('timeout', function () { finishWithOriginal(xhr, 'timeout'); }, {once: true});
          xhr.addEventListener('loadend', function () {
            if (!completed && (xhr.status < 200 || xhr.status >= 300)) {
              finishWithOriginal(xhr, xhr.status === 429 ? 'rate_limited' : 'network_error');
            }
          }, {once: true});
        }
      }
      return xhr;
    };
    return true;
  }

  function resetToEnglish(message, healthError) {
    try {
      if (engine) {
        engine.reset({selectLanguageRefreshRender: false, notTranslateTip: false});
        engine.setDocuments(roots);
        engine.to = SOURCE_LANGUAGE;
        engine.storage.set('to', SOURCE_LANGUAGE);
      }
    } catch (error) {}
    busy = false;
    actualLanguage = SOURCE_LANGUAGE;
    desiredLanguage = SOURCE_LANGUAGE;
    updateSelection(SOURCE_LANGUAGE);
    setStatus(message || 'Showing the original English page.');
    writeHealth('degraded', healthError || failureReason || 'translation_unavailable');
  }

  function finishRun() {
    if (!busy && runTarget === SOURCE_LANGUAGE) return;
    busy = false;
    if (runHadFailure && runTarget !== SOURCE_LANGUAGE && runLibraryHits > 0) {
      actualLanguage = runTarget;
      updateSelection(actualLanguage);
      setStatus('Using the local translation library; untranslated text remains in English.');
      writeHealth('partial_local', failureReason);
    } else if (runHadFailure && runTarget !== SOURCE_LANGUAGE) {
      resetToEnglish('Translation is temporarily unavailable. Showing English.', failureReason);
    } else if (runTarget !== SOURCE_LANGUAGE) {
      actualLanguage = runTarget;
      updateSelection(actualLanguage);
      var source = runLibraryHits > 0
        ? (runNetworkRequests > 0 ? 'Translated with the local library and browser fallback.' : 'Translated using the local translation library.')
        : 'Translated in your browser.';
      setStatus(source + (runProtectedTexts > 0 ? ' Protected content remains in English.' : ''));
      writeHealth('ok', '');
    }
    if (pendingLanguage) {
      var next = pendingLanguage;
      pendingLanguage = null;
      desiredLanguage = next;
      runLanguage(next);
    }
  }

  function pageTexts(targets) {
    var items = [], seen = new Set();
    targets.forEach(function (target) {
      var walker = document.createTreeWalker(target, NodeFilter.SHOW_TEXT);
      var node;
      while ((node = walker.nextNode()) && items.length < 360) {
        var text = String(node.nodeValue || '');
        var normalized = text.replace(/\s+/g, ' ').trim();
        if (!normalized || seen.has(normalized) || !isSafeNode(node)) continue;
        seen.add(normalized);
        items.push({key: String(items.length), text: text});
      }
    });
    return items;
  }

  async function preloadLibrary(languageId, targets) {
    if (!config.library_enabled || typeof config.library_url !== 'string' || !engine || !engine.util || typeof engine.util.hash !== 'function') return 0;
    var items = pageTexts(targets), pending = [], hits = 0;
    var csrf = document.querySelector('meta[name="csrf-token"]');
    if (!items.length || !csrf || !csrf.content) return 0;
    items.forEach(function (item) {
      var cacheKey = 'hash_' + languageId + '_' + engine.util.hash(item.text);
      if (engine.storage.get(cacheKey)) hits += 1;
      else pending.push(item);
    });
    if (!pending.length) return hits;

    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    var timeout = Math.max(600, Math.min(2500, Number(config.library_timeout) || 1200));
    var timer = window.setTimeout(function () { if (controller) controller.abort(); }, timeout);
    try {
      var response = await fetch(config.library_url, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf.content},
        body: JSON.stringify({language: languageId, items: pending}), signal: controller ? controller.signal : undefined
      });
      if (!response.ok) return hits;
      var data = await response.json(), byKey = new Map(pending.map(function (item) { return [item.key, item]; }));
      (Array.isArray(data.items) ? data.items : []).forEach(function (result) {
        var source = byKey.get(String(result.key));
        if (!source || typeof result.translation !== 'string' || !result.translation.trim()) return;
        engine.storage.set('hash_' + languageId + '_' + engine.util.hash(source.text), result.translation);
        hits += 1;
      });
    } catch (error) {
      writeHealth('degraded', 'library_lookup_unavailable');
    } finally {
      window.clearTimeout(timer);
    }
    return hits;
  }

  async function runLanguage(languageId, nodes) {
    if (!languageMap.has(languageId)) languageId = SOURCE_LANGUAGE;
    if (!engine && languageId !== SOURCE_LANGUAGE) {
      resetToEnglish('Translation is unavailable. Showing English.', 'engine_load_failed');
      return;
    }
    var targets = Array.isArray(nodes) && nodes.length ? nodes.filter(isSafeNode) : roots;
    if (!targets.length) {
      resetToEnglish('No public content is available to translate.', 'safe_root_missing');
      return;
    }
    roots.forEach(markDenied);
    runTarget = languageId;
    runHadFailure = false;
    failureReason = '';
    runLibraryHits = 0;
    runNetworkRequests = 0;
    runProtectedTexts = 0;
    networkPolicy = {allowed: false, to: languageId, texts: new Set()};
    if (languageId === SOURCE_LANGUAGE) {
      resetToEnglish('', '');
      writeHealth('ok', '');
      return;
    }
    try {
      if (!nodes) engine.reset({selectLanguageRefreshRender: false, notTranslateTip: false});
      engine.setDocuments(roots);
      engine.to = languageId;
      engine.storage.set('to', languageId);
      busy = true;
      setStatus(config.library_enabled ? 'Checking the local translation library…' : 'Translating…');
      runLibraryHits = await preloadLibrary(languageId, targets);
      setStatus(runLibraryHits > 0 ? 'Applying local translations…' : 'Translating…');
      engine.execute(targets);
    } catch (error) {
      failureReason = 'engine_error';
      resetToEnglish('Translation is temporarily unavailable. Showing English.', failureReason);
    }
  }

  function chooseLanguage(languageId, manual) {
    if (!languageMap.has(languageId)) languageId = SOURCE_LANGUAGE;
    if (manual) {
      safeSessionSet(manualSessionKey, languageId);
      selectionSource = 'manual_session';
    }
    desiredLanguage = languageId;
    updateSelection(languageId);
    if (busy) {
      pendingLanguage = languageId;
      setStatus('Finishing the current translation…');
      return;
    }
    runLanguage(languageId);
  }

  function bcp47Language(tag) {
    tag = String(tag || '').toLowerCase().replace(/_/g, '-');
    if (tag.indexOf('zh') === 0) return /(?:^|-)(hant|tw|hk|mo)(?:-|$)/.test(tag) ? 'chinese_traditional' : 'chinese_simplified';
    return ({en:'english',ja:'japanese',de:'deutsch',fr:'french',es:'spanish',it:'italian',ko:'korean',pt:'portuguese',pl:'polish',nl:'dutch',ar:'arabic',ru:'russian',vi:'vietnamese',id:'indonesian',ms:'malay',hi:'hindi'})[tag.split('-')[0]] || null;
  }

  function browserLanguage() {
    var tags = Array.isArray(navigator.languages) && navigator.languages.length ? navigator.languages : [navigator.language];
    for (var i = 0; i < tags.length; i += 1) {
      var language = bcp47Language(tags[i]);
      if (languageMap.has(language)) return language;
    }
    return SOURCE_LANGUAGE;
  }

  async function automaticLanguage() {
    var stored = safeSessionGet(manualSessionKey);
    if (languageMap.has(stored)) return {language: stored, source: 'manual_session', country: ''};
    if (config.auto_country && typeof config.context_url === 'string') {
      var controller = typeof AbortController === 'function' ? new AbortController() : null;
      var contextTimeout = Math.max(600, Math.min(3000, Number(config.context_timeout) || 1500));
      var timeout = window.setTimeout(function () { if (controller) controller.abort(); }, contextTimeout);
      try {
        var response = await fetch(config.context_url, {
          method: 'GET', credentials: 'same-origin', cache: 'no-store',
          headers: {'Accept': 'application/json'}, signal: controller ? controller.signal : undefined
        });
        if (response.ok) {
          var data = await response.json();
          if (languageMap.has(data.language)) {
            return {
              language: data.language,
              source: typeof data.source === 'string' ? data.source : 'country',
              country: typeof data.country === 'string' ? data.country : ''
            };
          }
        }
      } catch (error) {
        writeHealth('degraded', 'country_context_unavailable');
      } finally {
        window.clearTimeout(timeout);
      }
    }
    return {
      language: config.browser_fallback ? browserLanguage() : SOURCE_LANGUAGE,
      source: config.browser_fallback ? 'browser' : 'source',
      country: ''
    };
  }

  function scheduleDynamic(node) {
    if (busy || actualLanguage === SOURCE_LANGUAGE || !isSafeNode(node)) return;
    dynamicNodes.add(node.nodeType === Node.TEXT_NODE ? node.parentElement : node);
    window.clearTimeout(dynamicTimer);
    dynamicTimer = window.setTimeout(function () {
      if (busy || Date.now() - lastLibraryRenderAt < 600) return;
      var nodes = Array.from(dynamicNodes).filter(isSafeNode);
      dynamicNodes.clear();
      if (nodes.length) runLanguage(actualLanguage, nodes);
    }, 180);
  }

  function observeDynamicContent() {
    if (typeof MutationObserver !== 'function') return;
    var observer = new MutationObserver(function (mutations) {
      mutations.forEach(function (mutation) {
        if (mutation.type === 'childList') {
          mutation.addedNodes.forEach(function (node) {
            if (node.nodeType === Node.ELEMENT_NODE) markDenied(node);
            scheduleDynamic(node);
          });
        } else if (mutation.type === 'characterData') {
          scheduleDynamic(mutation.target);
        }
      });
    });
    roots.forEach(function (root) { observer.observe(root, {subtree: true, childList: true, characterData: true}); });
  }

  async function start() {
    renderLanguages();
    collectRoots();
    mount.hidden = false;
    if (!roots.length) {
      setStatus('Language switching is unavailable on this page.');
      writeHealth('degraded', 'safe_root_missing');
      return;
    }
    var engineReady = configureEngine();
    observeDynamicContent();
    var decision = await automaticLanguage();
    var language = decision.language;
    selectionSource = decision.source;
    selectionCountry = decision.country;
    if (!engineReady && language !== SOURCE_LANGUAGE) language = SOURCE_LANGUAGE;
    chooseLanguage(language, false);
    if (language === SOURCE_LANGUAGE) writeHealth(engineReady ? 'ok' : 'degraded', engineReady ? '' : 'engine_load_failed');
  }

  start();
})();
