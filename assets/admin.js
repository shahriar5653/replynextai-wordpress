(function () {
  'use strict';

  function initCatalogSync() {
    var card = document.querySelector('[data-rn-catalog-sync]');
    if (!card || !window.fetch || !window.URLSearchParams) return;
    var form = card.querySelector('form');
    var button = card.querySelector('[data-rn-sync-submit]');
    var spinner = card.querySelector('[data-rn-sync-spinner]');
    var label = card.querySelector('[data-rn-sync-message]');
    var nonce = form && form.querySelector('input[name="_wpnonce"]');
    if (!button || !spinner || !label || !nonce || button.dataset.available !== '1') return;

    var marker = card.dataset.marker || '';
    var busy = false;
    var timer;
    var failures = 0;
    var startedAt = 0;
    var isPending = function (phase) { return phase === 'queued' || phase === 'running'; };

    function render(phase, message) {
      busy = isPending(phase);
      card.dataset.phase = phase;
      spinner.hidden = !busy;
      button.disabled = busy;
      button.setAttribute('aria-busy', String(busy));
      button.textContent = busy ? button.dataset.busyLabel : button.dataset.idleLabel;
      if (label.textContent !== message) label.textContent = message;
    }

    async function request(action, watchMarker) {
      var params = new URLSearchParams({ action: action, _ajax_nonce: nonce.value });
      if (watchMarker !== undefined) params.set('marker', watchMarker);
      var controller = window.AbortController ? new AbortController() : null;
      var timeout = controller && setTimeout(function () { controller.abort(); }, 12000);
      try {
        var response = await fetch(card.dataset.ajaxUrl, { method: 'POST', body: params, credentials: 'same-origin', mode: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller ? controller.signal : undefined });
        if (response.status === 401 || response.status === 403) return { phase: 'failed', message: card.dataset.expired };
        var json = await response.json();
        var data = json && json.data;
        if (!data || !['queued', 'running', 'completed', 'failed'].includes(data.phase) || typeof data.message !== 'string') throw new Error('Sync status unavailable');
        return data;
      } finally { if (timeout) clearTimeout(timeout); }
    }

    function follow(delay) {
      clearTimeout(timer);
      timer = setTimeout(async function () {
        if (Date.now() - startedAt >= 10 * 60 * 1000) {
          render('failed', card.dataset.waiting);
          return;
        }
        try {
          var result = await request('replynextai_sync_products_status', marker);
          failures = 0;
          render(result.phase, result.message);
          if (busy) follow(3000);
        } catch (error) {
          if (++failures < 3) follow(4000);
          else render('failed', card.dataset.unreachable);
        }
      }, delay);
    }

    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      if (busy) return;
      startedAt = Date.now();
      failures = 0;
      render('queued', card.dataset.starting);
      try {
        var result = await request('replynextai_sync_products');
        marker = typeof result.marker === 'string' ? result.marker : '';
        render(result.phase, result.message);
        if (busy) follow(1600);
      } catch (error) {
        // The server may have accepted the enqueue before the browser timed
        // out. Do not submit a second start request automatically.
        render('failed', card.dataset.unreachable);
      }
    });

    if (isPending(card.dataset.phase)) {
      startedAt = Date.now();
      render(card.dataset.phase, label.textContent);
      follow(1600);
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    initCatalogSync();
    var wrap = document.querySelector('.rn-wrap');
    var header = wrap ? wrap.querySelector('.rn-admin-header') : null;

    function organizeNotices() {
      if (!wrap || !header) return;
      header.querySelectorAll('.notice, .update-nag, .error, .updated').forEach(function (notice) {
        notice.classList.add('rn-notice-moved');
        wrap.insertBefore(notice, header);
      });
    }

    organizeNotices();
    if (header && window.MutationObserver) {
      new MutationObserver(organizeNotices).observe(header, {
        childList: true,
        subtree: true
      });
    }

    var color = document.querySelector('[data-rn-preview-color]');
    var preview = document.querySelector('[data-rn-whatsapp-preview]');
    var label = document.querySelector('input[name$="[whatsapp_label]"]');
    var previewLabel = document.querySelector('[data-rn-preview-label]');
    var position = document.querySelector('select[name$="[whatsapp_position]"]');

    function updatePreview() {
      if (!preview) return;
      if (color) preview.style.setProperty('--rn-wa-color', color.value);
      if (label && previewLabel) previewLabel.textContent = label.value || 'Chat on WhatsApp';
      if (position) preview.classList.toggle('is-right', position.value === 'right');
    }

    [color, label, position].forEach(function (input) {
      if (input) input.addEventListener('input', updatePreview);
    });
    updatePreview();

    document.querySelectorAll('[data-rn-media-choose]').forEach(function (button) {
      button.addEventListener('click', function () {
        if (!window.wp || !wp.media) return;
        var field = document.getElementById(button.getAttribute('data-rn-media-choose'));
        var preview = document.getElementById(button.getAttribute('data-rn-media-preview'));
        var frame = wp.media({ title: 'Choose launcher icon', button: { text: 'Use icon' }, multiple: false, library: { type: 'image' } });
        frame.on('select', function () { var item = frame.state().get('selection').first().toJSON(); if (!item || !item.id || !item.url) return; field.value = item.id; preview.src = item.sizes && item.sizes.thumbnail ? item.sizes.thumbnail.url : item.url; preview.style.display = ''; });
        frame.open();
      });
    });
    document.querySelectorAll('[data-rn-media-remove]').forEach(function (button) {
      button.addEventListener('click', function () { var field = document.getElementById(button.getAttribute('data-rn-media-remove')); var preview = document.getElementById(button.getAttribute('data-rn-media-preview')); field.value = '0'; preview.src = ''; preview.style.display = 'none'; });
    });
  });
})();
