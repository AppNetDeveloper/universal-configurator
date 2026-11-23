/* Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
   Sin librerías. Utilidades comunes y el panel. El paso 2 del configurador vive en configurador.js. */
'use strict';

(function () {
  // ---- utilidades comunes (formularios con confirmación, campos para copiar)
  document.addEventListener('submit', function (e) {
    var f = e.target.closest('form[data-confirm]');
    if (f && !window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
  });
  document.addEventListener('click', function (e) {
    var c = e.target.closest('[data-copy]');
    if (!c) return;
    c.select();
    if (navigator.clipboard) navigator.clipboard.writeText(c.value).catch(function () {});
  });

  // ---- panel: vista previa en vivo de las fórmulas del despiece (la calcula el servidor; aquí no se evalúa nada)
  var varsEl = document.querySelector('[data-parts-vars]');
  if (varsEl) {
    var timers = new WeakMap();
    document.addEventListener('input', function (e) {
      var inp = e.target.closest('[data-expr]');
      if (!inp) return;
      var row = inp.closest('[data-part-row]');
      clearTimeout(timers.get(row));
      timers.set(row, setTimeout(function () {
        var body = new URLSearchParams();
        body.set('_csrf', varsEl.getAttribute('data-csrf'));
        row.querySelectorAll('[data-expr]').forEach(function (x) { body.set(x.getAttribute('data-expr'), x.value); });
        fetch(varsEl.getAttribute('data-url'), { method: 'POST', credentials: 'same-origin', body: body, headers: { 'Accept': 'application/json' } })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            var out = row.querySelector('[data-preview]');
            var bad = [];
            row.querySelectorAll('[data-expr]').forEach(function (x) {
              var res = j.result[x.getAttribute('data-expr')];
              x.classList.toggle('is-bad', !res.ok);
              x.title = res.ok ? res.text : res.error;
              if (!res.ok) bad.push(res.error);
            });
            out.textContent = '';
            if (bad.length) out.appendChild(Object.assign(document.createElement('span'), { className: 'line-error', textContent: bad[0] }));
            else out.textContent = j.result.thickness.text.replace(' mm', '') + ' · ' + j.result.length_expr.text.replace(' mm', '') + ' × ' + j.result.width_expr.text;
          }).catch(function () {});
      }, 300));
    });
  }

  // ---- «Envíamela por WhatsApp»: con JavaScript, de un toque (pide el enlace firmado y abre WhatsApp); sin él, el
  // formulario lleva a la página con el enlace y el botón de WhatsApp.
  document.addEventListener('submit', function (e) {
    var f = e.target.closest('form[data-share-wa]');
    if (!f || f.dataset.sent) return;
    var m = f.getAttribute('action').match(/^\/configurar\/(UC-[A-Z0-9]{8})\/compartir$/);
    if (!m) return;
    e.preventDefault();
    fetch('/api/configurations/' + m[1] + '/share', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': f.querySelector('[name=_csrf]').value }, body: '{}' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j.ok && j.whatsapp_url) window.location.href = j.whatsapp_url; else { f.dataset.sent = '1'; f.submit(); } })
      .catch(function () { f.dataset.sent = '1'; f.submit(); });
  });

  // ---- copiar un enlace (compartir) con un botón
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy-from]');
    if (!b) return;
    var src = document.getElementById(b.getAttribute('data-copy-from'));
    if (!src) return;
    src.select();
    var done = function () { var t = b.textContent; b.textContent = b.getAttribute('data-copied') || '✓'; setTimeout(function () { b.textContent = t; }, 1800); };
    if (navigator.clipboard) navigator.clipboard.writeText(src.value).then(done, done); else { document.execCommand('copy'); done(); }
  });
})();
