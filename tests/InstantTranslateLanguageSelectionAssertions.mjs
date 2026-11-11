import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {Window} from 'happy-dom';

const widget = readFileSync(new URL('../plugins/LongosInstantTranslate/Static/public/translate-widget.js', import.meta.url), 'utf8');
const prefix = 'longos_language_selection_test:';

function engineStub() {
  const executeLifecycle = {start: [], translateNetworkBefore: [], renderFinish: [], finally: []};

  return {
    storage: {get() { return null; }, set() {}},
    selectLanguageTag: {},
    language: {setLocal() {}, translateLanguagesRange: []},
    element: {},
    service: {use() {}},
    ignore: {
      tag: [],
      class: {
        data: [],
        push(className) { this.data.push(className); },
      },
    },
    lifecycle: {execute: executeLifecycle},
    request: {send() {}},
    setDocuments() {},
    reset() {},
    to: 'english',
    execute() {
      executeLifecycle.start.forEach((callback) => callback());
      executeLifecycle.renderFinish.forEach((callback) => callback('test-run', this.to));
      executeLifecycle.finally.forEach((callback) => callback());
    },
  };
}

async function boot({countryLanguage = 'japanese', country = 'JP', legacyLanguage = null, sessionLanguage = null} = {}) {
  const window = new Window({url: 'https://store.example/'});
  const config = {
    version: '2.0.3',
    engine_version: 'test',
    source_language: 'english',
    languages: [
      {id: 'english', native: 'English', short: 'EN', bcp47: 'en', dir: 'ltr'},
      {id: 'japanese', native: '日本語', short: 'JA', bcp47: 'ja', dir: 'ltr'},
      {id: 'french', native: 'Français', short: 'FR', bcp47: 'fr', dir: 'ltr'},
    ],
    auto_country: true,
    browser_fallback: true,
    context_url: '/_longos/instant-translate/context',
    context_timeout: 600,
    library_enabled: false,
    root_selectors: ['main#main-content'],
    storage_prefix: prefix,
    label_mode: 'short',
  };
  window.document.write(`<!doctype html><html lang="en"><body>
    <main id="main-content"><p>Public storefront content</p></main>
    <div id="longos-instant-translate" hidden>
      <button class="lit-widget__toggle" aria-expanded="false"><span class="lit-widget__current">EN</span></button>
      <div class="lit-widget__panel" hidden>
        <button class="lit-widget__close"></button>
        <div class="lit-widget__search-wrap"><input class="lit-widget__search"></div>
        <div class="lit-widget__languages"></div>
      </div>
      <div class="lit-widget__status"></div>
    </div>
    <script id="longos-instant-translate-config" type="application/json">${JSON.stringify(config)}</script>
  </body></html>`);
  window.document.close();
  window.translate = engineStub();
  if (legacyLanguage !== null) window.localStorage.setItem(`${prefix}manual_language`, legacyLanguage);
  if (sessionLanguage !== null) window.sessionStorage.setItem(`${prefix}manual_language_session`, sessionLanguage);

  let contextRequests = 0;
  window.fetch = async () => {
    contextRequests += 1;
    return {
      ok: true,
      async json() {
        return {language: countryLanguage, source: 'country', country};
      },
    };
  };
  window.eval(widget);
  await new Promise((resolve) => setTimeout(resolve, 30));

  return {
    contextRequests,
    health: JSON.parse(window.localStorage.getItem(`${prefix}health`)),
    window,
  };
}

test('legacy permanent manual choice cannot suppress verified country detection', async () => {
  const result = await boot({legacyLanguage: 'french'});

  assert.equal(result.contextRequests, 1);
  assert.equal(result.health.language, 'japanese');
  assert.equal(result.health.selection_source, 'country');
  assert.equal(result.health.country, 'JP');
  assert.equal(result.window.sessionStorage.getItem(`${prefix}manual_language_session`), null);
  assert.equal(result.window.localStorage.getItem(`${prefix}manual_language`), 'french');
  result.window.close();
});

test('manual choice overrides detection only for the current tab session', async () => {
  const result = await boot({countryLanguage: 'french', country: 'FR', sessionLanguage: 'japanese'});

  assert.equal(result.contextRequests, 0);
  assert.equal(result.health.language, 'japanese');
  assert.equal(result.health.selection_source, 'manual_session');
  assert.equal(result.health.country, '');
  result.window.close();
});
