import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {dirname, join} from 'node:path';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const read = (path) => readFileSync(join(root, path), 'utf8');
const widget = read('Static/public/translate-widget.js');
const view = read('Views/shop/widget.blade.php');
const policy = read('Services/StorefrontPolicy.php');
const manifest = JSON.parse(read('config.json'));
const vendor = readFileSync(join(root, 'Static/public/vendor/translate.min.js'));

assert.equal(manifest.code, 'longos_instant_translate');
assert.equal(manifest.version, 'v2.0.0');
assert.equal(createHash('sha256').update(vendor).digest('hex'), 'f027e461c331e21c60634725db8f13c01d72788faf87dc815882a7c0572e96e4');
assert.ok(policy.includes("'main#main-content'"));
assert.ok(widget.includes('manualKey'));
assert.ok(widget.indexOf('safeStorageGet(manualKey)') < widget.indexOf('fetch(config.context_url'));
assert.ok(widget.includes("EDGE_HOST = 'edge.microsoft.com'"));
assert.ok(widget.includes('networkPolicy.allowed'));
assert.ok(widget.includes('engine.element.tagAttribute = {}'));
assert.ok(widget.includes('MutationObserver'));
assert.ok(widget.includes('preloadLibrary'));
assert.ok(widget.includes('X-CSRF-TOKEN'));
assert.ok(!widget.includes('listener.start('));
assert.ok(!widget.includes('document.body.innerHTML'));
assert.ok(view.indexOf('vendor/translate.min.js') < view.indexOf('translate-widget.js'));

console.log('PASS: local-library preload, safe DOM roots, locked endpoint and vendored xnx3 contracts');
