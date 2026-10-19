// /crm/js/import.js — Import & export.
// Import in four steps: choose a CSV (upload, drop or paste) → match its
// columns to CRM fields (guessed for you) → check first (a dry run that writes
// nothing and lists every problem by row) → import. Then export cards for
// every part of the CRM and the recent imports. API: /crm/api/transfer.php.
(function () {
  'use strict';
  const { $, $$, esc, icon, api, toast, BOOT, L, dialog } = CRM;
  const root = $('#imp');
  const S = { step: 1, type: BOOT.config.modules.customers ? 'customer' : 'supplier', parsed: null, mapping: [], existing: 'skip', dry: null, result: null, targets: {}, fields: [], history: [], busy: false };
  const STEPS = ['Choose a file', 'Match columns', 'Check', 'Done'];
  const GROUPS = ['Account', 'Contact', 'Address'];
  const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;
  const typeWord = (t, many) => L(t === 'customer' ? (many ? 'customers' : 'customer') : (many ? 'suppliers' : 'supplier')).toLowerCase();

  // ------------------------------------------------------------- layout
  function render() {
    root.innerHTML = `
      <section class="fwc-card2" id="importCard" aria-labelledby="impH">
        <header>${icon('upload')}<div class="fwc-grow"><h2 id="impH">Bring in your list</h2><p>From Excel, Google Sheets, Sage, Xero or an old system — anything you can save as CSV. Nothing is saved until you press Import.</p></div></header>
        <div class="fwc-card2__body">
          <div class="fwc-steps" aria-label="Steps">${STEPS.map((s, i) => `<span class="fwc-step${S.step === i + 1 ? ' is-on' : ''}${S.step > i + 1 ? ' is-done' : ''}"${S.step === i + 1 ? ' aria-current="step"' : ''}><b>${S.step > i + 1 ? icon('check', 'i--xs') : i + 1}</b>${esc(s)}</span>`).join('')}</div>
          <div class="fwc-impstep" id="impStep">${[step1, step2, step3, step4][S.step - 1]()}</div>
        </div>
      </section>
      ${exportCard()}
      ${historyCard()}`;
    [bind1, bind2, bind3, bind4][S.step - 1]();
    bindExport();
  }

  // ------------------------------------------------------------- step 1
  function step1() {
    const m = BOOT.config.modules;
    const types = [['customer', icon('ic-customer', 'i--sm') + esc(L('customers'))], ['supplier', icon('ic-supplier', 'i--sm') + esc(L('suppliers'))]].filter(([t]) => m[t + 's']);
    return `
      <div class="fwc-impq"><span class="label">What are you importing?</span><div class="seg" role="radiogroup" id="impType">${types.map(([v, label]) => `<button type="button" role="radio" aria-checked="${S.type === v}" class="${S.type === v ? 'is-on' : ''}" data-v="${v}">${label}</button>`).join('')}</div></div>
      <label class="fwc-drop" id="drop" tabindex="0">
        ${icon('upload')}<b>Drop your CSV file here</b><span>or click to choose one · up to 5,000 rows · 5 MB</span>
        <input type="file" id="impFile" accept=".csv,.txt,.tsv,text/csv" hidden>
      </label>
      <div class="fwc-impalt">
        <button class="btn btn--ghost btn--sm" type="button" id="pasteBtn">${icon('i-copy')}Paste rows instead</button>
        <a class="btn btn--ghost btn--sm" href="/crm/api/transfer.php?op=template" download>${icon('download')}Download a template</a>
        <span class="hint">Working in Excel? <b>File › Save as › CSV UTF-8</b>, then drop the file here.</span>
      </div>
      <div class="fwc-paste" id="pasteBox" hidden>
        <textarea class="textarea" id="pasteText" rows="8" placeholder="Copy the cells in your spreadsheet (with the heading row) and paste them here"></textarea>
        <div class="fwc-paste__foot"><button class="btn btn--primary" type="button" id="pasteGo">${icon('chev-right')}Continue</button></div>
      </div>`;
  }
  function bind1() {
    const seg = $('#impType');
    seg.addEventListener('click', (e) => {
      const b = e.target.closest('button');
      if (!b) return;
      S.type = b.dataset.v;
      $$('button', seg).forEach((x) => { x.classList.toggle('is-on', x === b); x.setAttribute('aria-checked', x === b); });
    });
    const drop = $('#drop'), file = $('#impFile');
    file.addEventListener('change', () => { if (file.files[0]) upload(file.files[0]); });
    drop.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); file.click(); } });
    ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('is-over'); }));
    drop.addEventListener('drop', (e) => { const f = e.dataTransfer.files[0]; if (f) upload(f); });
    $('#pasteBtn').addEventListener('click', () => { const box = $('#pasteBox'); box.hidden = !box.hidden; if (!box.hidden) $('#pasteText').focus(); });
    $('#pasteGo').addEventListener('click', async () => {
      const text = $('#pasteText').value;
      if (!text.trim()) { toast('Paste the rows first.', { type: 'error' }); return; }
      await parse({ op: 'parse', text, name: 'pasted rows' });
    });
  }
  async function upload(f) {
    if (!/\.(csv|txt|tsv)$/i.test(f.name)) { toast('Save the spreadsheet as CSV (File › Save as › CSV) and choose that file.', { type: 'error' }); return; }
    if (f.size > 5 * 1024 * 1024) { toast('The file is too large (5 MB at most). Split it into smaller files.', { type: 'error' }); return; }
    const fd = new FormData();
    fd.append('op', 'parse');
    fd.append('file', f);
    await parse(fd, true);
  }
  async function parse(body, isForm) {
    if (S.busy) return;
    S.busy = true;
    const drop = $('#drop');
    if (drop) drop.innerHTML = `<span class="fwc-spin" aria-hidden="true"></span><b>Reading your file…</b>`;
    try {
      const d = isForm ? await api.upload('transfer.php', body) : await api.post('transfer.php', body);
      S.parsed = d;
      S.mapping = d.mapping.slice();
      S.dry = null;
      S.step = 2;
      render();
      $('#page').scrollTo({ top: 0 });
    } catch (err) {
      toast(err.message, { type: 'error' });
      render();
    } finally { S.busy = false; }
  }

  // ------------------------------------------------------------- step 2
  function targetOptions(cur) {
    let html = `<option value="">— Don't import —</option>`;
    GROUPS.forEach((g) => {
      const opts = Object.entries(S.targets).filter(([, t]) => t[1] === g);
      html += `<optgroup label="${esc(g === 'Account' ? L(S.type) : g)}">${opts.map(([k, t]) => `<option value="${k}" ${cur === k ? 'selected' : ''}>${esc(t[0])}</option>`).join('')}</optgroup>`;
    });
    const custom = S.fields.filter((f) => f.applies_to === 'all' || f.applies_to === S.type);
    if (custom.length) html += `<optgroup label="Your own fields">${custom.map((f) => `<option value="cf_${f.id}" ${cur === 'cf_' + f.id ? 'selected' : ''}>${esc(f.label)}</option>`).join('')}</optgroup>`;
    return html;
  }
  function step2() {
    const p = S.parsed;
    const mapped = S.mapping.filter(Boolean).length;
    return `
      <div class="fwc-impfile">${icon('i-file-text')}<div><b>${esc(p.name)}</b><span>${plural(p.rows, 'row', 'rows')} · ${plural(p.headers.length, 'column', 'columns')} · importing as ${esc(typeWord(S.type, true))}</span></div>
        <button class="btn btn--ghost btn--sm" type="button" id="impRestart">${icon('refresh')}Another file</button></div>
      <p class="fwc-impnote" id="mapNote">${icon('sparkle', 'i--sm')}We matched <b>${mapped}</b> of ${p.headers.length} columns. Check the ones in <span class="fwc-mark">amber</span>, then press <b>Check first</b>.</p>
      <div class="fwc-maptable-wrap"><table class="fwc-maptable">
        <thead><tr><th>Column in your file</th><th>Examples</th><th>Goes into</th></tr></thead>
        <tbody>${p.headers.map((h, i) => {
          const ex = p.sample.map((r) => r[i]).filter((v) => v !== '').slice(0, 3);
          return `<tr data-i="${i}" class="${S.mapping[i] ? '' : 'is-skip'}"><td><b>${esc(h)}</b></td><td>${ex.length ? ex.map((v) => `<small title="${esc(v)}">${esc(v)}</small>`).join('') : '<small class="fwc-muted">empty</small>'}</td>
            <td><select class="select" data-map="${i}" aria-label="Where ${esc(h)} goes">${targetOptions(S.mapping[i])}</select></td></tr>`;
        }).join('')}</tbody></table></div>
      <div class="fwc-impopts">
        <span class="label">When a ${esc(typeWord(S.type))} is already in the CRM (same name)</span>
        <div class="fwc-radios">
          ${[['skip', 'Leave it alone', 'Skip that row.'], ['update', 'Update it', 'Fill in and overwrite with the values in the file. Empty cells change nothing.'], ['add', 'Add it anyway', 'Creates a second one with “(2)” after the name.']]
            .map(([v, t, h]) => `<label class="fwc-radio"><input type="radio" name="existing" value="${v}" ${S.existing === v ? 'checked' : ''}><span><b>${t}</b><small>${h}</small></span></label>`).join('')}
        </div>
      </div>
      <div class="fwc-impnav"><button class="btn" type="button" id="back1">${icon('chev-left')}Back</button><span class="fwc-grow"></span><span class="fwc-impwarn" id="mapWarn" role="alert"></span><button class="btn btn--primary" type="button" id="dryBtn">${icon('eye')}Check first</button></div>`;
  }
  function validateMap() {
    const seen = {};
    let dup = '';
    S.mapping.forEach((t) => { if (!t) return; if (seen[t]) dup = t; seen[t] = true; });
    $$('#impStep select[data-map]').forEach((s) => {
      const tr = s.closest('tr');
      tr.classList.toggle('is-skip', !s.value);
      tr.classList.toggle('is-dup', !!s.value && s.value === dup);
    });
    const label = (t) => (S.targets[t] ? S.targets[t][0] : (S.fields.find((f) => 'cf_' + f.id === t) || {}).label || t);
    const warn = !S.mapping.includes('name') ? `Choose the column with the ${typeWord(S.type)} name.` : dup ? `“${label(dup)}” is chosen for two columns.` : '';
    $('#mapWarn').textContent = warn;
    $('#dryBtn').disabled = !!warn;
    return !warn;
  }
  function bind2() {
    $$('#impStep select[data-map]').forEach((s) => s.addEventListener('change', () => { S.mapping[+s.dataset.map] = s.value; S.dry = null; validateMap(); }));
    $$('#impStep input[name=existing]').forEach((r) => r.addEventListener('change', () => { S.existing = r.value; S.dry = null; }));
    // a guessed column the person has not looked at yet shows amber when nothing matched it
    $$('#impStep tr.is-skip').forEach((tr) => tr.classList.add('is-check'));
    $('#back1').addEventListener('click', () => { S.step = 1; render(); });
    $('#impRestart').addEventListener('click', () => { S.step = 1; S.parsed = null; render(); });
    $('#dryBtn').addEventListener('click', () => run(true));
    validateMap();
  }
  async function run(dry) {
    if (S.busy || (dry && !validateMap())) return;
    S.busy = true;
    const btn = dry ? $('#dryBtn') : $('#goBtn');
    const prev = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = `<span class="fwc-spin" aria-hidden="true"></span>${dry ? 'Checking…' : 'Importing…'}`;
    try {
      const mapping = {};
      S.mapping.forEach((t, i) => { if (t) mapping[i] = t; });
      const d = await api.post('transfer.php', { op: 'run', token: S.parsed.token, type: S.type, mapping, existing: S.existing, dry: dry ? 1 : 0 });
      if (dry) { S.dry = d; S.step = 3; } else { S.result = d; S.step = 4; await loadHistory(); }
      render();
      $('#page').scrollTo({ top: 0 });
    } catch (err) {
      toast(err.message, { type: 'error' });
      btn.disabled = false;
      btn.innerHTML = prev;
      if (/expired/i.test(err.message)) { S.step = 1; S.parsed = null; render(); }
    } finally { S.busy = false; }
  }

  // ------------------------------------------------------------- step 3/4
  function counts(c, dry) {
    const box = (n, label, color) => `<div style="--c:${color}"><b>${n}</b><span>${label}</span></div>`;
    return `<div class="fwc-result">
      ${box(c.created, dry ? 'will be added' : 'added', 'var(--success)')}
      ${box(c.updated, dry ? 'will be updated' : 'updated', 'var(--info)')}
      ${box(c.skipped, dry ? 'will be skipped' : 'skipped', 'var(--ink-3)')}
      ${box(c.failed, dry ? 'have problems' : 'not imported', c.failed ? 'var(--danger)' : 'var(--ink-3)')}
      ${box(c.contacts, dry ? 'contacts with them' : 'contacts', 'var(--gold-ink)')}</div>`;
  }
  function messages(list) {
    if (!list.length) return '';
    const failed = list.filter((m) => m.result === 'failed');
    const other = list.filter((m) => m.result !== 'failed');
    const rows = (ms) => ms.map((m) => `<tr class="is-${esc(m.result)}"><td>Row ${m.row}</td><td><b>${esc(m.name || '—')}</b></td><td>${esc(m.text)}</td></tr>`).join('');
    return `${failed.length ? `<h3 class="fwc-subh" style="margin-top:18px">${icon('alert', 'i--sm')}Fix these in your file (or leave them out)</h3><div class="fwc-maptable-wrap"><table class="fwc-maptable fwc-msgs">${rows(failed)}</table></div>` : ''}
      ${other.length ? `<details class="fwc-archived" style="margin-top:12px"><summary>${icon('ban', 'i--sm')}${plural(other.length, 'row', 'rows')} skipped</summary><div class="fwc-maptable-wrap"><table class="fwc-maptable fwc-msgs">${rows(other)}</table></div></details>` : ''}`;
  }
  function step3() {
    const d = S.dry, c = d.counts;
    const n = c.created + c.updated;
    return `
      <p class="fwc-impnote">${icon('eye', 'i--sm')}This is what will happen. <b>Nothing has been saved yet.</b></p>
      ${counts(c, true)}
      ${messages(d.messages)}
      <div class="fwc-impnav"><button class="btn" type="button" id="back2">${icon('chev-left')}Change the matching</button><span class="fwc-grow"></span>
        <button class="btn btn--primary" type="button" id="goBtn" ${n ? '' : 'disabled'}>${icon('check')}${n ? `Import ${plural(n, 'row', 'rows')}` : 'Nothing to import'}</button></div>`;
  }
  function bind3() {
    $('#back2').addEventListener('click', () => { S.step = 2; render(); });
    $('#goBtn').addEventListener('click', () => run(false));
  }
  function step4() {
    const d = S.result, c = d.counts;
    const route = S.type === 'customer' ? 'customers' : 'suppliers';
    return `
      <div class="fwc-done">${icon('ic-trophy')}<div><h3>Imported</h3><p>${plural(c.created, typeWord(S.type), typeWord(S.type, true))} added${c.updated ? `, ${c.updated} updated` : ''}${c.contacts ? ` with ${plural(c.contacts, 'contact', 'contacts')}` : ''}.</p></div></div>
      ${counts(c, false)}
      ${messages(d.messages)}
      <div class="fwc-impnav"><button class="btn" type="button" id="again">${icon('upload')}Import another file</button><span class="fwc-grow"></span>
        <a class="btn btn--primary" href="/crm/#${route}?sort=created&amp;dir=desc">${icon('chev-right')}See them in ${esc(L(route))}</a></div>`;
  }
  function bind4() {
    $('#again').addEventListener('click', () => { S.step = 1; S.parsed = null; S.dry = null; S.result = null; render(); });
  }

  // ------------------------------------------------------------- export
  function exportCard() {
    const m = BOOT.config.modules;
    const items = [
      ['customers', 'ic-customer', L('customers'), 'With their main contact, address, tags and your own fields', m.customers],
      ['suppliers', 'ic-supplier', L('suppliers'), 'With their main contact, address, tags and your own fields', m.suppliers],
      ['contacts', 'i-users', L('contacts'), `Everyone at your ${L('customers').toLowerCase()} and ${L('suppliers').toLowerCase()}`, true],
      ['deals', 'i-kanban', L('deals'), 'Every deal with its stage, value and owner', m.pipeline],
      ['tasks', 'i-check-square', L('tasks'), 'Open and done, with due dates and who has them', m.tasks],
      ['activity', 'ic-activity', 'Activity', 'Calls, meetings, emails and notes', true],
    ].filter((x) => x[4]);
    const st = BOOT.config.statuses;
    return `<section class="fwc-card2" aria-labelledby="expH">
      <header>${icon('download')}<div class="fwc-grow"><h2 id="expH">Take it with you</h2><p>Download any part of the CRM as a CSV that opens in Excel or Google Sheets.</p></div></header>
      <div class="fwc-card2__body">
        <div class="fwc-expfilter"><span class="label">${esc(L('customers'))} &amp; ${esc(L('suppliers').toLowerCase())}:</span>
          <select class="select" id="expStatus" aria-label="Only this status"><option value="">Every status</option>${Object.keys(st).map((k) => `<option value="${k}">Only ${esc(st[k].label.toLowerCase())}</option>`).join('')}</select>
          ${(BOOT.tags || []).length ? `<select class="select" id="expTag" aria-label="Only this tag"><option value="">Any tag</option>${BOOT.tags.map((t) => `<option value="${t.id}">Tagged ${esc(t.name)}</option>`).join('')}</select>` : ''}</div>
        <div class="fwc-exports">${items.map(([k, ic, label, hint]) => `<a href="/crm/api/transfer.php?op=export&amp;what=${k}" data-exp="${k}" download>${icon(ic)}<span>${esc(label)}<small>${esc(hint)}</small></span>${icon('download', 'fwc-exports__dl')}</a>`).join('')}</div>
      </div></section>`;
  }
  function bindExport() {
    const upd = () => {
      const status = ($('#expStatus') || {}).value || '', tag = ($('#expTag') || {}).value || '';
      $$('[data-exp]').forEach((a) => {
        const k = a.dataset.exp;
        const q = new URLSearchParams({ op: 'export', what: k });
        if (k === 'customers' || k === 'suppliers') { if (status) q.set('status', status); if (tag) q.set('tag', tag); }
        a.href = '/crm/api/transfer.php?' + q.toString();
      });
    };
    ['#expStatus', '#expTag'].forEach((s) => { const el = $(s); if (el) el.addEventListener('change', upd); });
    $$('[data-exp]').forEach((a) => a.addEventListener('click', () => toast('Preparing your download…')));
  }

  // ------------------------------------------------------------- history
  function historyCard() {
    const h = S.history;
    return `<section class="fwc-card2" aria-labelledby="histH">
      <header>${icon('i-history')}<div class="fwc-grow"><h2 id="histH">Recent imports</h2><p>The last 20 files brought in, by whom and how they went.</p></div></header>
      <div class="fwc-card2__body">${h.length ? `<div class="fwc-maptable-wrap"><table class="fwc-maptable"><thead><tr><th>File</th><th>When</th><th>Who</th><th>Rows</th><th>Result</th></tr></thead><tbody>
        ${h.map((x) => `<tr><td><b>${esc(x.file)}</b></td><td>${esc(CRM.relTime(x.at))}</td><td>${esc(x.who || '—')}</td><td>${x.rows}</td>
          <td>${x.status === 'completed' || x.status === 'done' ? `<span class="fwc-pill" data-tone="success">${x.ok} in</span>` : `<span class="fwc-pill" data-tone="muted">${esc(x.status)}</span>`}${x.failed ? ` <span class="fwc-pill" data-tone="danger">${x.failed} failed</span>` : ''}</td></tr>`).join('')}
        </tbody></table></div>` : `<p class="hint">Nothing imported yet.</p>`}</div></section>`;
  }
  async function loadHistory() {
    try {
      const d = await api.get('transfer.php');
      S.history = d.history;
      S.targets = d.targets;
      S.fields = d.fields;
    } catch (err) { /* the importer still works */ }
  }

  CRM.initChrome();
  loadHistory().then(render);
})();
