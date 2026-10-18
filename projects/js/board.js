/* =============================================================================
   Flowwork Projects — board
   State, filters (search, person, rules, sort), the head and toolbar, the
   table view (groups, sticky header and name column, inline editing,
   keyboard, drag & drop of rows, groups and columns, column widths, group
   summaries, subitems), bulk actions, live updates and the hooks the item
   panel (board-panel.js), the other views (board-views.js) and the dialogs
   (board-dialogs.js) plug into: window.FWB.
   ============================================================================= */
(function () {
  'use strict';

  const {
    $, $$, esc, icon, debounce, narrow, finePointer, api, toast, toastError, popover, closePop, dialog, toggleDrawer,
    P, BOOT, avatar, faces, personName, personAvatar, personById, ymd, parseYmd, today, addDays, daysBetween, fmtDate, relTime,
    safeColor, renderProjectSide, emptyState, boardIcon, pickPeople,
  } = window.FWP;
  const C = window.FWBCells;
  if (!BOOT.board) return;

  const store = {
    get: (k, d) => { try { const v = localStorage.getItem('fwp.b' + BOOT.board.id + '.' + k); return v == null ? d : JSON.parse(v); } catch (e) { return d; } },
    set: (k, v) => { try { localStorage.setItem('fwp.b' + BOOT.board.id + '.' + k, JSON.stringify(v)); } catch (e) { /* private mode */ } },
  };

  // ===========================================================================
  // State
  // ===========================================================================
  // Operators that compare with a value: a rule still waiting for its value does not filter yet.
  const VALUE_OPS = ['is', 'is_not', '=', '!=', '>', '<', '>=', '<=', 'before', 'after', 'contains', 'not_contains'];
  const ruleReady = (r) => !!(r && r.col != null && r.op) && (!VALUE_OPS.includes(r.op) || [].concat(r.value ?? []).some((x) => x !== '' && x != null));
  const prefs = BOOT.prefs || {};
  const S = {
    board: BOOT.board,
    project: BOOT.project || { id: BOOT.board.project_id, name: BOOT.board.project_name, level: BOOT.board.level },
    boards: BOOT.boards || [],
    columns: BOOT.columns || [],
    groups: BOOT.groups || [],
    items: BOOT.items || [],
    suppliers: BOOT.suppliers || [],
    deps: BOOT.deps || [],
    views: BOOT.views || [],
    cursor: BOOT.cursor || 0,
    members: BOOT.members || [],
    guest: !!(BOOT.board && BOOT.board.guest),
    view: 'table',
    q: '',
    person: prefs.person || null,
    rules: Array.isArray(prefs.filters) ? prefs.filters.filter(ruleReady) : [],
    match: 'all',
    sort: Array.isArray(prefs.sort) ? prefs.sort : [],
    hidden: new Set((prefs.hidden || []).map(Number)),
    collapsed: new Set(),
    selected: new Set(),
    expanded: new Set(),
    subs: new Map(),
    openItem: 0,
    focus: null,
    activeView: 0,
    density: prefs.density || 'normal',
    widths: {},
  };
  S.groups.forEach((g) => { if (g.collapsed) S.collapsed.add(g.id); });
  if (BOOT.people && BOOT.people.length) P.people = BOOT.people;
  const itemById = new Map();
  let applySeq = 0; // bumps whenever fresh item payloads land (a poll answer older than that is dropped)
  const el = { head: $('#head'), tools: $('#tools'), view: $('#view'), progress: $('#progress'), panel: $('#panel'), search: $('#searchInput'), searchClear: $('#searchClear') };
  const level = () => (S.guest ? 0 : (S.board.level || 0));
  const canEdit = () => level() >= 2;
  const canManage = () => level() >= 3;
  const busy = (on) => el.progress.classList.toggle('is-on', !!on);
  const views = new Map();
  const noun = () => S.board.item_noun || 'item';
  const Noun = () => noun().charAt(0).toUpperCase() + noun().slice(1);

  // ===========================================================================
  // Data helpers
  // ===========================================================================
  const col = (id) => S.columns.find((c) => c.id === Number(id)) || null;
  const group = (id) => S.groups.find((g) => g.id === Number(id)) || null;
  const item = (id) => itemById.get(Number(id)) || null;
  const colsOf = (...types) => S.columns.filter((c) => types.includes(c.type));
  const primaryStatus = () => colsOf('status')[0] || null;
  const primaryPeople = () => colsOf('people')[0] || null;
  function primaryDate() {
    const ds = colsOf('date');
    return ds.find((c) => /due|deadline|target|finish|end|complet/i.test(c.name)) || ds[0] || null;
  }
  const visibleColumns = () => S.columns.filter((c) => !S.hidden.has(c.id) && !(c.hidden && !S.hidden.size && prefs.hidden === undefined));
  function decorate(it) {
    const ps = primaryStatus();
    const l = ps ? C.statusOf(ps, it.values[ps.id]) : null;
    it._done = !!(l && l.done);
    it._group = group(it.group_id);
    return it;
  }
  function indexItems() {
    applySeq++;
    itemById.clear();
    S.items.forEach((it) => { if (!it.values || Array.isArray(it.values)) it.values = Object.assign({}, it.values || {}); decorate(it); itemById.set(it.id, it); });
  }
  /** Replace / add item payloads from the server. */
  function applyItems(list) {
    applySeq++;
    (list || []).forEach((p) => {
      if (!p || !p.id) return;
      if (!p.values || Array.isArray(p.values)) p.values = Object.assign({}, p.values || {});
      const i = S.items.findIndex((x) => x.id === p.id);
      if (i >= 0) S.items[i] = p; else S.items.push(p);
      decorate(p);
      itemById.set(p.id, p);
    });
  }
  function removeItems(ids) {
    const set = new Set(ids.map(Number));
    S.items = S.items.filter((x) => !set.has(x.id));
    set.forEach((id) => { itemById.delete(id); S.selected.delete(id); });
  }
  function itemsSorted(list) {
    if (!S.sort.length) return list.slice().sort((a, b) => a.position - b.position || a.id - b.id);
    return list.slice().sort((a, b) => {
      for (const s of S.sort) {
        let x, y;
        if (s.col === 'name') { x = a.title.toLowerCase(); y = b.title.toLowerCase(); }
        else { const c = col(s.col); if (!c) continue; x = C.sortKey(c, a); y = C.sortKey(c, b); }
        if (x < y) return s.dir === 'desc' ? 1 : -1;
        if (x > y) return s.dir === 'desc' ? -1 : 1;
      }
      return a.position - b.position;
    });
  }

  // ---------------------------------------------------------------- filters
  const t0 = () => parseYmd(today());
  function dateTest(op, d, val) {
    if (!d) return op === 'empty';
    if (op === 'not_empty') return true;
    if (op === 'empty') return false;
    const t = t0();
    const x = parseYmd(d);
    const endWeek = addDays(t, 7 - (t.getDay() || 7));
    switch (op) {
      case 'overdue': return x < t;
      case 'today': return daysBetween(t, x) === 0;
      case 'this_week': return x >= t && x <= endWeek;
      case 'next_week': return x > endWeek && x <= addDays(endWeek, 7);
      case 'past': return x < t;
      case 'future': return x > t;
      case 'before': return val ? d < val : true;
      case 'after': return val ? d > val : true;
      case 'is': return val ? d === val : true;
    }
    return true;
  }
  function ruleMatch(r, it) {
    if (r.col === 'group') return r.op === 'is_not' ? !(r.value || []).includes(it.group_id) : (r.value || []).includes(it.group_id);
    if (r.col === 'name') {
      const t = it.title.toLowerCase(), v = String(r.value || '').toLowerCase();
      return r.op === 'not_contains' ? !t.includes(v) : t.includes(v);
    }
    const c = col(r.col);
    if (!c) return true;
    const v = C.val(it, c);
    const empty = v == null || v === '' || (Array.isArray(v) && !v.length);
    if (r.op === 'empty') return empty;
    if (r.op === 'not_empty') return !empty;
    switch (c.type) {
      case 'status': case 'priority': case 'supplier': {
        const vals = (r.value || []).map(String);
        const has = !empty && vals.includes(String(v));
        return r.op === 'is_not' ? !has : has;
      }
      case 'people': case 'dropdown': {
        const vals = (r.value || []).map(String);
        const has = !empty && (v || []).some((x) => vals.includes(String(x)) || (vals.includes('me') && Number(x) === P.me.id));
        return r.op === 'is_not' ? !has : has;
      }
      case 'date': return dateTest(r.op, v, r.value);
      case 'timeline': return dateTest(r.op, v ? (r.op === 'overdue' || r.op === 'past' ? v.end : v.start) : null, r.value) || (r.op === 'today' && v && v.start <= today() && v.end >= today());
      case 'checkbox': return r.op === 'unchecked' ? !v : !!v;
      case 'number': case 'formula': case 'progress': case 'rating': {
        if (typeof v !== 'number') return false;
        const n = Number(r.value);
        return { '=': v === n, '!=': v !== n, '>': v > n, '<': v < n, '>=': v >= n, '<=': v <= n }[r.op] ?? true;
      }
      default: {
        const t = C.text(c, it).toLowerCase(), q = String(r.value || '').toLowerCase();
        return r.op === 'not_contains' ? !t.includes(q) : t.includes(q);
      }
    }
  }
  function matches(it) {
    if (S.q) {
      const q = S.q.toLowerCase();
      const hay = (it.title + ' ' + S.columns.map((c) => C.text(c, it)).join(' ')).toLowerCase();
      if (!hay.includes(q)) return false;
    }
    if (S.person) {
      const pc = colsOf('people');
      if (!pc.some((c) => (C.val(it, c) || []).includes(Number(S.person)))) return false;
    }
    const rules = S.rules.filter(ruleReady);
    if (rules.length) {
      const res = rules.map((r) => ruleMatch(r, it));
      if (S.match === 'any' ? !res.some(Boolean) : !res.every(Boolean)) return false;
    }
    return true;
  }
  const filtered = () => S.items.filter(matches);
  const isFiltered = () => !!(S.q || S.person || S.rules.some(ruleReady));
  function itemsIn(gid, list) { return itemsSorted((list || filtered()).filter((it) => it.group_id === gid)); }

  // ===========================================================================
  // Writes
  // ===========================================================================
  let pendingWrites = 0;
  /** Set a cell; optimistic for simple values, server answer replaces the item. */
  async function setValue(it, c, value) {
    const prev = it.values[c.id];
    if (JSON.stringify(prev ?? null) === JSON.stringify(value ?? null)) return;
    if (value == null || value === '' || (Array.isArray(value) && !value.length)) delete it.values[c.id];
    else it.values[c.id] = c.type === 'number' && value !== '' && !Number.isNaN(Number(value)) ? Number(String(value).replace(/[\s,R%]/g, '')) : value;
    decorate(it);
    applySeq++; // a poll answer already on its way predates this value
    refreshItem(it.id, { pop: c.type === 'status' });
    pendingWrites++;
    const live = cellFor(it.id, c.id); // the row was just re-rendered
    if (live) live.classList.add('is-saving');
    try {
      const d = await api.post('items.php', { action: 'set', id: it.id, column_id: c.id, value });
      applyItems(d.items);
      d.items.forEach((x) => refreshItem(x.id, { flash: d.automated && x.id !== it.id }));
      if (d.automated) { renderView(); toast('Automation ran'); }
      if (c.type === 'status') {
        const l = C.statusOf(c, value);
        if (l && l.done && !(C.statusOf(c, prev) || {}).done) celebrate(it);
      }
      return d;
    } catch (x) {
      if (prev == null) delete it.values[c.id]; else it.values[c.id] = prev;
      decorate(it);
      refreshItem(it.id);
      toastError(x);
    } finally {
      pendingWrites--;
      const fresh = cellFor(it.id, c.id);
      if (fresh) fresh.classList.remove('is-saving');
    }
  }
  function celebrate(it) {
    const row = rowEl(it.id);
    if (row) { row.classList.remove('is-flash'); void row.offsetWidth; row.classList.add('is-flash'); }
  }
  async function bulkSet(ids, c, value) {
    busy(true);
    try {
      const d = await api.post('items.php', { action: 'bulk_set', board_id: S.board.id, ids, column_id: c.id, value });
      applyItems(d.items);
      renderView();
      toast(`${c.name} updated on ${ids.length} ${noun()}${ids.length === 1 ? '' : 's'}`);
    } catch (x) { toastError(x); } finally { busy(false); }
  }
  async function renameItem(it, title) {
    title = title.trim();
    if (!title || title === it.title) return;
    const old = it.title;
    it.title = title;
    refreshItem(it.id);
    try { applyItems([(await api.post('items.php', { action: 'rename', id: it.id, title })).item]); refreshItem(it.id); }
    catch (x) { it.title = old; refreshItem(it.id); toastError(x); }
  }
  async function createItem(groupId, title, values = {}, where = 'bottom') {
    const d = await api.post('items.php', { action: 'create', board_id: S.board.id, group_id: groupId, title, values, where });
    if (where === 'top') S.items.forEach((x) => { if (x.group_id === d.item.group_id) x.position += 1; });
    applyItems([d.item]);
    return item(d.item.id);
  }
  async function archiveItems(ids, label) {
    if (!ids.length) return;
    busy(true);
    try {
      await api.post('items.php', { action: 'archive', board_id: S.board.id, ids });
      ids.forEach((id) => { const r = rowEl(id); if (r) r.classList.add('is-leaving'); });
      setTimeout(() => { removeItems(ids); renderView(); }, 220);
      if (S.openItem && ids.includes(S.openItem) && FWB.panel) FWB.panel.close();
      toast(label || `${ids.length === 1 ? 'Archived' : ids.length + ' ' + noun() + 's archived'}`, {
        action: { label: 'Undo', run: async () => {
          try { const d = await api.post('items.php', { action: 'restore', board_id: S.board.id, ids }); applyItems(d.items); renderView(); } catch (x) { toastError(x); }
        } },
      });
      S.board.archived_items = (S.board.archived_items || 0) + ids.length;
      return true;
    } catch (x) { toastError(x); } finally { busy(false); }
  }
  async function deleteItems(ids) {
    if (!ids.length) return;
    const ok = await dialog({ title: `Delete ${ids.length === 1 ? 'this ' + noun() : ids.length + ' ' + noun() + 's'}?`, body: 'Their updates, subitems and files are deleted for good. Archive instead to keep them restorable.', confirm: 'Delete', danger: true });
    if (!ok) return;
    try {
      await api.post('items.php', { action: 'delete', board_id: S.board.id, ids });
      removeItems(ids);
      if (S.openItem && ids.includes(S.openItem) && FWB.panel) FWB.panel.close();
      renderView();
      toast(ids.length === 1 ? 'Deleted' : `${ids.length} ${noun()}s deleted`);
      return true;
    } catch (x) { toastError(x); }
  }
  async function moveItems(ids, groupId, order) {
    const g = group(groupId);
    ids.forEach((id) => { const it = item(id); if (it) { it.group_id = groupId; decorate(it); } });
    if (order) order.forEach((id, i) => { const it = item(id); if (it) it.position = i; });
    renderView();
    try {
      const d = await api.post('items.php', { action: 'move', board_id: S.board.id, ids, group_id: groupId, order });
      applyItems(d.items);
      if (order) order.forEach((id, i) => { const it = item(id); if (it) it.position = i; });
      renderView();
      if (!order && g) toast(`Moved to ${g.name}`);
    } catch (x) { toastError(x); reloadBoard(); }
  }
  async function reorderGroup(groupId, order) {
    order.forEach((id, i) => { const it = item(id); if (it) it.position = i; });
    renderView();
    try { await api.post('items.php', { action: 'reorder', board_id: S.board.id, group_id: groupId, order }); } catch (x) { toastError(x); reloadBoard(); }
  }
  async function savePrefs(patch) {
    Object.assign(prefs, patch);
    if (S.guest) return;
    try { await api.post('boards.php', { action: 'prefs', id: S.board.id, prefs: patch }); } catch (x) { /* preferences are best effort */ }
  }
  const savePrefsSoon = debounce(savePrefs, 600);
  /** quiet: a live update (someone else's change) — the item panel keeps what is being typed. */
  async function reloadBoard(quiet) {
    if (S.guest) { location.reload(); return; }
    busy(true);
    try {
      const d = await api.get('boards.php', { id: S.board.id });
      Object.assign(S, { board: d.board, columns: d.columns, groups: d.groups, items: d.items, suppliers: d.suppliers, deps: d.deps, views: d.views, cursor: d.cursor, members: d.members });
      indexItems();
      render();
      if (S.openItem && FWB.panel) FWB.panel.refresh(S.openItem, !!quiet);
    } catch (x) { toastError(x); } finally { busy(false); }
  }

  // ===========================================================================
  // Head + toolbar
  // ===========================================================================
  const VIEW_DEFS = [['table', 'table', 'Table'], ['kanban', 'kanban', 'Kanban'], ['calendar', 'calendar', 'Calendar'], ['gantt', 'timeline', 'Timeline'], ['workload', 'workload', 'Workload'], ['chart', 'chart', 'Chart']];
  function renderSide() {
    if (S.guest) return;
    renderProjectSide({ project: S.project, boards: S.boards, active: 'board:' + S.board.id });
  }
  function renderHead() {
    const b = S.board;
    const memberIds = S.members.map((m) => m.id);
    el.head.innerHTML = `<div class="fwp-head__title"><span class="fwm-list__eyebrow">${S.guest ? esc(b.project_name || '') : `<a href="/projects/view.php?project_id=${S.project.id}">${esc(S.project.name || b.project_name)}</a>`}</span>
        <h2><span id="boardTitle" ${canEdit() ? 'title="Click to rename"' : ''}>${esc(b.title)}</span>
        ${S.guest ? '' : `<button class="icon-btn icon-btn--sm fwp-star ${b.starred ? 'is-on' : ''}" id="starBtn" type="button" aria-label="${b.starred ? 'Unstar' : 'Star'}" data-tip="${b.starred ? 'Starred' : 'Star'}">${icon('star', 'i--sm')}</button>`}
        ${b.description ? `<button class="icon-btn icon-btn--sm" id="descBtn" type="button" aria-label="About this board" data-tip="About">${icon('alert', 'i--sm')}</button>` : ''}</h2></div>
      <div class="fwp-head__actions">
        ${S.guest ? `<span class="fwp-pill fwp-pill--soft" style="--c:#b08d57" title="You can look, filter and open items, but not change them.">${icon('eye', 'i--xs')}Guest · read only</span>` : faces(memberIds, 4)}
        ${canManage() ? `<button class="btn btn--sm" id="shareBtn" type="button">${icon('share')}<span class="hide-narrow">Share</span></button>` : ''}
        ${canManage() ? `<button class="btn btn--sm" id="autoBtn" type="button">${icon('zap')}<span class="hide-narrow">Automate</span>${b.automations ? `<span class="fwp-badge fwp-badge--gold">${b.automations}</span>` : ''}</button>` : ''}
        ${S.guest ? '' : `<button class="icon-btn" id="activityBtn" type="button" aria-label="Board activity" data-tip="Activity">${icon('history')}</button>`}
        ${S.guest ? '' : `<button class="icon-btn" id="boardMenuBtn" type="button" aria-label="Board menu" data-tip="Board" data-tip-pos="left">${icon('more-h')}</button>`}
      </div>`;
  }
  function renderTools() {
    const nRules = S.rules.filter(ruleReady).length;
    const hiddenN = S.columns.filter((c) => S.hidden.has(c.id)).length;
    const sortLbl = S.sort.length ? (S.sort[0].col === 'name' ? 'Name' : (col(S.sort[0].col) || {}).name || 'Sort') : 'Sort';
    const person = S.person ? personById(S.person) : null;
    const saved = S.views.find((v) => v.id === S.activeView);
    el.tools.innerHTML = `
      ${canEdit() ? `<span class="fwp-split"><button class="btn btn--primary btn--sm" id="newItemBtn" type="button">${icon('plus')}<span>New ${esc(noun())}</span></button><button class="btn btn--primary btn--sm" id="newItemMore" type="button" aria-label="More ways to add">${icon('chev-down', 'i--sm')}</button></span>` : ''}
      <div class="seg" id="viewSeg" role="tablist" aria-label="View">${VIEW_DEFS.filter(([k]) => views.has(k) || k === 'table').map(([k, ic, l]) => `<button type="button" role="tab" data-view="${k}" class="${S.view === k ? 'is-on' : ''}" aria-selected="${S.view === k}" aria-label="${l}" data-tip="${l} · ${VIEW_DEFS.findIndex((x) => x[0] === k) + 1}">${icon(ic)}<span>${l}</span></button>`).join('')}</div>
      ${S.guest ? '' : `<button class="fwp-tool ${saved ? 'is-on' : ''}" id="viewsBtn" type="button">${icon('bookmark')}<span class="lbl">${saved ? esc(saved.name) : 'Views'}</span>${icon('chev-down', 'i--xs')}</button>`}
      <span class="fwp-sep hide-narrow"></span>
      <button class="fwp-tool ${person ? 'is-on' : ''}" id="personBtn" type="button" aria-label="Filter by person">${person ? avatar(person.name, person.email) : icon('user')}<span class="lbl">${person ? esc(person.name.split(' ')[0]) : 'Person'}</span></button>
      <button class="fwp-tool ${nRules ? 'is-on' : ''}" id="filterBtn" type="button">${icon('filter')}<span class="lbl">Filter</span>${nRules ? `<span class="fwp-badge">${nRules}</span>` : ''}</button>
      <button class="fwp-tool ${S.sort.length ? 'is-on' : ''}" id="sortBtn" type="button">${icon('sort')}<span class="lbl">${esc(sortLbl)}</span></button>
      ${S.view === 'table' ? `<button class="fwp-tool ${hiddenN ? 'is-on' : ''}" id="hideBtn" type="button">${icon(hiddenN ? 'eye-off' : 'eye')}<span class="lbl">Hide</span>${hiddenN ? `<span class="fwp-badge fwp-badge--gold">${hiddenN}</span>` : ''}</button>` : ''}
      ${isFiltered() ? `<button class="fwp-tool" id="clearFilters" type="button">${icon('x')}<span class="lbl">Clear</span></button>` : ''}
      <span class="grow"></span>
      <span id="viewTools" style="display:inline-flex;gap:6px;align-items:center"></span>`;
    const v = views.get(S.view);
    if (v && v.tools) v.tools($('#viewTools'));
    if (S.view === 'table') $('#viewTools').innerHTML = `<button class="icon-btn icon-btn--sm" id="densityBtn" type="button" aria-label="Row height" data-tip="${S.density === 'compact' ? 'Roomy rows' : 'Compact rows'}" data-tip-pos="left">${icon('rows', 'i--sm')}</button>`;
  }

  // ===========================================================================
  // Table view
  // ===========================================================================
  const CHECK_W = 38, ADD_W = 52;
  function colsTemplate(vc) { return `${CHECK_W}px var(--name-w) ${vc.map((c) => (S.widths[c.id] || c.width) + 'px').join(' ')} ${ADD_W}px`; }
  function headerRow(vc, g) {
    const sortOf = (id) => S.sort.find((s) => String(s.col) === String(id));
    return `<div class="fwp-thead fwp-grid" role="row" style="--cols:${colsTemplate(vc)}">
      <div class="fwp-th fwp-th--check" style="position:sticky;left:0;z-index:4;background:var(--glass-strong);border-left:0;border-radius:14px 0 0 0"><button class="fwp-check ${groupSelState(g)}" type="button" data-selgroup="${g.id}" role="checkbox" aria-checked="${{ 'is-on': 'true', 'is-mixed': 'mixed' }[groupSelState(g)] || 'false'}" aria-label="Select all in ${esc(g.name)}">${icon('check')}</button></div>
      <div class="fwp-th fwp-th--name" style="left:${CHECK_W}px;border-radius:0" data-hcol="name"><span>${esc(Noun())}</span>${sortOf('name') ? icon(sortOf('name').dir === 'desc' ? 'arrow-down' : 'arrow-up') : ''}</div>
      ${vc.map((c) => `<div class="fwp-th ${sortOf(c.id) ? 'is-sorted' : ''}" data-hcol="${c.id}" ${canEdit() && finePointer() ? 'draggable="true"' : ''} title="${esc(c.description || c.name)}">
        ${icon(C.TYPE_ICON[c.type] || 'text')}<span>${esc(c.name)}</span>${sortOf(c.id) ? icon(sortOf(c.id).dir === 'desc' ? 'arrow-down' : 'arrow-up') : ''}
        <button class="icon-btn icon-btn--sm fwp-th__menu" type="button" data-colmenu="${c.id}" aria-label="Column menu">${icon('chev-down', 'i--xs')}</button>
        ${canEdit() ? `<span class="fwp-th__resize" data-resize="${c.id}"></span>` : ''}</div>`).join('')}
      <div class="fwp-th fwp-th--add">${canEdit() ? `<button class="icon-btn icon-btn--sm" type="button" id="addColBtn_${g.id}" data-addcol aria-label="Add column" data-tip="Add column" data-tip-pos="left">${icon('plus', 'i--sm')}</button>` : ''}</div></div>`;
  }
  function groupSelState(g) {
    const ids = itemsIn(g.id).map((i) => i.id);
    const n = ids.filter((id) => S.selected.has(id)).length;
    return !n ? '' : n === ids.length ? 'is-on' : 'is-mixed';
  }
  function cellHTML(c, it, g) {
    const ed = canEdit() && C.editable(c);
    const firstFiles = colsOf('files')[0];
    return `<div class="fwp-cell fwp-cell--${c.type} ${ed || c.type === 'files' ? 'is-editable' : ''}" data-cid="${c.id}" tabindex="-1" role="gridcell">${C.render(c, it, { done: it._done, groupColor: g ? g.color : null, firstFiles: firstFiles && firstFiles.id === c.id })}</div>`;
  }
  function rowHTML(it, vc, g) {
    const n = `${it.subitems_done}/${it.subitems}`;
    // Guests see the count only: the subitems list comes from the member API.
    const subs = !it.subitems ? '' : S.guest ? `<span class="fwp-name__sub" title="${n} subitems done">${n}</span>`
      : `<button class="fwp-name__sub" type="button" data-subs="${it.id}" aria-label="Subitems" aria-expanded="${S.expanded.has(it.id)}" title="${n} subitems done">${icon('chev-right')}${n}</button>`;
    const sel = S.selected.has(it.id);
    return `<div class="fwp-row fwp-grid ${sel ? 'is-selected' : ''} ${S.openItem === it.id ? 'is-open' : ''} ${S.expanded.has(it.id) ? 'is-expanded' : ''}" data-iid="${it.id}" role="row" style="--cols:${colsTemplate(vc)}">
      <div class="fwp-cell fwp-cell--check" style="position:sticky;left:0;z-index:2"><button class="fwp-check ${sel ? 'is-on' : ''}" type="button" data-sel="${it.id}" role="checkbox" aria-checked="${sel}" aria-label="Select ${esc(it.title)}">${icon('check')}</button></div>
      <div class="fwp-cell fwp-cell--name" style="left:${CHECK_W}px" data-cid="name" tabindex="-1">
        ${canEdit() && !S.sort.length && finePointer() ? `<span class="fwp-grip" draggable="true" data-drag="${it.id}" aria-hidden="true">${icon('grip')}</span>` : ''}
        <span class="fwp-name"><span class="fwp-name__text" ${canEdit() ? 'data-rename' : ''}>${esc(it.title)}</span>${subs}</span>
        <button class="fwp-open ${it.updates && !S.guest ? 'has-updates' : ''}" type="button" data-open="${it.id}" aria-label="Open ${esc(it.title)}">${icon(it.updates && !S.guest ? 'message' : 'maximize-2')}${it.updates && !S.guest ? `<span class="fwp-open__n">${it.updates}</span>` : ''}</button></div>
      ${vc.map((c) => cellHTML(c, it, g)).join('')}
      <div class="fwp-cell fwp-cell--add"></div></div>
      ${S.expanded.has(it.id) ? subitemsHTML(it) : ''}`;
  }
  function subitemsHTML(it) {
    const list = S.subs.get(it.id);
    const body = !list ? '<div class="hint" style="padding:6px 8px">Loading…</div>' : list.map((s) => `<div class="fwp-sub ${s.done ? 'is-done' : ''}" data-sid="${s.id}" data-parent="${it.id}">
        <button class="fwp-check ${s.done ? 'is-on' : ''}" type="button" data-subdone="${s.id}" ${canEdit() ? '' : 'disabled'} aria-label="Done">${icon('check')}</button>
        <span class="fwp-sub__title" ${canEdit() ? 'data-subrename' : ''}>${esc(s.title)}</span>
        <button class="fwp-sub__due ${s.due && s.due < today() && !s.done ? 'is-overdue' : ''}" type="button" data-subdue="${s.id}" ${canEdit() ? '' : 'disabled'} aria-label="${s.due ? 'Due ' + esc(fmtDate(s.due)) : 'Due date'}">${s.due ? esc(fmtDate(s.due)) : icon('calendar', 'i--xs')}</button>
        <button class="fwp-open" type="button" data-subowner="${s.id}" ${canEdit() ? '' : 'disabled'} style="opacity:1" aria-label="${s.owner ? 'Owner: ' + esc(personName(s.owner)) : 'Owner'}">${s.owner ? personAvatar(s.owner) : icon('user', 'i--sm')}</button>
        <span></span></div>`).join('');
    return `<div class="fwp-subs" data-subsof="${it.id}"><div class="fwp-subs__list">${body}</div>
      ${canEdit() ? `<form class="fwp-sub__add" data-subadd="${it.id}"><input placeholder="+ Add a subitem" maxlength="255" aria-label="New subitem"></form>` : ''}</div>`;
  }
  function sumRowHTML(vc, items) {
    return `<div class="fwp-sumrow fwp-grid" style="--cols:${colsTemplate(vc)}">
      <div class="fwp-cell" style="position:sticky;left:0;z-index:2;background:color-mix(in srgb, var(--surface-sunken) 55%, var(--glass-strong));border-left:0;border-radius:0 0 0 14px"></div>
      <div class="fwp-cell fwp-cell--name" style="left:${CHECK_W}px;border-radius:0">${items.length} ${esc(noun())}${items.length === 1 ? '' : 's'}</div>
      ${vc.map((c) => `<div class="fwp-cell">${C.summary(c, items)}</div>`).join('')}<div class="fwp-cell"></div></div>`;
  }
  function groupHTML(g, i, vc, list) {
    const items = itemsIn(g.id, list);
    if (isFiltered() && !items.length) return '';
    const collapsed = S.collapsed.has(g.id);
    return `<section class="fwp-group ${collapsed ? 'is-collapsed' : ''}" data-gid="${g.id}" style="--c:${safeColor(g.color)};--i:${i}">
      <div class="fwp-ghead">
        ${canEdit() && finePointer() ? `<span class="fwp-ghead__grip" draggable="true" data-gdrag="${g.id}" aria-hidden="true">${icon('grip', 'i--sm')}</span>` : ''}
        <button class="icon-btn icon-btn--sm fwp-ghead__chev" type="button" data-collapse="${g.id}" aria-label="${collapsed ? 'Expand' : 'Collapse'} ${esc(g.name)}" aria-expanded="${!collapsed}">${icon('chev-down')}</button>
        <span class="fwp-ghead__name" data-gname="${g.id}" ${canEdit() ? 'title="Click to rename"' : ''}>${esc(g.name)}</span>
        <span class="fwp-ghead__count">${items.length} ${esc(noun())}${items.length === 1 ? '' : 's'}</span>
        <button class="icon-btn icon-btn--sm fwp-ghead__menu" type="button" data-gmenu="${g.id}" aria-label="Group menu">${icon('more-h', 'i--sm')}</button>
      </div>
      <div class="fwp-gbody" role="grid" aria-label="${esc(g.name)}">
        ${collapsed ? '' : headerRow(vc, g)}
        ${collapsed ? '' : items.map((it) => rowHTML(it, vc, g)).join('')}
        ${collapsed || !canEdit() ? '' : `<form class="fwp-addrow fwp-grid" data-addto="${g.id}" style="--cols:${colsTemplate(vc)}"><div class="fwp-cell" style="position:sticky;left:0;z-index:2;background:var(--glass-strong);border-left:0"></div><div class="fwp-cell fwp-cell--name" style="left:${CHECK_W}px"><input placeholder="+ Add ${esc(noun())}" maxlength="500" aria-label="New ${esc(noun())} in ${esc(g.name)}"></div>${vc.map(() => '<div class="fwp-cell"></div>').join('')}<div class="fwp-cell"></div></form>`}
        ${sumRowHTML(vc, items)}
      </div></section>`;
  }
  function renderTable() {
    const vc = visibleColumns();
    const list = filtered();
    document.body.classList.toggle('is-compact', S.density === 'compact');
    if (!S.groups.length) { el.view.innerHTML = `<div class="fwp-pad">${emptyState('An empty board', 'Add a group to start.', canEdit() ? `<button class="btn btn--primary" type="button" data-act="addgroup">${icon('plus')}Add group</button>` : '', { art: false })}</div>`; return; }
    const groups = S.groups.map((g, i) => groupHTML(g, i, vc, list)).join('');
    const nothing = isFiltered() && !list.length;
    // Take the keyboard back only if a cell had it: a re-render from the search box, a filter or a poll must not.
    const a = document.activeElement;
    const hadFocus = !!(a && el.view.contains(a) && a.matches('.fwp-cell[data-cid]'));
    const restoreAdds = keepAddBoxes(el.view);
    el.view.innerHTML = `<div class="fwp-board" id="board">
      ${S.board.truncated ? `<div class="fwp-banner" role="status" style="margin:0 12px 10px">${icon('alert')}<span class="grow">This board has ${esc(S.board.items_total)} ${esc(noun())}s — the first ${esc(S.items.length)} are shown. Archive or move some to see the rest.</span></div>` : ''}
      ${S.guest ? `<div class="fwp-guestbar">${icon('eye')}<span class="grow">You’re viewing this board as a guest — read only. It stays up to date as the team works.</span><form method="post" action="/projects/guest-logout.php" style="display:contents"><input type="hidden" name="csrf_token" value="${esc((document.querySelector('meta[name="csrf-token"]') || {}).content || '')}"><button class="btn btn--sm btn--ghost" type="submit">Sign out</button></form></div>` : ''}
      ${nothing ? emptyState('No matches', 'Nothing on this board matches the search and filters.', `<button class="btn" type="button" data-act="clearfilters">${icon('x')}Clear filters</button>`, { art: false }) : groups}
      ${!nothing && list.length > 0 && S.groups.length > 1 ? `<div class="fwp-group fwp-boardsum" style="--c:var(--gold)"><div class="fwp-gbody">${sumRowHTML(vc, list).replace('fwp-sumrow', 'fwp-sumrow')}</div></div>` : ''}
      ${canEdit() && !isFiltered() ? `<div class="fwp-addgroup"><button class="btn btn--sm btn--ghost" type="button" data-act="addgroup">${icon('plus')}Add new group</button></div>` : ''}
    </div>`;
    if (S.focus) {
      if (hadFocus) focusCell(S.focus.itemId, S.focus.colId, false);
      else { const c = cellFor(S.focus.itemId, S.focus.colId); if (c) c.classList.add('is-focus'); }
    }
    restoreAdds();
  }
  // What is half typed in an add box (and its caret) comes back after a re-render: live updates neither wipe it nor wait for it.
  const ADD_BOXES = '[data-addto] input, [data-subadd] input, .fwp-kb__add-input input';
  function keepAddBoxes(root) {
    const a = document.activeElement;
    const kept = $$('[data-addto] input, [data-subadd] input', root).filter((i) => i.value || i === a).map((i) => ({
      sel: i.form.dataset.addto ? `[data-addto="${i.form.dataset.addto}"] input` : `[data-subadd="${i.form.dataset.subadd}"] input`,
      value: i.value, focus: i === a, from: i.selectionStart, to: i.selectionEnd,
    }));
    return () => kept.forEach((k) => {
      const i = el.view.querySelector(k.sel);
      if (!i) return;
      i.value = k.value;
      if (k.focus) { i.focus({ preventScroll: true }); i.setSelectionRange(k.from, k.to); }
    });
  }
  function rowEl(id) { return el.view.querySelector(`.fwp-row[data-iid="${id}"]`); }
  function cellFor(id, cid) { const r = rowEl(id); return r ? r.querySelector(`.fwp-cell[data-cid="${cid}"]`) : null; }

  /** Re-render one item wherever it shows (table row + group summary, or the current view). */
  function refreshItem(id, opts = {}) {
    const it = item(id);
    if (S.view !== 'table') { const v = views.get(S.view); if (v && v.refreshItem) v.refreshItem(it, id); else renderView(); }
    else {
      const row = rowEl(id);
      const g = it ? group(it.group_id) : null;
      if (row && it && g && matches(it) && row.closest('[data-gid]').dataset.gid == g.id) {
        const vc = visibleColumns();
        const tmp = document.createElement('div');
        tmp.innerHTML = rowHTML(it, vc, g);
        const fresh = tmp.firstElementChild;
        // The row and, when expanded, its subitems block (a sibling) are replaced together.
        const old = row.nextElementSibling;
        const subs = old && old.matches(`.fwp-subs[data-subsof="${id}"]`) ? old : null;
        const restoreAdds = subs ? keepAddBoxes(subs) : null;
        if (subs) subs.remove();
        const hadFocus = row.contains(document.activeElement);
        row.replaceWith(...tmp.children);
        if (hadFocus && S.focus && S.focus.itemId === Number(id)) focusCell(S.focus.itemId, S.focus.colId, false);
        if (restoreAdds) restoreAdds();
        if (opts.pop) { const st = fresh.querySelector('.fwp-cell--status .fwp-st'); if (st) st.classList.add('is-pop'); }
        if (opts.flash) fresh.classList.add('is-flash');
        refreshSummary(g.id);
      } else renderView();
    }
    if (FWB.panel && S.openItem === Number(id) && FWB.panel.onItemChanged) FWB.panel.onItemChanged(it);
  }
  function refreshSummary(gid) {
    const sec = el.view.querySelector(`.fwp-group[data-gid="${gid}"]`);
    if (!sec) return;
    const vc = visibleColumns();
    const items = itemsIn(gid);
    const sum = sec.querySelector('.fwp-sumrow');
    if (sum) { const tmp = document.createElement('div'); tmp.innerHTML = sumRowHTML(vc, items); sum.replaceWith(tmp.firstElementChild); }
    const cnt = sec.querySelector('.fwp-ghead__count');
    if (cnt) cnt.textContent = `${items.length} ${noun()}${items.length === 1 ? '' : 's'}`;
    const bs = el.view.querySelector('.fwp-boardsum .fwp-sumrow');
    if (bs) { const tmp = document.createElement('div'); tmp.innerHTML = sumRowHTML(vc, filtered()); bs.replaceWith(tmp.firstElementChild); }
  }

  // ---------------------------------------------------------------- keyboard focus
  function focusCell(itemId, colId, scroll = true) {
    $$('.fwp-cell.is-focus', el.view).forEach((c) => c.classList.remove('is-focus'));
    const c = cellFor(itemId, colId);
    if (!c) return;
    S.focus = { itemId, colId };
    c.classList.add('is-focus');
    c.focus({ preventScroll: !scroll });
    if (scroll) c.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  }
  /** @return {boolean} whether the focus moved (false at the edges) */
  function moveFocus(dx, dy) {
    if (!S.focus) return false;
    const vc = ['name', ...visibleColumns().map((c) => c.id)];
    const rows = $$('.fwp-row', el.view).map((r) => Number(r.dataset.iid));
    const r0 = rows.indexOf(S.focus.itemId);
    const c0 = vc.findIndex((x) => String(x) === String(S.focus.colId));
    const ri = Math.max(0, Math.min(rows.length - 1, r0 + dy));
    const ci = Math.max(0, Math.min(vc.length - 1, c0 + dx));
    if (ri === r0 && ci === c0) return false;
    if (rows[ri] != null) focusCell(rows[ri], vc[ci]);
    return true;
  }
  /** Is this key event the focused table cell's (not a button, a box elsewhere or a stale focus)? */
  function isGridKey(e) {
    if (!S.focus || !e.target.closest || e.target.closest('input, textarea, select, button, a, [contenteditable="true"]')) return false;
    const c = cellFor(S.focus.itemId, S.focus.colId);
    return !!(c && c.contains(e.target));
  }
  function editCell(it, c, cellEl) {
    if (!it || !c) return;
    if (c.type === 'files') { openItem(it.id, 'files'); return; }
    if (!canEdit() || !C.editable(c)) { if (c.type !== 'formula') openItem(it.id); return; }
    closePop();
    C.edit(c, it, cellEl, (v) => {
      // Picked in a popover: once it closes, the keyboard is back on the cell (not on the page).
      setTimeout(() => { if (document.activeElement === document.body && S.focus && S.focus.itemId === it.id && String(S.focus.colId) === String(c.id)) focusCell(it.id, c.id, false); }, 0);
      return setValue(it, c, v);
    }, {
      canManage: canManage(),
      onEditLabels: (cc) => FWB.dialogs && FWB.dialogs.columnSettings(cc),
      allTags: (cc) => Array.from(new Set(S.items.flatMap((x) => x.values[cc.id] || []))).sort(),
      openFiles: (i) => openItem(i.id, 'files'),
    });
  }
  function startRename(it, span) {
    if (!canEdit()) return;
    const input = document.createElement('input');
    input.className = 'fwp-name__input';
    input.value = it.title;
    input.maxLength = 500;
    span.replaceWith(input);
    input.focus();
    input.select();
    let done = false;
    // Nothing to save: put the name back (the input must not stay, it holds up live updates).
    const finish = (save) => { if (done) return; done = true; const v = input.value.trim(); if (save && v && v !== it.title) renameItem(it, v); else refreshItem(it.id); };
    input.addEventListener('keydown', (e) => { e.stopPropagation(); if (e.key === 'Enter') { e.preventDefault(); finish(true); } if (e.key === 'Escape') { e.preventDefault(); finish(false); } });
    input.addEventListener('blur', () => finish(true));
  }

  // ===========================================================================
  // Views
  // ===========================================================================
  function registerView(name, def) { views.set(name, def); }
  function renderView() {
    if (S.view === 'table') renderTable();
    else {
      const v = views.get(S.view);
      if (v) v.render(el.view); else { S.view = 'table'; renderTable(); }
    }
    renderBulk();
    const fab = $('#fabNew');
    if (fab) { fab.hidden = !canEdit(); const s = $('span', fab); if (s) s.textContent = 'New ' + noun(); }
  }
  function setView(v, opts = {}) {
    if (!views.has(v) && v !== 'table') v = 'table';
    const prev = views.get(S.view);
    if (prev && prev.destroy) prev.destroy();
    S.view = v;
    if (!opts.keepSaved) S.activeView = 0;
    renderTools();
    el.view.classList.remove('is-entering');
    void el.view.offsetWidth;
    el.view.classList.add('is-entering');
    renderView();
    const u = new URL(location.href);
    u.searchParams.set('view', v);
    if (!S.activeView) u.searchParams.delete('view_id');
    history.replaceState(null, '', u.pathname + u.search);
    if (!opts.noSave) savePrefsSoon({ view: v });
  }
  function render() {
    renderSide();
    renderHead();
    renderTools();
    renderView();
  }

  // ===========================================================================
  // Bulk bar
  // ===========================================================================
  function renderBulk() {
    let bar = $('#bulkBar');
    const ids = Array.from(S.selected).filter((id) => itemById.has(id));
    if (!ids.length || !canEdit()) { if (bar) bar.remove(); return; }
    if (!bar) { bar = document.createElement('div'); bar.id = 'bulkBar'; bar.className = 'fwp-bulk'; bar.setAttribute('role', 'toolbar'); document.body.appendChild(bar); }
    bar.innerHTML = `<span class="fwp-bulk__count"><b>${ids.length}</b>${ids.length === 1 ? Noun() : Noun() + 's'} selected</span>
      <button type="button" data-bulk="set">${icon('pen')}Set</button>
      <button type="button" data-bulk="move">${icon('move')}Move to</button>
      <button type="button" data-bulk="dup">${icon('copy')}Duplicate</button>
      <button type="button" data-bulk="export">${icon('download')}Export</button>
      <button type="button" data-bulk="archive">${icon('archive')}Archive</button>
      <button type="button" data-bulk="delete" class="is-danger">${icon('trash')}Delete</button>
      <button type="button" data-bulk="clear" class="fwp-bulk__x" aria-label="Clear selection">${icon('x')}</button>`;
  }
  function clearSelection() { S.selected.clear(); $$('.fwp-row.is-selected', el.view).forEach((r) => { r.classList.remove('is-selected'); const c = r.querySelector('[data-sel]'); if (c) { c.classList.remove('is-on'); c.setAttribute('aria-checked', 'false'); } }); $$('[data-selgroup]', el.view).forEach((b) => { b.classList.remove('is-on', 'is-mixed'); b.setAttribute('aria-checked', 'false'); }); renderBulk(); }
  async function bulkAction(a, btn) {
    const ids = Array.from(S.selected).filter((id) => itemById.has(id));
    if (!ids.length) return;
    if (a === 'clear') return clearSelection();
    // The selection stays when the dialog is cancelled or the request fails.
    if (a === 'archive') { if (await archiveItems(ids)) clearSelection(); return; }
    if (a === 'delete') { if (await deleteItems(ids)) clearSelection(); return; }
    if (a === 'export') { location.href = `/projects/api/export.php?board_id=${S.board.id}&ids=${ids.join(',')}`; return; }
    if (a === 'dup') {
      busy(true);
      try { for (const id of ids) applyItems([(await api.post('items.php', { action: 'duplicate', id })).item]); renderView(); toast(`${ids.length} duplicated`); } catch (x) { toastError(x); } finally { busy(false); }
      return;
    }
    if (a === 'move') {
      const others = S.boards.filter((b) => b.id !== S.board.id && !b.archived && (b.level || 0) >= 2);
      popover(btn, `<div class="fwm-pop__title">Move to group</div>${S.groups.map((g) => `<button class="fwm-pop__item" data-v="g:${g.id}"><span class="fwp-dot fwp-dot--sq" style="--c:${safeColor(g.color)}"></span>${esc(g.name)}</button>`).join('')}
        ${others.length ? `<div class="fwm-pop__sep"></div><div class="fwm-pop__title">Move to board</div>${others.map((b) => `<button class="fwm-pop__item" data-v="b:${b.id}">${icon(boardIcon(b))}${esc(b.title)}</button>`).join('')}` : ''}`, {
        align: 'center',
        onClick: async (it) => {
          const [k, id] = it.dataset.v.split(':');
          if (k === 'g') { await moveItems(ids, Number(id)); clearSelection(); }
          else {
            try {
              await api.post('items.php', { action: 'move_board', board_id: S.board.id, ids, to_board_id: Number(id) });
              removeItems(ids); renderView();
              const b = S.boards.find((x) => x.id === Number(id));
              toast(`Moved to ${b ? b.title : 'the board'}`, { action: { label: 'Open', run: () => { location.href = '/projects/board.php?board_id=' + id; } } });
              clearSelection();
            } catch (x) { toastError(x); }
          }
        },
      });
      return;
    }
    if (a === 'set') {
      const cols = S.columns.filter((c) => C.editable(c) && !['files', 'rating'].includes(c.type));
      popover(btn, `<div class="fwm-pop__title">Set a column on ${ids.length}</div>${cols.map((c) => `<button class="fwm-pop__item" data-v="${c.id}">${icon(C.TYPE_ICON[c.type])}${esc(c.name)}</button>`).join('')}`, {
        align: 'center',
        onClick: (it) => {
          const c = col(it.dataset.v);
          const first = item(ids[0]);
          const proxy = { id: 0, values: { [c.id]: ids.every((id) => JSON.stringify(item(id).values[c.id]) === JSON.stringify(first.values[c.id])) ? first.values[c.id] : null } };
          setTimeout(() => C.edit(c, proxy, btn, (v) => bulkSet(ids, c, v), { canManage: false, allTags: () => [] }), 0);
        },
      });
    }
  }

  // ===========================================================================
  // Item panel hand-off
  // ===========================================================================
  /** opts.focus: move the keyboard into the panel (opened from the keyboard). */
  function openItem(id, tab, opts) {
    id = Number(id);
    if (!FWB.panel) return;
    const prev = S.openItem;
    S.openItem = id;
    if (prev) { const r = rowEl(prev); if (r) r.classList.remove('is-open'); }
    const r = rowEl(id);
    if (r) r.classList.add('is-open');
    FWB.panel.open(id, tab, opts);
    document.body.classList.add('is-panel');
    const u = new URL(location.href);
    u.searchParams.set('item', id);
    if (tab) u.searchParams.set('open', tab); else u.searchParams.delete('open');
    history.replaceState(null, '', u.pathname + u.search);
  }
  function panelClosed() {
    const r = rowEl(S.openItem);
    if (r) r.classList.remove('is-open');
    S.openItem = 0;
    document.body.classList.remove('is-panel');
    const u = new URL(location.href);
    u.searchParams.delete('item');
    u.searchParams.delete('open');
    history.replaceState(null, '', u.pathname + u.search);
  }

  // ===========================================================================
  // Menus: column, group, board, new item
  // ===========================================================================
  function columnMenu(anchor, c) {
    const so = S.sort.find((s) => String(s.col) === String(c.id));
    popover(anchor, `<div class="fwm-pop__title">${esc(c.name)} · ${esc((BOOT.types[c.type] || {}).label || c.type)}</div>
      <button class="fwm-pop__item" data-v="asc">${icon('arrow-up')}Sort ascending${so && so.dir === 'asc' ? '<small>✓</small>' : ''}</button>
      <button class="fwm-pop__item" data-v="desc">${icon('arrow-down')}Sort descending${so && so.dir === 'desc' ? '<small>✓</small>' : ''}</button>
      <button class="fwm-pop__item" data-v="filter">${icon('filter')}Filter by this column</button>
      <button class="fwm-pop__item" data-v="hide">${icon('eye-off')}Hide for me</button>
      ${canEdit() ? `<div class="fwm-pop__sep"></div>
      <button class="fwm-pop__item" data-v="settings">${icon('settings')}Settings…</button>
      <button class="fwm-pop__item" data-v="rename">${icon('pen')}Rename</button>
      <button class="fwm-pop__item" data-v="left">${icon('chev-left')}Add column to the left</button>
      <button class="fwm-pop__item" data-v="right">${icon('chev-right')}Add column to the right</button>
      <button class="fwm-pop__item" data-v="dup">${icon('copy')}Duplicate</button>
      <button class="fwm-pop__item is-danger" data-v="delete">${icon('trash')}Delete</button>` : ''}`, {
      onClick: async (it) => {
        const v = it.dataset.v;
        if (v === 'asc' || v === 'desc') { S.sort = so && so.dir === v ? [] : [{ col: c.id, dir: v }]; savePrefsSoon({ sort: S.sort }); renderTools(); renderView(); }
        if (v === 'filter') { if (FWB.dialogs) FWB.dialogs.filters($('#filterBtn'), c.id); }
        if (v === 'hide') { S.hidden.add(c.id); savePrefsSoon({ hidden: Array.from(S.hidden) }); renderTools(); renderView(); toast(`${c.name} hidden`, { action: { label: 'Undo', run: () => { S.hidden.delete(c.id); savePrefsSoon({ hidden: Array.from(S.hidden) }); renderTools(); renderView(); } } }); }
        if (v === 'settings' && FWB.dialogs) FWB.dialogs.columnSettings(c);
        if (v === 'rename') {
          const name = await dialog({ title: 'Rename column', input: { value: c.name, max: 100 }, confirm: 'Rename' });
          if (name && name !== c.name) { try { const d = await api.post('columns.php', { action: 'update', id: c.id, name }); replaceColumn(d.column); if (d.items) { applyItems(d.items); } renderView(); } catch (x) { toastError(x); } }
        }
        if (v === 'left' || v === 'right') {
          const vc = S.columns;
          const i = vc.findIndex((x) => x.id === c.id);
          const after = v === 'right' ? c.id : (i > 0 ? vc[i - 1].id : 0);
          if (FWB.dialogs) FWB.dialogs.addColumn(anchor, after, v === 'left' && i === 0);
        }
        if (v === 'dup') { try { const d = await api.post('columns.php', { action: 'duplicate', id: c.id }); insertColumn(d.column, c.id); applyItems(d.items); renderView(); toast('Column duplicated'); } catch (x) { toastError(x); } }
        if (v === 'delete') {
          if (!(await dialog({ title: `Delete “${c.name}”?`, body: 'Its values on every item are deleted too. This cannot be undone.', confirm: 'Delete column', danger: true }))) return;
          try { const d = await api.post('columns.php', { action: 'delete', id: c.id }); S.columns = S.columns.filter((x) => x.id !== c.id); applyItems(d.items); renderView(); toast('Column deleted'); } catch (x) { toastError(x); }
        }
      },
    });
  }
  function replaceColumn(c) { const i = S.columns.findIndex((x) => x.id === c.id); if (i >= 0) S.columns[i] = c; else S.columns.push(c); indexItems(); }
  function insertColumn(c, afterId, atStart) {
    if (atStart) S.columns.unshift(c);
    else {
      const i = S.columns.findIndex((x) => x.id === Number(afterId));
      if (i >= 0) S.columns.splice(i + 1, 0, c); else S.columns.push(c);
    }
    indexItems();
  }
  function groupMenu(anchor, g) {
    const COLORS = ['#2f5c84', '#7a5ab8', '#b08d57', '#2f7457', '#7a1f30', '#c06a2b', '#1f7a7a', '#b4577a', '#5b6670', '#4b4fa8', '#6b7d2b', '#a8281e'];
    popover(anchor, `<div class="fwm-pop__title">${esc(g.name)}</div>
      ${canEdit() ? `<button class="fwm-pop__item" data-v="rename">${icon('pen')}Rename</button>
      <div class="fwp-palette" style="padding:4px 8px 8px">${COLORS.map((c) => `<button type="button" data-v="color:${c}" class="${g.color === c ? 'is-on' : ''}" style="--c:${c}" aria-label="Colour"></button>`).join('')}</div>
      <button class="fwm-pop__item" data-v="add">${icon('plus')}Add a group below</button>
      <button class="fwm-pop__item" data-v="dup">${icon('copy')}Duplicate group</button>` : ''}
      <button class="fwm-pop__item" data-v="collapseall">${icon('chev-up')}Collapse all groups</button>
      <button class="fwm-pop__item" data-v="expandall">${icon('chev-down')}Expand all groups</button>
      <button class="fwm-pop__item" data-v="select">${icon('check-square')}Select all ${esc(noun())}s</button>
      ${canEdit() ? `<div class="fwm-pop__sep"></div><button class="fwm-pop__item is-danger" data-v="delete" ${S.groups.length < 2 ? 'disabled' : ''}>${icon('trash')}Delete group</button>` : ''}`, {
      onClick: async (it) => {
        const v = it.dataset.v;
        if (v.startsWith('color:')) {
          g.color = v.slice(6);
          renderView();
          try { await api.post('groups.php', { action: 'update', id: g.id, color: g.color }); } catch (x) { toastError(x); }
          return;
        }
        if (v === 'rename') { const n = el.view.querySelector(`[data-gname="${g.id}"]`); if (n) startGroupRename(g, n); }
        if (v === 'add') await addGroup('bottom', g.id);
        if (v === 'dup') { busy(true); try { const d = await api.post('groups.php', { action: 'duplicate', id: g.id }); const i = S.groups.findIndex((x) => x.id === g.id); S.groups.splice(i + 1, 0, d.group); applyItems(d.items); renderView(); toast('Group duplicated'); } catch (x) { toastError(x); } finally { busy(false); } }
        if (v === 'collapseall' || v === 'expandall') { S.groups.forEach((x) => { if (v === 'collapseall') S.collapsed.add(x.id); else S.collapsed.delete(x.id); }); renderView(); if (!S.guest) api.post('groups.php', { action: 'collapse', board_id: S.board.id, id: 0, all: v === 'collapseall' }).catch(() => {}); }
        if (v === 'select') { itemsIn(g.id).forEach((x) => S.selected.add(x.id)); renderView(); }
        if (v === 'delete') {
          const n = S.items.filter((x) => x.group_id === g.id).length;
          if (!(await dialog({ title: `Delete “${g.name}”?`, body: n ? `Its ${n} ${noun()}${n === 1 ? '' : 's'} move to the archive, where you can restore them.` : 'The group is empty.', confirm: 'Delete group', danger: true }))) return;
          try { await api.post('groups.php', { action: 'delete', id: g.id }); S.groups = S.groups.filter((x) => x.id !== g.id); removeItems(S.items.filter((x) => x.group_id === g.id).map((x) => x.id)); renderView(); toast('Group deleted'); } catch (x) { toastError(x); }
        }
      },
    });
  }
  function startGroupRename(g, n) {
    if (!canEdit()) return;
    n.contentEditable = 'true';
    n.focus();
    const range = document.createRange(); range.selectNodeContents(n); const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range);
    let done = false;
    const finish = async (save) => {
      if (done) return;
      done = true;
      n.contentEditable = 'false';
      const name = n.textContent.trim();
      if (!save || !name || name === g.name) { n.textContent = g.name; return; }
      const old = g.name;
      g.name = name;
      try { await api.post('groups.php', { action: 'update', id: g.id, name }); } catch (x) { g.name = old; n.textContent = old; toastError(x); }
    };
    n.addEventListener('keydown', function k(e) { e.stopPropagation(); if (e.key === 'Enter') { e.preventDefault(); n.removeEventListener('keydown', k); finish(true); } if (e.key === 'Escape') { n.removeEventListener('keydown', k); finish(false); } });
    n.addEventListener('blur', () => finish(true), { once: true });
  }
  async function addGroup(where = 'top', afterId = 0) {
    try {
      const d = await api.post('groups.php', { action: 'create', board_id: S.board.id, name: 'New group', where: where === 'top' ? 'top' : 'bottom' });
      if (where === 'top') S.groups.unshift(d.group); else S.groups.push(d.group);
      if (afterId) {
        // put it right after the group whose menu was used
        S.groups = S.groups.filter((x) => x.id !== d.group.id);
        const i = S.groups.findIndex((x) => x.id === afterId);
        S.groups.splice(i + 1, 0, d.group);
        api.post('groups.php', { action: 'reorder', board_id: S.board.id, ids: S.groups.map((x) => x.id) }).catch(() => {});
      }
      renderView();
      const n = el.view.querySelector(`[data-gname="${d.group.id}"]`);
      if (n) { n.scrollIntoView({ block: 'center' }); startGroupRename(d.group, n); }
    } catch (x) { toastError(x); }
  }
  function newItemTop() {
    if (!canEdit()) return;
    if (S.view !== 'table') setView('table');
    const g = S.groups.find((x) => !S.collapsed.has(x.id)) || S.groups[0];
    if (!g) return;
    S.collapsed.delete(g.id);
    renderView();
    (async () => {
      try {
        const it = await createItem(g.id, 'New ' + noun(), {}, 'top');
        renderView();
        const r = rowEl(it.id);
        if (r) { r.scrollIntoView({ block: 'center' }); const span = r.querySelector('[data-rename]'); if (span) startRename(it, span); }
      } catch (x) { toastError(x); }
    })();
  }

  // ===========================================================================
  // Events
  // ===========================================================================
  function bind() {
    el.view.addEventListener('click', onViewClick);
    el.view.addEventListener('dblclick', (e) => {
      const nameCell = e.target.closest('.fwp-cell--name');
      if (nameCell && !e.target.closest('button, input')) { const r = nameCell.closest('[data-iid]'); const it = item(r.dataset.iid); const span = nameCell.querySelector('[data-rename]'); if (it && span) startRename(it, span); }
    });
    el.view.addEventListener('submit', onViewSubmit);
    el.view.addEventListener('keydown', onViewKey);
    bindDrag();
    bindResize();
    el.head.addEventListener('click', onHeadClick);
    el.tools.addEventListener('click', onToolsClick);
    document.addEventListener('click', (e) => {
      const b = e.target.closest('[data-bulk]');
      if (b) bulkAction(b.dataset.bulk, b);
    });
    const fab = $('#fabNew');
    if (fab) fab.addEventListener('click', newItemTop);
    if (el.search) {
      $('#searchForm').addEventListener('submit', (e) => e.preventDefault());
      el.search.addEventListener('input', debounce(() => { S.q = el.search.value.trim(); el.searchClear.hidden = !S.q; renderTools(); renderView(); }, 160));
      el.search.addEventListener('keydown', (e) => { if (e.key === 'Escape') { el.search.value = ''; S.q = ''; el.searchClear.hidden = true; renderTools(); renderView(); el.search.blur(); } });
      el.searchClear.addEventListener('click', () => { el.search.value = ''; S.q = ''; el.searchClear.hidden = true; renderTools(); renderView(); el.search.focus(); });
    }
    document.addEventListener('keydown', onGlobalKey);
    window.addEventListener('beforeunload', (e) => { if (pendingWrites > 0) { e.preventDefault(); e.returnValue = ''; } });
  }

  async function onViewClick(e) {
    const t = e.target;
    if (t.closest('[data-stop]')) return;
    const a = t.closest('[data-act]');
    if (a) {
      if (a.dataset.act === 'addgroup') addGroup('bottom');
      if (a.dataset.act === 'clearfilters') clearFilters();
      return;
    }
    const sel = t.closest('[data-sel]');
    if (sel) {
      const id = Number(sel.dataset.sel);
      if (e.shiftKey && S.lastSel) {
        const rows = $$('.fwp-row', el.view).map((r) => Number(r.dataset.iid));
        const [i1, i2] = [rows.indexOf(S.lastSel), rows.indexOf(id)].sort((x, y) => x - y);
        rows.slice(i1, i2 + 1).forEach((x) => S.selected.add(x));
      } else if (S.selected.has(id)) S.selected.delete(id); else S.selected.add(id);
      S.lastSel = id;
      $$('.fwp-row', el.view).forEach((r) => { const on = S.selected.has(Number(r.dataset.iid)); r.classList.toggle('is-selected', on); const c = r.querySelector('[data-sel]'); if (c) { c.classList.toggle('is-on', on); c.setAttribute('aria-checked', on); } });
      $$('[data-selgroup]', el.view).forEach((b) => { const g = group(b.dataset.selgroup); b.classList.remove('is-on', 'is-mixed'); const s = groupSelState(g); if (s) b.classList.add(s); b.setAttribute('aria-checked', s === 'is-on' ? 'true' : s === 'is-mixed' ? 'mixed' : 'false'); });
      renderBulk();
      return;
    }
    const sg = t.closest('[data-selgroup]');
    if (sg) {
      const ids = itemsIn(Number(sg.dataset.selgroup)).map((x) => x.id);
      const all = ids.every((id) => S.selected.has(id));
      ids.forEach((id) => (all ? S.selected.delete(id) : S.selected.add(id)));
      renderView();
      return;
    }
    const col_ = t.closest('[data-collapse]');
    if (col_) {
      const gid = Number(col_.dataset.collapse);
      const on = !S.collapsed.has(gid);
      if (on) S.collapsed.add(gid); else S.collapsed.delete(gid);
      renderView();
      if (!S.guest) api.post('groups.php', { action: 'collapse', board_id: S.board.id, id: gid, collapsed: on }).catch(() => {});
      return;
    }
    const gname = t.closest('[data-gname]');
    if (gname && gname.contentEditable !== 'true' && canEdit()) { startGroupRename(group(gname.dataset.gname), gname); return; }
    const gm = t.closest('[data-gmenu]');
    if (gm) { groupMenu(gm, group(gm.dataset.gmenu)); return; }
    const cm = t.closest('[data-colmenu]');
    if (cm) { e.stopPropagation(); columnMenu(cm, col(cm.dataset.colmenu)); return; }
    const ac = t.closest('[data-addcol]');
    if (ac) { if (FWB.dialogs) FWB.dialogs.addColumn(ac, S.columns.length ? S.columns[S.columns.length - 1].id : 0); return; }
    const hc = t.closest('[data-hcol]');
    if (hc && !t.closest('button, [data-resize]')) {
      const id = hc.dataset.hcol === 'name' ? 'name' : Number(hc.dataset.hcol);
      const cur = S.sort.find((s) => String(s.col) === String(id));
      S.sort = !cur ? [{ col: id, dir: 'asc' }] : cur.dir === 'asc' ? [{ col: id, dir: 'desc' }] : [];
      savePrefsSoon({ sort: S.sort });
      renderTools();
      renderView();
      return;
    }
    const op = t.closest('[data-open]');
    if (op) { openItem(op.dataset.open); return; }
    const sb = t.closest('[data-subs]');
    if (sb) { toggleSubs(Number(sb.dataset.subs)); return; }
    if (await subClick(t)) return;
    const rate = t.closest('[data-rate]');
    if (rate && canEdit()) {
      const r = rate.closest('[data-iid]');
      const c = col(rate.closest('[data-cid]').dataset.cid);
      const it = item(r.dataset.iid);
      const n = Number(rate.dataset.rate);
      setValue(it, c, it.values[c.id] === n ? null : n);
      return;
    }
    const rn = t.closest('[data-rename]');
    if (rn) {
      const it = item(rn.closest('[data-iid]').dataset.iid);
      if (finePointer()) startRename(it, rn); else openItem(it.id);
      return;
    }
    const cell = t.closest('.fwp-cell[data-cid]');
    if (cell) {
      const row = cell.closest('[data-iid]');
      if (!row) return;
      const it = item(row.dataset.iid);
      if (cell.dataset.cid === 'name') { focusCell(it.id, 'name'); if (!t.closest('button')) openItem(it.id); return; }
      const c = col(cell.dataset.cid);
      focusCell(it.id, c.id, false);
      editCell(it, c, cell);
    }
  }

  function onViewSubmit(e) {
    const f = e.target;
    if (f.matches('[data-addto]')) {
      e.preventDefault();
      const input = f.querySelector('input');
      const title = input.value.trim();
      if (!title) return;
      input.value = '';
      const gid = Number(f.dataset.addto);
      const values = {};
      // A person filter that is on: new items get that person, like in the filtered view.
      const pc = primaryPeople();
      if (S.person && pc) values[pc.id] = [Number(S.person)];
      createItem(gid, title, values).then(() => { renderView(); const nf = el.view.querySelector(`[data-addto="${gid}"] input`); if (nf) nf.focus(); }).catch(toastError);
      return;
    }
    if (f.matches('[data-subadd]')) {
      e.preventDefault();
      const input = f.querySelector('input');
      const title = input.value.trim();
      if (!title) return;
      input.value = '';
      addSubitem(Number(f.dataset.subadd), title);
    }
  }

  function onViewKey(e) {
    if (window.FWP.activePop || !isGridKey(e)) return;
    const it = item(S.focus.itemId);
    if (!it) return;
    const keys = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
    if (keys[e.key]) { e.preventDefault(); moveFocus(...keys[e.key]); return; }
    // At either end of a row Tab leaves the table.
    if (e.key === 'Tab') { if (moveFocus(e.shiftKey ? -1 : 1, 0)) e.preventDefault(); return; }
    if (e.key === 'Enter') {
      e.preventDefault();
      if (e.shiftKey || S.focus.colId === 'name') {
        // Into the panel: the grid lets go of the keyboard.
        $$('.fwp-cell.is-focus', el.view).forEach((x) => x.classList.remove('is-focus'));
        S.focus = null;
        openItem(it.id, '', { focus: true });
        return;
      }
      const c = col(S.focus.colId);
      editCell(it, c, cellFor(it.id, c.id));
      return;
    }
    if (/^[0-9]$/.test(e.key) && canEdit() && S.focus.colId !== 'name') {
      const c = col(S.focus.colId);
      // Not also a view switch (1–6): the row re-renders, so the page-wide keys would not see a grid key any more.
      if (c && c.type === 'rating') { e.preventDefault(); e.stopPropagation(); setValue(it, c, Math.min(Number(e.key), (c.config || {}).max || 5) || null); return; }
    }
    if (e.key === 'x') { e.preventDefault(); const b = rowEl(it.id).querySelector('[data-sel]'); if (b) b.click(); return; }
    if ((e.key === 'Delete' || e.key === 'Backspace') && S.focus.colId !== 'name' && canEdit()) {
      const c = col(S.focus.colId);
      if (!c || !C.editable(c) || it.values[c.id] == null) return;
      e.preventDefault();
      const prev = it.values[c.id];
      setValue(it, c, null);
      toast(`${c.name} cleared`, { action: { label: 'Undo', run: () => setValue(it, c, prev) } });
    }
    // This Escape only leaves the table (the next one closes the panel).
    if (e.key === 'Escape') { e.stopPropagation(); $$('.fwp-cell.is-focus', el.view).forEach((x) => x.classList.remove('is-focus')); S.focus = null; }
  }

  function onGlobalKey(e) {
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    if (e.target.closest('input, textarea, select, [contenteditable="true"]') || window.FWP.activePop) return;
    if (document.querySelector('.fwm-modal:not([hidden])')) return;
    if (isGridKey(e)) return; // the table handles its own keys
    if (/^[1-6]$/.test(e.key)) { const v = VIEW_DEFS[+e.key - 1]; if (v && (views.has(v[0]) || v[0] === 'table')) { e.preventDefault(); setView(v[0]); } return; }
    if (e.key === 'n' && canEdit()) { e.preventDefault(); newItemTop(); return; }
    if (e.key === 'f' && FWB.dialogs) { e.preventDefault(); FWB.dialogs.filters($('#filterBtn')); return; }
    if (e.key === 'Escape' && S.selected.size) { clearSelection(); }
  }

  async function onHeadClick(e) {
    const t = e.target;
    if (t.closest('#boardTitle') && canEdit()) {
      const span = t.closest('#boardTitle');
      if (span.isContentEditable) return;
      span.contentEditable = 'true';
      span.focus();
      const r = document.createRange(); r.selectNodeContents(span); const s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
      // Once per edit: Enter blurs the title too, and both listeners go when the edit ends.
      let done = false;
      const ac = new AbortController();
      const finish = async (save) => {
        if (done) return;
        done = true;
        ac.abort();
        span.contentEditable = 'false';
        const title = span.textContent.trim();
        if (!save || !title || title === S.board.title) { span.textContent = S.board.title; return; }
        try { await api.post('boards.php', { action: 'update', id: S.board.id, title }); S.board.title = title; document.title = title + document.title.slice(document.title.indexOf(' ·')); const b = S.boards.find((x) => x.id === S.board.id); if (b) b.title = title; renderSide(); toast('Board renamed'); }
        catch (x) { span.textContent = S.board.title; toastError(x); }
      };
      span.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); finish(true); } if (ev.key === 'Escape') finish(false); }, { signal: ac.signal });
      span.addEventListener('blur', () => finish(true), { signal: ac.signal });
      return;
    }
    if (t.closest('#starBtn')) {
      const on = !S.board.starred;
      try { await api.post('boards.php', { action: 'star', id: S.board.id, on }); S.board.starred = on; const b = S.boards.find((x) => x.id === S.board.id); if (b) b.starred = on; renderHead(); renderSide(); } catch (x) { toastError(x); }
      return;
    }
    if (t.closest('#descBtn')) { popover(t.closest('#descBtn'), `<div class="fwp-pick" style="width:340px;white-space:pre-wrap;line-height:1.6">${esc(S.board.description)}</div>`, { role: 'dialog' }); return; }
    if (t.closest('#shareBtn') && FWB.dialogs) { FWB.dialogs.share(); return; }
    if (t.closest('#autoBtn') && FWB.dialogs) { FWB.dialogs.automations(); return; }
    if (t.closest('#activityBtn') && FWB.panel) { FWB.panel.openActivity(); return; }
    if (t.closest('#boardMenuBtn')) { boardMenu(t.closest('#boardMenuBtn')); }
  }
  function boardMenu(anchor) {
    popover(anchor, `${canManage() ? `<button class="fwm-pop__item" data-v="settings">${icon('settings')}Board settings…</button>` : ''}
      ${canEdit() ? `<button class="fwm-pop__item" data-v="addgroup">${icon('layers')}Add group</button>` : ''}
      <button class="fwm-pop__item" data-v="export">${icon('download')}Export to Excel (CSV)</button>
      ${canEdit() ? `<button class="fwm-pop__item" data-v="import">${icon('upload')}Import from CSV…</button>` : ''}
      <button class="fwm-pop__item" data-v="archived">${icon('archive')}Archived ${esc(noun())}s${S.board.archived_items ? `<small>${S.board.archived_items}</small>` : ''}</button>
      <button class="fwm-pop__item" data-v="print">${icon('print')}Print</button>
      ${canManage() ? `<div class="fwm-pop__sep"></div>
        <button class="fwm-pop__item" data-v="dup">${icon('copy')}Duplicate board…</button>
        <button class="fwm-pop__item" data-v="move">${icon('move')}Move to another project…</button>
        <button class="fwm-pop__item" data-v="archive">${icon('archive')}Archive board</button>
        <button class="fwm-pop__item is-danger" data-v="delete">${icon('trash')}Delete board…</button>` : ''}`, {
      align: 'end',
      onClick: async (it) => {
        const v = it.dataset.v;
        const D = FWB.dialogs;
        if (v === 'settings' && D) D.boardSettings();
        if (v === 'addgroup') addGroup('top');
        if (v === 'export') {
          const ids = isFiltered() ? filtered().map((x) => x.id) : null;
          if (ids && !ids.length) { toast('Nothing matches the filters, so there is nothing to export.'); return; }
          location.href = `/projects/api/export.php?board_id=${S.board.id}${ids ? '&ids=' + ids.join(',') : ''}`;
        }
        if (v === 'import' && D) D.importCsv();
        if (v === 'archived' && D) D.archived();
        if (v === 'print') window.print();
        if (v === 'dup' && D) D.duplicateBoard();
        if (v === 'move' && D) D.moveBoard();
        if (v === 'archive') {
          if (!(await dialog({ title: 'Archive this board?', body: 'It leaves the project’s board list and My work. A project manager can restore it from the project’s settings.', confirm: 'Archive board' }))) return;
          try { await api.post('boards.php', { action: 'archive', id: S.board.id }); location.href = '/projects/view.php?project_id=' + S.project.id; } catch (x) { toastError(x); }
        }
        if (v === 'delete') {
          const n = await dialog({ title: `Delete “${S.board.title}”?`, body: `All ${S.items.length} ${noun()}s, their updates and files are deleted for good. Type the board’s name to confirm.`, input: { placeholder: S.board.title }, confirm: 'Delete board', danger: true });
          if (n === null) return;
          if (n.trim().toLowerCase() !== S.board.title.trim().toLowerCase()) { toast('The name did not match — nothing was deleted.', { type: 'error' }); return; }
          try { await api.post('boards.php', { action: 'delete', id: S.board.id }); location.href = '/projects/view.php?project_id=' + S.project.id; } catch (x) { toastError(x); }
        }
      },
    });
  }
  function clearFilters() {
    S.q = ''; S.person = null; S.rules = []; S.activeView = 0;
    if (el.search) { el.search.value = ''; el.searchClear.hidden = true; }
    savePrefsSoon({ person: null, filters: [] });
    syncViewId();
    renderTools();
    renderView();
  }
  /** ?view_id= follows the saved view in use (a reload or a copied link must not bring back one that was left). */
  function syncViewId() {
    const u = new URL(location.href);
    if (S.activeView) u.searchParams.set('view_id', S.activeView); else u.searchParams.delete('view_id');
    history.replaceState(null, '', u.pathname + u.search);
  }
  function onToolsClick(e) {
    const t = e.target;
    const vb = t.closest('[data-view]');
    if (vb) { setView(vb.dataset.view); return; }
    if (t.closest('#newItemBtn')) { newItemTop(); return; }
    if (t.closest('#newItemMore')) {
      popover(t.closest('#newItemMore'), `<button class="fwm-pop__item" data-v="item">${icon('plus')}New ${esc(noun())}</button><button class="fwm-pop__item" data-v="group">${icon('layers')}New group</button>${FWB.dialogs ? `<button class="fwm-pop__item" data-v="import">${icon('upload')}Import from CSV…</button>` : ''}`, {
        onClick: (it) => { if (it.dataset.v === 'item') newItemTop(); if (it.dataset.v === 'group') addGroup('top'); if (it.dataset.v === 'import') FWB.dialogs.importCsv(); },
      });
      return;
    }
    if (t.closest('#personBtn')) {
      const ids = new Set(S.items.flatMap((it) => colsOf('people').flatMap((c) => C.val(it, c) || [])));
      const people = P.people.filter((p) => ids.has(p.id));
      if (!people.length) { toast('No one is assigned on this board yet.'); return; }
      pickPeople(t.closest('#personBtn'), S.person ? [S.person] : [], {
        title: 'Show items of', multi: false, people,
        onChange: (sel) => { S.person = sel.length ? (sel[0] === S.person ? null : sel[0]) : null; savePrefsSoon({ person: S.person }); renderTools(); renderView(); },
      });
      return;
    }
    if (t.closest('#filterBtn') && FWB.dialogs) { FWB.dialogs.filters(t.closest('#filterBtn')); return; }
    if (t.closest('#sortBtn') && FWB.dialogs) { FWB.dialogs.sort(t.closest('#sortBtn')); return; }
    if (t.closest('#hideBtn') && FWB.dialogs) { FWB.dialogs.hide(t.closest('#hideBtn')); return; }
    if (t.closest('#viewsBtn') && FWB.dialogs) { FWB.dialogs.views(t.closest('#viewsBtn')); return; }
    if (t.closest('#clearFilters')) { clearFilters(); return; }
    if (t.closest('#densityBtn')) { S.density = S.density === 'compact' ? 'normal' : 'compact'; savePrefsSoon({ density: S.density }); renderTools(); renderView(); }
  }

  // ---------------------------------------------------------------- subitems (table)
  async function toggleSubs(id) {
    if (S.guest) return; // the list comes from the member API
    if (S.expanded.has(id)) { S.expanded.delete(id); refreshItem(id); return; }
    S.expanded.add(id);
    refreshItem(id);
    await loadSubs(id);
  }
  async function loadSubs(id) {
    try { S.subs.set(id, (await api.get('subitems.php', { item_id: id })).subitems); } catch (x) { toastError(x); S.subs.set(id, []); }
    if (S.expanded.has(id)) refreshItem(id);
  }
  async function addSubitem(itemId, title, owner) {
    try {
      const d = await api.post('subitems.php', { action: 'add', item_id: itemId, title, owner });
      const list = S.subs.get(itemId) || [];
      list.push(d.subitem);
      S.subs.set(itemId, list);
      if (d.item) applyItems([d.item]);
      S.expanded.add(itemId);
      refreshItem(itemId);
      const f = el.view.querySelector(`[data-subadd="${itemId}"] input`);
      if (f) f.focus();
    } catch (x) { toastError(x); }
  }
  async function updateSub(sid, parentId, patch) {
    try {
      const d = await api.post('subitems.php', Object.assign({ action: 'update', id: sid }, patch));
      const list = (S.subs.get(parentId) || []).map((s) => (s.id === sid ? d.subitem : s));
      S.subs.set(parentId, list);
      if (d.item) applyItems([d.item]);
      refreshItem(parentId);
    } catch (x) { toastError(x); }
  }
  async function subClick(t) {
    const done = t.closest('[data-subdone]');
    if (done) { const row = done.closest('[data-sid]'); const s = (S.subs.get(Number(row.dataset.parent)) || []).find((x) => x.id === Number(done.dataset.subdone)); updateSub(s.id, Number(row.dataset.parent), { done: !s.done }); return true; }
    const due = t.closest('[data-subdue]');
    if (due) { const row = due.closest('[data-sid]'); const s = (S.subs.get(Number(row.dataset.parent)) || []).find((x) => x.id === Number(due.dataset.subdue)); window.FWP.pickDate(due, s.due, (d) => updateSub(s.id, Number(row.dataset.parent), { due: d || '' })); return true; }
    const own = t.closest('[data-subowner]');
    if (own) { const row = own.closest('[data-sid]'); const s = (S.subs.get(Number(row.dataset.parent)) || []).find((x) => x.id === Number(own.dataset.subowner)); pickPeople(own, s.owner ? [s.owner] : [], { multi: false, title: 'Owner', onChange: (ids) => updateSub(s.id, Number(row.dataset.parent), { owner: ids[0] || 0 }) }); return true; }
    const rn = t.closest('[data-subrename]');
    if (rn) {
      const row = rn.closest('[data-sid]');
      const pid = Number(row.dataset.parent);
      const s = (S.subs.get(pid) || []).find((x) => x.id === Number(row.dataset.sid));
      const input = document.createElement('input');
      input.className = 'fwp-name__input';
      input.value = s.title;
      rn.replaceWith(input);
      input.focus();
      let doneE = false;
      const fin = (save) => { if (doneE) return; doneE = true; if (save && input.value.trim() && input.value.trim() !== s.title) updateSub(s.id, pid, { title: input.value.trim() }); else refreshItem(pid); };
      input.addEventListener('keydown', (e) => { e.stopPropagation(); if (e.key === 'Enter') fin(true); if (e.key === 'Escape') fin(false); });
      input.addEventListener('blur', () => fin(true));
      return true;
    }
    return false;
  }

  // ---------------------------------------------------------------- drag & drop (rows, groups, columns)
  function bindDrag() {
    let drag = null; // {kind: 'row'|'group'|'col', ids|id}
    const clearMarks = () => $$('.is-drop-before, .is-drop-after', el.view).forEach((x) => x.classList.remove('is-drop-before', 'is-drop-after'));
    el.view.addEventListener('dragstart', (e) => {
      const g = e.target.closest('[data-drag]');
      const gg = e.target.closest('[data-gdrag]');
      const hc = e.target.closest('.fwp-th[data-hcol]');
      if (g) {
        const id = Number(g.dataset.drag);
        const ids = S.selected.has(id) ? Array.from(S.selected) : [id];
        drag = { kind: 'row', ids };
        ids.forEach((x) => { const r = rowEl(x); if (r) r.classList.add('is-dragging'); });
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', 'fwp-row');
        const r = rowEl(id);
        if (r) e.dataTransfer.setDragImage(r.querySelector('.fwp-cell--name'), 20, 20);
      } else if (gg) {
        drag = { kind: 'group', id: Number(gg.dataset.gdrag) };
        gg.closest('.fwp-group').classList.add('is-dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', 'fwp-group');
      } else if (hc && hc.dataset.hcol !== 'name') {
        drag = { kind: 'col', id: Number(hc.dataset.hcol) };
        hc.classList.add('is-dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', 'fwp-col');
      }
    });
    el.view.addEventListener('dragover', (e) => {
      if (!drag) return;
      clearMarks();
      if (drag.kind === 'row') {
        const r = e.target.closest('.fwp-row');
        const sec = e.target.closest('.fwp-group[data-gid]');
        if (!sec) return;
        e.preventDefault();
        if (r && !drag.ids.includes(Number(r.dataset.iid))) {
          const box = r.getBoundingClientRect();
          r.classList.add(e.clientY < box.top + box.height / 2 ? 'is-drop-before' : 'is-drop-after');
        } else if (!r) sec.querySelector('.fwp-ghead').classList.add('is-drop-after');
      } else if (drag.kind === 'group') {
        const sec = e.target.closest('.fwp-group[data-gid]');
        if (sec && Number(sec.dataset.gid) !== drag.id) { e.preventDefault(); sec.classList.add('is-drop-before'); }
      } else if (drag.kind === 'col') {
        const th = e.target.closest('.fwp-th[data-hcol]');
        if (th && th.dataset.hcol !== 'name' && Number(th.dataset.hcol) !== drag.id) {
          e.preventDefault();
          const box = th.getBoundingClientRect();
          th.classList.add(e.clientX < box.left + box.width / 2 ? 'is-drop-before' : 'is-drop-after');
        }
      }
    });
    el.view.addEventListener('drop', (e) => {
      if (!drag) return;
      e.preventDefault();
      const d = drag;
      drag = null;
      if (d.kind === 'row') {
        const sec = e.target.closest('.fwp-group[data-gid]');
        if (!sec) return clearMarks();
        const gid = Number(sec.dataset.gid);
        const target = e.target.closest('.fwp-row');
        const after = target && target.classList.contains('is-drop-after');
        clearMarks();
        let order = itemsIn(gid, S.items).map((x) => x.id).filter((x) => !d.ids.includes(x));
        let at = order.length;
        if (target && !d.ids.includes(Number(target.dataset.iid))) at = order.indexOf(Number(target.dataset.iid)) + (after ? 1 : 0);
        order.splice(at, 0, ...d.ids);
        const moving = d.ids.filter((id) => item(id) && item(id).group_id !== gid);
        if (moving.length) moveItems(d.ids, gid, order);
        else reorderGroup(gid, order);
      } else if (d.kind === 'group') {
        const sec = e.target.closest('.fwp-group[data-gid]');
        clearMarks();
        if (!sec) return;
        const ids = S.groups.map((g) => g.id).filter((x) => x !== d.id);
        ids.splice(ids.indexOf(Number(sec.dataset.gid)), 0, d.id);
        S.groups.sort((a, b) => ids.indexOf(a.id) - ids.indexOf(b.id));
        renderView();
        api.post('groups.php', { action: 'reorder', board_id: S.board.id, ids }).catch(toastError);
      } else if (d.kind === 'col') {
        const th = e.target.closest('.fwp-th[data-hcol]');
        const after = th && th.classList.contains('is-drop-after');
        clearMarks();
        if (!th || th.dataset.hcol === 'name') return;
        const ids = S.columns.map((c) => c.id).filter((x) => x !== d.id);
        ids.splice(ids.indexOf(Number(th.dataset.hcol)) + (after ? 1 : 0), 0, d.id);
        S.columns.sort((a, b) => ids.indexOf(a.id) - ids.indexOf(b.id));
        renderView();
        api.post('columns.php', { action: 'reorder', board_id: S.board.id, ids }).catch(toastError);
      }
    });
    el.view.addEventListener('dragend', () => { drag = null; clearMarks(); $$('.is-dragging', el.view).forEach((x) => x.classList.remove('is-dragging')); });
  }
  function bindResize() {
    el.view.addEventListener('pointerdown', (e) => {
      const h = e.target.closest('[data-resize]');
      if (!h) return;
      e.preventDefault();
      e.stopPropagation();
      const c = col(h.dataset.resize);
      const startX = e.clientX;
      const startW = S.widths[c.id] || c.width;
      h.classList.add('is-active');
      h.setPointerCapture(e.pointerId);
      const move = (ev) => {
        S.widths[c.id] = Math.max(60, Math.min(600, Math.round(startW + ev.clientX - startX)));
        $$('.fwp-grid', el.view).forEach((g) => { g.style.setProperty('--cols', colsTemplate(visibleColumns())); });
      };
      const up = async () => {
        h.classList.remove('is-active');
        h.removeEventListener('pointermove', move);
        h.removeEventListener('pointerup', up);
        const w = S.widths[c.id];
        if (w && w !== c.width) { c.width = w; try { await api.post('columns.php', { action: 'update', id: c.id, width: w }); } catch (x) { toastError(x); } }
      };
      h.addEventListener('pointermove', move);
      h.addEventListener('pointerup', up);
    });
  }

  // ===========================================================================
  // Live updates
  // ===========================================================================
  let pollTimer = null, quiet = 0;
  /** Someone is typing, renaming or dragging in the view: live updates wait (the cursor stays, so nothing is missed). */
  function editingInView() {
    if (window.FWP.activePop || (FWB.ganttDragging && FWB.ganttDragging())) return true;
    if (document.querySelector('.fwp-cell input.fwp-inline, .fwp-name__input, [data-gname][contenteditable="true"], #boardTitle[contenteditable="true"]')) return true;
    // An add box keeps its text through a re-render (keepAddBoxes): only one being typed in holds updates back.
    const a = document.activeElement;
    return !!(a && el.view.contains(a) && a.matches('input, textarea, select, [contenteditable="true"]') && !(a.matches(ADD_BOXES) && !a.value));
  }
  async function poll() {
    clearTimeout(pollTimer);
    if (S.guest || document.visibilityState !== 'visible' || editingInView()) { schedule(); return; }
    try {
      const seq = applySeq;
      const d = await api.get('changes.php', { board_id: S.board.id, since: S.cursor });
      // A write of ours landed while this answer was on its way: it may be older. Ask again from the same cursor.
      if (seq !== applySeq) { schedule(); return; }
      if (d.cursor) S.cursor = d.cursor;
      const others = (d.events || []).filter((ev) => ev.user_id !== P.me.id);
      if (d.structural && others.length) { await reloadBoard(true); quiet = 0; schedule(); return; }
      if ((d.items && d.items.length) || (d.removed && d.removed.length)) {
        applyItems(d.items);
        if (d.removed && d.removed.length) removeItems(d.removed);
        const otherIds = new Set(others.map((ev) => ev.item_id));
        if (S.view === 'table' && !(d.removed || []).length && d.items.every((x) => rowEl(x.id))) d.items.forEach((x) => refreshItem(x.id, { flash: otherIds.has(x.id) }));
        else renderView();
        // The open item changed elsewhere, or left the board (deleted, archived, moved): the panel catches up.
        if (S.openItem && FWB.panel && (otherIds.has(S.openItem) || (d.removed || []).includes(S.openItem))) FWB.panel.refresh(S.openItem, true);
        quiet = 0;
      } else quiet++;
    } catch (x) { quiet++; }
    schedule();
  }
  function schedule() {
    clearTimeout(pollTimer);
    const ms = document.visibilityState !== 'visible' ? 45000 : quiet > 12 ? 15000 : quiet > 4 ? 8000 : 4000;
    pollTimer = setTimeout(poll, ms);
  }
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') { quiet = 0; poll(); } });

  // ===========================================================================
  // Start
  // ===========================================================================
  function start() {
    indexItems();
    const o = BOOT.open || {};
    if (o.view_id && S.views.some((v) => v.id === o.view_id) && FWB.dialogs) { /* applied below */ }
    const initial = o.view || prefs.view || S.board.default_view || 'table';
    S.view = views.has(initial) || initial === 'table' ? initial : 'table';
    render();
    bind();
    if (o.view_id && FWB.dialogs) FWB.dialogs.applyView(o.view_id);
    if (o.item && item(o.item)) openItem(o.item, o.tab || '');
    else if (o.item) {
      // An archived item, or one on a collapsed / filtered group: the panel loads it anyway.
      openItem(o.item, o.tab || '');
    }
    schedule();
  }

  const FWB = window.FWB = {
    S, el, BOOT, prefs, store, col, group, item, colsOf, primaryStatus, primaryPeople, primaryDate, visibleColumns, filtered, matches, isFiltered, itemsIn, itemsSorted,
    canEdit, canManage, level, noun, Noun, busy, applyItems, removeItems, indexItems, decorate, setValue, bulkSet, renameItem, createItem, archiveItems, deleteItems,
    moveItems, reorderGroup, savePrefs, savePrefsSoon, reloadBoard, registerView, renderView, setView, render, renderTools, renderHead, renderSide, refreshItem,
    openItem, panelClosed, editCell, replaceColumn, insertColumn, addGroup, newItemTop, clearFilters, columnMenu, groupMenu, loadSubs, addSubitem, updateSub,
    VIEW_DEFS, start, ruleReady, syncViewId, isGridKey, cellFor, panel: null, dialogs: null, guestFileUrl: null, ganttDragging: null,
  };
})();
