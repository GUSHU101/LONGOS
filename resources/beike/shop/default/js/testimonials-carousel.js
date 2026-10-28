/** Progressive enhancement for the mobile testimonials scroll-snap carousel. */
const testimonialIndexForScroll = (positions, scrollLeft) => {
  if (!Array.isArray(positions) || positions.length === 0) return 0;

  const position = Number.isFinite(Number(scrollLeft)) ? Number(scrollLeft) : 0;
  return positions.reduce((nearest, candidate, index) => (
    Math.abs(Number(candidate) - position) < Math.abs(Number(positions[nearest]) - position) ? index : nearest
  ), 0);
};

const mountTestimonialCarousel = (track) => {
  if (!track?.querySelectorAll || track.dataset.testimonialsReady === 'true') return;

  const slides = Array.from(track.querySelectorAll('[data-testimonial-slide]'));
  if (slides.length < 2) return;

  const root = track.closest('.section-testimonials') || track.parentElement;
  const previous = root?.querySelector('[data-testimonials-previous]');
  const next = root?.querySelector('[data-testimonials-next]');
  const status = root?.querySelector('[data-testimonials-status]');
  const ownerWindow = track.ownerDocument?.defaultView;
  if (!previous || !next || !status || !ownerWindow) return;

  track.dataset.testimonialsReady = 'true';
  let activeIndex = 0;
  let positions = [];
  let frame = null;

  const setActive = (index) => {
    activeIndex = Math.max(0, Math.min(slides.length - 1, Number(index) || 0));
    previous.disabled = activeIndex === 0;
    next.disabled = activeIndex === slides.length - 1;
    status.textContent = `${activeIndex + 1} / ${slides.length}`;

    const label = status.dataset.positionLabel
      ?.replace('__CURRENT__', String(activeIndex + 1))
      .replace('__TOTAL__', String(slides.length));
    if (label) status.setAttribute('aria-label', label);
  };

  const measure = () => {
    const origin = Number(slides[0]?.offsetLeft) || 0;
    positions = slides.map((slide) => (Number(slide.offsetLeft) || 0) - origin);
    setActive(testimonialIndexForScroll(positions, track.scrollLeft));
  };

  const scheduleMeasure = () => {
    if (frame !== null) return;
    const requestFrame = ownerWindow.requestAnimationFrame || ((callback) => ownerWindow.setTimeout(callback, 16));
    frame = requestFrame(() => {
      frame = null;
      if (!track.isConnected) return;
      measure();
    });
  };

  const scrollToIndex = (index) => {
    const target = Math.max(0, Math.min(slides.length - 1, index));
    const reduceMotion = ownerWindow.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    const left = positions[target] ?? 0;
    if (typeof track.scrollTo === 'function') track.scrollTo({left, behavior: reduceMotion ? 'auto' : 'smooth'});
    else track.scrollLeft = left;
    setActive(target);
  };

  previous.addEventListener('click', () => scrollToIndex(activeIndex - 1));
  next.addEventListener('click', () => scrollToIndex(activeIndex + 1));
  track.addEventListener('scroll', scheduleMeasure, {passive: true});
  track.addEventListener('keydown', (event) => {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
    event.preventDefault();
    scrollToIndex(activeIndex + (event.key === 'ArrowRight' ? 1 : -1));
  });
  ownerWindow.addEventListener('resize', scheduleMeasure, {passive: true});
  measure();
};

const mountTestimonialCarousels = (context) => {
  const scope = context?.querySelectorAll ? context : (typeof document !== 'undefined' ? document : null);
  if (!scope) return;

  const tracks = Array.from(scope.querySelectorAll('[data-testimonials-carousel]'));
  if (scope.matches?.('[data-testimonials-carousel]')) tracks.unshift(scope);
  tracks.forEach(mountTestimonialCarousel);
};

if (typeof window !== 'undefined') {
  window.LongosTestimonialCarousel = Object.freeze({mount: mountTestimonialCarousel, mountAll: mountTestimonialCarousels});

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => mountTestimonialCarousels(document), {once: true});
  } else {
    mountTestimonialCarousels(document);
  }
  document.addEventListener('longos:builder-preview-updated', () => mountTestimonialCarousels(document));
}

module.exports = {testimonialIndexForScroll, mountTestimonialCarousel, mountTestimonialCarousels};
