const fs = require('node:fs');
const path = require('node:path');
const ts = require('typescript');
const assert = require('node:assert/strict');
const { test } = require('node:test');
require.extensions['.ts'] = (module, filename) => module._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText, filename);
const builder = path.join(__dirname, '../resources/beike/admin/js/builder');
const { BuilderWriteQueue } = require(path.join(builder, 'save-queue.ts'));
const { LongosBuilderApi, BuilderConflictError } = require(path.join(builder, 'api.ts'));
const { previewEventElement, previewFrameUrl } = require(path.join(builder, 'preview.ts'));
const { classifyPreviewChange } = require(path.join(builder, 'live-preview.ts'));
const { readLocalized, readMediaUrl, writeLocalized } = require(path.join(builder, 'localized-value.ts'));
const { mediaSelectionFromMessage, nextMediaSelection, normalizeMediaFile, normalizeMediaFiles, normalizeMediaUrl } = require(path.join(builder, 'media-library.ts'));
const { nativeComponentFields, nativeFieldVisibility, nativeSharedFields } = require(path.join(builder, 'native-component-fields.ts'));
const { builderRecoveryKey, clearBuilderRecovery, readBuilderRecovery, writeBuilderRecovery } = require(path.join(builder, 'recovery.ts'));
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));

test('native functional modules expose their required controls and preserve translations', () => {
  const renderers = { ColorField() {}, MediaField() {}, StructuredLinkField() {}, CommerceField() {}, PaymentMethodsField() {}, JsonObjectField() {}, DateTimeField() {} };
  const required = {
    img_text_slideshow: ['images', 'autoplay', 'scroll_text'], img_text_slideshow_2: ['images', 'autoplay', 'scroll_text'],
    tab_product: ['columns_desktop', 'columns_tablet', 'columns_mobile', 'tabs'], product: ['columns_desktop', 'columns_tablet', 'columns_mobile', 'products'],
    brand: ['columns_desktop', 'columns_tablet', 'columns_mobile', 'show_view_all', 'brands'], icons: ['columns_desktop', 'columns_tablet', 'columns_mobile', 'style_variant', 'images'], page: ['columns_desktop', 'columns_tablet', 'columns_mobile', 'show_view_all', 'items'],
    latest_products: ['limit', 'layout', 'columns_desktop', 'columns_tablet', 'columns_mobile'], bestseller: ['limit', 'layout', 'columns_desktop', 'columns_tablet', 'columns_mobile'],
    promo_bar: ['link'], trust_cards: ['columns', 'columns_tablet', 'columns_mobile', 'style_variant', 'items'], testimonials: ['columns', 'columns_tablet', 'columns_mobile', 'style_variant', 'items'], campaign_offer: ['offers', 'timer_mode', 'ends_at'],
    payment_trust: ['benefits', 'payments'], story_block: ['image', 'link'], feature_grid: ['items', 'columns', 'columns_tablet', 'columns_mobile', 'image_ratio', 'card_style'], faq: ['items', 'style_variant', 'open_first'], rich_text: ['text'], social_proof: ['items'], custom_code: ['html', 'css', 'settings'],
  };
  for (const [code, fields] of Object.entries(required)) {
    const moduleFields = { ...nativeComponentFields(code, renderers), ...nativeSharedFields(code, renderers.ColorField) };
    for (const field of fields) assert.ok(moduleFields[field], `${code} is missing ${field}`);
    assert.ok(moduleFields.module_size, `${code} is missing layout width`);
    assert.ok(moduleFields.style, `${code} is missing background styling`);
    assert.equal(moduleFields.style.objectFields.background_color.allowEmpty, true, `${code} background cannot inherit the page color`);
    for (const field of Object.values(moduleFields)) if (field && field.type === 'array') assert.equal(field.min, 1, `${code} allows an unusable empty item collection`);
  }
  const shared = nativeSharedFields('feature_grid', renderers.ColorField);
  assert.equal(shared.title.maxLength, 160);
  assert.equal(shared.sub_title.maxLength, 320);
  assert.match(shared.title.placeholder, /模块标题/);
  assert.deepEqual(nativeFieldVisibility('img_text_slideshow', { autoplay: false }), { autoplay_delay: false });
  assert.deepEqual(nativeFieldVisibility('img_text_slideshow', { autoplay: true }), { autoplay_delay: true });
  assert.deepEqual(nativeFieldVisibility('campaign_offer', { timer_mode: 'daily' }), { ends_at: false });
  assert.deepEqual(nativeFieldVisibility('campaign_offer', { timer_mode: 'fixed' }), { ends_at: true });
  const slideshowFields = nativeComponentFields('img_text_slideshow_2', renderers);
  assert.match(slideshowFields.images.arrayFields.mobile_image.label, /完整显示/);
  assert.equal(slideshowFields.images.defaultItemProps.mobile_image, '');
  const translated = writeLocalized({ en: 'Title', zh_cn: '标题' }, 'zh_cn', '新标题');
  assert.deepEqual(translated, { en: 'Title', zh_cn: '新标题' });
  assert.equal(readLocalized(translated, 'en'), 'Title');
});

