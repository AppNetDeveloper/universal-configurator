/* Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
   Paso 2: esquema de las paredes + tarjetas de módulo compactas. Sin librerías. El navegador NO calcula importes:
   cada cambio va a /api y se pinta lo que devuelve el servidor. Deshacer también lo hace el servidor. */
'use strict';

(function () {
  var dataEl = document.getElementById('uc-data');
  if (!dataEl) return;
  var D = JSON.parse(dataEl.textContent);
  var T = D.texts;
  var cfg = D.config;
  var types = {};
  D.types.forEach(function (t) { types[t.id] = t; });
  var root = document.getElementById('configurator');
  var plan = document.getElementById('plan');
  var saveState = document.getElementById('save-state');
  var undoBtn = document.getElementById('undo');
  var errors = {};     // item_id -> {msg, tiers, width}: medida rechazada por el servidor
  var pending = {};    // item_id -> temporizador de − / +
  var busy = 0;
  var open = null;     // tarjeta desplegada (id de línea)
  var picker = null;   // {mode: 'replace', item: id} | {mode: 'add', wall: id}
  var anchor = null;   // elemento cuya posición se conserva al repintar
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function h(tag, attrs, kids) {
    var el = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      var v = attrs[k];
      if (v === null || v === undefined || v === false) return;
      if (k === 'text') el.textContent = v;
      else if (k === 'class') el.className = v;
      else if (k === 'html') el.innerHTML = v; // solo SVG generado por el servidor (iconos), nunca texto del usuario
      else el.setAttribute(k, v === true ? '' : v);
    });
    (kids || []).forEach(function (c) { if (c) el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return el;
  }
  var cmText = function (v) { return String(v).replace('.', ','); };
  var cmNum = function (s) { var n = parseFloat(String(s).trim().replace(',', '.')); return isNaN(n) ? null : Math.round(n * 10) / 10; };
  var icon = function (typeId) { return (types[typeId] && types[typeId].icon) || D.fallbackIcon; };
  var tierLabel = function (tr) { return (tr.from_cm === tr.to_cm ? cmText(tr.to_cm) : cmText(tr.from_cm) + '–' + cmText(tr.to_cm)) + ' cm'; };

  function setState(text, isError) {
    saveState.textContent = text;
    saveState.classList.toggle('is-error', !!isError);
  }

  function toast(msg, action) {
    var t = document.querySelector('.toast') || document.body.appendChild(h('div', { class: 'toast', role: 'status' }));
    t.textContent = '';
    t.appendChild(document.createTextNode(msg));
    if (action) {
      var b = h('button', { type: 'button', class: 'toast-action', text: action.label });
      b.addEventListener('click', function () { t.classList.remove('show'); action.run(); });
      t.appendChild(b);
    }
    t.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { t.classList.remove('show'); }, action ? 6000 : 3200);
  }

  function post(path, body) {
    busy++;
    setState(T.saving);
    return fetch('/api/configurations/' + encodeURIComponent(cfg.code) + path, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': D.csrf, 'Accept': 'application/json' },
      body: JSON.stringify(body || {})
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: T.error }; }).then(function (b) { return { status: r.status, body: b }; });
    }).then(function (res) {
      busy--;
      // Aceptada o pedida desde otro sitio, o sesión caducada: se recarga (el deshacer explica su propio 409).
      if ((res.status === 409 && path !== '/undo') || res.status === 401 || res.status === 419) { window.location.reload(); return null; }
      return res;
    }, function () { busy--; setState(T.error, true); toast(T.error); return null; });
  }

  function send(ops, itemId) {
    return post('/ops', { ops: ops }).then(function (res) {
      if (!res) return false;
      if (res.body.ok) {
        cfg = res.body.config;
        if (itemId) delete errors[itemId];
        (res.body.notes || []).forEach(function (n) { toast(n); });
        undoBtn.hidden = false;
        setState(busy ? T.saving : T.saved);
        render();
        return true;
      }
      var b = res.body, target = b.item_id || itemId;
      if (target && b.tiers) { errors[target] = { msg: b.error, tiers: b.tiers, width: b.width_cm }; setState(T.invalid, true); }
      else { setState(T.error, true); toast(b.error || T.error); }
      render();
      return false;
    });
  }

  function undo() {
    post('/undo').then(function (res) {
      if (!res) return;
      if (res.body.ok) {
        cfg = res.body.config;
        errors = {};
        undoBtn.hidden = !res.body.can_undo;
        setState(T.undone);
        render();
      } else {
        undoBtn.hidden = true;
        setState('', false);
        toast(res.body.error || T.error);
      }
    });
  }
  undoBtn.addEventListener('click', undo);

  // ---- esquema: una tira por pared, cajas proporcionales al ancho y al alto del módulo
  function renderPlan() {
    plan.textContent = '';
    cfg.walls.forEach(function (w) {
      var total = w.items.reduce(function (s, it) { return s + it.width_mm; }, 0);
      var row = h('div', { class: 'plan-row' });
      w.items.forEach(function (it) {
        var bad = !!errors[it.id] || !it.valid;
        var box = h('button', { type: 'button', class: 'plan-box' + (open === it.id ? ' is-open' : '') + (bad ? ' is-invalid' : ''),
          style: 'flex-grow:' + it.width_mm,
          'aria-label': it.type_name + ', ' + cmText(it.width_cm) + ' cm', title: it.type_name + ' · ' + cmText(it.width_cm) + ' cm' },
          [h('span', { class: 'plan-ico', html: icon(it.module_type_id) }), h('span', { class: 'plan-w', text: cmText(it.width_cm) })]);
        box.addEventListener('click', function () { openItem(it.id, true); });
        row.appendChild(box);
      });
      if (!w.items.length) row.appendChild(h('span', { class: 'plan-empty', text: T.empty }));
      plan.appendChild(h('div', { class: 'plan-wall' }, [
        h('div', { class: 'plan-name' }, [h('span', { text: w.name }), h('span', { class: 'plan-len', text: cmText(Math.round(total) / 10) + ' cm' })]),
        row]));
    });
  }

  function openItem(id, scroll) {
    open = open === id && !scroll ? null : id;
    if (picker && picker.item !== open) picker = null;
    render();
    var card = root.querySelector('[data-item="' + id + '"]');
    if (!card) return;
    if (scroll) { card.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'center' }); return; }
    // Al desplegar, que se vea lo desplegado (sin quedar bajo la barra del total).
    var bar = document.querySelector('.totalbar');
    var limit = window.innerHeight - (bar ? bar.offsetHeight : 0) - 12;
    var r = card.getBoundingClientRect();
    if (open === id && r.bottom > limit) window.scrollBy({ top: Math.max(0, Math.min(r.bottom - limit, r.top - 120)), behavior: reduced ? 'auto' : 'smooth' });
  }

  // ---- lista visual para cambiar o añadir un módulo (icono, nombre y precio desde)
  function pickerGrid(currentId, onPick) {
    var list = D.types.filter(function (t) { return t.tiers && t.tiers.length; });
    var grid = h('div', { class: 'picker', role: 'list' });
    if (!list.length) grid.appendChild(h('p', { class: 'muted', text: T.nothingHere }));
    list.forEach(function (t) {
      var min = t.tiers.reduce(function (m, tr) { return tr.price_cents < m.price_cents ? tr : m; }, t.tiers[0]);
      var range = cmText(t.tiers[0].from_cm) + '–' + cmText(t.tiers[t.tiers.length - 1].to_cm) + ' cm';
      var b = h('button', { type: 'button', class: 'pick' + (t.id === currentId ? ' is-current' : ''), role: 'listitem', 'aria-pressed': t.id === currentId ? 'true' : 'false' }, [
        h('span', { class: 'pick-ico', html: t.icon }),
        h('span', { class: 'pick-name', text: t.name }),
        h('span', { class: 'pick-meta', text: (t.id === currentId ? T.current : T.from + ' ' + min.price_text) + ' · ' + range })]);
      b.addEventListener('click', function () { if (t.id !== currentId) onPick(t); });
      grid.appendChild(b);
    });
    return grid;
  }

  // ---- tarjeta de módulo: compacta (icono, nombre, precio; − ancho +); al tocarla, lo demás
  function itemCard(it) {
    var err = errors[it.id];
    var invalid = !!err || !it.valid;
    var tiers = err ? err.tiers : it.tiers;
    var width = err ? err.width : it.width_cm;
    var isOpen = open === it.id;
    var card = h('article', { class: 'mod' + (invalid ? ' is-invalid' : '') + (isOpen ? ' is-open' : ''), 'data-item': String(it.id) });

    var sub = invalid ? (err ? err.msg : it.error) : (it.tier_note || tierLabel({ from_cm: it.tier_from_cm, to_cm: it.tier_to_cm }));
    var head = h('button', { type: 'button', class: 'mod-head', 'aria-expanded': isOpen ? 'true' : 'false', 'aria-label': it.type_name + '. ' + (isOpen ? T.less : T.more) }, [
      h('span', { class: 'mod-ico-wrap', html: icon(it.module_type_id) }),
      h('span', { class: 'mod-title' }, [h('span', { class: 'mod-name', text: it.type_name }),
        h('span', { class: 'mod-sub' + (invalid ? ' is-error' : it.tier_note ? ' is-note' : ''), text: sub })]),
      h('span', { class: 'mod-price', text: invalid ? '—' : it.price_text }),
      h('span', { class: 'chev', 'aria-hidden': 'true' })]);
    head.addEventListener('click', function () { anchor = it.id; openItem(it.id, false); });
    card.appendChild(head);

    var input = h('input', { type: 'text', inputmode: 'decimal', autocomplete: 'off', value: cmText(width), 'aria-label': T.width + ' (cm) · ' + it.type_name });
    var minus = h('button', { type: 'button', class: 'step-btn', 'aria-label': '−1 cm', text: '−' });
    var plus = h('button', { type: 'button', class: 'step-btn', 'aria-label': '+1 cm', text: '+' });
    function queue(v) {
      input.value = cmText(v);
      anchor = it.id;
      clearTimeout(pending[it.id]);
      pending[it.id] = setTimeout(function () { send([{ op: 'set_width', item_id: it.id, width_cm: cmText(v) }], it.id); }, 400);
    }
    minus.addEventListener('click', function () { var v = cmNum(input.value); if (v === null) v = it.width_cm; if (v > 1) queue(Math.round((v - 1) * 10) / 10); });
    plus.addEventListener('click', function () { var v = cmNum(input.value); if (v === null) v = it.width_cm; queue(Math.round((v + 1) * 10) / 10); });
    input.addEventListener('change', function () {
      var raw = input.value.trim();
      if (raw === '') { input.value = cmText(width); return; }
      clearTimeout(pending[it.id]);
      anchor = it.id;
      send([{ op: 'set_width', item_id: it.id, width_cm: raw }], it.id);
    });
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); input.blur(); } });
    card.appendChild(h('div', { class: 'mod-quick' }, [h('div', { class: 'stepper' }, [minus, input, h('span', { class: 'unit', text: 'cm' }), plus])]));

    if (isOpen || invalid) {
      var more = h('div', { class: 'mod-more' });
      if (tiers && tiers.length) {
        var row = h('div', { class: 'tiers', role: 'group', 'aria-label': T.tiers });
        tiers.forEach(function (tr) {
          var current = !invalid && it.tier_from_cm === tr.from_cm && it.tier_to_cm === tr.to_cm;
          var b = h('button', { type: 'button', class: 'tier' + (current ? ' is-current' : ''), 'aria-pressed': current ? 'true' : 'false' },
            [h('span', { class: 'tier-w', text: tierLabel(tr) }), h('span', { class: 'tier-p', text: tr.price_text })]);
          b.addEventListener('click', function () { clearTimeout(pending[it.id]); anchor = it.id; send([{ op: 'set_width', item_id: it.id, width_cm: tr.to_cm }], it.id); });
          row.appendChild(b);
        });
        more.appendChild(row);
      }
      if (isOpen) {
        if (it.height_mm && it.depth_mm) more.appendChild(h('p', { class: 'mod-dims', text: T.dims.replace('{w}', String(it.width_mm)).replace('{h}', String(it.height_mm)).replace('{d}', String(it.depth_mm)) }));
        var rep = h('button', { type: 'button', class: 'btn btn-ghost btn-sm', 'aria-expanded': picker && picker.item === it.id ? 'true' : 'false', text: T.replace });
        rep.addEventListener('click', function () { picker = picker && picker.item === it.id ? null : { mode: 'replace', item: it.id }; anchor = it.id; render(); });
        var del = h('button', { type: 'button', class: 'btn btn-ghost btn-sm danger', text: T.remove });
        del.addEventListener('click', function () {
          delete errors[it.id];
          open = null; picker = null;
          send([{ op: 'remove', item_id: it.id }], it.id).then(function (ok) {
            if (ok) toast(T.removed.replace('{n}', it.type_name), { label: T.undo, run: undo });
          });
        });
        more.appendChild(h('div', { class: 'mod-actions' }, [rep, del]));
        if (picker && picker.item === it.id) {
          more.appendChild(pickerGrid(it.module_type_id, function (t) {
            picker = null; anchor = it.id;
            delete errors[it.id];
            send([{ op: 'replace', item_id: it.id, module_type_id: t.id }], it.id);
          }));
        }
      }
      card.appendChild(more);
    }
    return card;
  }

  function wallBlock(w) {
    var sec = h('section', { class: 'wall', 'aria-label': w.name });
    var sum = w.items.length + ' ' + (w.items.length === 1 ? T.module : T.modules);
    sec.appendChild(h('div', { class: 'wall-head' }, [h('h2', { text: w.name }), h('span', { class: 'wall-sub', text: sum })]));
    var list = h('div', { class: 'mod-list' });
    w.items.forEach(function (it) { list.appendChild(itemCard(it)); });
    sec.appendChild(w.items.length ? list : h('p', { class: 'wall-empty', text: T.empty }));
    var adding = picker && picker.mode === 'add' && picker.wall === w.id;
    var add = h('button', { type: 'button', class: 'btn btn-ghost btn-add', 'aria-expanded': adding ? 'true' : 'false', 'data-add': String(w.id) },
      [h('span', { 'aria-hidden': 'true', text: adding ? '× ' : '+ ' }), adding ? T.close : T.add]);
    add.addEventListener('click', function () { picker = adding ? null : { mode: 'add', wall: w.id }; open = null; anchor = 'add-' + w.id; render(); });
    sec.appendChild(add);
    if (adding) {
      sec.appendChild(h('p', { class: 'picker-title', text: T.chooseType }));
      sec.appendChild(pickerGrid(null, function (t) { picker = null; anchor = 'add-' + w.id; send([{ op: 'add', wall_id: w.id, module_type_id: t.id }]); }));
    }
    return sec;
  }

  function anchorEl() {
    if (anchor === null) return null;
    return typeof anchor === 'string' ? root.querySelector('[data-add="' + anchor.slice(4) + '"]') : root.querySelector('[data-item="' + anchor + '"]');
  }

  function render() {
    var a = anchorEl();
    var before = a ? a.getBoundingClientRect().top : null;
    var focused = document.activeElement && root.contains(document.activeElement) && document.activeElement.closest('[data-item]');
    var focusId = focused ? focused.getAttribute('data-item') : null;
    var focusInput = focused && document.activeElement.tagName === 'INPUT';
    root.textContent = '';
    cfg.walls.forEach(function (w) { root.appendChild(wallBlock(w)); });
    renderPlan();
    document.getElementById('total-live').textContent = cfg.total_text;
    var invalid = Object.keys(errors).length > 0 || !cfg.valid;
    var next = document.getElementById('to-summary');
    next.classList.toggle('btn-ghost', invalid);
    next.classList.toggle('btn-primary', !invalid);
    // Conservar la posición de lo que se tocaba (el repintado cambia alturas por encima).
    var b = anchorEl();
    if (b && before !== null) window.scrollBy(0, b.getBoundingClientRect().top - before);
    if (focusId) {
      var again = root.querySelector('[data-item="' + focusId + '"] ' + (focusInput ? 'input' : '.mod-head'));
      if (again && (document.activeElement === document.body || !root.contains(document.activeElement))) again.focus({ preventScroll: true });
    }
  }

  render();
})();
