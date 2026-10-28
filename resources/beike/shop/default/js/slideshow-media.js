/**
 * Keep mobile slideshow artwork complete while choosing a frame that matches
 * the image's real orientation. The blurred backdrop fills only the remaining
 * space; the foreground image is never cropped or stretched.
 */
const slideshowMediaShape = (width, height) => {
  const safeWidth = Number(width);
  const safeHeight = Number(height);
  if (!Number.isFinite(safeWidth) || !Number.isFinite(safeHeight) || safeWidth <= 0 || safeHeight <= 0) return '';

  const ratio = safeWidth / safeHeight;
  if (ratio >= 1.2) return 'landscape';
  if (ratio <= 0.9) return 'portrait';
  return 'square';
};

const syncSlideshowMedia = (image) => {
  const frame = image?.closest?.('.image-wrap');
  if (!frame) return;

  const shape = slideshowMediaShape(image.naturalWidth, image.naturalHeight);
  const previousShape = frame.dataset.mobileMediaShape || '';
  if (shape) frame.dataset.mobileMediaShape = shape;
  else delete frame.dataset.mobileMediaShape;

  if (shape !== previousShape && typeof CustomEvent === 'function') {
    frame.dispatchEvent(new CustomEvent('longos:slideshow-media-ready', {
      bubbles: true,
      detail: {shape},
    }));
  }
};

const mountSlideshowMedia = (root) => {
  const scope = root && typeof root.querySelectorAll === 'function' ? root : document;
  const images = Array.from(scope.querySelectorAll('[data-longos-slideshow-media]'));
  if (scope.matches?.('[data-longos-slideshow-media]')) images.unshift(scope);

  images.forEach((image) => {
    if (!image.dataset.longosSlideshowBound) {
      image.dataset.longosSlideshowBound = 'true';
      image.addEventListener('load', () => syncSlideshowMedia(image));
    }
    if (image.complete) syncSlideshowMedia(image);
  });
};

if (typeof window !== 'undefined') {
  window.LongosSlideshowMedia = Object.freeze({
    mediaShape: slideshowMediaShape,
    mount: mountSlideshowMedia,
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => mountSlideshowMedia(document), {once: true});
  } else {
    mountSlideshowMedia(document);
  }
}

module.exports = {slideshowMediaShape, mountSlideshowMedia};