test('editor and storefront expose the same four responsive device bands', () => {
  const indexSource = fs.readFileSync(path.join(builder, 'index.tsx'), 'utf8');
  for (const width of [320, 768, 1024, 1440]) assert.match(indexSource, new RegExp(`width: ${width}`));
  assert.match(indexSource, /useState\(1024\)/);

  const storefrontCss = fs.readFileSync(path.join(__dirname, '../resources/beike/shop/default/css/module/module-shopify-sections.scss'), 'utf8');
  assert.match(storefrontCss, /builder-section-grid--tablet-3/);
  assert.match(storefrontCss, /@media \(min-width: 1024px\)/);
  assert.match(storefrontCss, /@media \(max-width: 359\.98px\)/);
  assert.match(storefrontCss, /\.builder-responsive-module[\s\S]*max-width: 100%/);

  for (const template of ['brand', 'icons', 'product', 'page', 'tab_product', 'trust_cards', 'testimonials']) {
    const source = fs.readFileSync(path.join(__dirname, `../themes/default/design/${template}.blade.php`), 'utf8');
    assert.match(source, /builder-section-grid--tablet-/);
  }
  assert.match(fs.readFileSync(path.join(__dirname, '../themes/default/design/feature_grid.blade.php'), 'utf8'), /feature-grid--tablet-/);
});

test('media library normalizes legacy paths and keeps single and range selection deterministic', () => {
  assert.equal(normalizeMediaUrl('image/catalog/hero.jpg'), '/image/catalog/hero.jpg');
  assert.equal(normalizeMediaUrl('javascript:alert(1)'), '');
  assert.equal(readMediaUrl({ path: 'image/catalog/hero.jpg' }, 'en'), '/image/catalog/hero.jpg');
  const legacy = normalizeMediaFile({ path: 'image/catalog/hero.jpg', url: '/cache/hero.jpg', name: 'hero.jpg', mime: null });
  assert.equal(legacy.path, '/image/catalog/hero.jpg');
  assert.equal(mediaSelectionFromMessage({ type: 'beikeshop:file-manager-selection', files: [legacy] }, 'image').path, '/image/catalog/hero.jpg');
  assert.equal(mediaSelectionFromMessage({ type: 'beikeshop:file-manager-selection', files: [{ path: '/image/catalog/demo.mp4', mime: 'video/mp4' }] }, 'image'), null);
  assert.deepEqual(normalizeMediaFiles([legacy, legacy, { path: '/image/catalog/demo.mp4', mime: 'video/mp4' }], 'image').map(file => file.path), ['/image/catalog/hero.jpg']);
  assert.deepEqual(nextMediaSelection([], 3, 6, { multiple: true, shift: true, toggle: false }), [3]);
  assert.deepEqual(nextMediaSelection([1], 4, 6, { multiple: true, shift: true, toggle: false }), [1, 2, 3, 4]);
  assert.deepEqual(nextMediaSelection([1, 3], 3, 6, { multiple: true, shift: false, toggle: true }), [1]);
  assert.deepEqual(nextMediaSelection([1, 2], 4, 6, { multiple: false, shift: true, toggle: true }), [4]);
});

