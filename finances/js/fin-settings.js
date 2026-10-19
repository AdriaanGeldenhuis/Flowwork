// /finances/js/fin-settings.js — "Make it yours". Personal sections save to
// /finances/api/prefs.php; company sections (administrators) to
// /finances/api/settings.php. Every section saves on its own and says when it
// has unsaved changes.
(function () {
  'use strict';
  const F = window.FIN;
  const { $, $$, esc, icon, api, toast, toastError, dialog, BOOT, can } = F;
  const nav = $('#setNav'), content = $('#setContent');
  const SWATCHES = ['', '#7a1f30', '#a8281e', '#b07818', '#b08d57', '#2f7457', '#2f5c84', '#4f7cac', '#8a6fb8', '#3b3b3b'];
  const LANDINGS = () => (BOOT.routes || []).map((r) => [r.key, r.label]);
  let S = null;

  const card = (id, ic, title, about, body, foot = true) => `<section id="${id}"><div class="fwf-card2" data-card="${id}">
    <header>${icon(ic)}<div><h2>${esc(title)}</h2>${about ? `<p>${about}</p>` : ''}</div></header>
    <div class="fwf-card2__body">${body}</div>
    ${foot ? `<footer><span class="fwf-state" data-state>Saved</span>${foot === true ? '' : foot}<button class="btn btn--primary btn--sm" type="button" data-save="${id}" disabled>Save</button></footer>` : ''}</div></section>`;
  const sw = (name, cur) => `<div class="fwf-swatches" role="radiogroup">${SWATCHES.map((c) => `<button type="button" class="fwf-swatch${c ? '' : ' fwf-swatch--none'}${(cur || '') === c ? ' is-on' : ''}" style="--c:${c || 'transparent'}" data-swatch="${name}" data-v="${c}" aria-label="${c || 'Default colour'}" aria-pressed="${(cur || '') === c}"></button>`).join('')}
    <label class="fwf-swatch fwf-swatch--pick" aria-label="Pick a colour">${icon('pen', 'i--sm')}<input type="color" data-pick="${name}" value="${esc(cur || '#7a1f30')}"></label></div>`;
  const radio = (name, list, cur) => `<div class="seg" role="radiogroup">${list.map(([v, l]) => `<button type="button" data-radio="${name}" data-v="${esc(v)}" class="${String(cur) === String(v) ? 'is-on' : ''}" aria-pressed="${String(cur) === String(v)}">${esc(l)}</button>`).join('')}</div>`;
  const toggle = (name, label, on, hint = '', disabled = false) => `<label class="switch"><input type="checkbox" data-k="${name}" ${on ? 'checked' : ''} ${disabled ? 'disabled' : ''}><span class="switch__track"></span><span>${esc(label)}${hint ? `<small class="fwf-muted" style="display:block">${esc(hint)}</small>` : ''}</span></label>`;

  async function load() {
    try { S = await api.get('settings.php'); } catch (e) { content.innerHTML = F.errorBox(e); return; }
    paint();
  }

  function paint() {
    const p = BOOT.prefs, c = S.company, admin = can('admin');
    const sections = [['Your Finance', [['look', 'if-sliders', 'Look'], ['start', 'home', 'Start and numbers'], ['panels', 'if-layout', 'Your overview'], ['views', 'bookmark', 'Saved views'], ['keys', 'keyboard', 'Shortcuts']]]];
    if (admin) sections.push(['For the company', [['clook', 'palette', 'Company colour'], ['words', 'if-words', 'Wording'], ['parts', 'if-power', 'Parts of Finance'], ['layout', 'if-layout', 'Overview layout'], ['limits', 'if-hourglass', 'Thresholds'], ['rules', 'if-rules', 'Rules'], ['books', 'if-ledger', 'Books and VAT'], ['map', 'if-map', 'Account mappings'], ['fx', 'if-fx', 'Exchange rates']]]);
    else if (can('write')) sections.push(['For the company', [['fx', 'if-fx', 'Exchange rates']]]);
    nav.innerHTML = sections.map(([h, items]) => `<h5>${esc(h)}</h5>${items.map(([id, ic, l]) => `<a href="#${id}" data-nav="${id}">${icon(ic)}${esc(l)}</a>`).join('')}`).join('');
    const L = c.labels, D = S.defaults;
    const off = new Set(p.overview_off || []), coff = new Set(c.overview_off || []);
    let html = '';
    html += card('look', 'if-sliders', 'Look', 'Only for you. Your colour wins over the company colour.', `
      <div class="fwf-grid2"><div class="field"><span class="label">Density</span>${radio('density', [['comfortable', 'Comfortable'], ['compact', 'Compact']], p.density)}</div>
      <div class="field"><span class="label">Text size</span>${radio('text', [['sm', 'Smaller'], ['md', 'Normal'], ['lg', 'Larger']], p.text)}</div></div>
      <div class="field"><span class="label">Colour</span>${sw('accent', p.accent)}</div>`);
    html += card('start', 'home', 'Start and numbers', 'Where Finance opens for you, how amounts read and which period lists start on.', `
      <div class="fwf-grid3"><div class="field"><label class="label" for="sLand">Open on</label><select class="select" id="sLand" data-k="landing">${LANDINGS().map(([k, l]) => `<option value="${k}"${p.landing === k ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select></div>
      <div class="field"><label class="label" for="sPer">Default period</label><select class="select" id="sPer" data-k="period">${Object.entries(F.PERIOD_LABELS).filter(([k]) => k !== 'all').map(([k, l]) => `<option value="${k}"${p.period === k ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select></div>
      <div class="field"><span class="label">Amounts on cards</span>${radio('money', [['full', 'R 1,234,567.89'], ['compact', 'R 1.2m']], p.money)}</div></div>`);
    html += card('panels', 'if-layout', 'Your overview', 'Hide panels you do not need. The company decides the order.', `<div class="fwf-checks">${(c.overview || []).filter((k) => !coff.has(k)).map((k) => toggle('panel:' + k, S.panels[k], !off.has(k))).join('')}</div>`);
    html += card('views', 'bookmark', 'Saved views', 'Save any filtered list with “Save view” on that page; it appears in the sidebar.', (p.views || []).length ? `<div class="fwf-sortable">${p.views.map((v) => `<div class="fwf-srow"><span class="fwf-srow__grip">${icon('bookmark')}</span><span class="fwf-srow__main"><b>${esc(v.name)}</b><small class="fwf-muted">#${esc(v.route)}${v.query ? '?' + esc(v.query) : ''}</small></span><button class="icon-btn" type="button" data-rmview="${esc(v.id)}" aria-label="Remove ${esc(v.name)}">${icon('trash')}</button></div>`).join('')}</div>` : '<p class="fwf-muted">No saved views yet.</p>', false);
    html += card('keys', 'keyboard', 'Shortcuts', '', `<p class="fwf-muted">Press <kbd class="fwm-kbd">?</kbd> anywhere in Finance for the list: <kbd class="fwm-kbd">Ctrl K</kbd> searches everything, <kbd class="fwm-kbd">c</kbd> creates, <kbd class="fwm-kbd">g</kbd> then a letter goes to a page.</p>
      <button class="btn btn--sm" type="button" id="resetMine">${icon('undo')}Reset my settings</button>`, false);
    if (admin) {
      html += card('clook', 'palette', 'Company colour', 'For everyone who has not chosen their own.', `<div class="field">${sw('caccent', c.accent)}</div>`);
      html += card('words', 'if-words', 'Wording', 'Call things what your business calls them. Empty means the standard word.', `<div class="fwf-grid3">${Object.entries(D.labels).map(([k, def]) => `<div class="field"><label class="label" for="w_${k}">${esc(def)}</label><input class="input" id="w_${k}" data-label="${k}" maxlength="40" value="${esc(L[k] !== def ? L[k] : '')}" placeholder="${esc(def)}"></div>`).join('')}</div>`, `<button class="btn btn--sm btn--ghost" type="button" data-reset="labels">Standard words</button>`);
      html += card('parts', 'if-power', 'Parts of Finance', 'Switch off what the business does not use: its pages, menu items and overview panels disappear. Nothing in the books changes.', `<div class="fwf-checks">${[['bank', 'Bank'], ['receivables', 'Receivables'], ['payables', 'Payables'], ['vat', 'VAT'], ['assets', 'Fixed assets'], ['payroll', 'Payroll'], ['budgets', 'Budgets'], ['currencies', 'Foreign currencies']].map(([k, l]) => toggle('mod:' + k, l, c.modules[k])).join('')}</div>`);
      html += card('layout', 'if-layout', 'Overview layout', 'Drag to set the order everyone sees; switch a panel off for everyone.', `<div class="fwf-sortable" id="ovSort">${(c.overview || []).map((k) => `<div class="fwf-srow${coff.has(k) ? ' is-off' : ''}" data-panel="${k}" draggable="true"><span class="fwf-srow__grip" aria-hidden="true">${icon('grip')}</span><span class="fwf-srow__main"><b>${esc(S.panels[k])}</b></span>${toggle('cpanel:' + k, '', !coff.has(k))}</div>`).join('')}</div>`, `<button class="btn btn--sm btn--ghost" type="button" data-reset="overview">Standard layout</button>`);
      const t = c.thresholds;
      html += card('limits', 'if-hourglass', 'Thresholds', 'When things count as due, late or worth a second look.', `<div class="fwf-grid3">
        <div class="field"><label class="label" for="tDue">“Due soon” is within (days)</label><input class="input" id="tDue" type="number" min="1" max="60" data-t="due_soon_days" value="${esc(t.due_soon_days)}"></div>
        <div class="field"><label class="label" for="tFc">Cash forecast looks ahead</label><select class="select" id="tFc" data-t="forecast_days">${[30, 60, 90].map((d) => `<option value="${d}"${t.forecast_days === d ? ' selected' : ''}>${d} days</option>`).join('')}</select></div>
        <div class="field"><label class="label" for="tStale">Bank lines need attention after (days)</label><input class="input" id="tStale" type="number" min="1" max="180" data-t="stale_bank_days" value="${esc(t.stale_bank_days)}"></div>
        <div class="field"><label class="label" for="tLow">Warn when cash drops below (R)</label><input class="input fwf-amount" id="tLow" inputmode="decimal" data-t="low_cash" value="${esc(t.low_cash || '')}" placeholder="0 = off"></div>
        <div class="field"><label class="label" for="tBig">Flag manual journals above (R)</label><input class="input fwf-amount" id="tBig" inputmode="decimal" data-t="large_journal" value="${esc(t.large_journal || '')}" placeholder="0 = off"></div></div>`);
      const r = c.rules;
      html += card('rules', 'if-rules', 'Rules', 'How manual journals move through the books, and who may look.', `<div class="fwf-checks" style="grid-template-columns:1fr">
        ${toggle('rule:approval', 'Manual journals are approved before posting', r.approval, 'Off: a bookkeeper posts a draft directly.')}
        ${toggle('eng:require_sod', 'Someone else approves and posts (segregation of duties)', S.engine.require_sod === '1', 'The person who prepared or edited a journal cannot approve or post it.')}
        ${toggle('rule:memo_required', 'Manual journals need a description', r.memo_required)}
        ${toggle('rule:attachments_required', 'Manual journals need a supporting document to post', r.attachments_required)}
        ${toggle('rule:members_read', 'Members may look at Finance (read only)', r.members_read, 'Viewers, bookkeepers and admins always can.')}</div>`);
      html += card('books', 'if-ledger', 'Books and VAT', 'Read by the posting engine, reports and the VAT return.', `<div class="fwf-grid2">
        <div class="field"><label class="label" for="bFy">Financial year starts in</label><select class="select" id="bFy" data-e="fiscal_year_start">${S.months.map((m) => `<option${S.engine.fiscal_year_start === m ? ' selected' : ''}>${m}</option>`).join('')}</select></div>
        <div class="field"><span class="label">VAT basis</span>${radio('vat_basis', [['invoice', 'Invoice (accrual)'], ['payments', 'Payments (cash)']], S.engine.vat_basis)}${S.vat_in_flight ? `<div class="fwf-hint">${icon('lock', 'i--xs')} A prepared return must be filed before the basis can change.</div>` : ''}</div></div>`);
      const groups = [...new Set(S.mappings.map((m) => m.group))];
      html += card('map', 'if-map', 'Account mappings', 'Which account the posting engine uses for each role. Unpinned roles use the standard account of your chart.', groups.map((g) => `<div class="fwf-formsec"><h4>${esc(g)}</h4>${S.mappings.filter((m) => m.group === g).map((m) => `<div class="fwf-maprow"><span>${esc(m.label)}${m.pinned ? '' : ' <small class="fwf-muted">standard</small>'}</span>
        <select class="select" data-map="${m.key}">${F.accountOptions(m.account_id, (a) => m.types.includes(a.type))}</select></div>`).join('')}</div>`).join(''));
    }
    if (can('write')) {
      html += card('fx', 'if-fx', 'Exchange rates', 'Rand for one unit of a foreign currency on a date. Used for foreign invoices and bills when no rate is given.', `
        <div class="fwf-grid4" style="align-items:end"><div class="field"><label class="label" for="fxC">Currency</label><input class="input" id="fxC" maxlength="3" placeholder="USD" style="text-transform:uppercase"></div>
        <div class="field"><label class="label" for="fxD">Date</label><input class="input" id="fxD" type="date" value="${esc(BOOT.today)}"></div>
        <div class="field"><label class="label" for="fxR">Rand per unit</label><input class="input fwf-amount" id="fxR" inputmode="decimal" placeholder="18.25"></div>
        <button class="btn" type="button" id="fxAdd">${icon('plus')}Save rate</button></div>
        ${(S.rates || []).length ? `<table class="fwf-table" style="margin-top:10px"><thead><tr><th>Currency</th><th>Date</th><th class="r">Rand</th><th>Source</th></tr></thead><tbody>${S.rates.map((x) => `<tr><td><b>${esc(x.currency)}</b></td><td>${esc(F.fmtDate(x.date, 'always'))}</td><td class="r">${esc(x.rate.toFixed(4))}</td><td>${esc(x.source)}</td></tr>`).join('')}</tbody></table>` : '<p class="fwf-muted">No rates captured yet.</p>'}`, false);
    }
    if (admin) html += `<section><p class="fwf-hint"><button class="btn btn--sm btn--ghost" type="button" data-reset="all">${icon('undo')}Put every company setting back to standard</button> (accounts, VAT and the books are not touched).</p></section>`;
    content.innerHTML = html;
    bind();
    const h = location.hash.replace('#', '');
    if (h && $('#' + CSS.escape(h))) $('#' + CSS.escape(h)).scrollIntoView();
  }

  function dirty(cardEl, on = true) {
    const st = $('[data-state]', cardEl), b = $('[data-save]', cardEl);
    if (st) { st.textContent = on ? 'Not saved yet' : 'Saved'; st.classList.toggle('is-dirty', on); }
    if (b) b.disabled = !on;
  }

  function bind() {
    $$('[data-card]').forEach((cardEl) => {
      cardEl.addEventListener('input', (e) => { if (e.target.matches('input, select, textarea')) dirty(cardEl); });
      cardEl.addEventListener('change', (e) => { if (e.target.matches('input, select, textarea')) dirty(cardEl); });
    });
    $$('[data-radio]').forEach((b) => b.addEventListener('click', () => {
      $$(`[data-radio="${b.dataset.radio}"]`).forEach((x) => { x.classList.toggle('is-on', x === b); x.setAttribute('aria-pressed', x === b); });
      dirty(b.closest('[data-card]'));
    }));
    $$('[data-swatch]').forEach((b) => b.addEventListener('click', () => {
      $$(`[data-swatch="${b.dataset.swatch}"]`).forEach((x) => { x.classList.toggle('is-on', x === b); x.setAttribute('aria-pressed', x === b); });
      dirty(b.closest('[data-card]'));
    }));
    $$('[data-pick]').forEach((inp) => inp.addEventListener('input', () => {
      const g = inp.dataset.pick;
      $$(`[data-swatch="${g}"]`).forEach((x) => x.classList.remove('is-on'));
      inp.closest('.fwf-swatch').classList.add('is-on');
      inp.closest('.fwf-swatch').dataset.v = inp.value;
      dirty(inp.closest('[data-card]'));
    }));
    const ov = $('#ovSort');
    if (ov) F.sortable(ov, '.fwf-srow', () => dirty(ov.closest('[data-card]')));
    $$('[data-save]').forEach((b) => b.addEventListener('click', () => save(b.dataset.save, b.closest('[data-card]'))));
    $$('[data-rmview]').forEach((b) => b.addEventListener('click', async () => {
      try { const d = await api.post('prefs.php', { op: 'view_remove', id: b.dataset.rmview }); BOOT.prefs = d.prefs; paint(); toast('View removed'); } catch (e) { toastError(e); }
    }));
    $$('[data-reset]').forEach((b) => b.addEventListener('click', async () => {
      const part = b.dataset.reset;
      if (!(await dialog({ title: part === 'all' ? 'Put every company setting back?' : 'Back to standard?', body: 'The standard Finance settings come back for everyone. Accounts, VAT settings and the books are not touched.', confirm: 'Reset', danger: true }))) return;
      try { apply(await api.post('settings.php', { op: 'reset', part })); toast('Back to standard'); } catch (e) { toastError(e); }
    }));
    const rm = $('#resetMine');
    if (rm) rm.addEventListener('click', async () => {
      if (!(await dialog({ title: 'Reset your own settings?', body: 'Your look, start page, overview choices and saved views go back to standard.', confirm: 'Reset', danger: true }))) return;
      try { const d = await api.post('prefs.php', { op: 'reset' }); BOOT.prefs = d.prefs; restyle(); paint(); toast('Your settings are back to standard'); } catch (e) { toastError(e); }
    });
    const fx = $('#fxAdd');
    if (fx) fx.addEventListener('click', async () => {
      try { apply(await api.post('settings.php', { op: 'rate', currency: $('#fxC').value.trim().toUpperCase(), date: $('#fxD').value, rate: F.parseMoney($('#fxR').value) })); toast('Rate saved'); } catch (e) { toastError(e); }
    });
    $$('[data-nav]').forEach((a) => a.addEventListener('click', () => { $$('[data-nav]').forEach((x) => x.classList.toggle('is-on', x === a)); }));
  }

  const radioVal = (name) => { const b = $(`[data-radio="${name}"].is-on`); return b ? b.dataset.v : undefined; };
  const swatchVal = (name) => { const b = $(`[data-swatch="${name}"].is-on`) || $(`[data-pick="${name}"]`)?.closest('.fwf-swatch.is-on'); return b ? (b.dataset.v || '') : ''; };

  function apply(d) {
    if (d.boot) { Object.assign(BOOT, d.boot); }
    S = { ...S, ...d };
    restyle();
    paint();
  }
  /** Bring the page's look in line with BOOT straight away. */
  function restyle() {
    const p = BOOT.prefs;
    document.body.classList.toggle('is-compact', p.density === 'compact');
    ['sm', 'md', 'lg'].forEach((t) => document.body.classList.toggle('text-' + t, p.text === t));
    const accent = p.accent || (BOOT.config && BOOT.config.accent) || '';
    let st = $('#finVars');
    if (!accent) { if (st) st.remove(); return; }
    const hex = (x) => parseInt(accent.slice(x, x + 2), 16);
    const [r, g, b] = [hex(1), hex(3), hex(5)];
    const dark = `rgb(${Math.round(r * 0.78)},${Math.round(g * 0.78)},${Math.round(b * 0.78)})`;
    const light = `rgb(${Math.round(r + (255 - r) * 0.45)},${Math.round(g + (255 - g) * 0.45)},${Math.round(b + (255 - b) * 0.45)})`;
    if (!st) { st = document.createElement('style'); st.id = 'finVars'; document.head.appendChild(st); }
    st.textContent = `.fwm.fwf{--accent:${accent};--accent-hover:${dark};--accent-ink:${accent};--accent-solid:${accent};--accent-grad:linear-gradient(140deg,${light} 0%,${accent} 46%,${dark} 100%);--accent-soft:rgba(${r},${g},${b},.08);--accent-softer:rgba(${r},${g},${b},.045);--accent-ring:rgba(${r},${g},${b},.32)}.fwm.fwf[data-theme="dark"]{--accent:${light};--accent-hover:${light};--accent-ink:${light};--accent-solid:${accent};--accent-soft:rgba(${r},${g},${b},.16);--accent-softer:rgba(${r},${g},${b},.09);--accent-ring:rgba(${r},${g},${b},.42)}`;
  }

  async function save(id, cardEl) {
    const btn = $('[data-save]', cardEl);
    btn.disabled = true;
    try {
      if (['look', 'start', 'panels'].includes(id)) {
        const body = {};
        if (id === 'look') { body.density = radioVal('density'); body.text = radioVal('text'); body.accent = swatchVal('accent'); }
        if (id === 'start') { body.landing = $('[data-k=landing]').value; body.period = $('[data-k=period]').value; body.money = radioVal('money'); }
        if (id === 'panels') body.overview_off = $$('[data-k^="panel:"]', cardEl).filter((x) => !x.checked).map((x) => x.dataset.k.slice(6));
        BOOT.prefs = (await api.post('prefs.php', body)).prefs;
        restyle();
        dirty(cardEl, false);
        toast('Saved for you');
        return;
      }
      let d;
      if (id === 'clook') d = await api.post('settings.php', { op: 'company', accent: swatchVal('caccent') });
      if (id === 'words') { const labels = {}; $$('[data-label]', cardEl).forEach((x) => { labels[x.dataset.label] = x.value.trim(); }); d = await api.post('settings.php', { op: 'company', labels }); }
      if (id === 'parts') { const modules = {}; $$('[data-k^="mod:"]', cardEl).forEach((x) => { modules[x.dataset.k.slice(4)] = x.checked; }); d = await api.post('settings.php', { op: 'company', modules }); }
      if (id === 'layout') {
        const rows = $$('[data-panel]', cardEl);
        d = await api.post('settings.php', { op: 'company', overview: rows.map((r) => r.dataset.panel), overview_off: rows.filter((r) => !$('input[type=checkbox]', r).checked).map((r) => r.dataset.panel) });
      }
      if (id === 'limits') { const thresholds = {}; $$('[data-t]', cardEl).forEach((x) => { thresholds[x.dataset.t] = x.dataset.t === 'low_cash' || x.dataset.t === 'large_journal' ? (F.parseMoney(x.value) || 0) : +x.value; }); d = await api.post('settings.php', { op: 'company', thresholds }); }
      if (id === 'rules') {
        const rules = {}; $$('[data-k^="rule:"]', cardEl).forEach((x) => { rules[x.dataset.k.slice(5)] = x.checked; });
        await api.post('settings.php', { op: 'engine', require_sod: $('[data-k="eng:require_sod"]', cardEl).checked ? '1' : '0' });
        d = await api.post('settings.php', { op: 'company', rules });
      }
      if (id === 'books') {
        const was = S.engine;
        const fy = $('[data-e=fiscal_year_start]', cardEl).value, vb = radioVal('vat_basis');
        if (vb !== was.vat_basis && !(await dialog({ title: 'Change the VAT basis?', body: `From the next return, VAT is worked out on the <b>${vb === 'payments' ? 'payments (cash)' : 'invoice (accrual)'}</b> basis. Only change it when SARS has approved the change.`, confirm: 'Change it' }))) { btn.disabled = false; return; }
        d = await api.post('settings.php', { op: 'engine', fiscal_year_start: fy, vat_basis: vb });
      }
      if (id === 'map') {
        // only the roles that changed are pinned; the rest keep following the chart's standard account
        const was = Object.fromEntries(S.mappings.map((m) => [m.key, String(m.account_id || '')]));
        const map = {};
        $$('[data-map]', cardEl).forEach((x) => { if (x.value !== was[x.dataset.map]) map[x.dataset.map] = +x.value || null; });
        if (!Object.keys(map).length) { dirty(cardEl, false); return; }
        d = await api.post('settings.php', { op: 'mappings', map });
      }
      apply(d);
      toast('Saved for the company');
    } catch (e) { toastError(e); btn.disabled = false; }
  }

  F.initChrome();
  load();
})();
