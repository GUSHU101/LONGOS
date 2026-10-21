import http from 'node:http';
import crypto from 'node:crypto';
import {
  accessSync,
  closeSync,
  constants as fsConstants,
  existsSync,
  fsyncSync,
  mkdirSync,
  openSync,
  readFileSync,
  renameSync,
  unlinkSync,
  writeSync,
} from 'node:fs';
import {basename, dirname} from 'node:path';
import {spawnSync} from 'node:child_process';
import nodemailer from 'nodemailer';
import {verifySignedRequest} from './security.mjs';

function positiveInt(value, fallback, field = 'value', maximum = Number.MAX_SAFE_INTEGER) {
  const raw = String(value ?? '').trim();
  if (raw === '') return fallback;
  if (!/^\d+$/.test(raw)) throw new Error(`${field} must be a positive integer`);
  const parsed = Number(raw);
  if (!Number.isSafeInteger(parsed) || parsed <= 0 || parsed > maximum) {
    throw new Error(`${field} must be a positive integer no greater than ${maximum}`);
  }
  return parsed;
}

const port = positiveInt(process.env.PORT, 3000, 'PORT', 65_535);
const clientSecrets = new Map([
  ['longos', process.env.LONGOS_RELAY_SHARED_SECRET || ''],
  ['cloud-mail', process.env.CLOUD_MAIL_RELAY_SHARED_SECRET || ''],
].filter(([, secret]) => secret));
const maxRequestBytes = positiveInt(process.env.MAX_REQUEST_BYTES, 25 * 1024 * 1024, 'MAX_REQUEST_BYTES');
const maxAttachmentBytes = positiveInt(process.env.MAX_ATTACHMENT_BYTES, 20 * 1024 * 1024, 'MAX_ATTACHMENT_BYTES');
const maxAttachments = positiveInt(process.env.MAX_ATTACHMENTS, 25, 'MAX_ATTACHMENTS');
const maxRecipients = positiveInt(process.env.MAX_RECIPIENTS, 50, 'MAX_RECIPIENTS');
const maxRequestsPerMinute = positiveInt(process.env.MAX_REQUESTS_PER_MINUTE, 120, 'MAX_REQUESTS_PER_MINUTE');
const maxRequestsPerMinuteGlobal = positiveInt(process.env.MAX_REQUESTS_PER_MINUTE_GLOBAL, maxRequestsPerMinute * 10, 'MAX_REQUESTS_PER_MINUTE_GLOBAL');
const signatureWindowSeconds = positiveInt(process.env.SIGNATURE_WINDOW_SECONDS, 300, 'SIGNATURE_WINDOW_SECONDS');
const maxNonceRecords = positiveInt(process.env.MAX_NONCE_RECORDS, Math.max(10_000, maxRequestsPerMinuteGlobal * 10), 'MAX_NONCE_RECORDS');
const idempotencyRetentionSeconds = positiveInt(process.env.IDEMPOTENCY_RETENTION_SECONDS, Math.max(86_400, signatureWindowSeconds * 2), 'IDEMPOTENCY_RETENTION_SECONDS');
const maxIdempotencyRecords = positiveInt(process.env.MAX_IDEMPOTENCY_RECORDS, 100_000, 'MAX_IDEMPOTENCY_RECORDS');
const maxQueueMessages = positiveInt(process.env.MAX_QUEUE_MESSAGES, 1000, 'MAX_QUEUE_MESSAGES');
const idempotencyStorePath = String(process.env.IDEMPOTENCY_STORE_PATH || '').trim();

function normalizeDomain(value, field = 'domain') {
  const domain = String(value ?? '').trim().toLowerCase().replace(/\.$/, '');
  if (domain.length === 0 || domain.length > 253 || !domain.includes('.')
    || !domain.split('.').every(label => /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test(label))) {
    throw new Error(`Invalid ${field}`);
  }
  return domain;
}

