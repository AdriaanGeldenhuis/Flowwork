/* =============================================================================
   Flowwork Projects — the item panel
   Opens beside the board (full screen on phones): every column as a field,
   the description, dependencies, what the item is connected to elsewhere in
   Flowwork (mail, calendar, quotes, invoices, bills, timesheets), the
   updates conversation with @mentions and replies, subitems, files and the
   item's activity. Also shows the board's activity. Sets window.FWB.panel.
   ============================================================================= */
(function () {
  'use strict';

  const FWB = window.FWB;
  if (!FWB) return;
  const {
    $, $$, esc, icon, debounce, api, toast, toastError, popover, closePop, dialog, P, personById, personName, personAvatar, avatar,
    fmtDate, relTime, today, fileKind, isImage, fmtSize, richText, pickPeople, pickDate, activityHTML, safeColor, fmtMoney, boardIcon,
  } = window.FWP;
  const C = window.FWBCells;
  const S = FWB.S;
  const host = FWB.el.panel;

  const TABS = [['details', 'list', 'Details'], ['updates', 'message', 'Updates'], ['subitems', 'subitems', 'Subitems'], ['files', 'clip', 'Files'], ['activity', 'history', 'Activity']];
  const st = {
    mode: 'item', // 'item' | 'board' (board activity)
    id: 0,
    tab: FWB.store.get('ptab', 'details'),
    detail: null,
    updates: null,
    activity: null,
    actMore: false,
    uploads: [],
    mentions: new Map(),
    seq: 0,
    desc: { saves: 0, pending: 0, id: 0, text: '' }, // the description saves sent from here
  };
  const canEdit = () => !S.guest && st.detail && (st.detail.level || 0) >= 2 && !st.detail.archived;
  const canManage = () => !S.guest && st.detail && (st.detail.level || 0) >= 3;

  // ===========================================================================
  // Data
  // ===========================================================================
  /** The detail payload: from the server, or (guests) from the board itself. */
  async function load(id) {
    if (S.guest) {
      const it = FWB.item(id);
      if (!it) throw new Error('This item is not on the board.');
      const g = FWB.group(it.group_id);
      return Object.assign({}, it, { board: S.board.title, project: S.board.project_name, group: g ? g.name : '', description: it.description || '', level: 0, archived: false, subitem_list: it.subitem_list || [], watchers: [], deps: [], links: null });
    }
    return (await api.get('items.php', { id })).item;
  }
  /** Keep the panel's copy and the board's copy of the item in step. */
  function mergeItem(it) {
    if (!st.detail || !it || it.id !== st.detail.id) return;
    ['title', 'group_id', 'position', 'values', 'subitems', 'subitems_done', 'updates', 'files', 'updated_at', 'has_description'].forEach((k) => { if (k in it) st.detail[k] = it[k]; });
    const g = FWB.group(it.group_id);
    if (g) st.detail.group = g.name;
  }
  /** The board's copy of the open item (the panel's copy when it is archived or filtered out). */
  const boardItem = () => FWB.item(st.id) || st.detail;
  const isDone = (it) => { const ps = FWB.primaryStatus(); const l = ps ? C.statusOf(ps, it.values[ps.id]) : null; return !!(l && l.done); };

  // ===========================================================================
  // Open / close
  // ===========================================================================
  /** opts.focus: opened from the keyboard — the keyboard comes along. */
  async function open(id, tab, opts) {
    id = Number(id);
    st.mode = 'item';
    const switching = st.id !== id;
    st.id = id;
    if (tab && TABS.some((t) => t[0] === tab)) st.tab = tab;
    if (S.guest && !['details', 'files'].includes(st.tab)) st.tab = 'details';
    if (switching) { st.updates = null; st.activity = null; st.mentions = new Map(); }
    host.hidden = false;
    const seq = ++st.seq;
    const known = FWB.item(id);
    if (switching || !st.detail) {
      st.detail = null;
      host.innerHTML = shell(known ? known.title : 'Loading…', true);
    }
    const since = descSince();
    try {
      const d = await load(id);
      if (seq !== st.seq) return;
      keepSentDesc(d, since);
      st.detail = d;
      render();
      if (st.tab === 'updates') loadUpdates();
      if (st.tab === 'activity') loadActivity();
    } catch (x) {
      if (seq !== st.seq) return;
      host.innerHTML = shell('Item not found', false) ;
      $('.fwp-panel__body', host).innerHTML = `<div class="fwp-panelcard__empty">${esc(x.message || 'This item could not be opened.')}</div>`;
    }
    if (opts && opts.focus && seq === st.seq) { const b = $('[data-p=close]', host); if (b) b.focus(); }
  }
  function close() {
    if (host.hidden) return;
    const id = st.id;
    const had = host.contains(document.activeElement);
    flushDescription();
    closePop();
    host.hidden = true;
    host.innerHTML = '';
    st.id = 0;
    st.detail = null;
    st.mode = 'item';
    FWB.panelClosed();
    // The keyboard goes back to the item it came from.
    if (had && id) {
      const b = FWB.el.view.querySelector(`.fwp-row[data-iid="${id}"] [data-open], [data-kid="${id}"], [data-cid-item="${id}"], [data-gitem="${id}"]`);
      if (b) b.focus({ preventScroll: true });
    }
  }
  async function refresh(id, quiet) {
    if (st.mode !== 'item' || Number(id) !== st.id) return;
    const since = descSince();
    try {
      const d = await load(st.id);
      if (Number(id) !== st.id) return;
      keepSentDesc(d, since);
      if (!S.guest && d.board_id && d.board_id !== S.board.id) { gone(`This ${FWB.noun()} was moved to ${d.board || 'another board'}.`); return; }
      const typing = document.activeElement && host.contains(document.activeElement) && document.activeElement.matches('textarea, input, [contenteditable="true"]');
      st.detail = d;
      // Whoever asks (a live update, a colleague's column change), what is being typed stays.
      if (typing || st.descDirty) { renderHead(); renderTabs(); if (st.tab === 'details') renderFields(); return; }
      render();
      if (st.tab === 'updates') loadUpdates(true);
      if (st.tab === 'activity') loadActivity();
    } catch (x) {
      if (Number(id) !== st.id) return;
      // Deleted, or moved where this person cannot follow: nothing to show any more.
      if (x.status === 404 || (S.guest && !FWB.item(st.id))) { gone(`This ${FWB.noun()} was deleted or moved away.`); return; }
      if (!quiet) toastError(x);
    }
  }
  /** A description save was on its way while d loaded: d may predate it, so the text sent stays. */
  const descSince = () => ({ saves: st.desc.saves, saving: st.desc.pending > 0 });
  function keepSentDesc(d, since) {
    if ((since.saving || st.desc.pending || st.desc.saves !== since.saves) && st.desc.id === d.id) d.description = st.desc.text;
  }
  /** The open item left this board: close, without saving a name half-typed into it. */
  function gone(msg) {
    const t = $('#pTitle', host);
    if (t && document.activeElement === t && boardItem()) t.textContent = boardItem().title;
    close();
    toast(msg);
  }
  /** board.js tells us an item changed (a cell edit, live update…). */
  function onItemChanged(it) {
    if (st.mode !== 'item' || !st.detail || !it) return;
    mergeItem(it);
    renderHead();
    renderTabs();
    if (st.tab === 'details') renderFields();
    if (st.tab === 'files') renderFiles();
  }

  // ===========================================================================
  // Rendering
  // ===========================================================================
  function shell(title, loading) {
    return `<div class="fwm-reader__bar">
        <button class="icon-btn" type="button" data-p="close" aria-label="Close" data-tip="Close · Esc">${icon('x')}</button>
        <button class="icon-btn" type="button" data-p="prev" aria-label="Previous item" data-tip="Previous · k">${icon('chev-up')}</button>
        <button class="icon-btn" type="button" data-p="next" aria-label="Next item" data-tip="Next · j">${icon('chev-down')}</button>
        <span class="grow"></span><span id="pBarActs" style="display:inline-flex;gap:2px;align-items:center"></span></div>
      <div class="fwp-panel__scroll scroll" id="pScroll">
        <div class="fwp-panel__head" id="pHead"><div class="fwp-panel__crumb"><span>${esc(S.board.title)}</span></div><h2 class="fwp-panel__title">${esc(title)}</h2></div>
        <div class="fwp-tabs" id="pTabs" role="tablist"></div>
        <div class="fwp-panel__body" id="pBody">${loading ? '<div class="fwp-skel-line"></div><div class="fwp-skel-line"></div><div class="fwp-skel-line"></div><div class="fwp-skel-line"></div>' : ''}</div>
      </div>`;
  }
  function render() {
    if (!$('#pHead', host) || !$('#pBarActs', host)) host.innerHTML = shell('', false);
    renderBar();
    renderHead();
    renderTabs();
    renderBody();
  }
  function renderBar() {
    const d = st.detail;
    const bar = $('#pBarActs', host);
    if (!bar || !d) return;
    if (S.guest) { bar.innerHTML = ''; return; }
    const watching = (d.watchers || []).includes(P.me.id);
    bar.innerHTML = `<button class="icon-btn ${watching ? 'is-on' : ''}" type="button" data-p="watch" aria-pressed="${watching}" aria-label="${watching ? 'Stop following' : 'Follow'}" data-tip="${watching ? 'Following — you hear about every change' : 'Follow this item'}">${icon(watching ? 'bell' : 'eye')}</button>
      <button class="icon-btn" type="button" data-p="link" aria-label="Copy link" data-tip="Copy link">${icon('link')}</button>
      <button class="icon-btn" type="button" data-p="calendar" aria-label="Add to calendar" data-tip="Add to calendar">${icon('calendar-plus')}</button>
      <button class="icon-btn" type="button" data-p="more" aria-label="More" data-tip="More" data-tip-pos="left">${icon('more-h')}</button>`;
  }
  function renderHead() {
    const d = st.detail;
    const head = $('#pHead', host);
    if (!head || !d) return;
    const it = boardItem();
    const g = FWB.group(d.group_id);
    const ps = FWB.primaryStatus();
    const pp = FWB.primaryPeople();
    const lbl = ps ? C.statusOf(ps, it.values[ps.id]) : null;
    const people = pp ? (it.values[pp.id] || []) : [];
    const html = `<div class="fwp-panel__crumb">${g ? `<span class="fwp-dot fwp-dot--sq" style="--c:${safeColor(g.color)}"></span>` : ''}<span>${esc(S.board.title)}${d.group ? ' · ' + esc(d.group) : ''}</span></div>
      <h2 class="fwp-panel__title" id="pTitle" ${canEdit() ? 'contenteditable="true" spellcheck="true" role="textbox" aria-label="Item name"' : ''}>${esc(it.title)}</h2>
      ${d.archived ? `<div class="fwp-banner" style="margin-top:12px">${icon('archive')}<span class="grow">This ${esc(FWB.noun())} is archived.</span>${(d.level || 0) >= 2 ? `<button class="btn btn--sm" type="button" data-p="restore">${icon('undo')}Restore</button>` : ''}</div>` : ''}
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:10px;font-size:12.5px;color:var(--ink-3)">
        ${lbl ? `<span class="fwp-pill" style="--c:${safeColor(lbl.color)}">${esc(lbl.label)}</span>` : ''}
        ${people.length ? `<span class="fwp-faces">${people.slice(0, 4).map((id) => personAvatar(id)).join('')}</span>` : ''}
        <span>${S.guest ? 'Added' : 'Added by ' + esc(personName(it.created_by)) + ' ·'} ${esc(relTime(it.created_at))}</span>
      </div>`;
    const t = $('#pTitle', host);
    if (t && document.activeElement === t) {
      // Being renamed: leave the title (and what is typed in it) alone; refresh what is around it.
      const tmp = document.createElement('div');
      tmp.innerHTML = html;
      Array.from(head.children).forEach((x) => { if (x !== t) x.remove(); });
      const fresh = Array.from(tmp.children);
      const ti = fresh.findIndex((x) => x.id === 'pTitle');
      fresh.slice(0, ti).forEach((x) => head.insertBefore(x, t));
      fresh.slice(ti + 1).forEach((x) => head.appendChild(x));
      return;
    }
    head.innerHTML = html;
  }
  function renderTabs() {
    const d = st.detail;
    const tabs = $('#pTabs', host);
    if (!tabs || !d) return;
    const it = boardItem();
    const n = { updates: it.updates || 0, subitems: it.subitems || (d.subitem_list || []).length, files: (it.files || []).length };
    const list = S.guest ? TABS.filter((t) => ['details', 'files'].includes(t[0])) : TABS;
    tabs.innerHTML = list.map(([k, ic, l]) => `<button type="button" role="tab" data-ptab="${k}" class="${st.tab === k ? 'is-on' : ''}" aria-selected="${st.tab === k}">${icon(ic, 'i--sm')}${l}${n[k] ? `<span class="fwp-badge ${k === 'updates' ? 'fwp-badge--gold' : ''}">${n[k]}</span>` : ''}</button>`).join('');
  }
  function renderBody() {
    const body = $('#pBody', host);
    if (!body || !st.detail) return;
    body.style.animation = 'none';
    void body.offsetWidth;
    body.style.animation = '';
    if (st.tab === 'details') return renderDetails();
    if (st.tab === 'updates') return renderUpdates();
    if (st.tab === 'subitems') return renderSubitems();
    if (st.tab === 'files') return renderFiles();
    if (st.tab === 'activity') return renderActivity();
  }
  function setTab(tab) {
    if (tab === st.tab) return;
    flushDescription();
    st.tab = tab;
    FWB.store.set('ptab', tab);
    renderTabs();
    renderBody();
    if (tab === 'updates' && !st.updates) loadUpdates();
    if (tab === 'activity') loadActivity();
    const u = new URL(location.href);
    if (tab && tab !== 'details') u.searchParams.set('open', tab); else u.searchParams.delete('open');
    history.replaceState(null, '', u.pathname + u.search);
  }

  // ---------------------------------------------------------------- details
  function renderDetails() {
    const d = st.detail;
    const body = $('#pBody', host);
    body.innerHTML = `<div class="fwp-fields" id="pFields"></div>
      <div class="fwp-psec"><h4>Description</h4>
        ${canEdit() ? `<textarea class="fwp-desc" id="pDesc" maxlength="20000" placeholder="Add the detail people need — scope, measurements, who to call…">${esc(d.description || '')}</textarea><div class="hint" id="pDescHint" style="min-height:16px;margin-top:4px;font-size:11.5px;color:var(--ink-4)"></div>`
          : (d.description ? `<div class="fwp-upd__body" style="margin:0">${richText(d.description)}</div>` : '<div class="fwp-panelcard__empty" style="text-align:left;padding:4px 0">No description.</div>')}
      </div>
      ${S.guest ? '' : `<div class="fwp-psec" id="pDeps"></div><div class="fwp-psec" id="pConn"></div>`}`;
    renderFields();
    if (!S.guest) { renderDeps(); renderConnections(); }
    const ta = $('#pDesc', body);
    if (ta) {
      autoGrow(ta);
      ta.addEventListener('input', () => { autoGrow(ta); st.descDirty = true; descHint('Unsaved…'); saveDescSoon(); });
      ta.addEventListener('blur', flushDescription);
    }
  }
  function renderFields() {
    const box = $('#pFields', host);
    if (!box || !st.detail) return;
    const it = boardItem();
    const g = FWB.group(it.group_id);
    const done = isDone(it);
    const firstFiles = FWB.colsOf('files')[0];
    const editable = canEdit();
    const html = `<div class="fwp-field"><div class="fwp-field__label">${icon('layers')}<span>Group</span></div>
        <div class="fwp-cell ${editable && !st.detail.archived ? 'is-editable' : ''}" data-pgroup tabindex="0" role="button">${g ? `<span class="fwp-person"><span class="fwp-dot fwp-dot--sq" style="--c:${safeColor(g.color)}"></span><span>${esc(g.name)}</span></span>` : '<span class="fwp-muted">—</span>'}</div></div>
      ${S.columns.map((c) => `<div class="fwp-field"><div class="fwp-field__label" title="${esc(c.description || c.name)}">${icon(C.TYPE_ICON[c.type] || 'text')}<span>${esc(c.name)}</span></div>
        <div class="fwp-cell fwp-cell--${c.type} ${(editable && C.editable(c)) || c.type === 'files' ? 'is-editable' : ''}" data-pcid="${c.id}" tabindex="0" role="button" aria-label="${esc(c.name)}">${C.render(c, it, { done, groupColor: g ? g.color : null, firstFiles: firstFiles && firstFiles.id === c.id })}</div></div>`).join('')}`;
    // A field being typed in (text, number…) keeps its editor — removing it would save the half-typed value; the others refresh around it.
    const a = document.activeElement;
    const keep = a && box.contains(a) && a.matches('input, textarea') ? a.closest('.fwp-field') : null;
    const cell = keep && $('[data-pcid]', keep);
    if (cell) {
      const tmp = document.createElement('div');
      tmp.innerHTML = html;
      const fresh = Array.from(tmp.children);
      const i = fresh.findIndex((x) => $(`[data-pcid="${cell.dataset.pcid}"]`, x));
      if (i >= 0) {
        Array.from(box.children).forEach((x) => { if (x !== keep) x.remove(); });
        fresh.slice(0, i).forEach((x) => box.insertBefore(x, keep));
        fresh.slice(i + 1).forEach((x) => box.appendChild(x));
        return;
      }
    }
    // The keyboard stays on the field it was on (a value just saved re-renders them all).
    const on = a && box.contains(a) ? (a.dataset.pcid ? `[data-pcid="${a.dataset.pcid}"]` : a.matches('[data-pgroup]') ? '[data-pgroup]' : '') : '';
    box.innerHTML = html;
    const back = on && $(on, box);
    if (back) back.focus({ preventScroll: true });
  }
  function autoGrow(ta) { ta.style.height = 'auto'; ta.style.height = Math.min(520, Math.max(90, ta.scrollHeight + 2)) + 'px'; }
  function descHint(t) { const h = $('#pDescHint', host); if (h) h.textContent = t; }
  async function saveDescription() {
    const ta = $('#pDesc', host);
    if (!ta || !st.descDirty || !st.detail) return;
    const text = ta.value;
    const id = st.detail.id;
    st.descDirty = false;
    Object.assign(st.desc, { saves: st.desc.saves + 1, pending: st.desc.pending + 1, id, text });
    descHint('Saving…');
    try {
      await api.post('items.php', { action: 'describe', id, text });
      if (st.detail && st.detail.id === id) st.detail.description = text;
      const it = FWB.item(id);
      if (it) it.has_description = text.trim() !== '';
      descHint('Saved');
      setTimeout(() => { if (!st.descDirty) descHint(''); }, 1600);
    } catch (x) { st.descDirty = true; descHint('Not saved'); toastError(x); } finally { st.desc.pending--; }
  }
  const saveDescSoon = debounce(saveDescription, 1200);
  function flushDescription() { if (st.descDirty) saveDescription(); }

  function renderDeps() {
    const box = $('#pDeps', host);
    if (!box) return;
    const deps = st.detail.deps || [];
    const waiting = deps.filter((x) => x.kind === 'waiting_on');
    const blocking = deps.filter((x) => x.kind === 'blocking');
    const row = (x) => `<div class="fwp-dep ${x.kind === 'blocking' ? 'is-blocking' : ''}">${icon(x.kind === 'waiting_on' ? 'hourglass' : 'blocked')}
        <span><small class="fwp-muted">${x.kind === 'waiting_on' ? 'Waits on' : 'Blocks'}</small> <a href="#" data-popen="${x.item_id}">${esc(x.title)}</a>${x.lag ? ` <small class="fwp-muted">+${x.lag}d</small>` : ''}</span>
        ${canEdit() ? `<button class="icon-btn icon-btn--sm" type="button" data-pdeprm="${x.id}" aria-label="Remove dependency">${icon('x', 'i--sm')}</button>` : ''}</div>`;
    box.innerHTML = `<h4>Dependencies${canEdit() ? `<button class="btn btn--sm btn--ghost" type="button" data-p="depadd">${icon('plus')}Add</button>` : ''}</h4>
      ${deps.length ? waiting.concat(blocking).map(row).join('') : `<div class="hint" style="font-size:12.5px;color:var(--ink-3)">Nothing has to happen before this ${esc(FWB.noun())}. Link what it waits on and the timeline will draw the chain.</div>`}`;
  }
  function renderConnections() {
    const box = $('#pConn', host);
    if (!box) return;
    const L = st.detail.links || { emails: [], events: [], documents: [], hours: 0 };
    const KIND = { quote: ['quote', 'Quote'], invoice: ['receipt', 'Invoice'], bill: ['wallet', 'Supplier bill'] };
    const rows = [];
    L.emails.forEach((e) => rows.push(conn(e.url, 'mail', 'Email', e.subject, `${e.from} · ${fmtDate(String(e.at).slice(0, 10))}`)));
    L.events.forEach((e) => rows.push(conn(e.url, 'calendar', 'Event', e.title, e.all_day ? fmtDate(String(e.start).slice(0, 10), { dow: true }) : fmtDate(String(e.start).slice(0, 10), { dow: true }) + ' · ' + String(e.start).slice(11, 16))));
    L.documents.forEach((x) => { const k = KIND[x.kind] || ['file-text', x.kind]; rows.push(conn(x.url, k[0], k[1], x.number || ('#' + x.id), `${String(x.status || '').replace(/_/g, ' ')} · ${fmtMoney(x.total)}`)); });
    if (L.hours) rows.push(conn('/timesheets/', 'clock', 'Timesheets', `${String(L.hours).replace('.', ',')} hours logged`, 'Time booked against this item'));
    const d = st.detail;
    box.innerHTML = `<h4>Connected</h4>${rows.join('') || `<div class="hint" style="font-size:12.5px;color:var(--ink-3)">Emails filed to this ${esc(FWB.noun())}, events, quotes, invoices, bills and hours booked against it show here.</div>`}
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px">
        <a class="btn btn--sm" href="${esc(calendarUrl())}">${icon('calendar-plus')}Schedule</a>
        <a class="btn btn--sm" href="/qi/quote_new.php?project_id=${d.project_id}">${icon('quote')}Quote</a>
        <a class="btn btn--sm" href="/qi/invoice_new.php?project_id=${d.project_id}">${icon('receipt')}Invoice</a>
      </div>`;
  }
  function conn(url, ic, kind, title, sub) {
    const inner = `<span class="fwp-conn__icon">${icon(ic)}</span><span class="fwp-conn__text"><span class="fwp-conn__kind">${esc(kind)}</span><b>${esc(title)}</b><small>${esc(sub)}</small></span>${url ? icon('chev-right', 'i--sm') : ''}`;
    return url ? `<a class="fwp-conn" href="${esc(url)}">${inner}</a>` : `<div class="fwp-conn">${inner}</div>`;
  }
  function calendarUrl() {
    const it = boardItem();
    const dc = FWB.primaryDate();
    const tl = FWB.colsOf('timeline')[0];
    let date = dc && it.values[dc.id] ? it.values[dc.id] : (tl && it.values[tl.id] ? it.values[tl.id].start : today());
    const q = new URLSearchParams({ new: '1', title: it.title, date, all_day: '1', link: 'item:' + it.id, description: `${S.board.title} · ${location.origin}/projects/board.php?board_id=${S.board.id}&item=${it.id}` });
    return '/calendar/?' + q.toString();
  }

  // ---------------------------------------------------------------- updates
  async function loadUpdates(quiet) {
    const id = st.id;
    if (!quiet && !st.updates) renderUpdates();
    try {
      const d = await api.get('updates.php', { item_id: id });
      if (id !== st.id) return;
      const typing = document.activeElement && host.contains(document.activeElement) && document.activeElement.matches('.fwp-upd textarea, .fwp-replybox textarea');
      st.updates = d.updates;
      if (st.tab === 'updates' && !(quiet && typing)) renderUpdateList();
    } catch (x) { if (!quiet) toastError(x); }
  }
  function renderUpdates() {
    const body = $('#pBody', host);
    if (!body) return;
    const draft = FWB.store.get('draft.' + st.id, '');
    body.innerHTML = `${(st.detail.level || 0) >= 1 && !st.detail.archived ? `<form class="fwp-compose" id="pCompose">
        <textarea id="pUpdText" maxlength="10000" placeholder="Write an update — type @ to mention someone" aria-label="Write an update">${esc(draft)}</textarea>
        <div class="fwp-compose__foot"><span class="grow">Ctrl + Enter to post · everyone following hears about it</span>
          <button class="icon-btn icon-btn--sm" type="button" data-p="mention" aria-label="Mention someone" data-tip="Mention">${icon('at', 'i--sm')}</button>
          <button class="btn btn--primary btn--sm" type="submit">${icon('send')}Post</button></div></form>` : ''}
      <div id="pUpdList">${st.updates ? '' : '<div class="fwp-skel-line"></div><div class="fwp-skel-line"></div>'}</div>`;
    const ta = $('#pUpdText', body);
    if (ta) bindComposer(ta, (text) => postUpdate(text, null, ta));
    if (st.updates) renderUpdateList();
  }
  function updateHTML(u, reply) {
    const canDel = u.mine || canManage();
    return `<div class="fwp-upd ${reply ? 'fwp-reply' : ''}" data-uid="${u.id}">
      <div class="fwp-upd__head">${personAvatar(u.user_id)}<div class="fwp-upd__who"><b>${esc(u.user)}</b><small>${esc(relTime(u.at))}${u.edited ? ' · edited' : ''}</small></div></div>
      <div class="fwp-upd__body">${richText(u.body)}</div>
      ${S.guest ? '' : `<div class="fwp-upd__acts">
        ${!reply && !st.detail.archived ? `<button class="btn btn--ghost" type="button" data-ureply="${u.id}">${icon('reply', 'i--sm')}Reply</button>` : ''}
        ${u.mine ? `<button class="btn btn--ghost" type="button" data-uedit="${u.id}">${icon('pen', 'i--sm')}Edit</button>` : ''}
        ${canDel ? `<button class="btn btn--ghost" type="button" data-udel="${u.id}">${icon('trash', 'i--sm')}Delete</button>` : ''}</div>`}
      ${!reply && u.replies && u.replies.length ? `<div class="fwp-replies">${u.replies.map((r) => updateHTML(r, true)).join('')}</div>` : ''}
    </div>`;
  }
  function renderUpdateList() {
    const box = $('#pUpdList', host);
    if (!box) return;
    box.innerHTML = st.updates.length ? st.updates.map((u) => updateHTML(u, false)).join('')
      : `<div class="fwp-panelcard__empty" style="margin-top:18px">No updates yet. Ask a question, share progress or a photo — it all stays with this ${esc(FWB.noun())}.</div>`;
  }
  function findUpdate(id) {
    for (const u of st.updates || []) {
      if (u.id === id) return u;
      for (const r of u.replies || []) if (r.id === id) return r;
    }
    return null;
  }
  /** Turn "@Name" picked from the list into @[Name](id) for the server. */
  function encodeMentions(text) {
    const names = Array.from(st.mentions.keys()).sort((a, b) => b.length - a.length);
    names.forEach((n) => {
      const re = new RegExp('@' + n.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?![\\w\\]])', 'g');
      text = text.replace(re, `@[${n}](${st.mentions.get(n)})`);
    });
    return text;
  }
  /** Back to "@Name" for editing. */
  function decodeMentions(text) {
    return String(text).replace(/@\[([^\]]{1,120})\]\((\d{1,10})\)/g, (m, n, id) => { st.mentions.set(n, Number(id)); return '@' + n; });
  }
  async function postUpdate(raw, parentId, ta) {
    const text = raw.trim();
    if (!text) return;
    const btn = ta.form ? $('[type=submit]', ta.form) : null;
    if (btn) btn.classList.add('is-busy');
    try {
      const d = await api.post('updates.php', { action: 'add', item_id: st.id, body: encodeMentions(text), parent_id: parentId || undefined });
      if (!parentId) { ta.value = ''; FWB.store.set('draft.' + st.id, ''); ta.style.height = ''; }
      if (!st.updates) st.updates = [];
      if (parentId) { const p = findUpdate(parentId); if (p) (p.replies = p.replies || []).push(d.update); }
      else st.updates.unshift(Object.assign({ replies: [] }, d.update));
      const it = FWB.item(st.id);
      if (it) { it.updates = (it.updates || 0) + 1; FWB.refreshItem(it.id); }
      else if (st.detail) { st.detail.updates = (st.detail.updates || 0) + 1; renderTabs(); }
      renderUpdateList();
    } catch (x) { toastError(x); } finally { if (btn) btn.classList.remove('is-busy'); }
  }
  async function editUpdate(id) {
    const u = findUpdate(id);
    const node = $(`.fwp-upd[data-uid="${id}"] > .fwp-upd__body`, host);
    if (!u || !node) return;
    node.innerHTML = `<div class="fwp-replybox" style="margin:0"><textarea maxlength="10000" aria-label="Edit update">${esc(decodeMentions(u.body))}</textarea>
      <button class="btn btn--primary btn--sm" type="button" data-usave="${id}">Save</button><button class="btn btn--sm btn--ghost" type="button" data-ucancel="${id}">Cancel</button></div>`;
    const ta = $('textarea', node);
    ta.style.height = Math.min(320, ta.scrollHeight + 4) + 'px';
    ta.focus();
    bindComposer(ta, () => saveEdit(id, ta));
  }
  async function saveEdit(id, ta) {
    const text = ta.value.trim();
    if (!text) return;
    try {
      const d = await api.post('updates.php', { action: 'edit', id, body: encodeMentions(text) });
      const u = findUpdate(id);
      if (u) Object.assign(u, d.update, { replies: u.replies });
      renderUpdateList();
    } catch (x) { toastError(x); }
  }
  async function deleteUpdate(id) {
    if (!(await dialog({ title: 'Delete this update?', body: 'Its replies are deleted with it.', confirm: 'Delete', danger: true }))) return;
    try {
      await api.post('updates.php', { action: 'delete', id });
      const before = countUpdates();
      st.updates = (st.updates || []).filter((u) => u.id !== id).map((u) => Object.assign(u, { replies: (u.replies || []).filter((r) => r.id !== id) }));
      const gone = before - countUpdates();
      const it = FWB.item(st.id);
      if (it) { it.updates = Math.max(0, (it.updates || 0) - gone); FWB.refreshItem(it.id); }
      renderUpdateList();
    } catch (x) { toastError(x); }
  }
  const countUpdates = () => (st.updates || []).reduce((n, u) => n + 1 + (u.replies || []).length, 0);
  function openReply(id) {
    const node = $(`.fwp-upd[data-uid="${id}"]`, host);
    if (!node) return;
    let box = $(':scope > .fwp-replybox', node);
    if (!box) {
      box = document.createElement('form');
      box.className = 'fwp-replybox';
      box.innerHTML = `<textarea maxlength="10000" placeholder="Reply — @ to mention" aria-label="Reply"></textarea><button class="btn btn--primary btn--sm" type="submit">Reply</button>`;
      node.appendChild(box);
      const ta = $('textarea', box);
      bindComposer(ta, (text) => postUpdate(text, id, ta));
    }
    $('textarea', box).focus();
  }

  /** Ctrl+Enter submits; @ opens the people list; the main box keeps a draft. */
  function bindComposer(ta, submit) {
    const form = ta.closest('form');
    if (form) form.addEventListener('submit', (e) => { e.preventDefault(); submit(ta.value); });
    ta.addEventListener('keydown', (e) => {
      if (mention.open && mention.ta === ta) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); mention.move(e.key === 'ArrowDown' ? 1 : -1); return; }
        if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); mention.pick(); return; }
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); mention.close(); return; }
      }
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); submit(ta.value); }
      e.stopPropagation();
    });
    ta.addEventListener('input', () => {
      if (ta.id === 'pUpdText') { FWB.store.set('draft.' + st.id, ta.value); ta.style.height = 'auto'; ta.style.height = Math.min(320, Math.max(64, ta.scrollHeight)) + 'px'; }
      mention.check(ta);
    });
    ta.addEventListener('blur', () => setTimeout(() => { if (mention.ta === ta && document.activeElement !== ta) mention.close(); }, 150));
  }
  const mention = {
    open: false, ta: null, el: null, list: [], i: 0, start: 0,
    check(ta) {
      const pos = ta.selectionStart;
      const before = ta.value.slice(0, pos);
      const m = before.match(/(^|\s)@([\p{L}\p{N}'’-]{0,30})$/u);
      if (!m) { this.close(); return; }
      this.ta = ta;
      this.start = pos - m[2].length - 1;
      const q = m[2].toLowerCase();
      const team = new Set(S.members.map((x) => x.id));
      this.list = P.people.filter((p) => !q || p.name.toLowerCase().split(/\s+/).some((w) => w.startsWith(q)) || p.name.toLowerCase().startsWith(q))
        .sort((a, b) => (team.has(b.id) - team.has(a.id)) || a.name.localeCompare(b.name)).slice(0, 7);
      if (!this.list.length) { this.close(); return; }
      this.i = 0;
      this.show();
    },
    show() {
      if (!this.el) { this.el = document.createElement('div'); this.el.className = 'fwm-pop fwp-mention-list'; this.el.setAttribute('role', 'listbox'); document.body.appendChild(this.el); }
      this.open = true;
      this.el.innerHTML = this.list.map((p, i) => `<button type="button" class="fwm-pop__item ${i === this.i ? 'is-on' : ''}" data-mi="${i}" role="option" aria-selected="${i === this.i}">${avatar(p.name, p.email, '', false)}<span>${esc(p.name)}</span></button>`).join('');
      const r = this.ta.getBoundingClientRect();
      const h = this.el.offsetHeight;
      this.el.style.left = Math.max(8, Math.min(r.left + 8, window.innerWidth - 270)) + 'px';
      this.el.style.top = (r.bottom + h + 8 > window.innerHeight ? Math.max(8, r.top - h - 6) : r.bottom + 6) + 'px';
      this.el.onmousedown = (e) => { e.preventDefault(); const b = e.target.closest('[data-mi]'); if (b) { this.i = Number(b.dataset.mi); this.pick(); } };
    },
    move(d) { this.i = (this.i + d + this.list.length) % this.list.length; this.show(); },
    pick() {
      const p = this.list[this.i];
      const ta = this.ta;
      if (!p || !ta) return this.close();
      st.mentions.set(p.name, p.id);
      const end = ta.selectionStart;
      ta.value = ta.value.slice(0, this.start) + '@' + p.name + ' ' + ta.value.slice(end);
      const caret = this.start + p.name.length + 2;
      ta.setSelectionRange(caret, caret);
      ta.dispatchEvent(new Event('input'));
      this.close();
      ta.focus();
    },
    close() { this.open = false; if (this.el) { this.el.remove(); this.el = null; } },
  };

  // ---------------------------------------------------------------- subitems
  function subList() { return st.detail.subitem_list || []; }
  function renderSubitems() {
    const body = $('#pBody', host);
    if (!body) return;
    const list = subList();
    const done = list.filter((s) => s.done).length;
    const ed = canEdit();
    body.innerHTML = `${list.length ? `<div class="fwp-subprog"><span>${done} of ${list.length} done</span><span class="fwp-bar ${done === list.length ? 'is-done' : ''}" style="--p:${Math.round(done * 100 / list.length)}%"><i></i></span><b>${Math.round(done * 100 / list.length)}%</b></div>` : ''}
      <div class="fwp-psubs" id="pSubs">${list.map((s) => `<div class="fwp-sub ${s.done ? 'is-done' : ''}" data-psid="${s.id}">
          <button class="fwp-check ${s.done ? 'is-on' : ''}" type="button" data-psdone ${ed ? '' : 'disabled'} aria-label="${s.done ? 'Mark not done' : 'Mark done'}">${icon('check')}</button>
          <span class="fwp-sub__title" ${ed ? 'data-psrename tabindex="0"' : ''}>${esc(s.title)}</span>
          <button class="fwp-sub__due ${s.due && s.due < today() && !s.done ? 'is-overdue' : ''}" type="button" data-psdue ${ed ? '' : 'disabled'} aria-label="Due date">${s.due ? esc(fmtDate(s.due)) : icon('calendar', 'i--xs')}</button>
          <button class="fwp-open" type="button" data-psowner ${ed ? '' : 'disabled'} style="opacity:1" aria-label="Owner">${s.owner ? personAvatar(s.owner) : icon('user', 'i--sm')}</button>
          ${ed ? `<button class="icon-btn icon-btn--sm" type="button" data-psmenu aria-label="Subitem menu">${icon('more-h', 'i--sm')}</button>` : '<span></span>'}</div>`).join('')}</div>
      ${ed ? `<form class="fwp-sub__add" id="pSubAdd" style="margin-top:8px"><input placeholder="+ Add a subitem — Enter to add another" maxlength="255" aria-label="New subitem"></form>` : ''}
      ${!list.length && !ed ? `<div class="fwp-panelcard__empty">No subitems.</div>` : ''}
      ${!list.length && ed ? `<div class="hint" style="margin-top:12px;font-size:12.5px;color:var(--ink-3)">Break the ${esc(FWB.noun())} into steps. Each subitem can have its own owner and date, and the ${esc(FWB.noun())}’s progress follows them.</div>` : ''}`;
  }
  /** After a subitem write: the parent's fresh payload, and the table's expanded list. */
  function subChanged(d) {
    if (d.item) { FWB.applyItems([d.item]); FWB.refreshItem(d.item.id); mergeItem(d.item); }
    if (FWB.S.expanded.has(st.id)) { FWB.S.subs.set(st.id, subList().slice()); FWB.refreshItem(st.id); }
    renderTabs();
    renderSubitems();
  }
  async function subAdd(title) {
    try {
      const d = await api.post('subitems.php', { action: 'add', item_id: st.id, title });
      st.detail.subitem_list = subList().concat([d.subitem]);
      subChanged(d);
      const f = $('#pSubAdd input', host);
      if (f) f.focus();
    } catch (x) { toastError(x); }
  }
  async function subUpdate(sid, patch) {
    try {
      const d = await api.post('subitems.php', Object.assign({ action: 'update', id: sid }, patch));
      st.detail.subitem_list = subList().map((s) => (s.id === sid ? d.subitem : s));
      subChanged(d);
    } catch (x) { toastError(x); }
  }
  async function subMenu(anchor, s) {
    popover(anchor, `<button class="fwm-pop__item" data-v="promote">${icon('arrow-up')}Make it a ${esc(FWB.noun())} of its own</button>
      <button class="fwm-pop__item is-danger" data-v="delete">${icon('trash')}Delete subitem</button>`, {
      align: 'end',
      onClick: async (b) => {
        if (b.dataset.v === 'delete') {
          try {
            const d = await api.post('subitems.php', { action: 'delete', id: s.id });
            st.detail.subitem_list = subList().filter((x) => x.id !== s.id);
            subChanged(d);
          } catch (x) { toastError(x); }
        }
        if (b.dataset.v === 'promote') {
          try {
            const d = await api.post('subitems.php', { action: 'promote', id: s.id });
            st.detail.subitem_list = subList().filter((x) => x.id !== s.id);
            if (d.new_item) { FWB.applyItems([d.new_item]); FWB.renderView(); }
            subChanged(d);
            toast(`“${s.title}” is now a ${FWB.noun()}`, { action: d.new_item ? { label: 'Open', run: () => FWB.openItem(d.new_item.id) } : null });
          } catch (x) { toastError(x); }
        }
      },
    });
  }
  function subRename(span, s) {
    const input = document.createElement('input');
    input.className = 'fwp-name__input';
    input.value = s.title;
    input.maxLength = 255;
    span.replaceWith(input);
    input.focus();
    input.select();
    let done = false;
    const fin = (save) => { if (done) return; done = true; const v = input.value.trim(); if (save && v && v !== s.title) subUpdate(s.id, { title: v }); else renderSubitems(); };
    input.addEventListener('keydown', (e) => { e.stopPropagation(); if (e.key === 'Enter') { e.preventDefault(); fin(true); } if (e.key === 'Escape') { e.preventDefault(); fin(false); } });
    input.addEventListener('blur', () => fin(true));
  }

  // ---------------------------------------------------------------- files
  function renderFiles() {
    const body = $('#pBody', host);
    if (!body || st.tab !== 'files') return;
    const it = boardItem();
    const files = it.files || [];
    const ed = canEdit();
    body.innerHTML = `${ed ? `<div class="fwp-drop" id="pDrop" role="button" tabindex="0">${icon('upload')}<b>Drop files here</b>or click to choose — photos, drawings, PDFs, spreadsheets up to 25 MB</div>` : ''}
      <div id="pUploads">${st.uploads.map((u) => `<div class="fwp-conn" style="margin-top:10px"><span class="fwp-conn__icon">${icon('upload')}</span><span class="fwp-conn__text"><b>${esc(u.name)}</b><small>${u.error ? esc(u.error) : 'Uploading… ' + Math.round(u.p * 100) + '%'}</small><div class="fwp-upbar"><i style="--p:${Math.round(u.p * 100)}%"></i></div></span></div>`).join('')}</div>
      ${files.length ? `<div class="fwp-pfiles">${files.map((f) => {
        const [kind, label] = fileKind(f.name, f.mime);
        const url = C.fileUrl(f);
        return `<a class="fwp-pfile" href="${esc(url)}" target="_blank" rel="noopener" title="${esc(f.name)} · ${esc(fmtSize(f.size))}">
          <span class="fwp-pfile__thumb">${isImage(f) ? `<img src="${esc(url)}" alt="" loading="lazy">` : `<span class="fwp-filechip ${kind}" style="width:46px;height:56px;font-size:12px">${esc(label)}</span>`}</span>
          <span class="fwp-pfile__name">${esc(f.name)}</span>
          ${ed ? `<button class="icon-btn" type="button" data-pfdel="${f.id}" aria-label="Remove ${esc(f.name)}">${icon('trash', 'i--sm')}</button>` : ''}</a>`;
      }).join('')}</div>` : (ed ? '' : '<div class="fwp-panelcard__empty">No files.</div>')}`;
  }
  async function uploadFiles(list) {
    const files = Array.from(list || []);
    if (!files.length || !canEdit()) return;
    if (st.tab !== 'files') setTab('files');
    const id = st.id;
    const fc = FWB.colsOf('files')[0];
    for (const file of files) {
      const u = { name: file.name, p: 0, error: null };
      st.uploads.push(u);
      renderFiles();
      if (file.size > 25 * 1024 * 1024) { u.error = 'Too large — files can be up to 25 MB'; renderFiles(); setTimeout(() => { st.uploads = st.uploads.filter((x) => x !== u); renderFiles(); }, 5000); continue; }
      const fd = new FormData();
      fd.append('action', 'upload');
      fd.append('item_id', id);
      if (fc) fd.append('column_id', fc.id);
      fd.append('file', file);
      try {
        const d = await api.upload('files.php', fd, (p) => { u.p = p; const bar = $$('#pUploads .fwp-upbar i', host)[st.uploads.indexOf(u)]; if (bar) bar.style.setProperty('--p', Math.round(p * 100) + '%'); });
        st.uploads = st.uploads.filter((x) => x !== u);
        const it = FWB.item(id) || (st.detail && st.detail.id === id ? st.detail : null);
        if (it) { it.files = (it.files || []).concat([d.file]); if (FWB.item(id)) FWB.refreshItem(id); else onItemChanged(it); }
        if (st.id === id) { renderFiles(); renderTabs(); }
      } catch (x) {
        u.error = x.message;
        renderFiles();
        setTimeout(() => { st.uploads = st.uploads.filter((y) => y !== u); renderFiles(); }, 6000);
      }
    }
  }
  async function deleteFile(fid) {
    const it = boardItem();
    const f = (it.files || []).find((x) => x.id === fid);
    if (!f || !(await dialog({ title: `Remove “${f.name}”?`, body: 'The file is deleted from the server.', confirm: 'Remove', danger: true }))) return;
    try {
      await api.post('files.php', { action: 'delete', id: fid });
      it.files = it.files.filter((x) => x.id !== fid);
      if (FWB.item(it.id)) FWB.refreshItem(it.id); else onItemChanged(it);
      renderFiles();
      renderTabs();
    } catch (x) { toastError(x); }
  }

  // ---------------------------------------------------------------- activity
  async function loadActivity(more) {
    const scope = st.mode === 'board' ? { board_id: S.board.id } : { item_id: st.id };
    const ref = st.mode === 'board' ? 'b' : st.id;
    try {
      const last = more && st.activity ? st.activity[st.activity.length - 1] : null;
      const d = await api.get('activity.php', Object.assign({ limit: 40, before: last ? last.id : undefined }, scope));
      if ((st.mode === 'board' ? 'b' : st.id) !== ref) return;
      st.activity = more ? (st.activity || []).concat(d.activity) : d.activity;
      st.actMore = d.activity.length === 40;
      renderActivity();
    } catch (x) { toastError(x); }
  }
  function renderActivity() {
    const body = $('#pBody', host);
    if (!body) return;
    if (!st.activity) { body.innerHTML = '<div class="fwp-skel-line"></div><div class="fwp-skel-line"></div><div class="fwp-skel-line"></div>'; return; }
    body.innerHTML = `<div class="fwp-panelcard" style="box-shadow:none;background:transparent;padding:0">${activityHTML(st.activity, true, st.mode === 'item' ? { item: st.id } : {}) || '<div class="fwp-panelcard__empty">Nothing has happened yet.</div>'}</div>
      ${st.actMore ? `<div style="display:flex;justify-content:center;padding:14px 0"><button class="btn btn--sm" type="button" data-p="actmore">Show earlier</button></div>` : ''}`;
  }
  /** The board's activity, in the panel. */
  function openActivity() {
    flushDescription();
    if (S.openItem) { const r = FWB.el.view.querySelector(`.fwp-row[data-iid="${S.openItem}"]`); if (r) r.classList.remove('is-open'); S.openItem = 0; }
    // The address no longer names an item (a reload must not open it again).
    const u = new URL(location.href);
    u.searchParams.delete('item');
    u.searchParams.delete('open');
    history.replaceState(null, '', u.pathname + u.search);
    st.mode = 'board';
    st.id = 0;
    st.detail = null;
    st.activity = null;
    host.hidden = false;
    document.body.classList.add('is-panel');
    host.innerHTML = `<div class="fwm-reader__bar"><button class="icon-btn" type="button" data-p="close" aria-label="Close" data-tip="Close · Esc">${icon('x')}</button><span class="grow"></span>
        <a class="btn btn--sm btn--ghost" href="/projects/view.php?project_id=${S.project.id}&tab=activity">${icon('history')}Project activity</a></div>
      <div class="fwp-panel__scroll scroll"><div class="fwp-panel__head"><div class="fwp-panel__crumb"><span>${esc(S.board.title)}</span></div><h2 class="fwp-panel__title">Board activity</h2></div>
      <div class="fwp-panel__body" id="pBody"></div></div>`;
    renderActivity();
    loadActivity();
  }

  // ===========================================================================
  // Item actions (bar)
  // ===========================================================================
  function neighbour(dir) {
    const order = $$('.fwp-row[data-iid]', FWB.el.view).map((r) => Number(r.dataset.iid));
    const list = order.length ? order : FWB.filtered().map((x) => x.id);
    const i = list.indexOf(st.id);
    if (i < 0) return list[0] || null;
    return list[i + dir] || null;
  }
  function go(dir) {
    const id = neighbour(dir);
    if (id) { flushDescription(); const had = host.contains(document.activeElement); FWB.openItem(id, st.tab === 'activity' || st.tab === 'updates' ? st.tab : '', had ? { focus: true } : undefined); const r = FWB.el.view.querySelector(`.fwp-row[data-iid="${id}"]`); if (r) r.scrollIntoView({ block: 'nearest' }); }
  }
  async function moreMenu(anchor) {
    const d = st.detail;
    const ed = canEdit();
    const others = S.boards.filter((b) => b.id !== S.board.id && !b.archived && (b.level || 0) >= 2);
    popover(anchor, `${ed ? `<button class="fwm-pop__item" data-v="dup">${icon('copy')}Duplicate</button>
      <button class="fwm-pop__item" data-v="group">${icon('layers')}Move to group…</button>
      ${others.length ? `<button class="fwm-pop__item" data-v="board">${icon('move')}Move to board…</button>` : ''}` : ''}
      <button class="fwm-pop__item" data-v="mail">${icon('mail')}Share by email</button>
      ${ed ? `<div class="fwm-pop__sep"></div><button class="fwm-pop__item" data-v="archive">${icon('archive')}Archive</button>
      <button class="fwm-pop__item is-danger" data-v="delete">${icon('trash')}Delete</button>` : ''}`, {
      align: 'end',
      onClick: async (b) => {
        const v = b.dataset.v;
        if (v === 'dup') {
          try { const r = await api.post('items.php', { action: 'duplicate', id: d.id }); FWB.applyItems([r.item]); FWB.renderView(); toast('Duplicated', { action: { label: 'Open', run: () => FWB.openItem(r.item.id) } }); } catch (x) { toastError(x); }
        }
        if (v === 'group') { setTimeout(() => groupPicker(anchor), 0); return true; }
        if (v === 'board') { setTimeout(() => boardPicker(anchor, others), 0); return true; }
        if (v === 'mail') {
          const url = location.origin + (d.url || `/projects/board.php?board_id=${S.board.id}&item=${d.id}`);
          location.href = `mailto:?subject=${encodeURIComponent(d.title + ' — ' + S.board.title)}&body=${encodeURIComponent(d.title + '\n' + url)}`;
        }
        if (v === 'archive') { await FWB.archiveItems([d.id], 'Archived'); close(); }
        if (v === 'delete') { await FWB.deleteItems([d.id]); }
        return undefined;
      },
    });
  }
  function groupPicker(anchor) {
    const it = boardItem();
    popover(anchor, `<div class="fwm-pop__title">Move to group</div>${S.groups.map((g) => `<button class="fwm-pop__item ${g.id === it.group_id ? 'is-on' : ''}" data-v="${g.id}"><span class="fwp-dot fwp-dot--sq" style="--c:${safeColor(g.color)}"></span>${esc(g.name)}</button>`).join('')}`, {
      align: 'end',
      onClick: async (b) => { const gid = Number(b.dataset.v); if (gid !== it.group_id) { await FWB.moveItems([it.id], gid); const fresh = FWB.item(it.id); if (fresh) onItemChanged(fresh); } },
    });
  }
  function boardPicker(anchor, others) {
    popover(anchor, `<div class="fwm-pop__title">Move to board</div>${others.map((b) => `<button class="fwm-pop__item" data-v="${b.id}">${icon(boardIcon(b))}${esc(b.title)}</button>`).join('')}`, {
      align: 'end',
      onClick: async (b) => {
        const to = Number(b.dataset.v);
        const target = others.find((x) => x.id === to);
        try {
          await api.post('items.php', { action: 'move_board', board_id: S.board.id, ids: [st.id], to_board_id: to });
          const id = st.id;
          close();
          FWB.removeItems([id]);
          FWB.renderView();
          toast(`Moved to ${target ? target.title : 'the board'}`, { action: { label: 'Open', run: () => { location.href = `/projects/board.php?board_id=${to}&item=${id}`; } } });
        } catch (x) { toastError(x); }
      },
    });
  }
  async function toggleWatch() {
    const d = st.detail;
    const on = !(d.watchers || []).includes(P.me.id);
    try {
      await api.post('items.php', { action: 'watch', id: d.id, on });
      d.watchers = on ? (d.watchers || []).concat([P.me.id]) : (d.watchers || []).filter((x) => x !== P.me.id);
      renderBar();
      toast(on ? 'Following — you’ll hear about updates and changes' : 'No longer following');
    } catch (x) { toastError(x); }
  }
  async function copyLink() {
    const url = location.origin + (st.detail.url || `/projects/board.php?board_id=${S.board.id}&item=${st.id}`);
    try { await navigator.clipboard.writeText(url); toast('Link copied'); }
    catch (e) { await dialog({ title: 'Link to this item', input: { value: url }, confirm: 'Done', cancel: null }); }
  }
  function depPicker(anchor) {
    const taken = new Set((st.detail.deps || []).map((x) => x.item_id).concat([st.id]));
    const pool = S.items.filter((x) => !taken.has(x.id));
    if (!pool.length) { toast(`There is no other ${FWB.noun()} on this board to link.`); return; }
    const list = (q) => pool.filter((x) => !q || x.title.toLowerCase().includes(q.toLowerCase())).slice(0, 40)
      .map((x) => { const g = FWB.group(x.group_id); return `<button class="fwm-pop__item" data-v="${x.id}"><span class="fwp-dot fwp-dot--sq" style="--c:${safeColor(g ? g.color : '')}"></span><span style="min-width:0;overflow:hidden;text-overflow:ellipsis">${esc(x.title)}</span></button>`; }).join('') || '<div class="fwm-nav__empty">Nothing matches.</div>';
    const pop = popover(anchor, `<div class="fwp-pick" style="width:320px"><div class="fwm-pop__title">This ${esc(FWB.noun())} waits on…</div>
        <input class="fwm-pop__input" placeholder="Search ${esc(FWB.noun())}s" aria-label="Search"><div class="fwp-plist" style="max-height:300px;overflow:auto">${list('')}</div></div>`, {
      align: 'end',
      onClick: async (b) => {
        try {
          const r = await api.post('items.php', { action: 'dep_add', from: Number(b.dataset.v), to: st.id });
          const other = FWB.item(b.dataset.v);
          st.detail.deps = (st.detail.deps || []).concat([{ id: r.dep_id, kind: 'waiting_on', item_id: Number(b.dataset.v), title: other ? other.title : '', type: 'FS', lag: 0 }]);
          S.deps.push({ id: r.dep_id, from: Number(b.dataset.v), to: st.id, type: 'FS', lag: 0 });
          renderDeps();
          if (S.view === 'gantt') FWB.renderView();
        } catch (x) { toastError(x); }
      },
    });
    const inp = $('.fwm-pop__input', pop.el);
    inp.addEventListener('input', () => { $('.fwp-plist', pop.el).innerHTML = list(inp.value.trim()); });
  }
  async function depRemove(depId) {
    try {
      await api.post('items.php', { action: 'dep_remove', dep_id: depId });
      st.detail.deps = (st.detail.deps || []).filter((x) => x.id !== depId);
      S.deps = S.deps.filter((x) => x.id !== depId);
      renderDeps();
      if (S.view === 'gantt') FWB.renderView();
    } catch (x) { toastError(x); }
  }
  async function restore() {
    try {
      const d = await api.post('items.php', { action: 'restore', board_id: S.board.id, ids: [st.id] });
      FWB.applyItems(d.items);
      S.board.archived_items = Math.max(0, (S.board.archived_items || 1) - 1);
      FWB.renderView();
      toast('Restored');
      refresh(st.id);
    } catch (x) { toastError(x); }
  }
  async function renameFromTitle(node) {
    const title = node.textContent.replace(/\s+/g, ' ').trim();
    const it = boardItem();
    if (!title) { node.textContent = it.title; return; }
    if (title === it.title) return;
    if (FWB.item(it.id)) await FWB.renameItem(FWB.item(it.id), title);
    else {
      try { const d = await api.post('items.php', { action: 'rename', id: it.id, title }); st.detail.title = d.item.title; } catch (x) { node.textContent = it.title; toastError(x); }
    }
  }

  // ===========================================================================
  // Events
  // ===========================================================================
  host.addEventListener('click', async (e) => {
    const t = e.target;
    const tab = t.closest('[data-ptab]');
    if (tab) { setTab(tab.dataset.ptab); return; }
    const p = t.closest('[data-p]');
    if (p) {
      const a = p.dataset.p;
      if (a === 'close') close();
      if (a === 'prev') go(-1);
      if (a === 'next') go(1);
      if (a === 'watch') toggleWatch();
      if (a === 'link') copyLink();
      if (a === 'calendar') location.href = calendarUrl();
      if (a === 'more') moreMenu(p);
      if (a === 'restore') restore();
      if (a === 'depadd') depPicker(p);
      if (a === 'actmore') { p.classList.add('is-busy'); loadActivity(true); }
      if (a === 'mention') { const ta = $('#pUpdText', host); if (ta) { const pos = ta.selectionStart; const pre = ta.value.slice(0, pos); const add = (pre && !/\s$/.test(pre) ? ' ' : '') + '@'; ta.value = pre + add + ta.value.slice(pos); ta.focus(); ta.setSelectionRange(pos + add.length, pos + add.length); mention.check(ta); } }
      return;
    }
    const po = t.closest('[data-popen]');
    if (po) { e.preventDefault(); const id = Number(po.dataset.popen); if (FWB.item(id)) FWB.openItem(id); else open(id); return; }
    if (t.closest('[data-pdeprm]')) { depRemove(Number(t.closest('[data-pdeprm]').dataset.pdeprm)); return; }
    if (t.closest('[data-pgroup]') && canEdit()) { groupPicker(t.closest('[data-pgroup]')); return; }
    // fields
    const rate = t.closest('[data-rate]');
    if (rate && rate.closest('[data-pcid]')) {
      if (!canEdit()) return;
      const c = FWB.col(rate.closest('[data-pcid]').dataset.pcid);
      const it = FWB.item(st.id);
      if (it && c) { const n = Number(rate.dataset.rate); FWB.setValue(it, c, it.values[c.id] === n ? null : n); }
      return;
    }
    const cell = t.closest('[data-pcid]');
    if (cell && !t.closest('a[data-stop], a.fwp-txt')) {
      const c = FWB.col(cell.dataset.pcid);
      if (!c) return;
      if (c.type === 'files') { setTab('files'); return; }
      const it = FWB.item(st.id);
      if (!canEdit() || !C.editable(c) || !it) return;
      const kb = e.detail === 0; // Enter on the field: after the save the keyboard is back on it
      C.edit(c, it, cell, (v) => {
        const r = FWB.setValue(it, c, v);
        const back = kb && $(`[data-pcid="${c.id}"]`, host);
        if (back) back.focus({ preventScroll: true });
        return r;
      }, {
        canManage: canManage(),
        onEditLabels: (cc) => FWB.dialogs && FWB.dialogs.columnSettings(cc),
        allTags: (cc) => Array.from(new Set(S.items.flatMap((x) => x.values[cc.id] || []))).sort(),
        openFiles: () => setTab('files'),
      });
      return;
    }
    // updates
    const ur = t.closest('[data-ureply]'); if (ur) { openReply(Number(ur.dataset.ureply)); return; }
    const ue = t.closest('[data-uedit]'); if (ue) { editUpdate(Number(ue.dataset.uedit)); return; }
    const ud = t.closest('[data-udel]'); if (ud) { deleteUpdate(Number(ud.dataset.udel)); return; }
    const us = t.closest('[data-usave]'); if (us) { saveEdit(Number(us.dataset.usave), $('textarea', us.parentElement)); return; }
    if (t.closest('[data-ucancel]')) { renderUpdateList(); return; }
    // subitems
    const srow = t.closest('[data-psid]');
    if (srow) {
      const s = subList().find((x) => x.id === Number(srow.dataset.psid));
      if (!s) return;
      if (t.closest('[data-psdone]')) { subUpdate(s.id, { done: !s.done }); return; }
      if (t.closest('[data-psdue]')) { pickDate(t.closest('[data-psdue]'), s.due, (dd) => subUpdate(s.id, { due: dd || '' }), { title: 'Due' }); return; }
      if (t.closest('[data-psowner]')) { pickPeople(t.closest('[data-psowner]'), s.owner ? [s.owner] : [], { multi: false, title: 'Owner', onChange: (ids) => subUpdate(s.id, { owner: ids[0] === s.owner ? 0 : (ids[0] || 0) }) }); return; }
      if (t.closest('[data-psmenu]')) { subMenu(t.closest('[data-psmenu]'), s); return; }
      if (t.closest('[data-psrename]')) { subRename(t.closest('[data-psrename]'), s); return; }
      return;
    }
    // files
    const fd = t.closest('[data-pfdel]');
    if (fd) { e.preventDefault(); e.stopPropagation(); deleteFile(Number(fd.dataset.pfdel)); return; }
    if (t.closest('#pDrop')) { const fi = $('#fileInput'); if (fi) { fi.value = ''; fi.click(); } }
  });
  host.addEventListener('keydown', (e) => {
    const title = e.target.closest('#pTitle');
    if (title) {
      if (e.key === 'Enter') { e.preventDefault(); title.blur(); }
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); title.textContent = boardItem().title; title.blur(); }
      e.stopPropagation();
      return;
    }
    if (e.target.closest('#pDrop') && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); const fi = $('#fileInput'); if (fi) fi.click(); return; }
    const cell = e.target.closest('[data-pcid], [data-pgroup]');
    if (cell && e.key === 'Enter') { e.preventDefault(); cell.click(); }
    // A rating from the keyboard: 0–9 stars.
    if (cell && cell.dataset.pcid && /^[0-9]$/.test(e.key) && canEdit()) {
      const c = FWB.col(cell.dataset.pcid);
      const it = FWB.item(st.id);
      if (c && it && c.type === 'rating') { e.preventDefault(); e.stopPropagation(); FWB.setValue(it, c, Math.min(Number(e.key), (c.config || {}).max || 5) || null); }
    }
  });
  host.addEventListener('focusout', (e) => { if (e.target.id === 'pTitle') renameFromTitle(e.target); });
  host.addEventListener('submit', (e) => {
    if (e.target.id === 'pSubAdd') {
      e.preventDefault();
      const inp = $('input', e.target);
      const v = inp.value.trim();
      if (v) { inp.value = ''; subAdd(v); }
    }
  });
  // Drop files anywhere on the panel.
  host.addEventListener('dragover', (e) => { if (canEdit() && e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files')) { e.preventDefault(); const dz = $('#pDrop', host); if (dz) dz.classList.add('is-over'); } });
  host.addEventListener('dragleave', (e) => { if (!host.contains(e.relatedTarget)) { const dz = $('#pDrop', host); if (dz) dz.classList.remove('is-over'); } });
  host.addEventListener('drop', (e) => {
    if (!e.dataTransfer || !e.dataTransfer.files.length || !canEdit()) return;
    e.preventDefault();
    uploadFiles(e.dataTransfer.files);
  });
  host.addEventListener('paste', (e) => {
    if (!canEdit() || !e.clipboardData) return;
    const files = Array.from(e.clipboardData.files || []);
    if (files.length && (st.tab === 'files' || st.tab === 'updates')) { e.preventDefault(); uploadFiles(files); }
  });
  const fileInput = $('#fileInput');
  if (fileInput) fileInput.addEventListener('change', () => { if (st.id && fileInput.files.length) uploadFiles(fileInput.files); });

  document.addEventListener('keydown', (e) => {
    if (host.hidden || e.ctrlKey || e.metaKey || e.altKey) return;
    if (window.FWP.activePop || document.querySelector('.fwm-modal:not([hidden])')) return;
    if (e.target.closest('input, textarea, select, [contenteditable="true"]')) return;
    if (FWB.isGridKey(e)) return; // the table's own keys (its Escape leaves the table first)
    if (e.key === 'Escape') { e.preventDefault(); close(); return; }
    if (st.mode !== 'item') return;
    if (e.key === 'j') { e.preventDefault(); go(1); }
    if (e.key === 'k') { e.preventDefault(); go(-1); }
  });
  window.addEventListener('beforeunload', flushDescription);

  FWB.panel = { open, close, refresh, onItemChanged, openActivity, upload: uploadFiles, get state() { return st; } };
})();
