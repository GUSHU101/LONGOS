const assert = require('node:assert/strict');
const { test } = require('node:test');
const path = require('node:path');
const { Window } = require('happy-dom');

const { mountSlideshowMedia, slideshowMediaShape } = require(path.join(__dirname, '../resources/beike/shop/default/js/slideshow-media.js'));

test('mobile slideshow frames follow the real artwork orientation', () => {
  assert.equal(slideshowMediaShape(2400, 1050), 'landscape');
  assert.equal(slideshowMediaShape(1200, 1200), 'square');
  assert.equal(slideshowMediaShape(1080, 1350), 'portrait');
  assert.equal(slideshowMediaShape(0, 1350), '');
  assert.equal(slideshowMediaShape(Number.NaN, 1350), '');
});

test('loaded slideshow media updates its frame and emits a layout event', () => {
  const browser = new Window();
  const document = browser.document;
  document.body.innerHTML = '<div class="image-wrap"><picture><img data-longos-slideshow-media></picture></div>';
  const image = document.querySelector('img');
  const frame = document.querySelector('.image-wrap');
  Object.defineProperty(image, 'naturalWidth', { value: 2400 });
  Object.defineProperty(image, 'naturalHeight', { value: 1050 });
  const previousCustomEvent = global.CustomEvent;
  global.CustomEvent = browser.CustomEvent;
  let eventShape = '';
  frame.addEventListener('longos:slideshow-media-ready', event => { eventShape = event.detail.shape; });

  try {
    mountSlideshowMedia(document);
    image.dispatchEvent(new browser.Event('load'));
  } finally {
    global.CustomEvent = previousCustomEvent;
    browser.close();
  }

  assert.equal(frame.dataset.mobileMediaShape, 'landscape');
  assert.equal(image.dataset.longosSlideshowBound, 'true');
  assert.equal(eventShape, 'landscape');
});
