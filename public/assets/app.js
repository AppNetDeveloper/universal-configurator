/* Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
   Sin librerías. El configurador NO calcula importes: cada cambio va a /api y se pinta lo que devuelve el servidor. */
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

  var dataEl = document.getElementById('uc-data');
  if (!dataEl) return;
  var D = JSON.parse(dataEl.textContent);
  var T = D.texts;
  var cfg = D.config;
  var types = D.types;
  var root = document.getElementById('configurator');
  var saveState = document.getElementById('save-state');
  var errors = {};      // item_id -> {msg, tiers, width} (medida rechazada por el servidor)
  var pending = {};     // item_id -> timer de los botones − / +
  var busy = 0;

  function h(tag, attrs, kids) {
    var el = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      var v = attrs[k];
      if (v === null || v === undefined || v === false) return;
      if (k === 'text') el.textContent = v;
      else if (k === 'class') el.className = v;
      else el.setAttribute(k, v === true ? '' : v);
    });
    (kids || []).forEach(function (c) { if (c) el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return el;
  }

  function typeOptions(selectedId, placeholder) {
    var sel = h('select', {});
    if (placeholder) sel.appendChild(h('option', { value: '', text: placeholder }));
    var groups = {};
    types.forEach(function (t) { (groups[t.family || ''] = groups[t.family || ''] || []).push(t); });
    Object.keys(groups).forEach(function (fam) {
      var parent = fam ? h('optgroup', { label: fam.charAt(0).toUpperCase() + fam.slice(1) }) : sel;
      groups[fam].forEach(function (t) {
        parent.appendChild(h('option', { value: String(t.id), selected: t.id === selectedId, text: t.name + (t.ref ? ' · ' + t.ref : '') }));
      });
      if (fam) sel.appendChild(parent);
    });
    return sel;
  }

  function setState(text, isError) {
    saveState.textContent = text;
    saveState.classList.toggle('is-error', !!isError);
  }

  function toast(msg) {
    var t = document.querySelector('.toast') || document.body.appendChild(h('div', { class: 'toast', role: 'status' }));
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { t.classList.remove('show'); }, 3200);
  }

  function send(ops, itemId) {
    busy++;
    setState(T.saving);
    return fetch('/api/configurations/' + encodeURIComponent(cfg.code) + '/ops', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': D.csrf, 'Accept': 'application/json' },
      body: JSON.stringify({ ops: ops })
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: T.error }; }).then(function (b) { return { status: r.status, body: b }; });
    }).then(function (res) {
      busy--;
      if (res.body.ok) {
        cfg = res.body.config;
        if (itemId) delete errors[itemId];
        (res.body.notes || []).forEach(toast);
        setState(busy ? T.saving : T.saved);
        render();
        return true;
      }
      if (res.status === 409 || res.status === 401 || res.status === 419) { window.location.reload(); return false; }
      var b = res.body;
      var target = b.item_id || itemId;
      if (target && b.tiers) {
        errors[target] = { msg: b.error, tiers: b.tiers, width: b.width_cm };
        setState(T.invalid, true);
        render();
      } else {
        setState(T.error, true);
        toast(b.error || T.error);
        render();
      }
      return false;
    }).catch(function () {
      busy--;
      setState(T.error, true);
      toast(T.error);
    });
  }

  function itemCard(it, wall) {
    var err = errors[it.id];
    var invalid = !!err || !it.valid;
    var tiers = err ? err.tiers : it.tiers;
    var width = err ? err.width : it.width_cm;
    var card = h('article', { class: 'mod' + (invalid ? ' is-invalid' : ''), 'data-item': String(it.id) });

    var del = h('button', { class: 'icon-btn danger', type: 'button', title: T.remove, 'aria-label': T.remove + ' ' + it.type_name, text: '✕' });
    del.addEventListener('click', function () {
      if (window.confirm(T.confirmRemove)) { delete errors[it.id]; send([{ op: 'remove', item_id: it.id }], it.id); }
    });
    card.appendChild(h('div', { class: 'mod-top' }, [it.family ? h('span', { class: 'fam', text: it.family }) : null, h('span', { class: 'mod-name', text: it.type_name }), del]));

    // Ancho en cm con un decimal como mucho («58,5» o «58.5»); − / + mueven 1 cm. El servidor valida y calcula.
    var cmText = function (v) { return String(v).replace('.', ','); };
    var cmNum = function (s) { var n = parseFloat(String(s).trim().replace(',', '.')); return isNaN(n) ? null : Math.round(n * 10) / 10; };
    var input = h('input', { type: 'text', inputmode: 'decimal', autocomplete: 'off', value: cmText(width), 'aria-label': T.width + ' (cm) ' + it.type_name });
    var minus = h('button', { type: 'button', 'aria-label': '−1 cm', text: '−' });
    var plus = h('button', { type: 'button', 'aria-label': '+1 cm', text: '+' });
    function queue(v) {
      input.value = cmText(v);
      clearTimeout(pending[it.id]);
      pending[it.id] = setTimeout(function () { send([{ op: 'set_width', item_id: it.id, width_cm: cmText(v) }], it.id); }, 450);
    }
    minus.addEventListener('click', function () { var v = cmNum(input.value); if (v === null) v = it.width_cm; if (v > 1) queue(Math.round((v - 1) * 10) / 10); });
    plus.addEventListener('click', function () { var v = cmNum(input.value); if (v === null) v = it.width_cm; queue(Math.round((v + 1) * 10) / 10); });
    input.addEventListener('change', function () {
      var raw = input.value.trim();
      if (raw === '') { input.value = cmText(width); return; }
      clearTimeout(pending[it.id]);
      send([{ op: 'set_width', item_id: it.id, width_cm: raw }], it.id);
    });
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); input.blur(); } });
    var stepper = h('div', { class: 'stepper' }, [minus, input, h('span', { class: 'unit', text: 'cm' }), plus]);
    card.appendChild(h('div', { class: 'mod-mid' }, [stepper, h('span', { class: 'mod-price', text: invalid ? '—' : it.price_text })]));
    if (it.height_mm && it.depth_mm) card.appendChild(h('p', { class: 'mod-dims', text: T.dims.replace('{w}', String(it.width_mm)).replace('{h}', String(it.height_mm)).replace('{d}', String(it.depth_mm)) }));

    if (invalid) card.appendChild(h('p', { class: 'mod-error', role: 'alert', text: err ? err.msg : it.error }));

    if (tiers && tiers.length) {
      var row = h('div', { class: 'tiers', 'aria-label': T.tiers });
      tiers.forEach(function (tr) {
        var current = !invalid && it.tier_from_cm === tr.from_cm && it.tier_to_cm === tr.to_cm;
        var label = (tr.from_cm === tr.to_cm ? cmText(tr.to_cm) : cmText(tr.from_cm) + '–' + cmText(tr.to_cm)) + ' cm · ' + tr.price_text;
        var b = h('button', { type: 'button', class: 'tier' + (current ? ' is-current' : ''), 'aria-pressed': current ? 'true' : 'false', text: label });
        b.addEventListener('click', function () { clearTimeout(pending[it.id]); send([{ op: 'set_width', item_id: it.id, width_cm: tr.to_cm }], it.id); });
        row.appendChild(b);
      });
      card.appendChild(row);
    }

    var rep = typeOptions(it.module_type_id);
    rep.setAttribute('aria-label', T.replace);
    rep.title = T.replace;
    rep.addEventListener('change', function () { delete errors[it.id]; send([{ op: 'replace', item_id: it.id, module_type_id: parseInt(rep.value, 10) }], it.id); });
    card.appendChild(h('div', { class: 'mod-bot' }, [rep]));
    return card;
  }

  function wallBlock(w) {
    var sec = h('section', { class: 'wall', 'aria-label': w.name });
    var sum = w.items.length + ' ' + (w.items.length === 1 ? T.module : T.modules);
    sec.appendChild(h('div', { class: 'wall-head' }, [h('h2', { text: w.name }), h('span', { class: 'wall-sub', text: sum })]));
    var grid = h('div', { class: 'grid-mods' });
    w.items.forEach(function (it) { grid.appendChild(itemCard(it, w)); });
    sec.appendChild(w.items.length ? grid : h('p', { class: 'wall-empty', text: T.empty }));
    var add = typeOptions(null, T.chooseType);
    add.setAttribute('aria-label', T.add + ' · ' + w.name);
    var btn = h('button', { class: 'btn btn-ghost', type: 'button', text: '+ ' + T.add });
    btn.addEventListener('click', function () {
      if (!add.value) { add.focus(); return; }
      send([{ op: 'add', wall_id: w.id, module_type_id: parseInt(add.value, 10) }]);
    });
    sec.appendChild(h('div', { class: 'add-row' }, [add, btn]));
    return sec;
  }

  function render() {
    var focused = document.activeElement && document.activeElement.closest ? document.activeElement.closest('[data-item]') : null;
    var focusId = focused ? focused.getAttribute('data-item') : null;
    root.textContent = '';
    cfg.walls.forEach(function (w) { root.appendChild(wallBlock(w)); });
    document.getElementById('total-live').textContent = cfg.total_text;
    document.getElementById('mod-count').textContent = cfg.count + ' ' + (cfg.count === 1 ? T.module : T.modules);
    var invalid = Object.keys(errors).length > 0 || !cfg.valid;
    document.getElementById('to-summary').classList.toggle('btn-ghost', invalid);
    document.getElementById('to-summary').classList.toggle('btn-primary', !invalid);
    if (focusId) {
      var again = root.querySelector('[data-item="' + focusId + '"] input');
      if (again && document.activeElement === document.body) again.focus({ preventScroll: true });
    }
  }

  render();
})();
