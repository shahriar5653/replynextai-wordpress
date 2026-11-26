const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { runInNewContext } = require('node:vm');

const script = readFileSync(resolve(__dirname, '../assets/admin.js'), 'utf8');
function adminFixture(responses, phase = 'idle') {
  const label = { textContent: 'Last sync completed: 12 products.' };
  const spinner = { hidden: true };
  const button = { dataset: { available: '1', busyLabel: 'Syncing products…', idleLabel: 'Sync Products Now' }, setAttribute() {} };
  const form = { querySelector: () => ({ value: 'local-test-nonce' }), addEventListener(_event, listener) { this.submit = listener; } };
  const card = { dataset: { ajaxUrl: 'https://store.example/wp-admin/admin-ajax.php', phase, marker: 'before', starting: 'Starting…', unreachable: 'Status temporarily unavailable', expired: 'Session expired', waiting: 'Taking longer than expected' }, querySelector(selector) { return { form, '[data-rn-sync-submit]': button, '[data-rn-sync-spinner]': spinner, '[data-rn-sync-message]': label }[selector]; } };
  const timers = new Map();
  const calls = [];
  let timerId = 0;
  let prevents = 0;
  const fetch = async (url, options) => {
    calls.push({ url, options, params: Object.fromEntries(options.body) });
    const next = responses.shift();
    if (next instanceof Error) throw next;
    if (!next) throw new Error('Unexpected extra request');
    return { status: next.status || 200, json: async () => ({ success: !next.status, data: next }) };
  };
  const document = { querySelector: selector => selector === '[data-rn-catalog-sync]' ? card : null, querySelectorAll: () => [], addEventListener(_event, listener) { listener(); } };
  runInNewContext(script, { window: { fetch, URLSearchParams }, document, fetch, URLSearchParams, setTimeout: fn => { const id = ++timerId; timers.set(id, fn); return id; }, clearTimeout: id => timers.delete(id) });
  return { card, button, spinner, label, timers, calls, prevents: () => prevents,
    click: () => form.submit({ preventDefault() { prevents++; } }),
    async tick() { const [id, fn] = timers.entries().next().value; timers.delete(id); await fn(); },
  };
}

test('sync stays in the card, disables duplicate starts and displays the new count', async () => {
  const ui = adminFixture([
    { phase: 'queued', message: 'Scheduled', marker: 'before' },
    { phase: 'running', message: 'Syncing' },
    { phase: 'completed', message: 'Last sync completed: 14 products.', marker: 'after' },
  ]);
  const active = ui.click();
  await ui.click();
  await active;
  assert.equal(ui.prevents(), 2);
  assert.equal(ui.calls.length, 1);
  assert.equal(ui.spinner.hidden, false);
  assert.equal(ui.button.disabled, true);
  assert.equal(ui.label.textContent, 'Scheduled');
  await ui.tick();
  assert.equal(ui.label.textContent, 'Syncing');
  assert.equal(ui.calls[1].params.marker, 'before');
  assert.equal(ui.calls[1].params.action, 'replynextai_sync_products_step');
  assert.equal(ui.calls[1].params._ajax_nonce, 'local-test-nonce');
  assert.equal(ui.calls[1].options.credentials, 'same-origin');
  await ui.tick();
  assert.equal(ui.label.textContent, 'Last sync completed: 14 products.');
  assert.equal(ui.button.disabled, false);
  assert.equal(ui.spinner.hidden, true);
  assert.equal(ui.timers.size, 0);
});

test('opening an active card resumes the job without submitting a new start', async () => {
  const ui = adminFixture([{ phase: 'failed', status: 403 }], 'running');
  assert.equal(ui.spinner.hidden, false);
  await ui.tick();
  assert.equal(ui.calls[0].params.action, 'replynextai_sync_products_step');
  assert.equal(ui.label.textContent, 'Session expired');
  assert.equal(ui.button.disabled, false);
  assert.equal(ui.timers.size, 0);
});

test('lost start response recovers through status before running another batch', async () => {
  const ui = adminFixture([
    new Error('timeout'),
    { phase: 'queued', message: 'Not observed yet', marker: 'before' },
    { phase: 'queued', message: '100 synced', marker: 'batch-one' },
    { phase: 'completed', message: '101 synced', marker: 'done' },
  ]);
  await ui.click();
  await ui.tick(); await ui.tick(); await ui.tick();
  assert.deepEqual(ui.calls.map(call => call.params.action), [
    'replynextai_sync_products', 'replynextai_sync_products_status',
    'replynextai_sync_products_status', 'replynextai_sync_products_step',
  ]);
  assert.equal(ui.calls[3].params.marker, 'batch-one');
  assert.equal(ui.label.textContent, '101 synced');
});

test('network failures stop the loader without claiming completion or repeating the start', async () => {
  const ui = adminFixture([
    { phase: 'queued', message: 'Scheduled' }, new Error('offline'), new Error('offline'), new Error('offline'),
  ]);
  await ui.click();
  await ui.tick(); await ui.tick(); await ui.tick();
  assert.equal(ui.label.textContent, 'Status temporarily unavailable');
  assert.equal(ui.spinner.hidden, true);
  assert.equal(ui.timers.size, 0);
  assert.equal(ui.calls.filter(call => call.params.action === 'replynextai_sync_products').length, 1);
});
