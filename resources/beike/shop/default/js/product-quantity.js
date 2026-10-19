'use strict';

/**
 * Shared product-detail quantity rules.
 *
 * The storefront component and its no-Vue fallback both use this contract so
 * a delayed or failed optional script cannot change stock-bound behaviour.
 */
function stockLimit(stock, active = true) {
  if (!(active === true || active == 1)) return 0;

  const numeric = Math.floor(Number(stock));
  return Number.isFinite(numeric) && numeric > 0 ? numeric : 0;
}

function digits(value) {
  return String(value ?? '').replace(/\D/g, '').replace(/^0+(?=\d)/, '');
}

function clamp(value, maximum) {
  const limit = stockLimit(maximum);
  const parsed = Number.parseInt(digits(value), 10) || 1;

  return Math.max(1, Math.min(Math.max(1, limit), parsed));
}

function step(value, direction, maximum) {
  const delta = direction > 0 ? 1 : -1;
  return clamp(clamp(value, maximum) + delta, maximum);
}

const ProductQuantity = Object.freeze({ clamp, digits, step, stockLimit });

if (typeof window !== 'undefined') {
  window.LongosProductQuantity = ProductQuantity;
}

module.exports = ProductQuantity;
