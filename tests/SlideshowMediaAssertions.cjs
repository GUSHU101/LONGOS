const assert = require('node:assert/strict');
const { test } = require('node:test');
const path = require('node:path');
const { Window } = require('happy-dom');

const { mountSlideshowMedia, slideshowMediaDimensions } = require(path.join(__dirname, '../resources/beike/shop/default/js/slideshow-media.js'));

test('slideshow media dimensions reject invalid or incomplete images', () => {
  assert.deepEqual(slideshowMediaDimensions(2400, 1050), {width: 2400, height: 1050});
  assert.equal(slideshowMediaDimensions(0, 1350), null);
  assert.equal(slideshowMediaDimensions(Number.NaN, 1350), null);
});

test('loaded slideshow media emits one layout event for each responsive source', () => {
  const browser = new Window();
  const document = browser.document;
  document.body.innerHTML = '<div class="image-wrap"><picture><img data-longos-slideshow-media></picture></div>';
  const image = document.querySelector('img');
  const frame = document.querySelector('.image-wrap');
  Object.defineProperty(image, 'naturalWidth', { value: 2400 });
  Object.defineProperty(image, 'naturalHeight', { value: 1050 });
  const previousCustomEvent = global.CustomEvent;
  global.CustomEvent = browser.CustomEvent;
  let eventDetail = null;
  let eventCount = 0;
  frame.addEventListener('longos:slideshow-media-ready', event => { eventDetail = event.detail; eventCount += 1; });

  try {
    mountSlideshowMedia(document);
    image.dispatchEvent(new browser.Event('load'));
    image.dispatchEvent(new browser.Event('load'));
  } finally {
    global.CustomEvent = previousCustomEvent;
    browser.close();
  }

  assert.equal(image.dataset.longosSlideshowBound, 'true');
  assert.match(image.dataset.longosMediaSignature, /2400x1050$/);
  assert.deepEqual(eventDetail, {width: 2400, height: 1050});
  assert.equal(eventCount, 1);
});