test('preview change classifier patches one stable module and reloads structural edits', () => {
  const base = { content: [{ type: 'faq', props: { id: 'faq-1', placement: 'home', title: 'Questions', _builder: { visible: true } } }], root: { props: { pageType: 'home' } } };
  const fieldEdit = structuredClone(base); fieldEdit.content[0].props.title = 'Answers';
  assert.equal(classifyPreviewChange(base, fieldEdit).kind, 'module');
  const inserted = structuredClone(base); inserted.content.push({ type: 'promo_bar', props: { id: 'promo-1', placement: 'home' } });
  assert.deepEqual(classifyPreviewChange(base, inserted), { kind: 'reload', reason: 'structure' });
  const hidden = structuredClone(base); hidden.content[0].props._builder.visible = false;
  assert.deepEqual(classifyPreviewChange(base, hidden), { kind: 'reload', reason: 'structure' });
  const tokens = structuredClone(base); tokens.root.props.globalStyle = { primary_color: '#000000' };
  assert.deepEqual(classifyPreviewChange(base, tokens), { kind: 'reload', reason: 'root' });
});

test('module preview uses the protected fragment endpoint without draft version headers', async () => {
  const previous = global.fetch; let request;
  const api = new LongosBuilderApi({ update: '/save', revisions: '/revisions', restoreRevision: '/restore/__revision__', importBackup: '/import', previewModule: '/preview' }, 'csrf');
  try {
    global.fetch = async (url, options) => {
      request = { url, options };
      return new Response(JSON.stringify({ status: 'success', data: { module_id: 'faq-1', html: '<div data-builder-module-id="faq-1"></div>' } }), { headers: { 'Content-Type': 'application/json' } });
    };
    const result = await api.previewModule({ type: 'faq', props: { id: 'faq-1' } }, 'home');
    assert.equal(result.moduleId, 'faq-1'); assert.equal(request.url, '/preview');
    assert.equal(request.options.headers['X-CSRF-TOKEN'], 'csrf');
    assert.equal(JSON.parse(request.options.body).item.type, 'faq');
    assert.equal(request.options.headers['X-Builder-Draft-Version'], undefined);
  } finally { global.fetch = previous; }
});

test('obsolete live-preview requests can be cancelled without becoming a timeout failure', async () => {
  const previous = global.fetch; let requestSignal;
  const api = new LongosBuilderApi({ update: '/save', revisions: '/revisions', restoreRevision: '/restore/__revision__', importBackup: '/import', previewModule: '/preview' }, 'csrf');
  try {
    global.fetch = async (_url, options) => {
      requestSignal = options.signal;
      return await new Promise((resolve, reject) => {
        options.signal.addEventListener('abort', () => reject(new DOMException('cancelled', 'AbortError')), { once: true });
      });
    };
    const controller = new AbortController();
    const pending = api.previewModule({ type: 'faq', props: { id: 'faq-1' } }, 'home', controller.signal);
    controller.abort();
    await assert.rejects(pending, error => error instanceof DOMException && error.name === 'AbortError');
    assert.equal(requestSignal.aborted, true);
  } finally { global.fetch = previous; }
});