const allowedSenderDomains = new Set((process.env.ALLOWED_SENDER_DOMAINS || '').split(',')
  .filter(value => value.trim() !== '')
  .map(value => normalizeDomain(value, 'ALLOWED_SENDER_DOMAINS')));

if (clientSecrets.size !== 2) throw new Error('Both longos and cloud-mail relay secrets must be configured');
for (const [client, secret] of clientSecrets) {
  if (secret.length < 32) throw new Error(`${client} relay secret must contain at least 32 characters`);
}
if (clientSecrets.has('longos') && clientSecrets.has('cloud-mail')
  && clientSecrets.get('longos') === clientSecrets.get('cloud-mail')) {
  throw new Error('longos and cloud-mail relay secrets must be different');
}
if (allowedSenderDomains.size === 0) throw new Error('ALLOWED_SENDER_DOMAINS must contain at least one domain');
if (!idempotencyStorePath) throw new Error('IDEMPOTENCY_STORE_PATH is required for restart-safe delivery');
if (maxAttachmentBytes > maxRequestBytes) throw new Error('MAX_ATTACHMENT_BYTES must not exceed MAX_REQUEST_BYTES');
if (idempotencyRetentionSeconds < signatureWindowSeconds * 2) {
  throw new Error('IDEMPOTENCY_RETENTION_SECONDS must be at least twice SIGNATURE_WINDOW_SECONDS');
}

const transporter = nodemailer.createTransport({sendmail: true, newline: 'unix', path: '/usr/sbin/sendmail'});
const usedNonces = new Map();
const completedRequests = new Map();
const uncertainRequests = new Map();
const inFlightRequests = new Map();
const rateBuckets = new Map();
let globalRateBucket = {minute: -1, count: 0};
let receiptStoreHealthy = true;
let lastReceiptCompaction = 0;
let lastHealthCheck = 0;
let cachedHealth = {healthy: false, queueDepth: maxQueueMessages + 1};

function receiptCapacityAvailable(key = '') {
  return completedRequests.has(key)
    || uncertainRequests.has(key)
    || completedRequests.size + uncertainRequests.size < maxIdempotencyRecords;
}

function assertReceiptCapacity(key) {
  if (!receiptCapacityAvailable(key)) {
    throw Object.assign(new Error('Relay idempotency capacity is exhausted'), {status: 503});
  }
}

function fsyncDirectory(path) {
  let descriptor;
  try {
    descriptor = openSync(path, 'r');
    fsyncSync(descriptor);
  } catch {
    // Some filesystems do not support fsync on directories. The receipt file
    // itself is still fsynced before the atomic rename.
  } finally {
    if (descriptor !== undefined) closeSync(descriptor);
  }
}

function appendReceiptRecord(record) {
  if (!idempotencyStorePath) return;
  mkdirSync(dirname(idempotencyStorePath), {recursive: true, mode: 0o700});
  let descriptor;
  try {
    descriptor = openSync(idempotencyStorePath, 'a', 0o600);
    writeSync(descriptor, `${JSON.stringify(record)}\n`, null, 'utf8');
    fsyncSync(descriptor);
    receiptStoreHealthy = true;
  } catch (error) {
    receiptStoreHealthy = false;
    throw new Error('Unable to persist the relay idempotency journal', {cause: error});
  } finally {
    if (descriptor !== undefined) closeSync(descriptor);
  }
}

