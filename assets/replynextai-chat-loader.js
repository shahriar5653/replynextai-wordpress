(function () {
  'use strict';

  function loadWidget() {
    var configNode = document.querySelector('script#replynextai-wordpress-widget-config[type="application/json"]');
    if (!configNode || !document.head || window.__rn_chat_initialized || document.getElementById('replynextai-hosted-widget')) return;

    var config, src;
    try {
      config = JSON.parse(configNode.textContent);
      if (!config || typeof config.widgetId !== 'string' || !config.widgetId || typeof config.scriptUrl !== 'string') return;
      src = new URL(config.scriptUrl);
    } catch (error) {
      return;
    }

    if ((src.protocol !== 'https:' && src.protocol !== 'http:') || src.username || src.password ||
        !/\/chat-widget\.js$/.test(src.pathname) || src.hash) return;

    // An existing direct embed from the same server should take precedence.
    // Ignore its version query so old snippets and this loader don't compete.
    var embeds = document.querySelectorAll('script[src][data-widget-id]');
    for (var i = 0; i < embeds.length; i++) {
      var type = (embeds[i].type || '').trim().toLowerCase();
      if (type && type !== 'text/javascript' && type !== 'application/javascript') continue;
      try {
        var existing = new URL(embeds[i].src, document.baseURI);
        if (existing.origin === src.origin && existing.pathname === src.pathname) return;
      } catch (error) {
        continue;
      }
    }

    var widget = document.createElement('script');
    widget.id = 'replynextai-hosted-widget';
    widget.async = true;
    widget.src = src.href;
    widget.setAttribute('data-widget-id', config.widgetId);
    widget.setAttribute('data-is-user', config.visitor ? 'true' : 'false');
    if (config.visitor) {
      widget.setAttribute('data-visitor-name', config.visitor.name || '');
      widget.setAttribute('data-visitor-email', config.visitor.email || '');
    }
    document.head.appendChild(widget);
  }

  // Also works if an optimizer moves the loader into a bundle in the head.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadWidget, { once: true });
  } else {
    loadWidget();
  }
})();
