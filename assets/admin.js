(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
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
  });
})();