function compactReceiptStore(force = false) {
  if (!idempotencyStorePath || (!force && Date.now() - lastReceiptCompaction < 3_600_000)) return;
  const tempPath = `${idempotencyStorePath}.${process.pid}.tmp`;
  const records = [
    ...[...completedRequests].map(([key, item]) => ({key, state: 'accepted', ...item})),
    ...[...uncertainRequests].map(([key, item]) => ({key, state: 'pending', ...item})),
  ].sort((left, right) => Number(left.createdAt) - Number(right.createdAt));
  const lines = records.map(item => JSON.stringify(item));
  try {
    let descriptor;
    try {
      descriptor = openSync(tempPath, 'wx', 0o600);
      writeSync(descriptor, lines.length ? `${lines.join('\n')}\n` : '', null, 'utf8');
      fsyncSync(descriptor);
    } finally {
      if (descriptor !== undefined) closeSync(descriptor);
    }
    renameSync(tempPath, idempotencyStorePath);
    fsyncDirectory(dirname(idempotencyStorePath));
    lastReceiptCompaction = Date.now();
    receiptStoreHealthy = true;
  } catch (error) {
    try { unlinkSync(tempPath); } catch {}
    receiptStoreHealthy = false;
    console.error('Unable to compact the relay idempotency store', error);
  }
}

function initializeReceiptStore() {
  if (!idempotencyStorePath) return;
  try {
    mkdirSync(dirname(idempotencyStorePath), {recursive: true, mode: 0o700});
    if (existsSync(idempotencyStorePath)) {
      const oldest = Date.now() - idempotencyRetentionSeconds * 1000;
      for (const line of readFileSync(idempotencyStorePath, 'utf8').split('\n')) {
        if (!line) continue;
        try {
          const item = JSON.parse(line);
          if (typeof item.key !== 'string' || Number(item.createdAt) < oldest) continue;
          const requestHash = /^[a-f0-9]{64}$/.test(item.requestHash || '') ? item.requestHash : '';
          const state = item.state || (item.body?.accepted === true ? 'accepted' : '');
          completedRequests.delete(item.key);
          uncertainRequests.delete(item.key);
          if (state === 'accepted' && item.body?.accepted === true) {
            assertReceiptCapacity(item.key);
            completedRequests.set(item.key, {createdAt: Number(item.createdAt), body: item.body, requestHash});
          } else if (state === 'pending') {
            assertReceiptCapacity(item.key);
            uncertainRequests.set(item.key, {createdAt: Number(item.createdAt), requestHash});
          }
        } catch {
          // A partial final line can remain after a hard power loss. Earlier
          // complete receipts remain usable and compaction removes the line.
        }
      }
    }
    compactReceiptStore(true);
  } catch (error) {
    throw new Error('Unable to initialize the relay idempotency store', {cause: error});
  }
}

function rememberPendingRequest(key, requestHash = '') {
  assertReceiptCapacity(key);
  const item = {createdAt: Date.now(), requestHash};
  appendReceiptRecord({key, state: 'pending', ...item});
  completedRequests.delete(key);
  uncertainRequests.delete(key);
  uncertainRequests.set(key, item);
}

function rememberCompletedRequest(key, body, requestHash = '') {
  assertReceiptCapacity(key);
  const item = {createdAt: Date.now(), body, requestHash};
  appendReceiptRecord({key, state: 'accepted', ...item});
  completedRequests.delete(key);
  completedRequests.set(key, item);
  uncertainRequests.delete(key);
}

function releasePendingRequest(key, requestHash = '') {
  appendReceiptRecord({key, state: 'released', createdAt: Date.now(), requestHash});
  uncertainRequests.delete(key);
}

initializeReceiptStore();

function json(response, status, body) {
  response.writeHead(status, {'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store', 'x-content-type-options': 'nosniff'});
  response.end(JSON.stringify(body));
}

function globalRateLimitExceeded() {
  const minute = Math.floor(Date.now() / 60_000);
  if (globalRateBucket.minute !== minute) globalRateBucket = {minute, count: 0};
  globalRateBucket.count += 1;
  return globalRateBucket.count > maxRequestsPerMinuteGlobal;
}

function senderRateLimitExceeded(key) {
  const minute = Math.floor(Date.now() / 60_000);
  const bucket = rateBuckets.get(key);
  if (!bucket || bucket.minute !== minute) {
    rateBuckets.set(key, {minute, count: 1});
    return false;
  }
  bucket.count += 1;
  return bucket.count > maxRequestsPerMinute;
}

