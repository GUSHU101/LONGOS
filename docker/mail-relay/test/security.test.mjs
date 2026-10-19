import assert from 'node:assert/strict';
import test from 'node:test';
import {createSignature, verifySignedRequest} from '../src/security.mjs';

const secret = '0123456789abcdef0123456789abcdef';
const now = 1_700_000_000_000;
const timestamp = String(now / 1000);
const nonce = 'nonce-1';
const rawBody = JSON.stringify({subject: 'hello'});

test('accepts a valid signature exactly once', () => {
  const usedNonces = new Map();
  const input = {secret, timestamp, nonce, signature: createSignature(secret, timestamp, nonce, rawBody), rawBody, windowSeconds: 300, usedNonces, now};
  assert.equal(verifySignedRequest(input), true);
  assert.equal(verifySignedRequest(input), false);
});

test('rejects tampered and expired requests', () => {
  const base = {secret, timestamp, nonce, signature: createSignature(secret, timestamp, nonce, rawBody), rawBody, windowSeconds: 300, usedNonces: new Map(), now};
  assert.equal(verifySignedRequest({...base, rawBody: '{}'}), false);
  assert.equal(verifySignedRequest({...base, now: now + 301_000}), false);
});

test('scopes replay nonces to the authenticated client', () => {
  const usedNonces = new Map();
  const base = {secret, timestamp, nonce, signature: createSignature(secret, timestamp, nonce, rawBody), rawBody, windowSeconds: 300, usedNonces, now};

  assert.equal(verifySignedRequest({...base, nonceScope: 'longos'}), true);
  assert.equal(verifySignedRequest({...base, nonceScope: 'cloud-mail'}), true);
  assert.equal(verifySignedRequest({...base, nonceScope: 'longos'}), false);
});

test('bounds the replay cache without evicting a still-live nonce', () => {
  const usedNonces = new Map();
  for (let index = 1; index <= 4; index += 1) {
    const currentNonce = `nonce-${index}`;
    const accepted = verifySignedRequest({
      secret,
      timestamp,
      nonce: currentNonce,
      signature: createSignature(secret, timestamp, currentNonce, rawBody),
      rawBody,
      windowSeconds: 300,
      usedNonces,
      nonceScope: 'longos',
      maxNonceRecords: 3,
      now: now + index,
    });
    assert.equal(accepted, index <= 3);
  }

  assert.equal(usedNonces.size, 3);
  assert.equal(usedNonces.has('longos:nonce-1'), true);
  const latestNonce = 'nonce-3';
  assert.equal(verifySignedRequest({
    secret,
    timestamp,
    nonce: latestNonce,
    signature: createSignature(secret, timestamp, latestNonce, rawBody),
    rawBody,
    windowSeconds: 300,
    usedNonces,
    nonceScope: 'longos',
    maxNonceRecords: 3,
    now: now + 5,
  }), false);
});
