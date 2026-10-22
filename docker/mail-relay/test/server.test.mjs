import assert from 'node:assert/strict';
import {mkdtempSync, readFileSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import test, {after} from 'node:test';

const temporary = mkdtempSync(join(tmpdir(), 'longos-mail-relay-'));
const journal = join(temporary, 'receipts.jsonl');
Object.assign(process.env, {
  ALLOWED_SENDER_DOMAINS: 'example.com',
  CLOUD_MAIL_RELAY_SHARED_SECRET: 'cloud-mail-secret-0123456789abcdef',
  IDEMPOTENCY_STORE_PATH: journal,
  LONGOS_RELAY_SHARED_SECRET: 'longos-secret-0123456789abcdef0123',
  RELAY_NO_LISTEN: 'true',
});

const relay = await import('../src/server.mjs');

after(() => rmSync(temporary, {recursive: true, force: true}));

test('rejects invalid numeric limits instead of silently weakening them', () => {
  assert.equal(relay.positiveInt('', 25, 'TEST_LIMIT'), 25);
  assert.equal(relay.positiveInt('32', 25, 'TEST_LIMIT'), 32);
  assert.throws(() => relay.positiveInt('0', 25, 'TEST_LIMIT'), /TEST_LIMIT must be a positive integer/);
  assert.throws(() => relay.positiveInt('12mb', 25, 'TEST_LIMIT'), /TEST_LIMIT must be a positive integer/);
  assert.throws(() => relay.positiveInt('101', 25, 'TEST_LIMIT', 100), /no greater than 100/);
});

test('persists pending intent before acceptance and clears it with a durable receipt', () => {
  const key = 'longos:order-1';
  const hash = 'a'.repeat(64);
  relay.rememberPendingRequest(key, hash);
  assert.equal(relay.uncertainRequests.has(key), true);
  assert.match(readFileSync(journal, 'utf8'), /"state":"pending"/);

  relay.rememberCompletedRequest(key, {accepted: true, queueId: 'order-1'}, hash);
  assert.equal(relay.uncertainRequests.has(key), false);
  assert.match(readFileSync(journal, 'utf8'), /"state":"accepted"/);
});

test('a released failed submission no longer remains uncertain', () => {
  const key = 'longos:order-2';
  const hash = 'b'.repeat(64);
  relay.rememberPendingRequest(key, hash);
  relay.releasePendingRequest(key, hash);
  assert.equal(relay.uncertainRequests.has(key), false);
  assert.match(readFileSync(journal, 'utf8'), /"state":"released"/);
});

test('rejects non-canonical base64 before allocating an attachment', () => {
  assert.throws(() => relay.normalizeMessage({
    requestId: 'invalid-attachment',
    senderContext: {source: 'longos'},
    from: {address: 'no-reply@example.com'},
    to: ['customer@example.net'],
    subject: 'Receipt',
    text: 'Body',
    attachments: [{filename: 'bad.txt', content: 'YQ==='}],
  }), /Invalid base64 attachment/);
});

test('reports component health, queue capacity and receipt capacity without message data', () => {
  const payload = relay.healthPayload({
    healthy: false,
    queueDepth: 12,
    components: {postfix: true, opendkim: false, queue: true, receiptStore: true},
  });

  assert.equal(payload.status, 'degraded');
  assert.equal(payload.queueDepth, 12);
  assert.equal(payload.queueLimit, 1000);
  assert.equal(payload.components.postfix, 'ok');
  assert.equal(payload.components.opendkim, 'degraded');
  assert.equal(typeof payload.idempotencyCapacity, 'number');
  assert.equal(Object.hasOwn(payload, 'messages'), false);
  assert.equal(Object.hasOwn(payload, 'recipients'), false);
});