async function readBody(request) {
  const chunks = [];
  let size = 0;
  for await (const chunk of request) {
    size += chunk.length;
    if (size > maxRequestBytes) throw Object.assign(new Error('Request body is too large'), {status: 413});
    chunks.push(chunk);
  }
  return Buffer.concat(chunks).toString('utf8');
}

function cleanHeader(value, field, maxLength = 998) {
  const normalized = String(value ?? '').trim();
  if (normalized.length > maxLength || /[\r\n]/.test(normalized)) throw Object.assign(new Error(`Invalid ${field}`), {status: 400});
  return normalized;
}

function normalizeAddress(value, field) {
  const address = cleanHeader(value, field, 320);
  const at = address.lastIndexOf('@');
  const local = at < 1 ? '' : address.slice(0, at);
  let domain;
  try { domain = normalizeDomain(at < 0 ? '' : address.slice(at + 1), field); } catch {
    throw Object.assign(new Error(`Invalid ${field}`), {status: 400});
  }
  if (local.length > 64 || local.startsWith('.') || local.endsWith('.') || local.includes('..')
    || !/^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+$/.test(local)) {
    throw Object.assign(new Error(`Invalid ${field}`), {status: 400});
  }
  return `${local}@${domain}`;
}

function normalizeAddressList(value, field, required = false) {
  if (value === undefined && !required) return [];
  if (!Array.isArray(value) || (required && value.length === 0)) throw Object.assign(new Error(`Invalid ${field} list`), {status: 400});
  return [...new Set(value.map(address => normalizeAddress(address, field)))];
}

function normalizeSenderContext(value) {
  if (value === undefined) return {source: 'unknown', tenantId: '', tenantCode: '', accountId: '', category: ''};
  if (!value || typeof value !== 'object' || Array.isArray(value)) throw Object.assign(new Error('Invalid senderContext'), {status: 400});
  const source = cleanHeader(value.source || 'unknown', 'senderContext.source', 32);
  if (!['longos', 'cloud-mail', 'unknown'].includes(source)) throw Object.assign(new Error('Invalid senderContext.source'), {status: 400});
  return {
    source,
    tenantId: cleanHeader(value.tenantId || '', 'senderContext.tenantId', 128),
    tenantCode: cleanHeader(value.tenantCode || '', 'senderContext.tenantCode', 128),
    accountId: cleanHeader(value.accountId || '', 'senderContext.accountId', 128),
    category: cleanHeader(value.category || '', 'senderContext.category', 64),
  };
}

