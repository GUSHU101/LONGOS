const assert = require('node:assert/strict');
const { test } = require('node:test');
const path = require('node:path');
const { Window } = require('happy-dom');

const {
  mountTestimonialCarousels,
  testimonialIndexForScroll,
} = require(path.join(__dirname, '../resources/beike/shop/default/js/testimonials-carousel.js'));

test('testimonial carousel resolves the slide nearest the native scroll position', () => {
  assert.equal(testimonialIndexForScroll([0, 300, 600], 0), 0);
  assert.equal(testimonialIndexForScroll([0, 300, 600], 410), 1);
  assert.equal(testimonialIndexForScroll([0, 300, 600], 570), 2);
  assert.equal(testimonialIndexForScroll([], 200), 0);
});

test('testimonial controls update position and expose bounded navigation', () => {
  const browser = new Window();
  const document = browser.document;
  document.body.innerHTML = `
    <section class="section-testimonials">
      <div data-testimonials-carousel tabindex="0">
        <div data-testimonial-slide></div>
        <div data-testimonial-slide></div>
        <div data-testimonial-slide></div>
      </div>
      <div data-testimonials-controls>
        <button data-testimonials-previous></button>
        <output data-testimonials-status data-position-label="Review __CURRENT__ of __TOTAL__"></output>
        <button data-testimonials-next></button>
      </div>
    </section>`;

  const track = document.querySelector('[data-testimonials-carousel]');
  const slides = Array.from(document.querySelectorAll('[data-testimonial-slide]'));
  slides.forEach((slide, index) => Object.defineProperty(slide, 'offsetLeft', {value: index * 300}));
  track.scrollTo = ({left}) => { track.scrollLeft = left; };

  mountTestimonialCarousels(document);
  const previous = document.querySelector('[data-testimonials-previous]');
  const next = document.querySelector('[data-testimonials-next]');
  const status = document.querySelector('[data-testimonials-status]');

  assert.equal(track.dataset.testimonialsReady, 'true');
  assert.equal(previous.disabled, true);
  assert.equal(next.disabled, false);
  assert.equal(status.textContent, '1 / 3');

  next.click();
  assert.equal(track.scrollLeft, 300);
  assert.equal(status.textContent, '2 / 3');
  assert.equal(status.getAttribute('aria-label'), 'Review 2 of 3');

  next.click();
  assert.equal(track.scrollLeft, 600);
  assert.equal(next.disabled, true);

  track.dispatchEvent(new browser.KeyboardEvent('keydown', {key: 'ArrowLeft'}));
  assert.equal(track.scrollLeft, 300);
  assert.equal(status.textContent, '2 / 3');
  browser.close();
});
