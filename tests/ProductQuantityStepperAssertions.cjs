'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const quantity = require('../resources/beike/shop/default/js/product-quantity');

test('product quantity normalizes SKU stock and inactive states', () => {
  assert.equal(quantity.stockLimit(8, 1), 8);
  assert.equal(quantity.stockLimit('4.9', true), 4);
  assert.equal(quantity.stockLimit(8, 0), 0);
  assert.equal(quantity.stockLimit(-2, 1), 0);
  assert.equal(quantity.stockLimit('invalid', 1), 0);
});

test('product quantity never leaves the purchasable stock range', () => {
  assert.equal(quantity.clamp('', 7), 1);
  assert.equal(quantity.clamp(0, 7), 1);
  assert.equal(quantity.clamp('003', 7), 3);
  assert.equal(quantity.clamp(99, 7), 7);
  assert.equal(quantity.clamp(4, 0), 1);
});

test('product quantity buttons stop exactly at one and available stock', () => {
  assert.equal(quantity.step(1, -1, 5), 1);
  assert.equal(quantity.step(1, 1, 5), 2);
  assert.equal(quantity.step(5, 1, 5), 5);
  assert.equal(quantity.step(5, -1, 5), 4);
});

test('manual quantity input keeps digits only before applying limits', () => {
  assert.equal(quantity.digits('00a12x'), '12');
  assert.equal(quantity.digits(''), '');
  assert.equal(quantity.clamp('3 items', 9), 3);
});