test('workbench guards storefront actions and reports chrome saves only after persistence', () => {
  const indexSource = fs.readFileSync(path.join(builder, 'index.tsx'), 'utf8');
  const chromeSource = fs.readFileSync(path.join(builder, 'chrome-settings.tsx'), 'utf8');
  const configSource = fs.readFileSync(path.join(builder, 'config.tsx'), 'utf8');
  const localizedSource = fs.readFileSync(path.join(builder, 'localized-fields.tsx'), 'utf8');
  const viewSource = fs.readFileSync(path.join(__dirname, '../resources/beike/admin/views/pages/design/builder/puck.blade.php'), 'utf8');
  for (const expected of ['blockPreviewAction', 'blockPreviewSubmit', 'onBlockedAction', 'previewAbort.current?.abort()', 'window.addEventListener("offline"', 'window.addEventListener("online"']) {
    assert.ok(indexSource.includes(expected), `builder is missing ${expected}`);
  }
  for (const expected of ['onDraftChange: (chrome: BuilderChrome) => void', 'onDraftChange(clone(next))', 'onSave: (chrome: BuilderChrome) => Promise<boolean>', 'editRevision.current === revision', 'saveError', '保存失败，当前修改仍保留在编辑器中']) {
    assert.ok(chromeSource.includes(expected), `chrome settings are missing ${expected}`);
  }
  assert.match(viewSource, /is-offline/);
  assert.match(viewSource, /44dvh/);
  assert.match(viewSource, /beike-puck-media-dialog[\s\S]*100dvh/);
  for (const fallback of ['fallback: 15', 'fallback: 1440', 'fallback: 64', 'fallback: 16', 'fallback: 12']) assert.ok(indexSource.includes(fallback));
  assert.ok(indexSource.includes('社交分享特色图'));
  assert.ok(indexSource.includes('preserveLocalRecovery'));
  assert.ok(indexSource.includes('scheduleLocalRecovery'));
  assert.ok(indexSource.includes('stageChrome'));
  assert.ok(indexSource.includes('...((embeds[embed.code]'));
  assert.ok(configSource.includes('root: { fields: {} }'));
  assert.ok(configSource.includes('schemaFields({ settings: item.fields || [], blocks: [] })'));
  assert.ok(configSource.includes('const uniqueId = useId()'));
  for (const expected of ['ModuleInspectorPanel', 'moduleSetupStatus', 'beike-puck-existing-insert__filters', '搜索名称、用途或关键词']) assert.ok(indexSource.includes(expected), `module workbench is missing ${expected}`);
  for (const expected of ['ResponsiveVisibilityField', 'SectionSpacingField', 'nativeFieldVisibility', 'resolveFields:', 'const move = (index: number, direction: -1 | 1)', '展示顺序']) assert.ok(configSource.includes(expected), `module controls are missing ${expected}`);
  assert.ok(fs.readFileSync(path.join(builder, 'native-component-fields.ts'), 'utf8').includes('跟随页面背景'));
  for (const expected of ['field.maxLength', 'field.placeholder', 'beike-puck-localized-field__count']) assert.ok(localizedSource.includes(expected), `localized fields are missing ${expected}`);
  assert.ok(!indexSource.includes('role="menuitem"'));
  assert.ok(!configSource.includes('plugins/BeikeShopTestSandbox'));
  assert.ok(!configSource.includes('pageType: { type: "select"'));
});

test('browser recovery restores only the current bounded draft and can be cleared after save', () => {
  const values = new Map();
  const storage = {
    getItem: key => values.has(key) ? values.get(key) : null,
    setItem: (key, value) => values.set(key, value),
    removeItem: key => values.delete(key),
  };
  const key = builderRecoveryKey('/admin/design');
  const data = { content: [{ type: 'faq', props: { id: 'faq-1', title: 'Recovered' } }], root: { props: { pageType: 'home' } } };
  const chrome = { header: { logo: 'local' }, footer: {}, menu: {} };
  assert.equal(writeBuilderRecovery(storage, key, 7, data, chrome, 1000), true);
  const recovered = readBuilderRecovery(storage, key, 7, 1500);
  assert.equal(recovered.data.content[0].props.title, 'Recovered');
  assert.equal(recovered.chrome.header.logo, 'local');
  assert.equal(readBuilderRecovery(storage, key, 8, 1500), null);
  assert.equal(values.has(key), false);
  assert.equal(writeBuilderRecovery(storage, key, 8, data, chrome, 2000), true);
  clearBuilderRecovery(storage, key);
  assert.equal(values.has(key), false);
  assert.equal(writeBuilderRecovery(storage, key, 8, { content: 'broken', root: {} }, chrome, 2000), false);
});

