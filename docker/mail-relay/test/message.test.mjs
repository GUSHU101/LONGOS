import assert from 'node:assert/strict';
import {mkdtempSync, readFileSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import test from 'node:test';
import {createSignature} from '../src/security.mjs';

process.env.LONGOS_RELAY_SHARED_SECRET = 'longos-longos-longos-longos-123456';
process.env.CLOUD_MAIL_RELAY_SHARED_SECRET = 'cloud-mail-cloud-mail-cloud-123456';
process.env.ALLOWED_SENDER_DOMAINS = 'mail.example.com';
process.env.RELAY_NO_LISTEN = 'true';
const receiptDirectory = mkdtempSync(join(tmpdir(), 'longos-relay-test-'));
process.env.IDEMPOTENCY_STORE_PATH = join(receiptDirectory, 'receipts.jsonl');

const {normalizeMessage, rememberCompletedRequest, server} = await import('../src/server.mjs');

test.after(() => rmSync(receiptDirectory, {recursive: true, force: true}));

test('normalizes To, Cc and Bcc while keeping the envelope complete', () => {
  const result = normalizeMessage({
    requestId: 'delivery:42',
    from: {address: 'orders+shop-a@mail.example.com', name: 'Shop A'},
    to: ['to@example.net'],
    cc: ['cc@example.net'],
    bcc: ['bcc@example.net', 'to@example.net'],
    subject: 'Order',
    text: 'Body',
    senderContext: {source: 'longos', tenantId: '42', tenantCode: 'shop-a', category: 'orders'},
  });

  assert.deepEqual(result.mail.to, ['to@example.net']);
  assert.deepEqual(result.mail.cc, ['cc@example.net']);
  assert.deepEqual(result.mail.bcc, ['bcc@example.net', 'to@example.net']);
  assert.deepEqual(result.mail.envelope.to, ['to@example.net', 'cc@example.net', 'bcc@example.net']);
  assert.equal(result.rateKey, 'longos:orders+shop-a@mail.example.com');
  assert.equal(result.senderContext.tenantCode, 'shop-a');
});

test('rejects a sender outside the signing allowlist', () => {
  assert.throws(() => normalizeMessage({
    from: {address: 'orders@other.example'},
    to: ['to@example.net'],
    subject: 'Order',
    text: 'Body',
  }), error => error.status === 403);
});

test('rejects non-string message bodies and excessive attachment counts', () => {
  const base = {
    requestId: 'validation:42',
    from: {address: 'orders+shop-a@mail.example.com'},
    to: ['to@example.net'],
    subject: 'Order',
    senderContext: {source: 'longos'},
  };
  assert.throws(() => normalizeMessage({...base, text: {unexpected: true}}), error => error.status === 400);
  assert.throws(() => normalizeMessage({
    ...base,
    text: 'Body',
    attachments: Array.from({length: 26}, (_, index) => ({filename: `${index}.txt`, content: 'YQ=='})),
  }), error => error.status === 400);
});

test('persists accepted request receipts for restart-safe idempotency', () => {
  rememberCompletedRequest('longos:delivery:42', {accepted: true, queueId: 'delivery:42'}, 'a'.repeat(64));
  const receipts = readFileSync(process.env.IDEMPOTENCY_STORE_PATH, 'utf8');
  assert.match(receipts, /"key":"longos:delivery:42"/);
  assert.match(receipts, /"accepted":true/);
  assert.match(receipts, /"requestHash":"a{64}"/);
});

test('rejects request-id reuse with different message content', async () => {
  const requestId = 'collision:42';
  const original = JSON.stringify({requestId, subject: 'Original'});
  rememberCompletedRequest(`longos:${requestId}`, {accepted: true, queueId: requestId},
    (await import('node:crypto')).createHash('sha256').update(original).digest('hex'));

  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  try {
    const body = JSON.stringify({
      requestId,
      from: {address: 'orders+shop-a@mail.example.com'},
      to: ['to@example.net'],
      subject: 'Changed',
      text: 'Body',
      senderContext: {source: 'longos', tenantId: '42'},
    });
    const timestamp = String(Math.floor(Date.now() / 1000));
    const nonce = 'idempotency-collision-nonce';
    const response = await fetch(`http://127.0.0.1:${server.address().port}/v1/send`, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        'x-relay-client': 'longos',
        'x-relay-timestamp': timestamp,
        'x-relay-nonce': nonce,
        'x-relay-signature': createSignature(process.env.LONGOS_RELAY_SHARED_SECRET, timestamp, nonce, body),
      },
      body,
    });
    assert.equal(response.status, 409);
  } finally {
    await new Promise(resolve => server.close(resolve));
  }
});

test('binds a client-specific signature to the matching sender context', async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  try {
    const body = JSON.stringify({
      requestId: 'mismatch:1',
      from: {address: 'orders+shop-a@mail.example.com'},
      to: ['to@example.net'],
      subject: 'Order',
      text: 'Body',
      senderContext: {source: 'longos', tenantId: '42'},
    });
    const timestamp = String(Math.floor(Date.now() / 1000));
    const nonce = 'client-mismatch-nonce';
    const response = await fetch(`http://127.0.0.1:${server.address().port}/v1/send`, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        'x-relay-client': 'cloud-mail',
        'x-relay-timestamp': timestamp,
        'x-relay-nonce': nonce,
        'x-relay-signature': createSignature(process.env.CLOUD_MAIL_RELAY_SHARED_SECRET, timestamp, nonce, body),
      },
      body,
    });
    assert.equal(response.status, 403);
  } finally {
    await new Promise(resolve => server.close(resolve));
  }
});

