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