test('writes never overlap; a failed save does not block the next save or restore', async () => {
  const queue = new BuilderWriteQueue(); const first = deferred(); const events = [];
  const one = queue.run(async () => { events.push('first'); await first.promise; });
  const rejected = assert.rejects(one, /offline/);
  const two = queue.run(async () => { events.push('second'); });
  const three = queue.run(async () => { events.push('restore'); });
  await delay(0); assert.deepEqual(events, ['first']);
  first.reject(new Error('offline')); await rejected; await Promise.all([two, three]);
  assert.deepEqual(events, ['first', 'second', 'restore']);
});

test('restore checks application status even when HTTP status is 200', async () => {
  const previous = global.fetch;
  const api = new LongosBuilderApi({ update: '/save', revisions: '/revisions', restoreRevision: '/restore/__revision__', importBackup: '/import' }, 'test');
  try {
    global.fetch = async () => new Response(JSON.stringify({ status: 'fail', message: 'Snapshot expired' }));
    await assert.rejects(api.restoreRevision(7, 3), /Snapshot expired/);
    global.fetch = async () => new Response('<html>Login required</html>');
    await assert.rejects(api.restoreRevision(7, 3), /版本恢复失败/);
    global.fetch = async () => new Response(JSON.stringify({ status: 'success', data: { draft_version: 4, has_unpublished_changes: true } }));
    const result = await api.restoreRevision(7, 3);
    assert.equal(result.draftVersion, 4); assert.equal(result.hasUnpublishedChanges, true);
  } finally { global.fetch = previous; }
});

test('writes carry optimistic version headers and surface a 409 as a typed conflict', async () => {
  const previous = global.fetch; let request;
  const api = new LongosBuilderApi({ update: '/save', revisions: '/revisions', restoreRevision: '/restore/__revision__', importBackup: '/import' }, 'token');
  try {
    global.fetch = async (url, options) => {
      request = { url, options };
      return new Response(JSON.stringify({ status: 'fail', message: 'newer draft', data: { draft_version: 9 } }), { status: 409, headers: { 'Content-Type': 'application/json' } });
    };
    await assert.rejects(api.save({ content: [], root: {} }, { header: {}, footer: {}, menu: {} }, 8), error => {
      assert.equal(error instanceof BuilderConflictError, true); assert.equal(error.serverVersion, 9); return true;
    });
    assert.equal(request.options.headers['X-Builder-Draft-Version'], '8');
    assert.equal(request.options.headers['X-Builder-Force'], '0');
    assert.equal(request.options.headers['X-CSRF-TOKEN'], 'token');
  } finally { global.fetch = previous; }
});

test('expired admin sessions produce an actionable save error', async () => {
  const previous = global.fetch;
  const api = new LongosBuilderApi({ update: '/save', revisions: '/revisions', restoreRevision: '/restore/__revision__', importBackup: '/import' }, 'token');
  try {
    global.fetch = async () => new Response(JSON.stringify({ message: 'CSRF token mismatch' }), { status: 419, headers: { 'Content-Type': 'application/json' } });
    await assert.rejects(api.save({ content: [], root: { props: {} } }, { header: {}, footer: {}, menu: {} }, 1), /登录会话已失效/);
  } finally { global.fetch = previous; }
});

test('publish sends the complete intent and reports revision/cache outcomes', async () => {
  const previous = global.fetch; let request;
  const api = new LongosBuilderApi({ update: '/save', revisions: '/revisions', restoreRevision: '/restore/__revision__', importBackup: '/import' }, 'token');
  try {
    global.fetch = async (url, options) => {
      request = { url, options };
      return new Response(JSON.stringify({ status: 'success', data: { draft_version: 12, has_unpublished_changes: false, published: true, revision_created: false, cache_refreshed: true } }), { headers: { 'Content-Type': 'application/json' } });
    };
    const result = await api.save({ content: [], root: { props: {} } }, { header: {}, footer: {}, menu: {} }, 11, true);
    assert.equal(JSON.parse(request.options.body).publish, true);
    assert.equal(result.published, true); assert.equal(result.draftVersion, 12);
    assert.equal(result.hasUnpublishedChanges, false); assert.equal(result.revisionCreated, false); assert.equal(result.cacheRefreshed, true);
  } finally { global.fetch = previous; }
});