function normalizeMessage(body) {
  if (!body || typeof body !== 'object' || Array.isArray(body)) throw Object.assign(new Error('JSON object expected'), {status: 400});
  const requestId = cleanHeader(body.requestId || crypto.randomUUID(), 'requestId', 128);
  if (!/^[a-zA-Z0-9._:-]+$/.test(requestId)) throw Object.assign(new Error('Invalid requestId'), {status: 400});
  const fromAddress = normalizeAddress(body.from?.address, 'from address');
  const fromDomain = fromAddress.split('@')[1];
  if (!allowedSenderDomains.has(fromDomain)) throw Object.assign(new Error('Sender domain is not allowed'), {status: 403});
  const to = normalizeAddressList(body.to, 'to recipient');
  const cc = normalizeAddressList(body.cc, 'cc recipient');
  const bcc = normalizeAddressList(body.bcc, 'bcc recipient');
  const allRecipients = [...new Set([...to, ...cc, ...bcc])];
  if (allRecipients.length === 0 || allRecipients.length > maxRecipients) throw Object.assign(new Error(`Recipient count must be between 1 and ${maxRecipients}`), {status: 400});
  if (body.headers !== undefined && (!body.headers || typeof body.headers !== 'object' || Array.isArray(body.headers))) {
    throw Object.assign(new Error('Headers must be an object'), {status: 400});
  }
  const headers = {};
  for (const [name, value] of Object.entries(body.headers || {})) {
    const lower = name.toLowerCase();
    if (['in-reply-to', 'references', 'reply-to'].includes(lower)) headers[lower] = cleanHeader(value, lower);
  }
  if (body.attachments !== undefined && !Array.isArray(body.attachments)) throw Object.assign(new Error('Attachments must be an array'), {status: 400});
  if ((body.attachments || []).length > maxAttachments) throw Object.assign(new Error(`Attachment count must not exceed ${maxAttachments}`), {status: 400});
  let attachmentBytes = 0;
  const attachments = (body.attachments || []).map((attachment, index) => {
    if (!attachment || typeof attachment !== 'object' || typeof attachment.content !== 'string') throw Object.assign(new Error(`Invalid attachment at index ${index}`), {status: 400});
    const encoded = attachment.content.replace(/\s/g, '');
    if (!/^[a-zA-Z0-9+/]*={0,2}$/.test(encoded) || encoded.length % 4 !== 0) throw Object.assign(new Error(`Invalid base64 attachment at index ${index}`), {status: 400});
    const padding = encoded.endsWith('==') ? 2 : encoded.endsWith('=') ? 1 : 0;
    const decodedBytes = encoded.length / 4 * 3 - padding;
    if (attachmentBytes + decodedBytes > maxAttachmentBytes) throw Object.assign(new Error('Attachments are too large'), {status: 413});
    const content = Buffer.from(encoded, 'base64');
    if (content.toString('base64') !== encoded) throw Object.assign(new Error(`Invalid base64 attachment at index ${index}`), {status: 400});
    attachmentBytes += content.length;
    if (attachmentBytes > maxAttachmentBytes) throw Object.assign(new Error('Attachments are too large'), {status: 413});
    return {filename: cleanHeader(basename(attachment.filename || `attachment-${index + 1}`), 'filename', 255), content, contentType: cleanHeader(attachment.contentType || 'application/octet-stream', 'content type', 200), cid: attachment.contentId ? cleanHeader(attachment.contentId, 'content id', 255) : undefined, contentDisposition: attachment.disposition === 'inline' ? 'inline' : 'attachment'};
  });
  // Treat JSON null like an omitted MIME alternative. Older LONGOS clients
  // serialized Symfony's absent text/HTML body as null, and rejecting that
  // made otherwise valid plain-text mail fail at the relay boundary.
  if (body.text != null && typeof body.text !== 'string') throw Object.assign(new Error('Message text must be a string'), {status: 400});
  if (body.html != null && typeof body.html !== 'string') throw Object.assign(new Error('Message HTML must be a string'), {status: 400});
  if (!body.text && !body.html) throw Object.assign(new Error('Message text or HTML is required'), {status: 400});
  const senderContext = normalizeSenderContext(body.senderContext);
  return {
    requestId,
    senderContext,
    // Context improves auditability but the rate key is anchored to the
    // authorized sender address so clients cannot bypass limits by rotating a
    // caller-supplied tenant/account identifier.
    rateKey: `${senderContext.source}:${fromAddress}`,
    mail: {
      from: {address: fromAddress, name: cleanHeader(body.from?.name || '', 'from name', 200)},
      envelope: {from: fromAddress, to: allRecipients},
      to,
      cc,
      bcc,
      subject: cleanHeader(body.subject, 'subject'),
      text: body.text || undefined,
      html: body.html || undefined,
      headers,
      attachments,
      messageId: `<${requestId}@${fromDomain}>`,
    },
  };
}

function cleanupReplayState() {
  const oldestNonce = Date.now() - signatureWindowSeconds * 2 * 1000;
  const oldestReceipt = Date.now() - idempotencyRetentionSeconds * 1000;
  for (const [key, createdAt] of usedNonces) if (createdAt < oldestNonce) usedNonces.delete(key);
  for (const [key, item] of completedRequests) if (item.createdAt < oldestReceipt) completedRequests.delete(key);
  for (const [key, item] of uncertainRequests) if (item.createdAt < oldestReceipt) uncertainRequests.delete(key);
  compactReceiptStore();
  const currentMinute = Math.floor(Date.now() / 60_000);
  for (const [key, bucket] of rateBuckets) if (bucket.minute < currentMinute - 1) rateBuckets.delete(key);
}

