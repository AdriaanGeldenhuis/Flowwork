// /crm/js/dedupe.js — Duplicates.
// Scan for customers or suppliers that look alike (same VAT or registration
// number, email, phone, website or a very similar name), compare a pair side
// by side, choose which one stays and which value to keep for every field,
// and merge: contacts, activity, deals, tasks, documents, bills and the books
// all move to the one that stays. "Not duplicates" hides a pair for good.
// API: /crm/api/dedupe.php.
(function () {
  'use strict';
  const { $, $$, esc, icon, api, toast, BOOT, L, dialog, tempModal, openModal, closeModal, avatar } = CRM;
  const root = $('#dup');
  const S = { list: [], type: 'all', focus: BOOT.focus || null, scanned: null, busy: false };
  const FIELD_LABELS = { name: 'Name', legal_name: 'Legal name', reg_no: 'Registration number', vat_no: 'VAT number', email: 'Email', phone: 'Phone', website: 'Website',
    industry_id: 'Industry', region_id: 'Region', status: 'Status', owner_id: 'Owner', currency: 'Currency', notes: 'Notes' };
  const COUNT_LABELS = { contacts: ['contact', 'contacts'], activity: ['activity', 'activities'], deals: ['deal', 'deals'], documents: ['document', 'documents'] };
  const plural = (n, [one, many]) => `${n} ${n === 1 ? one : many}`;

  function scoreRing(score) {
    const pct = Math.round(score * 100);
    const c = pct >= 92 ? 'var(--danger)' : pct >= 85 ? 'var(--warning)' : 'var(--gold)';
    return `<span class="fwc-ring" style="--p:${pct};--c:${c}" title="${pct}% alike"><b>${pct}</b><small>%</small></span>`;
  }
  function pairs() {
    let list = S.list;
    if (S.type !== 'all') list = list.filter((c) => c.type === S.type);
    if (S.focus) list = list.filter((c) => c.left.id === S.focus.id || c.right.id === S.focus.id);
    return list;
  }

  function render() {
    const list = pairs();
    const m = BOOT.config.modules;
    const counts = { all: S.list.length, customer: S.list.filter((c) => c.type === 'customer').length, supplier: S.list.filter((c) => c.type === 'supplier').length };
    const chips = [['all', 'All'], ['customer', L('customers')], ['supplier', L('suppliers')]].filter(([k]) => k === 'all' || m[k + 's']);
    root.innerHTML = `
      <section class="fwc-card2 fwc-dupscan">
        <header>${icon('ic-merge')}<div class="fwc-grow"><h2>Keep one of everyone</h2><p>Flowwork looks for accounts with the same VAT number, registration number or email, or names that are nearly the same (a shared phone number counts towards it). Merging moves everything — contacts, activity, deals, quotes, invoices, bills and the books — onto the one you keep.</p></div></header>
        <div class="fwc-card2__body fwc-dupscan__row">
          <div class="fwc-chips" role="tablist">${chips.map(([k, label]) => `<button class="fwc-chip${S.type === k ? ' is-on' : ''}" type="button" role="tab" aria-selected="${S.type === k}" data-type="${k}">${esc(label)}<small>${counts[k]}</small></button>`).join('')}</div>
          <span class="fwc-grow"></span>
          ${S.scanned ? `<span class="hint">Checked ${S.scanned.scanned} accounts just now · ${S.scanned.found} new suggestion${S.scanned.found === 1 ? '' : 's'}</span>` : ''}
          <button class="btn btn--primary" type="button" id="scanBtn">${icon('search')}Scan for duplicates</button>
        </div>
      </section>
      ${S.focus ? `<div class="fwc-banner fwc-banner--focus">${avatar(S.focus.name, 'fwc-ava--sm')}<span>Showing suggestions for <b>${esc(S.focus.name)}</b>.</span>
          <span class="fwc-grow"></span><div class="fwc-mergewith"><input class="input" id="mergeWith" placeholder="Merge with… type a name" aria-label="Merge with another account" autocomplete="off"></div>
          <button class="icon-btn" type="button" id="unfocus" aria-label="Show all suggestions" data-tip="Show all">${icon('x')}</button></div>` : ''}
      <div id="pairs">${list.length ? list.map(pairHtml).join('') : CRM.emptyState('ic-heart', S.list.length || S.focus ? 'Nothing here' : 'No duplicates found',
        S.focus ? `No suggestions for ${esc(S.focus.name)}. Type another account's name above to merge them anyway.` : 'Run a scan now and then — after an import, for example. Every pair you merge or wave away stays sorted.', '')}</div>`;
    bind();
  }
  function pairHtml(c) {
    const side = (a) => `<a class="fwc-pair__acc" href="${CRM.accountUrl(a.id)}" target="_blank" rel="noopener">${avatar(a.name, 'fwc-ava--sm')}<span><b>${esc(a.name)}</b><small>#${a.id} · ${esc(L(c.type))}</small></span></a>`;
    return `<div class="fwc-pair" data-id="${c.id}">
      ${side(c.left)}
      <div class="fwc-pair__mid">${scoreRing(c.score)}<div class="fwc-why">${c.why.map((w) => `<span class="fwc-badge">${esc(w)}</span>`).join('')}</div></div>
      ${side(c.right)}
      <div class="fwc-pair__acts"><button class="btn btn--primary btn--sm" type="button" data-merge="${c.id}">${icon('ic-merge')}Compare &amp; merge</button>
        <button class="btn btn--ghost btn--sm" type="button" data-dismiss="${c.id}">${icon('x')}Not the same</button></div>
    </div>`;
  }
  function bind() {
    $$('[data-type]', root).forEach((b) => b.addEventListener('click', () => { S.type = b.dataset.type; render(); }));
    $('#scanBtn').addEventListener('click', scan);
    const un = $('#unfocus');
    if (un) un.addEventListener('click', () => { S.focus = null; history.replaceState(null, '', '/crm/dedupe.php'); render(); });
    const mw = $('#mergeWith');
    if (mw && CRM.forms) CRM.forms.accountPicker(mw, (a) => { if (a && a.id !== S.focus.id) compare(S.focus.id, a.id, 0); }, { type: S.focus.type });
  }
  async function onClick(e) {
    const mb = e.target.closest('[data-merge]');
    if (mb) { const c = S.list.find((x) => x.id === Number(mb.dataset.merge)); compare(c.left.id, c.right.id, c.id); return; }
    const db = e.target.closest('[data-dismiss]');
    if (db) {
      const id = Number(db.dataset.dismiss);
      const row = db.closest('.fwc-pair');
      row.classList.add('is-leaving');
      try {
        const d = await api.post('dedupe.php', { op: 'dismiss', id });
        S.list = d.candidates;
        setTimeout(render, 180);
        toast('Got it — that pair will not be suggested again');
      } catch (err) { row.classList.remove('is-leaving'); toast(err.message, { type: 'error' }); }
    }
  }
  async function scan() {
    if (S.busy) return;
    S.busy = true;
    const b = $('#scanBtn');
    b.disabled = true;
    b.innerHTML = `<span class="fwc-spin" aria-hidden="true"></span>Scanning…`;
    try {
      const d = await api.post('dedupe.php', { op: 'scan', type: S.type });
      S.list = d.candidates;
      S.scanned = { scanned: d.scanned, found: d.found };
      render();
    } catch (err) {
      toast(err.message, { type: 'error' });
      b.disabled = false;
      b.innerHTML = `${icon('search')}Scan for duplicates`;
    } finally { S.busy = false; }
  }

  // ------------------------------------------------------- compare & merge
  function display(acc, f) {
    const v = acc.fields[f];
    if (v == null || String(v).trim() === '') return '';
    if (f === 'industry_id' || f === 'region_id' || f === 'owner_id') return acc.labels[f] || '#' + v;
    if (f === 'status') return (BOOT.config.statuses[v] || {}).label || v;
    return String(v);
  }
  async function compare(leftId, rightId, candidateId) {
    let d;
    try { d = await api.get('dedupe.php', { op: 'compare', left: leftId, right: rightId }); } catch (err) { toast(err.message, { type: 'error' }); return; }
    const [A, B] = d.accounts;
    const weight = (x) => Object.values(x.counts).reduce((a, n) => a + n, 0);
    let keep = weight(B) > weight(A) ? 'B' : 'A';
    const acc = { A, B };
    const fields = Object.keys(FIELD_LABELS);
    const head = (k) => {
      const x = acc[k];
      return `<label class="fwc-keep" data-keep="${k}"><input type="radio" name="keep" value="${k}" ${keep === k ? 'checked' : ''}>
        <span>${avatar(x.fields.name, 'fwc-ava--sm')}<span><b>${esc(x.fields.name)}</b><small>#${x.id} · added ${esc(CRM.fmtDate(String(x.created_at).slice(0, 10)))}</small>
        <small>${Object.entries(x.counts).map(([c, n]) => plural(n, COUNT_LABELS[c])).join(' · ')}</small></span></span><em class="fwc-keep__tag"></em></label>`;
    };
    const m = tempModal(`<div class="fwm-dialog__head"><h2>Merge two ${esc(L(A.type + 's').toLowerCase())}</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <div class="fwm-dialog__body">
        <p class="fwm-dialog__lead" style="margin-bottom:12px">Choose the one that stays, then the value to keep where they differ.</p>
        <div class="fwc-keeps">${head('A')}${head('B')}</div>
        <div class="fwc-maptable-wrap" style="margin-top:12px"><table class="fwc-merge"><thead><tr><th>Field</th><th>${esc(A.fields.name)}</th><th>${esc(B.fields.name)}</th></tr></thead><tbody>
          ${fields.map((f) => {
            const a = display(A, f), b = display(B, f);
            const same = a === b;
            return `<tr data-f="${f}" class="${same ? 'is-same' : ''}"><th scope="row">${esc(FIELD_LABELS[f])}</th>
              ${['A', 'B'].map((k) => { const v = k === 'A' ? a : b; return `<td class="${v ? '' : 'is-empty'}"><label><input type="radio" name="f_${f}" value="${k}" ${same ? 'disabled' : ''}><span>${v ? esc(v) : 'empty'}</span></label></td>`; }).join('')}</tr>`;
          }).join('')}</tbody></table></div>
        <p class="fwc-mergesum" id="mergeSum"></p>
      </div>
      <div class="fwm-dialog__foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary fwc-danger" type="button" id="doMerge">${icon('ic-merge')}Merge</button></div>`, 'fwc-mergedlg', 'width:min(820px,100%)');
    const syncKeep = () => {
      $$('.fwc-keep', m).forEach((l) => { l.classList.toggle('is-on', l.dataset.keep === keep); $('.fwc-keep__tag', l).textContent = l.dataset.keep === keep ? 'Stays' : 'Merged in, then removed'; });
      // default each field to the kept side, or the other side when the kept one is empty
      fields.forEach((f) => {
        const tr = $(`tr[data-f="${f}"]`, m);
        if (tr.classList.contains('is-same')) return;
        const other = keep === 'A' ? 'B' : 'A';
        const kv = display(acc[keep], f);
        const pickSide = kv ? keep : other;
        const r = $(`input[value="${pickSide}"]`, tr);
        if (r && !tr.dataset.touched) r.checked = true;
      });
      const lose = acc[keep === 'A' ? 'B' : 'A'];
      const moving = Object.entries(lose.counts).filter(([, n]) => n > 0).map(([c, n]) => plural(n, COUNT_LABELS[c]));
      $('#mergeSum', m).innerHTML = `${icon('alert', 'i--sm')}<span><b>${esc(lose.fields.name)}</b> is merged into <b>${esc(acc[keep].fields.name)}</b>${moving.length ? ` — ${esc(moving.join(', '))} move across` : ''}. It is then removed (it stays under Deleted, marked “merged into #${acc[keep].id}”). This cannot be undone.</span>`;
    };
    m.addEventListener('change', (e) => {
      if (e.target.name === 'keep') { keep = e.target.value; syncKeep(); return; }
      const tr = e.target.closest('tr[data-f]');
      if (tr) tr.dataset.touched = '1';
    });
    syncKeep();
    $('#doMerge', m).addEventListener('click', async () => {
      const keepAcc = acc[keep], loseAcc = acc[keep === 'A' ? 'B' : 'A'];
      const pick = {};
      fields.forEach((f) => { const r = $(`input[name="f_${f}"]:checked`, m); if (r) pick[f] = r.value === keep ? 'keep' : 'other'; });
      const btn = $('#doMerge', m);
      btn.disabled = true;
      btn.innerHTML = `<span class="fwc-spin" aria-hidden="true"></span>Merging…`;
      try {
        const d2 = await api.post('dedupe.php', { op: 'merge', keep: keepAcc.id, other: loseAcc.id, pick, candidate: candidateId || 0 });
        S.list = d2.candidates;
        closeModal(m);
        if (S.focus && S.focus.id === loseAcc.id) S.focus = { id: keepAcc.id, name: keepAcc.fields.name, type: keepAcc.type };
        render();
        toast(`Merged into ${keepAcc.fields.name}`, { action: { label: 'Open', run: () => { location.href = CRM.accountUrl(keepAcc.id); } } });
      } catch (err) {
        btn.disabled = false;
        btn.innerHTML = `${icon('ic-merge')}Merge`;
        toast(err.message, { type: 'error' });
      }
    });
    openModal(m);
  }

  async function init() {
    CRM.initChrome();
    try {
      const d = await api.get('dedupe.php');
      S.list = d.candidates;
    } catch (err) { root.innerHTML = CRM.errorBox(err); return; }
    root.addEventListener('click', onClick);
    render();
  }
  init();
})();
