/* DotCliq Studio — UI behaviour + dependency-free SVG charts */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var fmt = function (n) { return Math.round(n).toLocaleString('en-KE'); };
  var short = function (n) {
    var a = Math.abs(n);
    if (a >= 1e6) return (n / 1e6).toFixed(a >= 1e7 ? 0 : 1).replace(/\.0$/, '') + 'M';
    if (a >= 1e3) return (n / 1e3).toFixed(a >= 1e4 ? 0 : 1).replace(/\.0$/, '') + 'k';
    return String(Math.round(n));
  };
  var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var NS = 'http://www.w3.org/2000/svg';
  var PALETTE = ['#f6b900', '#d6304f', '#33d6a0', '#6ea8ff', '#c084fc', '#fb923c', '#2dd4bf', '#94a3b8'];

  /* ---------- Theme & nav ---------- */
  var themeBtn = $('#themeBtn');
  if (themeBtn) themeBtn.addEventListener('click', function () {
    var n = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', n);
    try { localStorage.setItem('dc-theme', n); } catch (e) {}
  });
  var menuBtn = $('#menuBtn'), scrim = $('#scrim');
  if (menuBtn) menuBtn.addEventListener('click', function () { document.body.classList.toggle('nav-open'); });
  if (scrim) scrim.addEventListener('click', function () { document.body.classList.remove('nav-open'); });

  document.addEventListener('click', function (e) {
    var t = e.target;
    var x = t.closest && t.closest('.alert-x');
    if (x) { x.parentNode.remove(); return; }
    var pw = t.closest && t.closest('[data-toggle-pw]');
    if (pw) {
      var inp = $(pw.getAttribute('data-toggle-pw'));
      if (inp) { inp.type = inp.type === 'password' ? 'text' : 'password'; pw.textContent = inp.type === 'password' ? 'Show' : 'Hide'; }
      return;
    }
    var open = t.closest && t.closest('[data-open]');
    if (open) {
      var d = document.getElementById(open.getAttribute('data-open'));
      if (d && d.showModal) {
        var json = open.getAttribute('data-fill');
        if (json) { try { fillForm(d, JSON.parse(json)); } catch (er) {} }
        d.showModal();
      }
      return;
    }
    var close = t.closest && t.closest('[data-close]');
    if (close) { var dd = close.closest('dialog'); if (dd) dd.close(); return; }
    if (t.tagName === 'DIALOG') t.close();
  });

  document.addEventListener('submit', function (e) {
    var f = e.target, msg = f.getAttribute && f.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
    var b = f.querySelector && f.querySelector('button[type=submit]:not([data-nolock])');
    if (b && !e.defaultPrevented) setTimeout(function () { b.disabled = true; }, 0);
  });

  function fillForm(scope, data) {
    Object.keys(data).forEach(function (k) {
      var el = scope.querySelector('[name="' + k + '"]');
      if (!el) return;
      if (el.type === 'checkbox') el.checked = !!+data[k]; else el.value = data[k] == null ? '' : data[k];
    });
    var title = scope.querySelector('[data-title]');
    if (title && data._title) title.textContent = data._title;
  }

  /* Live table filter: <input data-filter="#tableId"> */
  $$('[data-filter]').forEach(function (inp) {
    inp.addEventListener('input', function () {
      var q = inp.value.toLowerCase();
      $$('#' + inp.getAttribute('data-filter').replace('#', '') + ' tbody tr').forEach(function (tr) {
        tr.style.display = tr.textContent.toLowerCase().indexOf(q) > -1 ? '' : 'none';
      });
    });
  });

  /* Reveal extra fields when a select is set to "new": <select data-new="#box"> */
  $$('select[data-new]').forEach(function (s) {
    var t = $(s.getAttribute('data-new'));
    var f = function () { if (t) t.style.display = s.value === 'new' ? '' : 'none'; };
    s.addEventListener('change', f); f();
  });

  /* ---------- SVG helpers ---------- */
  function el(name, attrs, parent) {
    var n = document.createElementNS(NS, name);
    Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
    if (parent) parent.appendChild(n);
    return n;
  }
  function niceMax(v) {
    if (v <= 0) return 1;
    var p = Math.pow(10, Math.floor(Math.log10(v))), f = v / p;
    var n = f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10;
    return n * p;
  }
  function legend(host, items) {
    var l = document.createElement('div'); l.className = 'legend';
    l.innerHTML = items.map(function (i) { return '<span><i style="background:' + i.color + '"></i>' + esc(i.label) + '</span>'; }).join('');
    host.appendChild(l);
  }
  function noData(host) { host.innerHTML = '<div class="nodata">No data for this period</div>'; }

  function axes(svg, W, H, pad, max, labels) {
    var steps = 4;
    for (var i = 0; i <= steps; i++) {
      var y = pad.t + (H - pad.t - pad.b) * (1 - i / steps);
      el('line', { x1: pad.l, x2: W - pad.r, y1: y, y2: y, 'class': 'gl' }, svg);
      var t = el('text', { x: pad.l - 8, y: y + 4, 'text-anchor': 'end' }, svg); t.textContent = short(max * i / steps);
    }
    var n = labels.length, every = Math.max(1, Math.ceil(n / 9));
    labels.forEach(function (lb, i) {
      if (i % every) return;
      var x = pad.l + (W - pad.l - pad.r) * (n === 1 ? .5 : i / (n - 1));
      var t = el('text', { x: x, y: H - 8, 'text-anchor': 'middle' }, svg); t.textContent = lb;
    });
  }

  /* ---------- Line / area chart ---------- */
  function lineChart(host, d) {
    var series = d.series || [], labels = d.labels || [];
    var all = [].concat.apply([], series.map(function (s) { return s.values; }));
    if (!labels.length || !all.some(function (v) { return v > 0; })) return noData(host);
    var W = 640, H = 270, pad = { l: 46, r: 14, t: 14, b: 30 };
    var max = niceMax(Math.max.apply(null, all));
    var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': d.title || 'Line chart' });
    axes(svg, W, H, pad, max, labels);
    var n = labels.length;
    var X = function (i) { return pad.l + (W - pad.l - pad.r) * (n === 1 ? .5 : i / (n - 1)); };
    var Y = function (v) { return pad.t + (H - pad.t - pad.b) * (1 - v / max); };
    var defs = el('defs', {}, svg);
    series.forEach(function (s, si) {
      var c = s.color || PALETTE[si];
      var g = el('linearGradient', { id: 'lg' + si + host.id, x1: 0, x2: 0, y1: 0, y2: 1 }, defs);
      el('stop', { offset: '0%', 'stop-color': c, 'stop-opacity': .28 }, g);
      el('stop', { offset: '100%', 'stop-color': c, 'stop-opacity': 0 }, g);
      var pts = s.values.map(function (v, i) { return X(i) + ',' + Y(v); });
      if (n > 1) {
        el('path', { d: 'M' + X(0) + ',' + Y(0) + ' L' + pts.join(' L') + ' L' + X(n - 1) + ',' + Y(0) + ' Z', fill: 'url(#lg' + si + host.id + ')' }, svg);
        el('path', { d: 'M' + pts.join(' L'), fill: 'none', stroke: c, 'stroke-width': 2.4, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }, svg);
      }
      s.values.forEach(function (v, i) {
        if (n > 40 && !v) return;
        var cc = el('circle', { cx: X(i), cy: Y(v), r: n > 31 ? 2.5 : 3.6, fill: c, stroke: 'var(--card)', 'stroke-width': 1.5 }, svg);
        var t = el('title', {}, cc); t.textContent = s.name + ' · ' + labels[i] + ': ' + (d.prefix || '') + fmt(v);
      });
    });
    host.innerHTML = ''; host.appendChild(svg);
    if (series.length > 1) legend(host, series.map(function (s, i) { return { label: s.name, color: s.color || PALETTE[i] }; }));
  }

  /* ---------- Bar chart ---------- */
  function barChart(host, d) {
    var labels = d.labels || [], vals = d.values || [];
    if (!labels.length || !vals.some(function (v) { return v > 0; })) return noData(host);
    var W = 640, H = 270, pad = { l: 46, r: 10, t: 14, b: 30 };
    var max = niceMax(Math.max.apply(null, vals));
    var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': d.title || 'Bar chart' });
    var n = labels.length, bw = (W - pad.l - pad.r) / n, every = Math.max(1, Math.ceil(n / 12));
    for (var i = 0; i <= 4; i++) {
      var y = pad.t + (H - pad.t - pad.b) * (1 - i / 4);
      el('line', { x1: pad.l, x2: W - pad.r, y1: y, y2: y, 'class': 'gl' }, svg);
      var t = el('text', { x: pad.l - 8, y: y + 4, 'text-anchor': 'end' }, svg); t.textContent = short(max * i / 4);
    }
    var c = d.color || PALETTE[0];
    vals.forEach(function (v, i) {
      var h = (H - pad.t - pad.b) * (v / max), x = pad.l + i * bw + bw * .18, w = bw * .64;
      var r = el('rect', { x: x, y: H - pad.b - h, width: w, height: Math.max(h, v > 0 ? 2 : 0), rx: Math.min(5, w / 2), fill: c }, svg);
      var tt = el('title', {}, r); tt.textContent = labels[i] + ': ' + (d.prefix || '') + fmt(v);
      if (i % every === 0) { var lb = el('text', { x: x + w / 2, y: H - 8, 'text-anchor': 'middle' }, svg); lb.textContent = labels[i]; }
    });
    if (d.target) {
      var ty = pad.t + (H - pad.t - pad.b) * (1 - Math.min(d.target, max) / max);
      el('line', { x1: pad.l, x2: W - pad.r, y1: ty, y2: ty, stroke: '#33d6a0', 'stroke-dasharray': '5 5', 'stroke-width': 1.5 }, svg);
    }
    host.innerHTML = ''; host.appendChild(svg);
  }

  /* ---------- Donut ---------- */
  function donut(host, d) {
    var items = (d.items || []).filter(function (i) { return i.value > 0; });
    if (!items.length) return noData(host);
    var total = items.reduce(function (a, b) { return a + b.value; }, 0);
    var S = 220, R = 86, r = 56, cx = S / 2, cy = S / 2, a0 = -Math.PI / 2;
    var wrap = document.createElement('div'); wrap.className = 'donut-wrap';
    var svg = el('svg', { viewBox: '0 0 ' + S + ' ' + S, role: 'img', 'aria-label': d.title || 'Breakdown' });
    items.forEach(function (it, i) {
      var frac = it.value / total, a1 = a0 + frac * Math.PI * 2 - (items.length > 1 ? .012 : 0);
      var large = (a1 - a0) > Math.PI ? 1 : 0;
      var p = function (rad, a) { return (cx + rad * Math.cos(a)) + ',' + (cy + rad * Math.sin(a)); };
      var path = items.length === 1
        ? 'M' + (cx - R) + ',' + cy + ' a' + R + ',' + R + ' 0 1,0 ' + (2 * R) + ',0 a' + R + ',' + R + ' 0 1,0 ' + (-2 * R) + ',0 M' + (cx - r) + ',' + cy + ' a' + r + ',' + r + ' 0 1,1 ' + (2 * r) + ',0 a' + r + ',' + r + ' 0 1,1 ' + (-2 * r) + ',0'
        : 'M' + p(R, a0) + ' A' + R + ',' + R + ' 0 ' + large + ' 1 ' + p(R, a1) + ' L' + p(r, a1) + ' A' + r + ',' + r + ' 0 ' + large + ' 0 ' + p(r, a0) + ' Z';
      var seg = el('path', { d: path, fill: PALETTE[i % PALETTE.length], 'fill-rule': 'evenodd' }, svg);
      var t = el('title', {}, seg); t.textContent = it.label + ': ' + (d.prefix || '') + fmt(it.value) + ' (' + Math.round(frac * 100) + '%)';
      a0 += frac * Math.PI * 2;
    });
    var c1 = el('text', { x: cx, y: cy - 2, 'text-anchor': 'middle', 'class': 'donut-c' }, svg); c1.textContent = (d.prefix || '') + short(total);
    var c2 = el('text', { x: cx, y: cy + 16, 'text-anchor': 'middle' }, svg); c2.textContent = d.center || 'total';
    wrap.appendChild(svg); host.innerHTML = ''; host.appendChild(wrap);
    legend(wrap, items.map(function (it, i) { return { label: it.label + ' · ' + Math.round(it.value / total * 100) + '%', color: PALETTE[i % PALETTE.length] }; }));
  }

  $$('.chart[data-chart]').forEach(function (h, i) {
    if (!h.id) h.id = 'ch' + i;
    var d; try { d = JSON.parse(h.getAttribute('data-chart')); } catch (e) { return; }
    var type = h.getAttribute('data-type');
    if (type === 'line') lineChart(h, d); else if (type === 'bar') barChart(h, d); else if (type === 'donut') donut(h, d);
  });

  /* ---------- Sale form ---------- */
  var sf = $('#saleForm');
  if (sf) {
    var packages = []; try { packages = JSON.parse(sf.getAttribute('data-packages') || '[]'); } catch (e) {}
    var cur = sf.getAttribute('data-cur') || 'KSh';
    var body = $('#itemRows'), tpl = $('#itemTpl');
    var num = function (v) { v = parseFloat(String(v).replace(/,/g, '')); return isNaN(v) ? 0 : v; };
    var money = function (n) { return cur + ' ' + fmt(n); };

    function recalc() {
      var sub = 0;
      $$('tr.item', body).forEach(function (tr) {
        var q = Math.max(1, num($('[name="qty[]"]', tr).value)), p = num($('[name="price[]"]', tr).value), lt = q * p;
        $('.lt', tr).textContent = fmt(lt); sub += lt;
      });
      var disc = Math.min(sub, num($('#discount').value)), total = Math.max(0, sub - disc), dep = num($('#deposit').value);
      $('#subT').textContent = money(sub); $('#totT').textContent = money(total);
      var bal = total - dep, b = $('#balT');
      b.textContent = money(Math.max(0, bal)); b.className = bal > 0 ? 'gold-t' : 'green-t';
      var w = $('#depWarn'); if (w) w.style.display = dep > total + 0.004 ? 'block' : 'none';
    }
    function addRow(pkgId, desc, qty, price) {
      var tr = tpl.content.querySelector('tr.item').cloneNode(true);
      var sel = $('select', tr);
      packages.forEach(function (p) { var o = document.createElement('option'); o.value = p.id; o.textContent = p.name + ' — ' + fmt(p.price); sel.appendChild(o); });
      sel.value = pkgId || ''; $('[name="desc[]"]', tr).value = desc || ''; $('[name="qty[]"]', tr).value = qty || 1; $('[name="price[]"]', tr).value = price || '';
      sel.addEventListener('change', function () {
        var p = packages.filter(function (x) { return String(x.id) === sel.value; })[0];
        if (p) { $('[name="desc[]"]', tr).value = p.name; $('[name="price[]"]', tr).value = p.price; }
        recalc();
      });
      $('.rm', tr).addEventListener('click', function () { if ($$('tr.item', body).length > 1) { tr.remove(); recalc(); } });
      body.appendChild(tr); recalc();
    }
    var pre = []; try { pre = JSON.parse(sf.getAttribute('data-prefill') || '[]'); } catch (e) {}
    if (pre.length) pre.forEach(function (p) { addRow(p.pkg, p.desc, p.qty, p.price); }); else addRow();
    $('#addItem').addEventListener('click', function () { addRow(); });
    sf.addEventListener('input', recalc);

    $$('input[name="client_mode"]', sf).forEach(function (r) {
      r.addEventListener('change', function () {
        var isNew = $('input[name="client_mode"]:checked', sf).value === 'new';
        $('#clientExisting').style.display = isNew ? 'none' : '';
        $('#clientNew').style.display = isNew ? '' : 'none';
      });
    });
    var sh = $('#withShoot');
    if (sh) sh.addEventListener('change', function () { $('#shootBox').style.display = sh.checked ? '' : 'none'; });
    recalc();
  }
})();
