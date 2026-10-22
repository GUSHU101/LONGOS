const assert = require('node:assert/strict');
const test = require('node:test');
const {Window} = require('happy-dom');

const scriptPath = require.resolve('../resources/beike/shop/default/js/order-actions.js');

function boot(markup, post) {
  const window = new Window({url: 'https://shop.example.test/account/orders'});
  window.document.body.innerHTML = `${markup}<div id="storefront-feedback" role="status"></div>`;
  window.confirm = () => true;
  window.setTimeout = () => 1;
  window.$http = {post};
  window.layer = {msg: () => {}};

  global.window = window;
  global.document = window.document;
  Object.defineProperty(global, 'navigator', {configurable: true, value: window.navigator});
  delete require.cache[scriptPath];
  require(scriptPath);

  return window;
}

const settle = () => new Promise((resolve) => setImmediate(resolve));

test('order action asks for confirmation and exposes a busy state', async () => {
  let requests = 0;
  const window = boot(
    '<button data-order-action="cancel" data-action-url="/orders/1/cancel" data-confirm="Confirm" data-processing="Working">Cancel</button>',
    async (url) => {
      requests += 1;
      assert.equal(url, '/orders/1/cancel');
      return {message: 'Updated'};
    },
  );
  const button = window.document.querySelector('button');

  button.click();
  assert.equal(button.getAttribute('aria-busy'), 'true');
  assert.equal(button.textContent, 'Working');
  await settle();

  assert.equal(requests, 1);
  assert.equal(window.document.querySelector('#storefront-feedback').textContent, 'Updated');
});

test('cancelled confirmation does not send a request', async () => {
  let requests = 0;
  const window = boot(
    '<button data-order-action="complete" data-action-url="/orders/1/complete" data-confirm="Confirm">Complete</button>',
    async () => { requests += 1; },
  );
  window.confirm = () => false;

  window.document.querySelector('button').click();
  await settle();

  assert.equal(requests, 0);
});

test('failed order action restores the control and announces the error', async () => {
  const window = boot(
    '<button data-order-action="cancel" data-action-url="/orders/1/cancel" data-error="Try again">Cancel</button>',
    async () => Promise.reject(new Error('offline')),
  );
  const button = window.document.querySelector('button');

  button.click();
  await settle();

  assert.equal(button.disabled, false);
  assert.equal(button.hasAttribute('aria-busy'), false);
  assert.equal(button.textContent, 'Cancel');
  assert.equal(window.document.querySelector('#storefront-feedback').textContent, 'Try again');
});

test('copy order number announces completion', async () => {
  let copied = '';
  const window = boot(
    '<button data-copy-order="202610220001" data-copy-success="Copied">Copy</button>',
    async () => ({}),
  );
  Object.defineProperty(window.navigator, 'clipboard', {
    configurable: true,
    value: {writeText: async (value) => { copied = value; }},
  });

  window.document.querySelector('button').click();
  await settle();

  assert.equal(copied, '202610220001');
  assert.equal(window.document.querySelector('#storefront-feedback').textContent, 'Copied');
});
