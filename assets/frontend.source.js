(function () {
  "use strict";
  function init() {
    document
      .querySelectorAll("[data-rn-contact-float]")
      .forEach(function (root) {
        var toggle = root.querySelector("[data-rn-contact-toggle]"),
          menu = root.querySelector("[data-rn-contact-menu]"),
          dragging = false,
          moved = false,
          startX = 0,
          startY = 0,
          startLeft = 0,
          startTop = 0;
        if (!toggle || !menu) return;
        function viewport() {
          var v = window.visualViewport;
          return v ? { x: v.offsetLeft, y: v.offsetTop, width: v.width, height: v.height }
            : { x: 0, y: 0, width: window.innerWidth, height: window.innerHeight };
        }
        function fitMenu() {
          var v = viewport(), r = toggle.getBoundingClientRect();
          var above = Math.max(0, r.top - v.y - 20);
          var below = Math.max(0, v.y + v.height - r.bottom - 20);
          var down = menu.scrollHeight > above && below > above;
          root.classList.toggle("rn-contact-menu-down", down);
          menu.style.setProperty("--rn-menu-height", Math.min(360, down ? below : above) + "px");
          menu.style.setProperty("--rn-menu-width", Math.max(0, v.width - 16) + "px");
          var rect = menu.getBoundingClientRect();
          var shift = parseFloat(menu.style.getPropertyValue("--rn-menu-shift")) || 0;
          var left = rect.left - shift;
          menu.style.setProperty("--rn-menu-shift", Math.max(v.x + 8, Math.min(left, v.x + v.width - rect.width - 8)) - left + "px");
        }
        function position(x, y) {
          var v = viewport(), r = toggle.getBoundingClientRect();
          root.style.left = Math.max(v.x + 8, Math.min(x, v.x + v.width - r.width - 8)) + "px";
          root.style.top = Math.max(v.y + 8, Math.min(y, v.y + v.height - r.height - 8)) + "px";
          root.style.right = "auto";
          root.style.bottom = "auto";
          if (root.classList.contains("rn-contact-open")) fitMenu();
        }
        function fitViewport() {
          var v = viewport(), r = toggle.getBoundingClientRect();
          if (r.left < v.x + 8 || r.right > v.x + v.width - 8 ||
              r.top < v.y + 8 || r.bottom > v.y + v.height - 8) position(r.left, r.top);
          else if (root.classList.contains("rn-contact-open")) fitMenu();
        }
        function saved() {
          try {
            var p = JSON.parse(
              localStorage.getItem("replynextai_contact_position") || "null",
            );
            if (p && typeof p.x === "number") position(p.x, p.y);
          } catch (e) {}
        }
        function down(e) {
          var p = e.touches ? e.touches[0] : e;
          var r = root.getBoundingClientRect();
          dragging = true;
          moved = false;
          startX = p.clientX;
          startY = p.clientY;
          startLeft = r.left;
          startTop = r.top;
          root.classList.add("rn-contact-dragging");
        }
        function move(e) {
          if (!dragging) return;
          var p = e.touches ? e.touches[0] : e;
          if (Math.abs(p.clientX - startX) + Math.abs(p.clientY - startY) > 6)
            moved = true;
          if (moved) {
            e.preventDefault();
            position(
              startLeft + p.clientX - startX,
              startTop + p.clientY - startY,
            );
          }
        }
        function up() {
          if (!dragging) return;
          dragging = false;
          root.classList.remove("rn-contact-dragging");
          if (moved) {
            try {
              var r = root.getBoundingClientRect();
              localStorage.setItem(
                "replynextai_contact_position",
                JSON.stringify({ x: r.left, y: r.top }),
              );
            } catch (e) {}
          }
        }
        toggle.addEventListener("pointerdown", down);
        window.addEventListener("pointermove", move, { passive: false });
        window.addEventListener("pointerup", up);
        window.addEventListener("pointercancel", up);
        window.addEventListener("resize", fitViewport);
        if (window.visualViewport) {
          window.visualViewport.addEventListener("resize", fitViewport);
          window.visualViewport.addEventListener("scroll", fitViewport);
        }
        toggle.addEventListener("click", function (e) {
          if (moved) {
            e.preventDefault();
            e.stopPropagation();
            moved = false;
            return;
          }
          e.stopPropagation();
          var open = root.classList.toggle("rn-contact-open");
          toggle.setAttribute("aria-expanded", open ? "true" : "false");
          if (open) fitViewport();
        });
        root.querySelectorAll(".rn-wa-button").forEach(function (b) {
          b.addEventListener("click", function () {
            root.classList.remove("rn-contact-open");
            toggle.setAttribute("aria-expanded", "false");
          });
        });
        document.addEventListener("click", function (e) {
          if (!root.contains(e.target)) {
            root.classList.remove("rn-contact-open");
            toggle.setAttribute("aria-expanded", "false");
          }
        });
        document.addEventListener("keydown", function (e) {
          if (e.key === "Escape") {
            root.classList.remove("rn-contact-open");
            toggle.setAttribute("aria-expanded", "false");
            toggle.focus();
          }
        });
        saved();
        fitViewport();
      });
  }
  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", init);
  else init();
})();