async function handleSend(request, response) {
  const rawBody = await readBody(request);
  const relayClient = cleanHeader(request.headers['x-relay-client'] || '', 'relay client', 32);
  const secret = clientSecrets.get(relayClient);
  if (!secret) return json(response, 401, {accepted: false, message: 'Unknown relay client'});
  const valid = verifySignedRequest({secret, timestamp: request.headers['x-relay-timestamp'], nonce: request.headers['x-relay-nonce'], signature: request.headers['x-relay-signature'], rawBody, windowSeconds: signatureWindowSeconds, usedNonces, nonceScope: relayClient, maxNonceRecords});
  if (!valid) return json(response, 401, {accepted: false, message: 'Invalid or expired signature'});
  // Count every authenticated attempt before JSON parsing or attachment
  // decoding, so malformed signed traffic cannot bypass global backpressure.
  if (globalRateLimitExceeded()) return json(response, 429, {accepted: false, message: 'Global rate limit exceeded'});
  let parsed;
  try { parsed = JSON.parse(rawBody); } catch { return json(response, 400, {accepted: false, message: 'Invalid JSON'}); }
  const {requestId, mail, senderContext, rateKey} = normalizeMessage(parsed);
  if (senderContext.source !== relayClient) {
    return json(response, 403, {accepted: false, message: 'Relay client does not match sender context'});
  }
  // Idempotency belongs to the authenticated client. A second authorized
  // client must not be able to suppress delivery by reusing a request ID.
  const requestKey = `${relayClient}:${requestId}`;
  const requestHash = crypto.createHash('sha256').update(rawBody).digest('hex');
  const previous = completedRequests.get(requestKey);
  if (previous) {
    if (previous.requestHash && previous.requestHash !== requestHash) {
      return json(response, 409, {accepted: false, message: 'Request ID was already used for different content'});
    }
    return json(response, 202, previous.body);
  }
  const uncertain = uncertainRequests.get(requestKey);
  if (uncertain) {
    const message = uncertain.requestHash && uncertain.requestHash !== requestHash
      ? 'Request ID was already used for different content'
      : 'Previous delivery status is uncertain; the relay refused an automatic duplicate';
    return json(response, 409, {accepted: false, retryable: false, message});
  }
  if (inFlightRequests.has(requestKey)) {
    const message = inFlightRequests.get(requestKey) === requestHash
      ? 'Request is already being processed'
      : 'Request ID is already processing different content';
    return json(response, 409, {accepted: false, message});
  }
  if (senderRateLimitExceeded(rateKey)) return json(response, 429, {accepted: false, message: 'Sender rate limit exceeded'});
  const operational = relayHealth();
  if (!operational.healthy) {
    return json(response, 503, {accepted: false, retryable: true, message: 'Mail relay is not ready'});
  }
  // Persist intent before invoking sendmail. If the process or host dies after
  // Postfix accepts the message but before the final receipt is written, a
  // restart will fail closed instead of delivering the same request twice.
  rememberPendingRequest(requestKey, requestHash);
  inFlightRequests.set(requestKey, requestHash);
  let acceptedByPostfix = false;
  try {
    const info = await transporter.sendMail(mail);
    acceptedByPostfix = true;
    const result = {accepted: true, queueId: requestId, messageId: info.messageId || mail.messageId};
    rememberCompletedRequest(requestKey, result, requestHash);
    console.info(JSON.stringify({
      event: 'mail_queued',
      queueId: requestId,
      recipients: mail.envelope.to.length,
      source: senderContext.source,
      tenantCode: senderContext.tenantCode || undefined,
      accountId: senderContext.accountId || undefined,
      category: senderContext.category || undefined,
      senderDomain: mail.from.address.split('@')[1],
    }));
    return json(response, 202, result);
  } catch (error) {
    if (!acceptedByPostfix) {
      // Nodemailer did not receive successful local sendmail completion, so a
      // retry is safe once the release tombstone is durable.
      releasePendingRequest(requestKey, requestHash);
    }
    throw error;
  } finally { inFlightRequests.delete(requestKey); }
}

