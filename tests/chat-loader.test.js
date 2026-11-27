const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { test } = require('node:test');
const { runInNewContext } = require('node:vm');

const loader = readFileSync(require.resolve('../assets/replynextai-chat-loader.js'), 'utf8');
const config = { widgetId: 'public-widget-id', scriptUrl: 'https://replynextai.com/chat-widget.js?ver=1.4.8' };

function fixture({ data = config, readyState = 'complete', embeds = [], initialized = false } = {}) {
  const appended = [];
  const ready = [];
  const document = {
    readyState, baseURI: 'https://store.example/',
    // Simulates a combined loader with no script tag/configuration of its own.
    currentScript: null,
    querySelector(selector) {
      assert.equal(selector, 'script#replynextai-wordpress-widget-config[type="application/json"]');
      return data === null ? null : { textContent: typeof data === 'string' ? data : JSON.stringify(data) };
    },
    querySelectorAll() { return embeds; },
    getElementById(id) { return appended.find(script => script.id === id); },
    createElement(tag) {
      assert.equal(tag, 'script');
      return { attributes: {}, setAttribute(name, value) { this.attributes[name] = value; } };
    },
    addEventListener(event, callback, options) {
      assert.equal(event, 'DOMContentLoaded');
      assert.equal(options.once, true);
      ready.push(callback);
    },
    head: { appendChild(script) { appended.push(script); } },
  };
  const window = { __rn_chat_initialized: initialized };
  return { execute: () => runInNewContext(loader, { document, window, URL }), appended, ready };
}

test('combined loader works before the footer config is parsed and injects hosted chat only once', () => {
  const { execute, appended, ready } = fixture({ readyState: 'loading' });
  execute();
  execute();
  assert.equal(appended.length, 0);
  ready.forEach(callback => callback());
  assert.equal(appended.length, 1);
  assert.equal(appended[0].src, config.scriptUrl);
  assert.equal(appended[0].async, true);
  assert.equal(appended[0].attributes['data-widget-id'], config.widgetId);
  assert.equal(appended[0].attributes['data-is-user'], 'false');
  assert.equal(appended[0].attributes['data-visitor-email'], undefined);
});

test('connected custom service and opted-in visitor data use the existing embed attributes', () => {
  const src = 'https://assistant.example/service/chat-widget.js?ver=1.4.8';
  const { execute, appended } = fixture({ data: { ...config, scriptUrl: src, visitor: { name: 'Test User', email: 'test@example.org' } } });
  execute();
  assert.equal(appended[0].src, src);
  assert.equal(appended[0].attributes['data-visitor-name'], 'Test User');
  assert.equal(appended[0].attributes['data-visitor-email'], 'test@example.org');
  assert.equal(appended[0].attributes['data-is-user'], 'true');
});

test('an existing legacy embed or already initialized chat does not get a second script', () => {
  for (const options of [{ embeds: [{ src: 'https://replynextai.com/chat-widget.js?ver=1.4.7' }] }, { initialized: true }]) {
    const { execute, appended } = fixture(options);
    execute();
    assert.equal(appended.length, 0);
  }
});

test('non-executable optimized tags and broken script URLs do not block the loader', () => {
  const { execute, appended } = fixture({ embeds: [{ src: config.scriptUrl, type: 'litespeed/javascript' }, { src: 'https://[' }] });
  execute();
  assert.equal(appended.length, 1);
});

test('missing, invalid and unsafe configuration fails quietly without network work', () => {
  for (const data of [null, 'not JSON', {}, { ...config, scriptUrl: 'javascript:alert(1)' }, { ...config, scriptUrl: 'https://replynextai.com/tracker.js' }]) {
    const { execute, appended } = fixture({ data });
    execute();
    assert.equal(appended.length, 0);
  }
});
