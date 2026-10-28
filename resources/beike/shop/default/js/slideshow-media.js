/** Keep Swiper's auto-height in sync when a responsive picture source loads. */
const slideshowMediaDimensions = (width, height) => {
  const safeWidth = Number(width);
  const safeHeight = Number(height);
  if (!Number.isFinite(safeWidth) || !Number.isFinite(safeHeight) || safeWidth <= 0 || safeHeight <= 0) return null;

  return {width: safeWidth, height: safeHeight};
};

const syncSlideshowMedia = (image) => {
  const frame = image?.closest?.('.image-wrap');
  if (!frame) return;

  const dimensions = slideshowMediaDimensions(image.naturalWidth, image.naturalHeight);
  if (!dimensions) return;
  const signature = `${image.currentSrc || image.src}|${dimensions.width}x${dimensions.height}`;
  if (image.dataset.longosMediaSignature === signature) return;
  image.dataset.longosMediaSignature = signature;

  if (typeof CustomEvent !== 'function') return;
  frame.dispatchEvent(new CustomEvent('longos:slideshow-media-ready', {
    bubbles: true,
    detail: dimensions,
  }));
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
    mount: mountSlideshowMedia,
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => mountSlideshowMedia(document), {once: true});
  } else {
    mountSlideshowMedia(document);
  }
}

module.exports = {slideshowMediaDimensions, mountSlideshowMedia};
