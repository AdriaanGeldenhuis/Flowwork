// /receipts/js/inbox.js — the receipts list: views (chips) without a reload,
// search as you type, order, "show more", and for bookkeepers a selection
// with read again / set aside / bring back / delete. New slips appear as
// they are sent in and read.
(function () {
  'use strict';
  const { $, $$, esc, api, card, money, toast, toastError, dialog, busy, boot, debounce } = window.FWR;
  const caps = boot.caps || {};
  const LEADS = {
    todo: 'Being read, to review and draft bills — oldest first.', review: 'Read and waiting for someone to check them.', reading: 'Sent in moments ago, or waiting to be read.',
    draft: 'Saved as bills in Finance, not posted yet.', blocked: 'A missing VAT number, compliance document or purchase order is holding these bills back.',
    done: 'Posted to Finance — and paid, when they were paid on the spot.', aside: 'Duplicates, personal slips and documents that are not bills. Bring one back if it was a mistake.', all: 'Everything sent in.',
  };
  const state = { view: boot.view, q: boot.q || '', sort: boot.sort || '', page: 1, rows: [], total: 0, selected: new Set(), seq: 0 };
  const cards = $('#cards');
  $('#sort').value = state.sort;

  function url() {
    const p = new URLSearchParams();
    if (state.view !== 'todo') p.set('view', state.view);
    if (state.q) p.set('q', state.q);
    if (state.sort) p.set('sort', state.sort);
    return location.pathname + (p.toString() ? '?' + p : '');
  }
  async function load(append = false) {
    const n = ++state.seq;
    if (!append) { state.page = 1; }
    try {
      const res = await api.get('receipts.php', { op: 'list', view: state.view, q: state.q, sort: state.sort, page: state.page });
      if (n !== state.seq) return;
      state.rows = append ? state.rows.concat(res.rows) : res.rows;
      state.total = res.total;
      Object.entries(res.counts).forEach(([k, v]) => { const c = $(`[data-chip="${k}"]`); if (c) c.textContent = v; });
      $$('[data-count="todo"]').forEach((c) => { c.textContent = res.counts.todo; });
      draw(res);
      healStuck(res.rows);
    } catch (e) {
      if (n === state.seq) cards.innerHTML = `<div class="fwr-empty"><b>Could not load the receipts</b>${esc(e.message)}</div>`;
    }
  }
  function draw(res) {
    const sel = caps.capture;
    if (!state.rows.length) {
      const empty = {
        todo: ['Inbox zero', caps.upload ? 'Nothing is waiting. Drop the next slip anywhere on this page.' : 'Nothing is waiting.'],
        blocked: ['Nothing is held', 'No bill is waiting on a check.'], aside: ['Nothing set aside', ''], done: ['Nothing in the books yet', 'Posted receipts show here.'],
      }[state.view] || ['No receipts here', ''];
      cards.innerHTML = `<div class="fwr-empty" style="grid-column:1/-1"><b>${esc(state.q ? 'Nothing matches “' + state.q + '”' : empty[0])}</b>${esc(state.q ? 'Try a supplier, a slip number or an amount.' : empty[1])}
        ${caps.upload && !state.q && state.view === 'todo' ? '<br><button class="btn btn--primary" type="button" data-capture="pick">Send in receipts</button>' : ''}</div>`;
    } else {
      cards.innerHTML = state.rows.map((r) => card(r, { select: sel, uploader: caps.all })).join('');
      state.rows.forEach((r) => { if (state.selected.has(r.id)) { const c = $(`[data-select="${r.id}"]`); if (c) { c.checked = true; c.closest('.fwr-card').classList.add('is-selected'); } } });
    }
    $('#more').hidden = state.rows.length >= state.total;
    $('#sum').innerHTML = state.total ? `<b>${state.total}</b> ${state.total === 1 ? 'receipt' : 'receipts'}${res && res.sum > 0 ? ' · <b>' + esc(money(res.sum)) + '</b>' : ''}` : '';
    const ex = $('#exportBtn');
    if (ex) ex.href = '/receipts/api/receipts.php?' + new URLSearchParams({ op: 'export', view: state.view, q: state.q, sort: state.sort });
    syncBulk();
  }
  function setView(v) {
    state.view = v;
    state.selected.clear();
    $$('#chips .fwr-chip').forEach((c) => { const on = c.dataset.view === v; c.classList.toggle('is-on', on); c.setAttribute('aria-selected', String(on)); });
    const title = (boot.views || {})[v] || 'Receipts';
    $('#viewTitle').textContent = title;
    $('#viewEyebrow').textContent = title;
    $('#viewLead').textContent = LEADS[v] || '';
    document.title = title + ' · ' + document.title.split(' · ').slice(1).join(' · ');
    $$('#side .fwm-nav__item').forEach((a) => {
      const u = new URL(a.href, location.href);
      const on = u.pathname === '/receipts/inbox.php' && (u.searchParams.get('view') || 'todo') === v;
      a.classList.toggle('is-active', on);
      if (on) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
    history.replaceState(null, '', url());
    cards.innerHTML = '<div class="fwr-loading"><span class="fwr-spin"></span>Loading receipts…</div>';
    load();
  }
  $('#chips').addEventListener('click', (e) => {
    const c = e.target.closest('[data-view]');
    if (!c || e.ctrlKey || e.metaKey) return;
    e.preventDefault();
    setView(c.dataset.view);
  });
  $('#q').addEventListener('input', debounce(() => { state.q = $('#q').value.trim(); history.replaceState(null, '', url()); load(); }, 250));
  $('#sort').addEventListener('change', () => { state.sort = $('#sort').value; history.replaceState(null, '', url()); load(); });
  $('#moreBtn').addEventListener('click', async () => { state.page++; busy($('#moreBtn'), true); await load(true); busy($('#moreBtn'), false); });

  // Slips left "being read" (the page was closed while sending) are read now.
  const healed = new Set();
  function healStuck(rows) {
    if (!caps.capture) return;
    rows.filter((r) => r.state === 'reading' && !healed.has(r.id) && Date.now() - new Date(String(r.uploaded_at).replace(' ', 'T')).getTime() > 120000)
      .slice(0, 3).forEach((r) => {
        healed.add(r.id);
        api.post('read.php', { id: r.id }).then(() => later()).catch(() => {});
      });
  }

  // ------------------------------------------------------------ selection
  const bulk = $('#bulk');
  function syncBulk() {
    if (!bulk) return;
    bulk.classList.toggle('is-on', state.selected.size > 0);
    cards.classList.toggle('is-selecting', state.selected.size > 0);
    $('#bulkCount').textContent = state.selected.size + ' selected';
    $('[data-bulk="restore"]', bulk).hidden = state.view !== 'aside';
    $('[data-bulk="aside"]', bulk).hidden = state.view === 'aside';
  }
  cards.addEventListener('change', (e) => {
    const c = e.target.closest('[data-select]');
    if (!c) return;
    const id = +c.dataset.select;
    if (c.checked) state.selected.add(id); else state.selected.delete(id);
    c.closest('.fwr-card').classList.toggle('is-selected', c.checked);
    syncBulk();
  });
  const allBtn = $('#selectAll');
  if (allBtn) allBtn.addEventListener('click', () => {
    const every = state.rows.length && state.rows.every((r) => state.selected.has(r.id));
    state.rows.forEach((r) => { if (every) state.selected.delete(r.id); else state.selected.add(r.id); });
    draw();
  });
  if (bulk) bulk.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-bulk]');
    if (!b) return;
    const ids = Array.from(state.selected);
    const act = b.dataset.bulk;
    if (act === 'clear') { state.selected.clear(); draw(); return; }
    try {
      if (act === 'read') {
        busy(b, true);
        let ok = 0, failed = 0;
        for (const id of ids) {
          try { const r = await api.post('read.php', { id, force: 1 }); if (r.read.ok) ok++; else failed++; } catch (x) { failed++; }
        }
        toast(`${ok} read again` + (failed ? ` · ${failed} could not be read` : ''));
      } else if (act === 'aside') {
        const res = await dialog({ title: ids.length === 1 ? 'Set this receipt aside?' : `Set ${ids.length} receipts aside?`, body: 'They leave the to-do list. A draft bill on one is deleted with it. You can bring them back.',
          choices: Object.entries(boot.aside).map(([value, label]) => ({ value, label })), note: { label: 'Note (optional)', placeholder: 'Anything the next person should know' }, confirm: 'Set aside' });
        if (!res) return;
        busy(b, true);
        const r = await api.post('receipts.php', { op: 'aside', ids, reason: res.choice, note: res.note });
        toast(r.done === 1 ? 'Set aside' : r.done + ' set aside');
      } else if (act === 'restore') {
        busy(b, true);
        const r = await api.post('receipts.php', { op: 'restore', ids });
        toast(r.done === 1 ? 'Brought back' : r.done + ' brought back');
      } else if (act === 'delete') {
        if (!await dialog({ title: ids.length === 1 ? 'Delete this receipt?' : `Delete ${ids.length} receipts?`, body: 'The slips are removed for good, with any draft bill made from them. Receipts behind a bill in the books are kept.', confirm: 'Delete', danger: true })) return;
        busy(b, true);
        const r = await api.post('receipts.php', { op: 'delete', ids });
        toast(r.done === 1 ? 'Deleted' : r.done + ' deleted');
      }
      state.selected.clear();
      await load();
    } catch (x) {
      toastError(x);
      load();
    } finally {
      busy(b, false);
    }
  });

  // New slips appear as they arrive
  const later = debounce(() => load(), 700);
  document.addEventListener('fwr:uploaded', later);
  document.addEventListener('fwr:read', later);

  load();
})();
