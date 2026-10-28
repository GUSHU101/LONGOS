const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const root = path.resolve(__dirname, '..');
const widget = fs.readFileSync(path.join(root, 'plugins/LongosLiveChat/Views/shop/widget.blade.php'), 'utf8');
const admin = fs.readFileSync(path.join(root, 'plugins/LongosLiveChat/Views/admin/index.blade.php'), 'utf8');

function replaceBladeJson(source) {
  const marker = '@json(';
  let result = '';
  let cursor = 0;

  while (cursor < source.length) {
    const start = source.indexOf(marker, cursor);
    if (start === -1) return result + source.slice(cursor);
    result += source.slice(cursor, start) + '"__blade_json__"';

    let index = start + marker.length;
    let depth = 1;
    let quote = '';
    let escaped = false;
    while (index < source.length && depth > 0) {
      const character = source[index++];
      if (escaped) { escaped = false; continue; }
      if (quote && character === '\\') { escaped = true; continue; }
      if (quote) { if (character === quote) quote = ''; continue; }
      if (character === '"' || character === "'") { quote = character; continue; }
      if (character === '(') depth += 1;
      if (character === ')') depth -= 1;
    }
    assert.equal(depth, 0, 'unbalanced @json directive');
    cursor = index;
  }

  return result;
}

function scripts(view) {
  return [...view.matchAll(/<script>([\s\S]*?)<\/script>/g)].map((match) => match[1]);
}

function compileInlineScripts(view) {
  for (const script of scripts(view)) {
    const normalized = replaceBladeJson(script).replace(/\{\{[\s\S]*?\}\}/g, '5000');
    assert.doesNotThrow(() => new Function(normalized));
  }
}

test('live chat inline scripts remain syntactically valid after Blade values are compiled', () => {
  compileInlineScripts(widget);
  compileInlineScripts(admin);
});

test('live chat requests recover from stalled networks without overlapping poll loops', () => {
  assert.match(widget, /timeout:\s*12000/);
  assert.match(admin, /timeout:\s*12000/);
  assert.match(admin, /window\.setTimeout\(this\.poll/);
  assert.doesNotMatch(admin, /window\.setInterval\(\(\)\s*=>\s*\{[\s\S]*?loadConversations/);
  assert.match(admin, /this\._messageRequest\?\.abort\(\)/);
  assert.match(admin, /merged\.slice\(0, retainedLimit\)/);
});
