/* G-SMaRT BIR Integration System front-end behaviour. No dependencies. */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var csrf = ($('meta[name="csrf-token"]') || {}).content || '';

  function post(url, data) {
    var body = data instanceof FormData ? data : (function () { var f = new FormData(); Object.keys(data || {}).forEach(function (k) { f.append(k, data[k]); }); return f; })();
    if (!body.has('_csrf')) body.append('_csrf', csrf);
    return fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
      .then(function (r) {
        return r.json().catch(function () {
          // Not JSON: the web server answered before the application could (size limits, timeouts, a crash).
          var why = { 413: 'The web server refused the data as too large (raise LimitRequestBody, or client_max_body_size on Nginx)',
            403: 'The web server or a firewall refused the request (403)', 404: 'The upload address was not found on the server (404)',
            500: 'The server failed while handling the request (500). The server error log has the details',
            502: 'The server did not answer in time (502)', 503: 'The server is busy or down (503)', 504: 'The server took too long to answer (504)' };
          return { ok: false, error: why[r.status] || ('Unexpected response from the server (' + r.status + ')') };
        });
      }, function () { return { ok: false, error: 'The connection to the server was lost' }; });
  }
  window.GSB = { post: post, csrf: csrf };

  /* theme */
  function applyTheme(pref) {
    var dark = pref === 'dark' || (pref === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
    document.documentElement.setAttribute('data-theme-pref', pref);
  }
  $$('[data-theme-toggle]').forEach(function (b) {
    b.addEventListener('click', function () {
      var now = document.documentElement.getAttribute('data-theme');
      var next = now === 'dark' ? 'light' : 'dark';
      try { localStorage.setItem('gsb-theme', next); } catch (e) {}
      applyTheme(next);
    });
  });
  if (window.matchMedia) {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
      var p = 'system'; try { p = localStorage.getItem('gsb-theme') || 'system'; } catch (e) {}
      if (p === 'system') applyTheme('system');
    });
  }

  /* folder tabs (click for touch / keyboard) */
  $$('.tab-btn').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      var tab = btn.parentElement;
      var open = tab.classList.contains('open');
      $$('.tab.open').forEach(function (t) { t.classList.remove('open'); t.firstElementChild.setAttribute('aria-expanded', 'false'); });
      if (!open) { tab.classList.add('open'); btn.setAttribute('aria-expanded', 'true'); }
      e.stopPropagation();
    });
  });
  document.addEventListener('click', function (e) {
    if (!e.target.closest('.tab')) $$('.tab.open').forEach(function (t) { t.classList.remove('open'); });
    var um = $('.user-menu[open]');
    if (um && !e.target.closest('.user-menu')) um.removeAttribute('open');
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      $$('.tab.open').forEach(function (t) { t.classList.remove('open'); });
      closeHelp(); $$('.sheet-overlay').forEach(function (s) { s.hidden = true; });
    }
  });

  /* autosubmit selects */
  $$('[data-autosubmit]').forEach(function (el) { el.addEventListener('change', function () { el.form.submit(); }); });

  /* notices */
  $$('[data-dismiss]').forEach(function (b) { b.addEventListener('click', function () { b.parentElement.remove(); }); });

  /* confirm */
  document.addEventListener('submit', function (e) {
    var f = e.target;
    var msg = f.getAttribute('data-confirm') || (e.submitter && e.submitter.getAttribute('data-confirm'));
    if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
    var req = f.getAttribute('data-prompt');
    if (req) {
      var v = window.prompt(req);
      if (!v) { e.preventDefault(); return; }
      var inp = f.querySelector('input[name="reason"]') || (function () { var i = document.createElement('input'); i.type = 'hidden'; i.name = 'reason'; f.appendChild(i); return i; })();
      inp.value = v;
    }
  });

  /* help drawer */
  var drawer = $('#help-drawer');
  function closeHelp() { if (drawer) drawer.hidden = true; }
  $$('[data-help]').forEach(function (b) { b.addEventListener('click', function () { if (drawer) { drawer.hidden = false; var x = drawer.querySelector('[data-close-help]'); if (x) x.focus(); } }); });
  if (drawer) {
    drawer.addEventListener('click', function (e) { if (e.target === drawer || e.target.closest('[data-close-help]')) closeHelp(); });
  }

  /* manual: images open full size; contents stay open on wide screens */
  var zoom = $('#img-zoom');
  function closeZoom() { if (zoom) { zoom.hidden = true; } }
  document.addEventListener('click', function (e) {
    var img = e.target.closest ? e.target.closest('img[data-zoom]') : null;
    if (img && zoom) {
      zoom.querySelector('img').src = img.currentSrc || img.src;
      zoom.querySelector('img').alt = img.alt;
      var cap = img.closest('figure') ? img.closest('figure').querySelector('figcaption') : null;
      zoom.querySelector('figcaption').textContent = cap ? cap.textContent : '';
      zoom.hidden = false;
      zoom.querySelector('[data-zoom-close]').focus();
      return;
    }
    if (zoom && !zoom.hidden && (e.target === zoom || e.target.closest('[data-zoom-close]'))) { closeZoom(); }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeZoom(); closeHelp(); } });
  var toc = $('[data-toc]');
  if (toc && window.matchMedia) {
    var wide = window.matchMedia('(min-width: 961px)');
    var syncToc = function () { if (wide.matches) { toc.open = true; } };
    syncToc();
    if (!wide.matches && !document.querySelector('.manual-results')) { toc.open = false; }
    if (wide.addEventListener) { wide.addEventListener('change', syncToc); }
    toc.addEventListener('toggle', function () { if (wide.matches && !toc.open) { toc.open = true; } });
    var cur = toc.querySelector('[aria-current="page"]');
    if (cur && wide.matches && cur.scrollIntoView) { var nav = toc.querySelector('nav'); if (nav) { nav.scrollTop = cur.offsetTop - nav.clientHeight / 3; } }
  }

  /* fields that apply to some choices only, for example the purchase month on uploads */
  $$('[data-show-for]').forEach(function (box) {
    var sel = document.getElementById(box.getAttribute('data-show-for'));
    if (!sel) { return; }
    var values = box.getAttribute('data-show-values').split(',');
    var sync = function () {
      var on = values.indexOf(sel.value) !== -1;
      box.hidden = !on;
      $$('input, select, textarea', box).forEach(function (i) { i.disabled = !on; if (i.hasAttribute('data-required')) { i.required = on; } });
    };
    sel.addEventListener('change', sync);
    sync();
  });

  /* bottom sheet */
  $$('[data-open-sheet]').forEach(function (b) { b.addEventListener('click', function () { var s = document.getElementById(b.getAttribute('data-open-sheet')); if (s) s.hidden = false; }); });
  $$('.sheet-overlay').forEach(function (s) { s.addEventListener('click', function (e) { if (e.target === s || e.target.closest('[data-close-sheet]')) s.hidden = true; }); });

  /* select-all checkboxes */
  $$('[data-check-all]').forEach(function (c) {
    c.addEventListener('change', function () { $$('input[name="' + c.getAttribute('data-check-all') + '"]').forEach(function (x) { x.checked = c.checked; }); });
  });

  /* ---------------------------------------------------------------- money helpers (centavos, no floats) */
  function toCents(v) {
    v = String(v || '').replace(/[,\s₱]/g, '');
    var neg = false;
    if (/^\(.*\)$/.test(v)) { neg = true; v = v.slice(1, -1); }
    if (v.charAt(0) === '-') { neg = !neg; v = v.slice(1); }
    if (!/^\d*(\.\d*)?$/.test(v) || v === '' || v === '.') return v === '' ? 0 : null;
    var p = v.split('.'); var i = parseInt(p[0] || '0', 10); var f = ((p[1] || '') + '000').slice(0, 3);
    var c = i * 100 + parseInt(f.slice(0, 2), 10) + (parseInt(f.charAt(2), 10) >= 5 ? 1 : 0);
    return neg ? -c : c;
  }
  function fmtCents(c) {
    var neg = c < 0; c = Math.abs(c);
    var s = Math.floor(c / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + ('0' + (c % 100)).slice(-2);
    return (neg ? '-' : '') + s;
  }
  function rateUnits(r) {
    r = String(r || '').replace('%', '').trim(); if (r === '') return null;
    if (!/^\d+(\.\d+)?$/.test(r)) return null;
    var p = r.split('.'); return parseInt(p[0], 10) * 10000 + parseInt(((p[1] || '') + '0000').slice(0, 4), 10);
  }
  function applyRate(cents, units) {
    var neg = cents < 0; var c = Math.abs(cents);
    var q = Math.floor(c / 1000000), rem = c % 1000000;
    var tax = q * units + Math.floor((rem * units + 500000) / 1000000);
    return neg ? -tax : tax;
  }
  window.GSB.money = { toCents: toCents, fmt: fmtCents, rateUnits: rateUnits, applyRate: applyRate };

  /* ---------------------------------------------------------------- purchase form: R_Purchases columns, live totals, vendor lookup */
  $$('form[data-purchase-form]').forEach(function (form) {
    var field = function (k) { return form.querySelector('[data-pf="' + k + '"]'); };
    var out = function (k) { return form.querySelector('[data-pf-out="' + k + '"]'); };
    var vatInput = field('input_vat');
    var vatHint = form.querySelector('[data-pf-vat-hint]');
    var vatTouched = vatInput && vatInput.value !== '';
    var lastAuto = null;
    function recalc() {
      var c = {};
      ['exempt_amount', 'zero_rated_amount', 'services_amount', 'capital_goods_amount', 'other_goods_amount'].forEach(function (k) {
        var el = field(k); var v = el ? toCents(el.value) : 0;
        c[k] = v === null ? 0 : v;
        if (el) { el.classList.toggle('is-bad', v === null); }
      });
      var taxable = c.services_amount + c.capital_goods_amount + c.other_goods_amount;
      var total = c.exempt_amount + c.zero_rated_amount + taxable;
      var rateEl = field('vat_rate');
      var units = rateEl ? rateUnits(rateEl.value === '' ? '12' : rateEl.value) : 120000;
      if (rateEl) { rateEl.classList.toggle('is-bad', units === null); }
      var vat = units === null ? 0 : applyRate(taxable, units);
      out('taxable').textContent = fmtCents(taxable);
      out('total').textContent = fmtCents(total);
      if (vatInput && !vatInput.disabled) {
        if (!vatTouched || vatInput.value === '' || toCents(vatInput.value) === lastAuto) {
          vatInput.value = fmtCents(vat); vatTouched = false;
        }
        lastAuto = vat;
        var given = toCents(vatInput.value);
        if (vatHint) {
          vatHint.textContent = given !== null && Math.abs(given - vat) > 1
            ? 'Computed ' + fmtCents(vat) + '. The amount you typed is kept and flagged for review.'
            : 'Taxable x rate. Type over it only to match the invoice.';
          vatHint.classList.toggle('warn-text', given !== null && Math.abs(given - vat) > 1);
        }
      }
    }
    $$('[data-pf]', form).forEach(function (el) {
      el.addEventListener('input', function () { if (el === vatInput) { vatTouched = true; } recalc(); });
      el.addEventListener('blur', function () {
        if (el.getAttribute('data-pf') === 'vat_rate') { return; }
        var v = toCents(el.value); if (el.value !== '' && v !== null) { el.value = fmtCents(v); }
      });
    });
    recalc();

    partyLookup(form);
  });

  /* Vendor or payor lookup by TIN (and branch), like typing Vendor_TIN in R_Purchases or the TIN in the SAWT. */
  function partyLookup(form) {
    var noun = form.getAttribute('data-party-noun') || 'vendor';
    var tinEl = form.querySelector('[data-vendor-tin]');
    var brEl = form.querySelector('[data-vendor-branch]');
    var idEl = form.querySelector('[data-vendor-id]');
    var note = form.querySelector('[data-vendor-note]');
    var listEl = form.querySelector('[data-vendors]');
    var vendors = []; try { vendors = JSON.parse(listEl ? listEl.textContent : '[]'); } catch (e) { vendors = []; }
    var map = { company: 'v_company', last: 'v_last', first: 'v_first', middle: 'v_middle', address1: 'v_address1', address2: 'v_address2' };
    var filled = idEl && idEl.value !== '';
    function lockVendor(on) { $$('[data-vendor-field]', form).forEach(function (i) { i.readOnly = on; }); }
    function lookup() {
      if (!tinEl || tinEl.disabled) { return; }
      var d = tinEl.value.replace(/\D/g, '');
      var tin = d.slice(0, 9);
      var br = brEl && brEl.value.replace(/\D/g, '') !== '' ? brEl.value.replace(/\D/g, '') : (d.length > 9 ? d.slice(9) : '0000');
      if (br.length === 3 || br.length === 4) { br = ('0000' + br).slice(-4); }
      var hit = null;
      if (tin.length === 9) { vendors.forEach(function (v) { if (v.tin === tin && v.branch === br) { hit = v; } }); }
      if (hit) {
        Object.keys(map).forEach(function (k) { var i = form.querySelector('[name="' + map[k] + '"]'); if (i) { i.value = hit[k] || ''; } });
        idEl.value = hit.id; filled = true; lockVendor(true);
        note.textContent = 'From your directory: ' + hit.name + (/\.$/.test(hit.name) ? '' : '.');
      } else {
        if (filled) { Object.keys(map).forEach(function (k) { var i = form.querySelector('[name="' + map[k] + '"]'); if (i) { i.value = ''; } }); }
        idEl.value = ''; filled = false; lockVendor(false);
        note.textContent = tin.length === 9 ? 'New ' + noun + '. It is added to your directory when you save.' : '';
      }
    }
    if (tinEl) {
      tinEl.addEventListener('change', lookup);
      tinEl.addEventListener('input', function () { if (tinEl.value.replace(/\D/g, '').length >= 9) { lookup(); } });
      if (brEl) { brEl.addEventListener('change', lookup); }
      if (filled) { lockVendor(true); }
    }
  }

  /* ---------------------------------------------------------------- sales: VAT sales workbook columns, gross <-> net and VAT */
  $$('form[data-sales-form]').forEach(function (form) {
    var f = function (k) { return form.querySelector('[data-sl="' + k + '"]'); };
    var gross = f('gross'), net = f('taxable'), rate = f('rate'), vat = f('vat');
    var hint = form.querySelector('[data-sl-hint]');
    var out = form.querySelector('[data-sl-out="total"]');
    // The amount the person typed last decides the others: gross gives net and VAT, net gives VAT and gross.
    var lead = gross && gross.value !== '' ? 'gross' : 'net';
    var vatTouched = false, lastAuto = { net: null, vat: null, gross: null };
    function divRound(a, b) { var neg = (a < 0) !== (b < 0); a = Math.abs(a); b = Math.abs(b); var q = Math.floor(a / b); if ((a - q * b) * 2 >= b) { q++; } return neg ? -q : q; }
    function cents(el) { var v = el ? toCents(el.value) : 0; if (el) { el.classList.toggle('is-bad', el.value !== '' && v === null); } return v === null ? 0 : v; }
    function setAuto(el, key, c) { if (!el || el.disabled) { return; } el.value = fmtCents(c); lastAuto[key] = c; }
    function isAuto(el, key) { return el && (el.value === '' || toCents(el.value) === lastAuto[key]); }
    function recalc() {
      var u = rateUnits(rate && rate.value !== '' ? rate.value : '12');
      if (rate) { rate.classList.toggle('is-bad', u === null); }
      if (u === null) { u = 120000; }
      var g = cents(gross), n, v;
      if (lead === 'gross' && gross && gross.value !== '') {
        n = divRound(g * 1000000, 1000000 + u);
        if (vatTouched && !isAuto(vat, 'vat')) { n = g - cents(vat); }
        if (isAuto(net, 'net')) { setAuto(net, 'net', n); } else { n = cents(net); }
        if (!vatTouched || isAuto(vat, 'vat')) { setAuto(vat, 'vat', g - n); vatTouched = false; }
      } else {
        n = cents(net);
        if (!vatTouched || isAuto(vat, 'vat')) { setAuto(vat, 'vat', applyRate(n, u)); vatTouched = false; }
        v = cents(vat);
        if (gross && (gross.value === '' || toCents(gross.value) === lastAuto.gross)) { if (net.value !== '') { setAuto(gross, 'gross', n + v); } else { gross.value = ''; lastAuto.gross = null; } }
      }
      v = cents(vat);
      var expected = applyRate(cents(net), u);
      if (hint) {
        var off = vat && vat.value !== '' && Math.abs(v - expected) > 1;
        hint.textContent = off ? 'Taxable sales x rate is ' + fmtCents(expected) + '. The amount you typed is kept and flagged for review.' : 'Taxable sales x rate.';
        hint.classList.toggle('warn-text', off);
      }
      if (out) { out.textContent = fmtCents(cents(f('exempt_amount')) + cents(f('zero_rated_amount')) + cents(net)); }
    }
    $$('[data-sl]', form).forEach(function (el) {
      el.addEventListener('input', function () {
        if (el === gross) { lead = 'gross'; lastAuto.net = toCents(net.value); }
        if (el === net) { lead = 'net'; lastAuto.gross = toCents(gross ? gross.value : ''); }
        if (el === vat) { vatTouched = true; }
        recalc();
      });
      el.addEventListener('blur', function () {
        if (el === rate) { return; }
        var c = toCents(el.value); if (el.value !== '' && c !== null) { el.value = fmtCents(c); }
      });
    });
    if (net && net.value !== '') { lastAuto.net = toCents(net.value); }
    if (vat && vat.value !== '') { lastAuto.vat = toCents(vat.value); }
    if (gross && gross.value !== '') { lastAuto.gross = toCents(gross.value); }
    recalc();
    partyLookup(form);
  });

  /* ---------------------------------------------------------------- taxes withheld on us: SAWT columns, ATC rate and nature, tax as income x rate */
  $$('form[data-sawt-form]').forEach(function (form) {
    var f = function (k) { return form.querySelector('[data-sw="' + k + '"]'); };
    var atc = f('atc'), nature = f('nature'), income = f('income'), rate = f('rate'), tax = f('tax');
    var hint = form.querySelector('[data-sw-hint]');
    var taxTouched = tax && tax.value !== '';
    var lastAuto = null, lastNature = null;
    function atcOpt() { return atc && atc.selectedIndex >= 0 ? atc.options[atc.selectedIndex] : null; }
    function recalc() {
      var o = atcOpt();
      var atcRate = o ? o.getAttribute('data-rate') : null;
      if (rate) { rate.placeholder = atcRate ? atcRate + ' from ATC' : 'From ATC'; }
      var u = rateUnits(rate && rate.value !== '' ? rate.value : (atcRate || ''));
      if (rate) { rate.classList.toggle('is-bad', rate.value !== '' && u === null); }
      var c = toCents(income ? income.value : '');
      if (income) { income.classList.toggle('is-bad', income.value !== '' && c === null); }
      if (!tax || tax.disabled) { return; }
      if (u === null || c === null) { if (!taxTouched) { tax.value = ''; } lastAuto = null; return; }
      var t = applyRate(c, u);
      if (!taxTouched || tax.value === '' || toCents(tax.value) === lastAuto) { tax.value = fmtCents(t); taxTouched = false; }
      lastAuto = t;
      var given = toCents(tax.value);
      if (hint) {
        var off = given !== null && Math.abs(given - t) > 1;
        hint.textContent = off ? 'Income x rate is ' + fmtCents(t) + '. The amount you typed is kept, as certified on the 2307.' : 'Income x rate. Type over it only to match the 2307.';
        hint.classList.toggle('warn-text', off);
      }
    }
    if (atc) {
      atc.addEventListener('change', function () {
        var o = atcOpt();
        if (nature && !nature.disabled && o && o.getAttribute('data-desc') && (nature.value === '' || nature.value === lastNature)) {
          nature.value = o.getAttribute('data-desc'); lastNature = nature.value;
        }
        recalc();
      });
    }
    [income, rate, tax].forEach(function (el) {
      if (!el) { return; }
      el.addEventListener('input', function () { if (el === tax) { taxTouched = true; } recalc(); });
      el.addEventListener('blur', function () {
        if (el === rate) { return; }
        var v = toCents(el.value); if (el.value !== '' && v !== null) { el.value = fmtCents(v); }
      });
    });
    recalc();
    partyLookup(form);
  });

  /* ---------------------------------------------------------------- withholding form: ATC filter + tax preview */
  $$('form[data-withholding]').forEach(function (form) {
    var kind = form.querySelector('[name="tax_kind"]');
    var atc = form.querySelector('[name="atc_code"]');
    var income = form.querySelector('[name="income_amount"]');
    var rate = form.querySelector('[name="tax_rate"]');
    var tax = form.querySelector('[name="tax_withheld"]');
    var note = form.querySelector('[data-atc-note]');
    function filter() {
      if (!kind || !atc) return;
      $$('option[data-kind]', atc).forEach(function (o) { o.hidden = o.getAttribute('data-kind') !== kind.value; o.disabled = o.hidden; });
      var sel = atc.options[atc.selectedIndex];
      if (sel && sel.disabled) atc.value = '';
    }
    function preview() {
      var sel = atc && atc.options[atc.selectedIndex];
      var atcRate = sel ? sel.getAttribute('data-rate') : null;
      if (note) note.textContent = sel && sel.value ? (sel.getAttribute('data-desc') + ' (' + atcRate + '%' + (sel.getAttribute('data-status') !== 'approved' ? ', pending review' : '') + ')') : '';
      var u = rateUnits(rate && rate.value !== '' ? rate.value : atcRate);
      var c = toCents(income ? income.value : '');
      var out = form.querySelector('[data-tax-preview]');
      if (out) out.textContent = (u !== null && c !== null) ? 'Computed tax: ' + fmtCents(applyRate(c, u)) : '';
    }
    if (kind) kind.addEventListener('change', function () { filter(); preview(); });
    [atc, income, rate].forEach(function (el) { if (el) { el.addEventListener('change', preview); el.addEventListener('input', preview); } });
    var fill = form.querySelector('[data-fill-tax]');
    if (fill) fill.addEventListener('click', function () {
      var sel = atc.options[atc.selectedIndex];
      var u = rateUnits(rate.value !== '' ? rate.value : (sel ? sel.getAttribute('data-rate') : ''));
      var c = toCents(income.value);
      if (u !== null && c !== null) tax.value = fmtCents(applyRate(c, u)).replace(/,/g, '');
    });
    filter(); preview();
  });

  /* ---------------------------------------------------------------- compensation: compute totals */
  $$('[data-comp-compute]').forEach(function (b) {
    b.addEventListener('click', function () {
      var f = b.form;
      function v(n) { var e = f.querySelector('[name="' + n + '"]'); return e ? (toCents(e.value) || 0) : 0; }
      function set(n, c) { var e = f.querySelector('[name="' + n + '"]'); if (e) e.value = (c / 100).toFixed(2); }
      ['prev', 'pres'].forEach(function (p) {
        var nt = v(p + '_nt_smw') + v(p + '_nt_13th') + v(p + '_nt_deminimis') + v(p + '_nt_contrib') + v(p + '_nt_other');
        var tx = v(p + '_tx_basic') + v(p + '_tx_13th') + v(p + '_tx_other');
        set(p + '_nt_total', nt); set(p + '_tx_total', tx); set(p + '_gross', nt + tx);
      });
      set('taxable_comp_total', v('prev_tx_total') + v('pres_tx_total'));
      if (!v('net_taxable_comp')) set('net_taxable_comp', v('prev_tx_total') + v('pres_tx_total'));
      set('tax_withheld_adjusted', v('pres_withheld_jan_nov') + v('pres_withheld_dec') - v('over_withheld_refunded'));
      b.textContent = 'Totals computed';
      setTimeout(function () { b.textContent = 'Compute totals'; }, 1600);
    });
  });

  /* ---------------------------------------------------------------- local drafts */
  $$('form[data-autosave]').forEach(function (form) {
    var key = 'gsb-draft-' + form.getAttribute('data-autosave');
    var saved = null; try { saved = JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) {}
    if (saved && form.getAttribute('data-fresh') === '1') {
      var bar = document.createElement('div');
      bar.className = 'callout warn';
      bar.innerHTML = 'An unsaved draft from ' + new Date(saved.t).toLocaleString() + ' is available. <button type="button" class="link-btn">Restore it</button> or <button type="button" class="link-btn">discard</button>.';
      form.insertBefore(bar, form.firstChild);
      var btns = bar.querySelectorAll('button');
      btns[0].addEventListener('click', function () {
        Object.keys(saved.v).forEach(function (n) { var el = form.elements[n]; if (el && el.type !== 'hidden' && el.type !== 'file') { if (el.type === 'checkbox') el.checked = !!saved.v[n]; else el.value = saved.v[n]; } });
        bar.remove();
      });
      btns[1].addEventListener('click', function () { try { localStorage.removeItem(key); } catch (e) {} bar.remove(); });
    }
    var t;
    form.addEventListener('input', function () {
      clearTimeout(t);
      t = setTimeout(function () {
        var v = {};
        Array.prototype.forEach.call(form.elements, function (el) { if (el.name && el.name !== '_csrf' && el.type !== 'file' && el.type !== 'hidden') v[el.name] = el.type === 'checkbox' ? el.checked : el.value; });
        try { localStorage.setItem(key, JSON.stringify({ t: Date.now(), v: v })); } catch (e) {}
      }, 400);
    });
    form.addEventListener('submit', function () { try { localStorage.removeItem(key); } catch (e) {} });
  });

  /* ---------------------------------------------------------------- chunked, resumable upload */
  $$('form[data-chunked]').forEach(function (form) {
    var input = form.querySelector('input[type=file]');
    var bar = form.querySelector('.progress i');
    var label = form.querySelector('[data-upload-status]');
    form.addEventListener('submit', function (e) {
      if (!input || !input.files.length || !window.fetch || !window.Blob) return;
      e.preventDefault();
      var file = input.files[0];
      var size = 1024 * 1024;
      var url = form.getAttribute('data-chunked');
      var btn = form.querySelector('[type=submit]'); if (btn) btn.disabled = true;
      if (label) { label.classList.remove('upload-error'); label.removeAttribute('role'); label.textContent = 'Starting the upload...'; }
      post(url, { action: 'open', name: file.name, total: file.size }).then(function (r) {
        if (!r.ok) throw new Error(r.error);
        var token = r.token, offset = r.received || 0;
        function next() {
          if (offset >= file.size) return post(url, { action: 'finish', token: token });
          var fd = new FormData(); fd.append('action', 'chunk'); fd.append('token', token); fd.append('offset', offset);
          fd.append('chunk', file.slice(offset, offset + size), file.name);
          return post(url, fd).then(function (c) {
            if (!c.ok) throw new Error(c.error);
            offset = c.received;
            if (bar) bar.style.width = Math.round(offset / file.size * 100) + '%';
            if (label) label.textContent = 'Uploaded ' + Math.round(offset / file.size * 100) + '%';
            return next();
          });
        }
        return next();
      }).then(function (done) {
        if (!done.ok) throw new Error(done.error);
        var h = document.createElement('input'); h.type = 'hidden'; h.name = 'upload_token'; h.value = done.token; form.appendChild(h);
        input.value = ''; input.removeAttribute('required');
        form.removeAttribute('data-chunked');
        form.submit();
      }).catch(function (err) {
        var m = String(err && err.message ? err.message : err).replace(/\.+$/, '');
        if (label) { label.textContent = 'Upload failed. ' + m + '.' + (/again/i.test(m) ? '' : ' Choose the file again to resume.'); label.classList.add('upload-error'); label.setAttribute('role', 'alert'); }
        if (bar) bar.style.width = '0';
        if (btn) btn.disabled = false;
      });
    });
  });

  /* ---------------------------------------------------------------- import staging loop */
  var stage = $('[data-stage-url]');
  if (stage) {
    var bar = $('.progress i', stage), txt = $('[data-stage-text]', stage);
    (function loop() {
      post(stage.getAttribute('data-stage-url'), {}).then(function (r) {
        if (!r.ok) { txt.textContent = r.error || 'Staging failed.'; return; }
        bar.style.width = (r.total ? Math.round(r.processed / r.total * 100) : 100) + '%';
        txt.textContent = 'Checked ' + r.processed + ' of ' + r.total + ' rows: ' + r.valid + ' valid, ' + r.errors + ' with errors, ' + r.duplicates + ' duplicates';
        if (r.done) { window.location = stage.getAttribute('data-done-url'); } else { setTimeout(loop, 60); }
      });
    })();
  }

  /* ---------------------------------------------------------------- DAT viewer toggles */
  $$('[data-toggle-view]').forEach(function (b) {
    b.addEventListener('click', function () {
      var target = b.getAttribute('data-toggle-view');
      $$('[data-view]').forEach(function (v) { v.hidden = v.getAttribute('data-view') !== target; });
      $$('[data-toggle-view]').forEach(function (x) { x.classList.toggle('btn-primary', x === b); });
    });
  });

  /* copy */
  $$('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var el = document.getElementById(b.getAttribute('data-copy'));
      if (!el) return;
      (navigator.clipboard ? navigator.clipboard.writeText(el.value || el.textContent) : Promise.reject()).then(function () { b.textContent = 'Copied'; }, function () { el.select && el.select(); });
    });
  });

  /* ---------------------------------------------------------------- grid entry */
  var grid = $('table[data-grid]');
  if (grid) {
    var tbody = grid.tBodies[0];
    var tpl = $('#grid-row-template');
    var lookup = {};
    $$('#cp-list option').forEach(function (o) { lookup[o.value] = o.getAttribute('data-id'); });
    function addRow(focus) {
      var tr = tpl.content.firstElementChild.cloneNode(true);
      tbody.appendChild(tr);
      if (focus) { var f = tr.querySelector('input,select'); if (f) f.focus(); }
      return tr;
    }
    for (var i = 0; i < 5; i++) addRow(false);
    $('[data-grid-add]').addEventListener('click', function () { addRow(true); });
    grid.addEventListener('keydown', function (e) {
      var cell = e.target.closest('td'); if (!cell) return;
      var tr = cell.parentElement;
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); save(); return; }
      if (e.key === 'Enter') {
        e.preventDefault();
        var idx = Array.prototype.indexOf.call(tr.children, cell);
        var nextTr = tr.nextElementSibling || addRow(false);
        var t = nextTr.children[idx] && nextTr.children[idx].querySelector('input,select');
        if (t) t.focus();
      }
    });
    function save() {
      var rows = [];
      $$('tr', tbody).forEach(function (tr, n) {
        if (tr.classList.contains('saved')) return;
        var r = { _row: n }; var any = false;
        $$('[data-col]', tr).forEach(function (el) {
          r[el.getAttribute('data-col')] = el.value;
          var preset = el.hasAttribute('data-default') && el.value === el.getAttribute('data-default');
          if (el.value && el.tagName !== 'SELECT' && !preset) any = true;
        });
        if (r.party) r.counterparty_id = lookup[r.party] || '';
        if (any) rows.push(r);
      });
      if (!rows.length) return;
      var status = $('[data-grid-status]');
      status.textContent = 'Saving ' + rows.length + ' rows...';
      post(grid.getAttribute('data-grid'), { rows: JSON.stringify(rows) }).then(function (res) {
        if (!res.ok) { status.textContent = res.error; return; }
        var trs = $$('tr', tbody), ok = 0, bad = 0;
        res.results.forEach(function (r) {
          var tr = trs[r.row]; if (!tr) return;
          var m = tr.querySelector('.msg');
          if (r.ok) { ok++; tr.classList.add('saved'); $$('input,select', tr).forEach(function (el) { el.disabled = true; }); if (m) m.textContent = 'Saved'; }
          else { bad++; if (m) m.textContent = r.error; }
        });
        status.textContent = ok + ' saved' + (bad ? ', ' + bad + ' need fixing' : '');
      });
    }
    $('[data-grid-save]').addEventListener('click', save);
  }
})();