test('a malformed successful save never clears the local draft state', async () => {
  const previous = global.fetch;
  const api = new LongosBuilderApi({ update: '/save', revisions: '/revisions', restoreRevision: '/restore/__revision__', importBackup: '/import' }, 'token');
  try {
    global.fetch = async () => new Response(JSON.stringify({ status: 'success', data: { has_unpublished_changes: false } }), { headers: { 'Content-Type': 'application/json' } });
    await assert.rejects(api.save({ content: [], root: { props: {} } }, { header: {}, footer: {}, menu: {} }, 11), /保存结果不完整/);
    global.fetch = async () => new Response(JSON.stringify({ status: 'success', data: { draft_version: 12 } }), { headers: { 'Content-Type': 'application/json' } });
    await assert.rejects(api.save({ content: [], root: { props: {} } }, { header: {}, footer: {}, menu: {} }, 11), /保存结果不完整/);
  } finally { global.fetch = previous; }
});

test('iframe selection accepts foreign DOM nodes and preview URL preserves fragments', async () => {
  const { Window } = await import('happy-dom');
  const parent = new Window({ url: 'http://localhost/editor' }); const child = new Window();
  global.window = parent;
  try {
    const module = child.document.createElement('section'); module.id = 'module-abc';
    const text = child.document.createTextNode('Banner'); module.append(text);
    assert.equal(previewEventElement(module), module);
    assert.equal(previewEventElement(text), module);
    assert.equal(previewEventElement(null), null);
    const url = new URL(previewFrameUrl('/?design=1#offer', 3));
    assert.equal(url.searchParams.getAll('design').length, 1);
    assert.equal(url.searchParams.get('builder_preview'), '3'); assert.equal(url.hash, '#offer');
  } finally { await parent.happyDOM.close(); await child.happyDOM.close(); delete global.window; }
});

test('resource search ignores obsolete results, clears loading on empty input and exposes errors', async () => {
  const { Window } = await import('happy-dom');
  const win = new Window({ url: 'http://localhost/editor' });
  global.window = win; global.document = win.document; global.IS_REACT_ACT_ENVIRONMENT = true;
  const React = require('react'); const { createRoot } = require('react-dom/client');
  const { useCommerceSearch } = require(path.join(builder, 'use-commerce-search.ts'));
  const requests = []; let state;
  const adapter = { supports: () => true, search: (_, query, signal) => { const request = { ...deferred(), query, signal }; requests.push(request); return request.promise; } };
  const Probe = ({ query }) => { state = useCommerceSearch(adapter, 'product', query); return null; };
  const root = createRoot(win.document.createElement('div'));
  const render = query => React.act(async () => { root.render(React.createElement(Probe, { query })); });
  try {
    await render('old'); await React.act(() => delay(240));
    await render('new'); assert.equal(requests[0].signal.aborted, true);
    await React.act(() => delay(240));
    await React.act(async () => { requests[1].resolve([{ id: 2, name: 'New' }]); });
    await React.act(async () => { requests[0].resolve([{ id: 1, name: 'Old' }]); });
    assert.equal(state.results[0].id, 2);
    await render(''); assert.equal(state.loading, false); assert.deepEqual(state.results, []);
    await render('failure'); await React.act(() => delay(240));
    await React.act(async () => { requests[2].reject(new Error('offline')); });
    assert.equal(state.error, 'offline'); assert.equal(state.loading, false);
  } finally { await React.act(async () => root.unmount()); await win.happyDOM.close(); delete global.window; delete global.document; }
});