function relayHealth(force = false) {
  if (!force && Date.now() - lastHealthCheck < 5000) return cachedHealth;
  let storeWritable = true;
  try {
    if (idempotencyStorePath) accessSync(dirname(idempotencyStorePath), fsConstants.W_OK);
  } catch {
    storeWritable = false;
  }
  const postfix = spawnSync('postfix', ['status'], {stdio: 'ignore', timeout: 3000});
  const opendkim = spawnSync('pgrep', ['-x', 'opendkim'], {stdio: 'ignore', timeout: 3000});
  const queue = spawnSync('postqueue', ['-j'], {encoding: 'utf8', timeout: 5000, maxBuffer: 4 * 1024 * 1024});
  const queueDepth = queue.status === 0
    ? String(queue.stdout || '').split('\n').filter(line => line.trim() !== '').length
    : maxQueueMessages + 1;
  const healthy = postfix.status === 0
    && opendkim.status === 0
    && queue.status === 0
    && queueDepth <= maxQueueMessages
    && receiptCapacityAvailable()
    && receiptStoreHealthy
    && storeWritable;
  lastHealthCheck = Date.now();
  cachedHealth = {healthy, queueDepth};
  return cachedHealth;
}

const server = http.createServer(async (request, response) => {
  try {
    if (request.method === 'GET' && request.url === '/health') {
      const health = relayHealth(true);
      return json(response, health.healthy ? 200 : 503, {
        status: health.healthy ? 'ok' : 'degraded',
        queueDepth: health.queueDepth,
        uncertainRequests: uncertainRequests.size,
      });
    }
    if (request.method === 'POST' && request.url === '/v1/send') return await handleSend(request, response);
    return json(response, 404, {message: 'Not found'});
  } catch (error) {
    const status = Number.isInteger(error.status) ? error.status : 500;
    if (status >= 500) console.error(error);
    return json(response, status, {accepted: false, message: status >= 500 ? 'Relay submission failed' : error.message});
  } finally { cleanupReplayState(); }
});

// The relay is private, but a stalled Docker peer must not hold sockets and
// worker memory forever. Message submission has a stricter application-level
// request-size limit above; these values bound incomplete HTTP requests.
server.headersTimeout = positiveInt(process.env.HEADERS_TIMEOUT_MS, 10_000, 'HEADERS_TIMEOUT_MS');
server.requestTimeout = positiveInt(process.env.REQUEST_TIMEOUT_MS, 60_000, 'REQUEST_TIMEOUT_MS');
server.keepAliveTimeout = positiveInt(process.env.KEEP_ALIVE_TIMEOUT_MS, 5_000, 'KEEP_ALIVE_TIMEOUT_MS');
server.maxHeadersCount = positiveInt(process.env.MAX_HEADERS_COUNT, 48, 'MAX_HEADERS_COUNT');
server.maxRequestsPerSocket = positiveInt(process.env.MAX_REQUESTS_PER_SOCKET, 1000, 'MAX_REQUESTS_PER_SOCKET');

if (process.env.RELAY_NO_LISTEN !== 'true') {
  server.listen(port, '0.0.0.0', () => console.info(JSON.stringify({event: 'relay_started', port, allowedSenderDomains: [...allowedSenderDomains]})));
}
function shutdown() { server.close(() => process.exit(0)); setTimeout(() => process.exit(1), 10_000).unref(); }
process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);

export {
  normalizeMessage,
  positiveInt,
  rememberCompletedRequest,
  rememberPendingRequest,
  releasePendingRequest,
  server,
  uncertainRequests,
};
