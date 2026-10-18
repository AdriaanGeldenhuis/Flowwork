/* =============================================================================
   Flowwork Projects — board views beside the table
   Kanban (by status, priority, person, dropdown or group), Calendar (month,
   by any date or timeline column), Timeline (Gantt: drag to move, drag the
   ends to resize, dependency arrows, zoom), Workload (people × weeks) and
   Chart (a small dashboard). Every view honours the search, person filter
   and filter rules, and edits go through FWB.setValue / moveItems like the
   table. Registered with FWB.registerView.
   ============================================================================= */
(function () {
  'use strict';

  const FWB = window.FWB;
  if (!FWB) return;
  const {
    $, $$, esc, icon, api, toast, toastError, popover, P, personById, personName, personAvatar, faces, avatar,
    ymd, parseYmd, today, addDays, daysBetween, MONTHS, DOW, mon3, fmtDate, fmtRange, safeColor, fmtNum, emptyState, narrow, finePointer,
  } = window.FWP;
  const C = window.FWBCells;
  const S = FWB.S;
  const el = FWB.el;
  const prefs = FWB.prefs;
  const plural = (n, w) => `${n} ${w}${n === 1 ? '' : 's'}`;

  /** The column a view uses: the saved choice, else the first that fits. */
  function pickCol(pref, types, fallback) {
    const id = prefs[pref];
    const c = id ? FWB.col(id) : null;
    if (c && types.includes(c.type)) return c;
    return fallback ? fallback() : (S.columns.find((x) => types.includes(x.type)) || null);
  }
  function colSelect(id, cols, current, label) {
    if (cols.length < 2) return '';
    return `<label class="fwp-tool" style="padding-right:4px">${esc(label)}<select class="select" id="${id}" style="height:28px;width:auto;margin-left:4px;font-size:12.5px;border:0;background:transparent;padding:0 22px 0 4px">
      ${cols.map((c) => `<option value="${esc(c.id)}" ${String(current) === String(c.id) ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></label>`;
  }
  /** The colour an item shows in: its status, else its group. */
  function itemColor(it) {
    const ps = FWB.primaryStatus();
    const l = ps ? C.statusOf(ps, it.values[ps.id]) : null;
    if (l) return safeColor(l.color);
    const g = FWB.group(it.group_id);
    return safeColor(g ? g.color : '', '#7a1f30');
  }
  const noEdit = () => !FWB.canEdit();
  const emptyView = (title, text, action = '') => `<div class="fwp-pad">${emptyState(title, text, action, { art: true })}</div>`;
  const addColumnBtn = (type, label) => (FWB.canEdit() && FWB.dialogs ? `<button class="btn btn--primary" type="button" data-vadd="${type}">${icon('plus')}${esc(label)}</button>` : '');
  el.view.addEventListener('click', (e) => {
    const b = e.target.closest('[data-vadd]');
    if (b && FWB.dialogs) FWB.dialogs.addColumn(b, S.columns.length ? S.columns[S.columns.length - 1].id : 0, false, b.dataset.vadd);
  });

  // ===========================================================================
  // Kanban
  // ===========================================================================
  const KB_TYPES = ['status', 'priority', 'people', 'dropdown'];
  const kanban = {
    col() { return pickCol('kanban_column', KB_TYPES, () => FWB.primaryStatus() || S.columns.find((c) => KB_TYPES.includes(c.type)) || null); },
    by() { return prefs.kanban_column === 'group' ? 'group' : 'col'; },
    lanes() {
      if (this.by() === 'group') return S.groups.map((g) => ({ key: 'g' + g.id, value: g.id, label: g.name, color: safeColor(g.color) }));
      const c = this.col();
      if (!c) return [];
      if (c.type === 'status') return c.config.labels.map((l) => ({ key: 's' + l.key, value: l.key, label: l.label, color: safeColor(l.color), done: l.done })).concat([{ key: 'none', value: null, label: 'No status', color: '#85766b' }]);
      if (c.type === 'priority') return C.PRIORITIES.map((p) => ({ key: 'p' + p.key, value: p.key, label: p.label, color: p.color })).concat([{ key: 'none', value: null, label: 'No priority', color: '#85766b' }]);
      if (c.type === 'dropdown') return (c.config.options || []).map((o) => ({ key: 'o' + o.label, value: o.label, label: o.label, color: safeColor(o.color) })).concat([{ key: 'none', value: null, label: 'Not set', color: '#85766b' }]);
      // people: everyone assigned on the board, then the team, then unassigned
      const ids = new Set(S.items.flatMap((it) => it.values[c.id] || []));
      S.members.forEach((m) => ids.add(m.id));
      return Array.from(ids).map((id) => personById(id) || { id, name: 'Former member' }).sort((a, b) => a.name.localeCompare(b.name))
        .map((p) => ({ key: 'u' + p.id, value: p.id, label: p.name, person: p })).concat([{ key: 'none', value: null, label: 'Unassigned', color: '#85766b' }]);
    },
    laneOf(it, c) {
      if (this.by() === 'group') return ['g' + it.group_id];
      const v = it.values[c.id];
      if (c.type === 'people') return v && v.length ? v.map((id) => 'u' + id) : ['none'];
      if (c.type === 'dropdown') return v && v.length ? v.map((o) => 'o' + o) : ['none'];
      if (c.type === 'status') { const l = C.statusOf(c, v); return l ? ['s' + l.key] : ['none']; }
      return v ? [(c.type === 'priority' ? 'p' : 'x') + v] : ['none'];
    },
    tools(box) {
      const cols = S.columns.filter((c) => KB_TYPES.includes(c.type));
      const cur = this.by() === 'group' ? 'group' : (this.col() || {}).id;
      box.innerHTML = `<label class="fwp-tool" style="padding-right:4px">${icon('kanban')}<select class="select" id="kbBy" aria-label="Kanban columns" style="height:28px;width:auto;margin-left:2px;font-size:12.5px;border:0;background:transparent;padding:0 22px 0 4px">
        ${cols.map((c) => `<option value="${c.id}" ${String(cur) === String(c.id) ? 'selected' : ''}>By ${esc(c.name)}</option>`).join('')}<option value="group" ${cur === 'group' ? 'selected' : ''}>By group</option></select></label>`;
      $('#kbBy', box).addEventListener('change', (e) => { prefs.kanban_column = e.target.value === 'group' ? 'group' : Number(e.target.value); FWB.savePrefsSoon({ kanban_column: prefs.kanban_column }); FWB.renderView(); });
    },
    render(v) {
      const c = this.col();
      if (this.by() !== 'group' && !c) { v.innerHTML = emptyView('Kanban needs a status', 'Add a Status, Priority, People or Dropdown column and cards line up in its lanes.', addColumnBtn('status', 'Add a status column')); return; }
      const scroll = {};
      $$('.fwp-kb__list', v).forEach((l) => { scroll[l.dataset.lane] = l.scrollTop; });
      const lanes = this.lanes();
      const by = new Map(lanes.map((l) => [l.key, []]));
      const list = FWB.itemsSorted(FWB.filtered());
      list.forEach((it) => this.laneOf(it, c).forEach((k) => { if (by.has(k)) by.get(k).push(it); else if (by.has('none')) by.get('none').push(it); }));
      const num = S.columns.find((x) => x.type === 'number' || x.type === 'formula');
      const ps = FWB.primaryStatus();
      v.innerHTML = `<div class="fwp-kb" id="kb">${lanes.map((l) => {
        const items = by.get(l.key) || [];
        if (l.key === 'none' && !items.length) return '';
        const sum = num && items.length ? C.aggregate(num, items) : null;
        return `<section class="fwp-kb__col" data-lane="${esc(l.key)}" style="--c:${l.color || '#85766b'}">
          <div class="fwp-kb__head">${l.person ? `${avatar(l.person.name, l.person.email)}<b style="font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(l.label)}</b>` : `<span class="fwp-pill" style="--c:${l.color}">${esc(l.label)}</span>`}<span class="grow"></span><span class="fwp-kb__count">${items.length}</span></div>
          ${sum != null && typeof sum === 'number' ? `<div class="fwp-kb__sum">${esc(num.name)}: ${C.unitWrap(num, esc(C.numText(num, sum)))}</div>` : ''}
          <div class="fwp-kb__list" data-lane="${esc(l.key)}">${items.map((it) => this.card(it, c, ps)).join('')}</div>
          ${FWB.canEdit() ? `<button class="fwp-kb__add" type="button" data-kbadd="${esc(l.key)}">${icon('plus', 'i--sm')}Add ${esc(FWB.noun())}</button>` : ''}
        </section>`;
      }).join('')}</div>`;
      $$('.fwp-kb__list', v).forEach((x) => { if (scroll[x.dataset.lane]) x.scrollTop = scroll[x.dataset.lane]; });
    },
    card(it, c, ps) {
      const g = FWB.group(it.group_id);
      const dc = FWB.primaryDate();
      const pc = FWB.primaryPeople();
      const tl = FWB.colsOf('timeline')[0];
      const pr = FWB.colsOf('priority')[0];
      const d = dc ? it.values[dc.id] : null;
      const t = today();
      const meta = [];
      if (d) meta.push(`<span class="${!it._done && d < t ? 'fwp-due is-overdue' : ''}" style="${!it._done && d < t ? 'color:var(--danger);font-weight:600' : ''}">${icon('calendar')}${esc(fmtDate(d))}</span>`);
      else if (tl && it.values[tl.id]) meta.push(`<span>${icon('timeline')}${esc(fmtRange(it.values[tl.id].start, it.values[tl.id].end))}</span>`);
      if (it.subitems) meta.push(`<span>${icon('subitems')}${it.subitems_done}/${it.subitems}</span>`);
      if (it.updates) meta.push(`<span>${icon('message')}${it.updates}</span>`);
      if (it.files && it.files.length) meta.push(`<span>${icon('clip')}${it.files.length}</span>`);
      const showStatus = ps && (this.by() === 'group' || !c || c.id !== ps.id);
      const l = showStatus ? C.statusOf(ps, it.values[ps.id]) : null;
      const p = pr && (!c || c.id !== pr.id) ? C.priorityOf(it.values[pr.id]) : null;
      const people = pc && (!c || c.id !== pc.id) ? (it.values[pc.id] || []) : [];
      return `<article class="fwp-kcard ${it._done ? 'is-done' : ''}" data-kid="${it.id}" ${FWB.canEdit() && finePointer() ? 'draggable="true"' : ''} style="--g:${safeColor(g ? g.color : '')}" tabindex="0" role="button" aria-label="${esc(it.title)}">
        <div class="fwp-kcard__title">${esc(it.title)}</div>
        ${meta.length ? `<div class="fwp-kcard__meta">${meta.join('')}</div>` : ''}
        ${l || p || people.length ? `<div class="fwp-kcard__foot">${l ? `<span class="fwp-pill" style="--c:${safeColor(l.color)}">${esc(l.label)}</span>` : ''}${p ? `<span class="fwp-pill fwp-pill--soft" style="--c:${p.color}">${esc(p.label)}</span>` : ''}<span class="grow"></span>${faces(people, 3, 'fwp-faces--sm')}</div>` : ''}
      </article>`;
    },
    /** Move a card from one lane to another. */
    async drop(it, fromKey, toKey) {
      if (fromKey === toKey) return;
      if (this.by() === 'group') { await FWB.moveItems([it.id], Number(toKey.slice(1))); return; }
      const c = this.col();
      const lane = this.lanes().find((l) => l.key === toKey);
      if (!lane) return;
      let value = lane.value;
      if (c.type === 'people') {
        const cur = (it.values[c.id] || []).filter((id) => 'u' + id !== fromKey);
        value = lane.value == null ? [] : Array.from(new Set(cur.concat([lane.value])));
      } else if (c.type === 'dropdown' && c.config.multi) {
        const cur = (it.values[c.id] || []).filter((o) => 'o' + o !== fromKey);
        value = lane.value == null ? [] : Array.from(new Set(cur.concat([lane.value])));
      } else if (c.type === 'dropdown') value = lane.value == null ? [] : [lane.value];
      await FWB.setValue(it, c, value);
      FWB.renderView();
    },
    async add(key, title) {
      const c = this.col();
      const lane = this.lanes().find((l) => l.key === key);
      let gid = (S.groups[0] || {}).id;
      const values = {};
      if (this.by() === 'group') gid = lane.value;
      else if (lane && lane.value != null) values[c.id] = c.type === 'people' ? [lane.value] : c.type === 'dropdown' ? [lane.value] : lane.value;
      if (!gid) return;
      try { await FWB.createItem(gid, title, values); FWB.renderView(); const f = $(`[data-lane="${CSS.escape(key)}"] .fwp-kb__add-input input`, el.view); if (f) f.focus(); else { const b = $(`[data-kbadd="${CSS.escape(key)}"]`, el.view); if (b) b.click(); } }
      catch (x) { toastError(x); }
    },
  };
  // kanban events
  let kbDrag = null;
  el.view.addEventListener('dragstart', (e) => {
    const k = e.target.closest('[data-kid]');
    if (!k || S.view !== 'kanban') return;
    kbDrag = { id: Number(k.dataset.kid), from: k.closest('[data-lane]').dataset.lane };
    k.classList.add('is-dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', 'fwp-card');
  });
  el.view.addEventListener('dragover', (e) => {
    if (!kbDrag || S.view !== 'kanban') return;
    const col = e.target.closest('.fwp-kb__col');
    $$('.fwp-kb__col.is-drop', el.view).forEach((x) => { if (x !== col) x.classList.remove('is-drop'); });
    if (col) { e.preventDefault(); col.classList.add('is-drop'); }
  });
  el.view.addEventListener('drop', (e) => {
    if (!kbDrag || S.view !== 'kanban') return;
    const col = e.target.closest('.fwp-kb__col');
    e.preventDefault();
    const d = kbDrag;
    kbDrag = null;
    $$('.is-drop, .is-dragging', el.view).forEach((x) => x.classList.remove('is-drop', 'is-dragging'));
    const it = FWB.item(d.id);
    if (col && it) kanban.drop(it, d.from, col.dataset.lane);
  });
  el.view.addEventListener('dragend', () => { if (S.view !== 'kanban') return; kbDrag = null; $$('.is-drop, .is-dragging', el.view).forEach((x) => x.classList.remove('is-drop', 'is-dragging')); });
  el.view.addEventListener('click', (e) => {
    if (S.view !== 'kanban') return;
    const add = e.target.closest('[data-kbadd]');
    if (add) {
      const key = add.dataset.kbadd;
      const f = document.createElement('form');
      f.className = 'fwp-kb__add-input';
      f.innerHTML = `<input maxlength="500" placeholder="${esc(FWB.Noun())} name — Enter to add" aria-label="New ${esc(FWB.noun())}">`;
      add.replaceWith(f);
      const inp = $('input', f);
      inp.focus();
      f.addEventListener('submit', (ev) => { ev.preventDefault(); const t = inp.value.trim(); if (t) kanban.add(key, t); });
      inp.addEventListener('keydown', (ev) => { ev.stopPropagation(); if (ev.key === 'Escape') FWB.renderView(); });
      inp.addEventListener('blur', () => setTimeout(() => { if (f.isConnected && !inp.value.trim()) FWB.renderView(); }, 120));
      return;
    }
    const card = e.target.closest('[data-kid]');
    if (card) FWB.openItem(card.dataset.kid);
  });
  el.view.addEventListener('keydown', (e) => {
    if (S.view !== 'kanban' && S.view !== 'calendar' && S.view !== 'workload') return;
    const card = e.target.closest('[data-kid], [data-cid-item], [data-wlid]');
    if (card && e.key === 'Enter') { e.preventDefault(); card.click(); }
  });
  FWB.registerView('kanban', kanban);

  // ===========================================================================
  // Calendar (month)
  // ===========================================================================
  const cal = {
    month: null,
    col() { return pickCol('calendar_column', ['date', 'timeline'], () => FWB.primaryDate() || FWB.colsOf('timeline')[0] || null); },
    tools(box) {
      const cols = S.columns.filter((c) => c.type === 'date' || c.type === 'timeline');
      box.innerHTML = colSelect('calCol', cols, (this.col() || {}).id, 'By');
      const s = $('#calCol', box);
      if (s) s.addEventListener('change', () => { prefs.calendar_column = Number(s.value); FWB.savePrefsSoon({ calendar_column: prefs.calendar_column }); FWB.renderView(); });
    },
    span(it, c) {
      const v = it.values[c.id];
      if (!v) return null;
      return c.type === 'timeline' ? [v.start, v.end] : [v, v];
    },
    render(v) {
      const c = this.col();
      if (!c) { v.innerHTML = emptyView('Nothing to put on a calendar', 'Add a Date or Timeline column and items land on their days.', addColumnBtn('date', 'Add a date column')); return; }
      if (!this.month) this.month = this.firstMonth(c);
      const m = this.month;
      const start = addDays(m, -((m.getDay() + 6) % 7)); // weeks start on Monday
      const weeks = Math.ceil((daysBetween(start, new Date(m.getFullYear(), m.getMonth() + 1, 0)) + 1) / 7);
      const days = weeks * 7;
      const t = today();
      const list = FWB.itemsSorted(FWB.filtered());
      const byDay = new Map();
      const undated = [];
      list.forEach((it) => {
        const sp = this.span(it, c);
        if (!sp) { undated.push(it); return; }
        const a = parseYmd(sp[0]), b = parseYmd(sp[1]);
        const n = Math.min(daysBetween(a, b), 120);
        for (let i = 0; i <= n; i++) {
          const k = ymd(addDays(a, i));
          if (!byDay.has(k)) byDay.set(k, []);
          byDay.get(k).push({ it, first: i === 0, last: i === n, span: n > 0 });
        }
      });
      const cells = [];
      for (let i = 0; i < days; i++) {
        const d = addDays(start, i);
        const k = ymd(d);
        const evs = byDay.get(k) || [];
        const shown = evs.slice(0, 4);
        const wk = d.getDay() === 0 || d.getDay() === 6;
        cells.push(`<div class="fwp-cal__cell ${d.getMonth() !== m.getMonth() ? 'is-other' : ''} ${k === t ? 'is-today' : ''} ${wk ? 'is-weekend' : ''}" data-day="${k}">
          <div class="fwp-cal__num"><b>${d.getDate()}</b>${FWB.canEdit() ? `<button class="icon-btn icon-btn--sm" type="button" data-caladd="${k}" aria-label="Add on ${esc(fmtDate(k))}">${icon('plus', 'i--sm')}</button>` : ''}</div>
          ${shown.map((x) => `<button class="fwp-cal__ev ${x.it._done ? 'is-done' : ''} ${x.span ? 'is-span' : ''}" type="button" data-cid-item="${x.it.id}" ${FWB.canEdit() && finePointer() ? 'draggable="true"' : ''} style="--c:${itemColor(x.it)}" title="${esc(x.it.title)}">${x.span && !x.first ? '↳ ' : ''}${esc(x.it.title)}</button>`).join('')}
          ${evs.length > 4 ? `<button class="fwp-cal__more" type="button" data-calmore="${k}">+${evs.length - 4} more</button>` : ''}
        </div>`);
      }
      v.innerHTML = `<div class="fwp-cal">
        <div class="fwp-cal__bar"><h3>${MONTHS[m.getMonth()]} <em>${m.getFullYear()}</em></h3>
          ${undated.length ? `<button class="btn btn--sm btn--ghost" type="button" data-calundated>${icon('calendar')}${undated.length} without ${esc(c.type === 'timeline' ? 'dates' : 'a date')}</button>` : ''}
          <button class="btn btn--sm" type="button" data-calnav="0">Today</button>
          <button class="icon-btn" type="button" data-calnav="-1" aria-label="Previous month">${icon('chev-left')}</button>
          <button class="icon-btn" type="button" data-calnav="1" aria-label="Next month">${icon('chev-right')}</button></div>
        <div class="fwp-cal__dow">${['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((x) => `<div>${narrow() ? x[0] : x}</div>`).join('')}</div>
        <div class="fwp-cal__grid">${cells.join('')}</div></div>`;
      this.undated = undated;
      this.byDay = byDay;
    },
    /** This month — unless nothing on the board is near it, then the month of the work closest to today. */
    firstMonth(c) {
      const t = parseYmd(today());
      const here = new Date(t.getFullYear(), t.getMonth(), 1);
      const from = ymd(addDays(here, -7)), to = ymd(addDays(new Date(t.getFullYear(), t.getMonth() + 1, 0), 7));
      let best = null, gap = Infinity;
      for (const it of S.items) {
        const sp = this.span(it, c);
        if (!sp) continue;
        if (sp[1] >= from && sp[0] <= to) return here;
        const d = sp[1] < from ? parseYmd(sp[1]) : parseYmd(sp[0]);
        const g = Math.abs(daysBetween(t, d));
        if (g < gap) { gap = g; best = d; }
      }
      return best ? new Date(best.getFullYear(), best.getMonth(), 1) : here;
    },
    async moveTo(it, day) {
      const c = this.col();
      const v = it.values[c.id];
      if (c.type === 'date') { if (v !== day) await FWB.setValue(it, c, day); }
      else {
        const len = v ? daysBetween(parseYmd(v.start), parseYmd(v.end)) : 0;
        const nv = { start: day, end: ymd(addDays(parseYmd(day), len)) };
        if (!v || v.start !== nv.start) await FWB.setValue(it, c, nv);
      }
      FWB.renderView();
    },
    async addOn(day, anchor) {
      const title = await FWP_prompt(`New ${FWB.noun()} on ${fmtDate(day, { dow: true })}`);
      if (!title) return;
      const c = this.col();
      const g = S.groups[0];
      if (!g) return;
      try {
        await FWB.createItem(g.id, title, { [c.id]: c.type === 'date' ? day : { start: day, end: day } });
        FWB.renderView();
        toast(`Added to ${g.name}`);
      } catch (x) { toastError(x); }
    },
  };
  async function FWP_prompt(title) { return window.FWP.dialog({ title, input: { placeholder: `${FWB.Noun()} name`, max: 500 }, confirm: 'Add' }); }
  let calDrag = null;
  el.view.addEventListener('dragstart', (e) => {
    const ev = e.target.closest('[data-cid-item]');
    if (!ev || S.view !== 'calendar') return;
    calDrag = Number(ev.dataset.cidItem);
    ev.classList.add('is-dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', 'fwp-ev');
  });
  el.view.addEventListener('dragover', (e) => {
    if (calDrag == null || S.view !== 'calendar') return;
    const cell = e.target.closest('[data-day]');
    $$('.fwp-cal__cell.is-drop', el.view).forEach((x) => { if (x !== cell) x.classList.remove('is-drop'); });
    if (cell) { e.preventDefault(); cell.classList.add('is-drop'); }
  });
  el.view.addEventListener('drop', (e) => {
    if (calDrag == null || S.view !== 'calendar') return;
    e.preventDefault();
    const cell = e.target.closest('[data-day]');
    const it = FWB.item(calDrag);
    calDrag = null;
    $$('.is-drop, .is-dragging', el.view).forEach((x) => x.classList.remove('is-drop', 'is-dragging'));
    if (cell && it) cal.moveTo(it, cell.dataset.day);
  });
  el.view.addEventListener('dragend', () => { if (S.view !== 'calendar') return; calDrag = null; $$('.is-drop, .is-dragging', el.view).forEach((x) => x.classList.remove('is-drop', 'is-dragging')); });
  el.view.addEventListener('click', (e) => {
    if (S.view !== 'calendar') return;
    const nav = e.target.closest('[data-calnav]');
    if (nav) {
      const n = Number(nav.dataset.calnav);
      if (!n) { const t = parseYmd(today()); cal.month = new Date(t.getFullYear(), t.getMonth(), 1); }
      else cal.month = new Date(cal.month.getFullYear(), cal.month.getMonth() + n, 1);
      FWB.renderView();
      return;
    }
    const add = e.target.closest('[data-caladd]');
    if (add) { cal.addOn(add.dataset.caladd, add); return; }
    const more = e.target.closest('[data-calmore]');
    if (more) {
      const evs = cal.byDay.get(more.dataset.calmore) || [];
      popover(more, `<div class="fwm-pop__title">${esc(fmtDate(more.dataset.calmore, { dow: true }))}</div>${evs.map((x) => `<button class="fwm-pop__item" data-v="${x.it.id}"><span class="fwp-dot" style="--c:${itemColor(x.it)}"></span><span style="min-width:0;overflow:hidden;text-overflow:ellipsis">${esc(x.it.title)}</span></button>`).join('')}`, { onClick: (b) => FWB.openItem(b.dataset.v) });
      return;
    }
    const und = e.target.closest('[data-calundated]');
    if (und) {
      popover(und, `<div class="fwm-pop__title">No ${esc((cal.col() || {}).name || 'date')} yet</div>${cal.undated.slice(0, 60).map((it) => `<button class="fwm-pop__item" data-v="${it.id}"><span class="fwp-dot" style="--c:${itemColor(it)}"></span><span style="min-width:0;overflow:hidden;text-overflow:ellipsis">${esc(it.title)}</span></button>`).join('')}`, { align: 'end', onClick: (b) => FWB.openItem(b.dataset.v) });
      return;
    }
    const ev = e.target.closest('[data-cid-item]');
    if (ev) FWB.openItem(ev.dataset.cidItem);
  });
  FWB.registerView('calendar', cal);

  // ===========================================================================
  // Timeline (Gantt)
  // ===========================================================================
  const ZOOMS = { day: 34, week: 15, month: 5 };
  const gantt = {
    collapsed: new Set(),
    scrolled: false,
    zoom() { return ZOOMS[prefs.timeline_zoom] ? prefs.timeline_zoom : 'day'; },
    col() { return pickCol('timeline_column', ['timeline', 'date'], () => FWB.colsOf('timeline')[0] || FWB.primaryDate() || null); },
    span(it, c) {
      const v = it.values[c.id];
      if (!v) return null;
      return c.type === 'timeline' ? { s: v.start, e: v.end, ms: false } : { s: v, e: v, ms: true };
    },
    progress(it) {
      if (it._done) return 100;
      const pc = FWB.colsOf('progress')[0];
      if (pc && typeof it.values[pc.id] === 'number') return Math.max(0, Math.min(100, it.values[pc.id]));
      if (it.subitems) return Math.round(it.subitems_done * 100 / it.subitems);
      return 0;
    },
    tools(box) {
      const cols = S.columns.filter((c) => c.type === 'timeline' || c.type === 'date');
      const z = this.zoom();
      box.innerHTML = `${colSelect('ganttCol', cols, (this.col() || {}).id, 'By')}
        <span class="seg" role="group" aria-label="Zoom">${Object.keys(ZOOMS).map((k) => `<button type="button" data-gzoom="${k}" class="${z === k ? 'is-on' : ''}">${k[0].toUpperCase() + k.slice(1)}s</button>`).join('')}</span>
        <button class="btn btn--sm" type="button" id="ganttToday">${icon('target', 'i--sm')}Today</button>`;
      const s = $('#ganttCol', box);
      if (s) s.addEventListener('change', () => { prefs.timeline_column = Number(s.value); FWB.savePrefsSoon({ timeline_column: prefs.timeline_column }); this.scrolled = false; FWB.renderView(); });
      $$('[data-gzoom]', box).forEach((b) => b.addEventListener('click', () => { prefs.timeline_zoom = b.dataset.gzoom; FWB.savePrefsSoon({ timeline_zoom: b.dataset.gzoom }); this.scrolled = false; FWB.renderTools(); FWB.renderView(); }));
      $('#ganttToday', box).addEventListener('click', () => this.scrollToToday(true));
    },
    render(v) {
      const c = this.col();
      if (!c) { v.innerHTML = emptyView('A timeline needs dates', 'Add a Timeline column (start and end) — bars appear here and you can drag them to reschedule.', addColumnBtn('timeline', 'Add a timeline column')); return; }
      const dayW = ZOOMS[this.zoom()];
      const list = FWB.filtered();
      const spans = list.map((it) => this.span(it, c)).filter(Boolean);
      const t0 = parseYmd(today());
      // The window covers the dates in use, within 18 months back and 3 years ahead
      // of today — a typo like 2001 must not stretch the chart over decades.
      const lo = addDays(t0, -548), hi = addDays(t0, 1095);
      let min = addDays(t0, -14), max = addDays(t0, 60);
      let dataMin = null, dataMax = null;
      spans.forEach((s) => {
        const a = parseYmd(s.s), b = parseYmd(s.e);
        if (b < lo || a > hi) return;
        if (a < min) min = a < lo ? lo : a;
        if (b > max) max = b > hi ? hi : b;
        if (!dataMin || a < dataMin) dataMin = a;
        if (!dataMax || b > dataMax) dataMax = b;
      });
      min = addDays(min, -7 - ((min.getDay() + 6) % 7));
      max = addDays(max, 21);
      const days = daysBetween(min, max) + 1;
      this.dataMin = dataMin;
      this.dataMax = dataMax;
      this.min = min;
      this.dayW = dayW;
      const width = days * dayW;
      // rows: group header, then its items
      const rows = [];
      S.groups.forEach((g) => {
        const items = FWB.itemsIn(g.id, list);
        if (!items.length && FWB.isFiltered()) return;
        rows.push({ g });
        if (!this.collapsed.has(g.id)) items.forEach((it) => rows.push({ it, g }));
      });
      this.rows = rows;
      // scale
      let months = '', daysH = '', weekends = '';
      for (let i = 0; i < days;) {
        const d = addDays(min, i);
        const left = new Date(d.getFullYear(), d.getMonth() + 1, 1);
        const n = Math.min(days - i, daysBetween(d, left));
        months += `<div style="width:${n * dayW}px"><span>${dayW * n > 60 ? (dayW >= 10 ? MONTHS[d.getMonth()] : mon3(d)) + ' ' + d.getFullYear() : ''}</span></div>`;
        i += n;
      }
      for (let i = 0; i < days; i++) {
        const d = addDays(min, i);
        const wk = d.getDay() === 0 || d.getDay() === 6;
        if (wk && dayW >= 10) weekends += `<div class="fwp-gantt__weekend" style="left:${i * dayW}px;width:${dayW}px"></div>`;
        if (dayW >= 28) daysH += `<div class="${wk ? 'is-weekend' : ''} ${ymd(d) === today() ? 'is-today' : ''}">${d.getDate()}</div>`;
        else if (dayW >= 10 && d.getDay() === 1) daysH += `<div style="flex:0 0 ${dayW * 7}px;width:${dayW * 7}px;text-align:left;padding-left:4px">${d.getDate()} ${mon3(d)}</div>`;
        else if (dayW < 10 && d.getDate() === 1) daysH += '';
      }
      if (dayW >= 10 && dayW < 28) { // week labels start on the first Monday
        const firstMon = (8 - min.getDay()) % 7;
        daysH = `<div style="flex:0 0 ${firstMon * dayW}px"></div>` + daysH;
      }
      const lane = 38;
      const ed = FWB.canEdit();
      const listHTML = rows.map((r) => r.it
        ? `<div class="fwp-gantt__item" data-gitem="${r.it.id}" title="${esc(r.it.title)}"><span>${esc(r.it.title)}</span>${faces((FWB.primaryPeople() ? r.it.values[FWB.primaryPeople().id] : null) || [], 2, 'fwp-faces--sm')}</div>`
        : `<div class="fwp-gantt__grp ${this.collapsed.has(r.g.id) ? 'is-collapsed' : ''}" data-ggrp="${r.g.id}" style="--c:${safeColor(r.g.color)}">${icon('chev-down')}${esc(r.g.name)}</div>`).join('');
      const pos = new Map();
      const bars = rows.map((r, i) => {
        if (!r.it) {
          // group summary bar: earliest start to latest end
          const lo2 = ymd(min), hi2 = ymd(max);
          const items = FWB.itemsIn(r.g.id, list).map((it) => this.span(it, c)).filter((s) => s && s.e >= lo2 && s.s <= hi2);
          if (!items.length) return `<div class="fwp-gantt__row is-grp"></div>`;
          const a = items.reduce((m, s) => (s.s < m ? s.s : m), items[0].s), b = items.reduce((m, s) => (s.e > m ? s.e : m), items[0].e);
          const ca = a < lo2 ? lo2 : a, cb = b > hi2 ? hi2 : b;
          const x = daysBetween(min, parseYmd(ca)) * dayW, w = (daysBetween(parseYmd(ca), parseYmd(cb)) + 1) * dayW;
          return `<div class="fwp-gantt__row is-grp"><div style="position:absolute;left:${x}px;width:${w}px;top:16px;height:6px;border-radius:3px;background:${safeColor(r.g.color)};opacity:.55"></div></div>`;
        }
        const s = this.span(r.it, c);
        if (!s) return `<div class="fwp-gantt__row" data-grow="${r.it.id}">${ed ? `<button class="fwp-gantt__label" type="button" data-gset="${r.it.id}" style="left:${(daysBetween(min, t0)) * dayW + 6}px;pointer-events:auto;border:0;background:none;cursor:pointer;color:var(--ink-4)">+ Set dates</button>` : ''}</div>`;
        const sa = parseYmd(s.s), sb = parseYmd(s.e);
        if (sb < min || sa > max) {
          // outside the window: a pointer to where it is, opening the item
          const left = sb < min;
          return `<div class="fwp-gantt__row" data-grow="${r.it.id}"><button class="fwp-gantt__label" type="button" data-gitem="${r.it.id}" style="${left ? 'left:6px' : `left:${width - 190}px`};pointer-events:auto;border:0;background:none;cursor:pointer;color:var(--warning)">${left ? '◂ ' : ''}${esc(s.ms ? fmtDate(s.s) : fmtRange(s.s, s.e))}${left ? '' : ' ▸'}</button></div>`;
        }
        const x = Math.max(0, daysBetween(min, sa)) * dayW;
        const w = Math.max(dayW, (daysBetween(sa < min ? min : sa, sb > max ? max : sb) + 1) * dayW);
        pos.set(r.it.id, { x, w, y: i * lane + lane / 2, ms: s.ms });
        const col = itemColor(r.it);
        const label = `<span class="fwp-gantt__label" style="left:${x + w + 8}px">${w < 120 ? esc(r.it.title) : ''}</span>`;
        if (s.ms) return `<div class="fwp-gantt__row" data-grow="${r.it.id}"><span class="fwp-gantt__ms ${ed ? '' : 'is-readonly'}" data-gbar="${r.it.id}" style="left:${x + dayW / 2}px;--c:${col}" title="${esc(r.it.title)} · ${esc(fmtDate(s.s))}"></span><span class="fwp-gantt__label" style="left:${x + dayW / 2 + 14}px">${esc(r.it.title)}</span></div>`;
        return `<div class="fwp-gantt__row" data-grow="${r.it.id}"><div class="fwp-gantt__bar ${ed ? '' : 'is-readonly'}" data-gbar="${r.it.id}" style="left:${x}px;width:${w}px;--c:${col};--p:${this.progress(r.it)}%">
          <i></i><span>${w >= 120 ? esc(r.it.title) : ''}</span>${ed ? '<b class="fwp-gantt__h fwp-gantt__h--l" data-gh="l"></b><b class="fwp-gantt__h fwp-gantt__h--r" data-gh="r"></b>' : ''}</div>${label}</div>`;
      }).join('');
      // dependency arrows (predecessor end → successor start)
      const paths = S.deps.map((d) => {
        const a = pos.get(d.from), b = pos.get(d.to);
        if (!a || !b) return '';
        const x1 = a.x + (a.ms ? dayW / 2 + 8 : a.w), y1 = a.y, x2 = b.x + (b.ms ? dayW / 2 - 8 : 0), y2 = b.y;
        const late = x2 < x1 - 1;
        const mid = Math.max(x1 + 10, Math.min(x2 - 10, x1 + 14));
        const dPath = x2 - 20 >= x1 ? `M${x1} ${y1} H${mid} V${y2} H${x2 - 4}` : `M${x1} ${y1} h10 V${(y1 + y2) / 2} H${x2 - 14} V${y2} H${x2 - 4}`;
        return `<path d="${dPath}" class="${late ? 'is-late' : ''}"/><path class="arrow ${late ? 'is-late' : ''}" d="M${x2 - 5} ${y2 - 4} L${x2 + 1} ${y2} L${x2 - 5} ${y2 + 4} Z"/>`;
      }).join('');
      const todayX = daysBetween(min, t0) * dayW + dayW / 2;
      const prevLeft = v.scrollLeft;
      v.innerHTML = `<div class="fwp-gantt" style="--day:${dayW}px;--lane:${lane}px">
        <div class="fwp-gantt__list"><div class="fwp-gantt__lhead">${esc(FWB.Noun())}</div>${listHTML || `<div class="hint" style="padding:16px">No ${esc(FWB.noun())}s match.</div>`}</div>
        <div class="fwp-gantt__chart" style="width:${width}px">
          <div class="fwp-gantt__scale"><div class="fwp-gantt__months">${months}</div><div class="fwp-gantt__days">${daysH}</div></div>
          <div class="fwp-gantt__body" style="height:${rows.length * lane}px">
            <div class="fwp-gantt__bg">${weekends}</div>
            ${bars}
            <svg class="fwp-gantt__deps" width="${width}" height="${rows.length * lane}" aria-hidden="true">${paths}</svg>
            <div class="fwp-gantt__today" style="left:${todayX}px"></div>
          </div></div></div>`;
      if (!this.scrolled) { this.scrolled = true; requestAnimationFrame(() => this.scrollToToday(false, true)); } else v.scrollLeft = prevLeft;
    },
    /** Today — or, on the first look, the work itself when it is all in the past or the future. */
    scrollToToday(smooth, toWork) {
      if (!this.min) return;
      const t = parseYmd(today());
      let x = daysBetween(this.min, t) * this.dayW - 280;
      if (toWork && this.dataMin && this.dataMax) {
        if (this.dataMax < t) x = daysBetween(this.min, this.dataMin) * this.dayW - 40;
        else if (this.dataMin > t) x = daysBetween(this.min, this.dataMin) * this.dayW - 120;
      }
      el.view.scrollTo({ left: Math.max(0, x), behavior: smooth ? 'smooth' : 'auto' });
    },
    destroy() { this.scrolled = false; tip(null); },
  };
  // gantt interaction: drag bars (move / resize), click to open, collapse groups
  let gDrag = null;
  function tip(text, x, y) {
    let t = $('#ganttTip');
    if (!text) { if (t) t.remove(); return; }
    if (!t) { t = document.createElement('div'); t.id = 'ganttTip'; t.className = 'fwp-gantt__tip'; document.body.appendChild(t); }
    t.textContent = text;
    t.style.left = Math.min(x + 12, window.innerWidth - t.offsetWidth - 8) + 'px';
    t.style.top = (y - 36) + 'px';
  }
  el.view.addEventListener('pointerdown', (e) => {
    if (S.view !== 'gantt' || !FWB.canEdit() || e.button !== 0) return;
    const bar = e.target.closest('[data-gbar]');
    if (!bar) return;
    const it = FWB.item(bar.dataset.gbar);
    const c = gantt.col();
    const s = it && gantt.span(it, c);
    if (!s) return;
    const h = e.target.closest('[data-gh]');
    gDrag = { it, c, bar, mode: h ? h.dataset.gh : 'move', x0: e.clientX, s0: s.s, e0: s.e, ds: 0, de: 0, moved: false, ms: s.ms };
    bar.setPointerCapture(e.pointerId);
    e.preventDefault();
  });
  el.view.addEventListener('pointermove', (e) => {
    if (S.view !== 'gantt') return;
    if (!gDrag) {
      const bar = e.target.closest('[data-gbar]');
      if (bar && finePointer()) { const it = FWB.item(bar.dataset.gbar); const s = it && gantt.span(it, gantt.col()); if (s) tip(`${it.title} · ${s.ms ? fmtDate(s.s) : fmtRange(s.s, s.e) + ' · ' + plural(daysBetween(parseYmd(s.s), parseYmd(s.e)) + 1, 'day')}`, e.clientX, e.clientY); }
      else tip(null);
      return;
    }
    const n = Math.round((e.clientX - gDrag.x0) / gantt.dayW);
    if (Math.abs(e.clientX - gDrag.x0) > 3) gDrag.moved = true;
    if (!gDrag.moved) return;
    const g = gDrag;
    g.bar.classList.add('is-dragging');
    let s = parseYmd(g.s0), en = parseYmd(g.e0);
    if (g.mode === 'move' || g.ms) { s = addDays(s, n); en = addDays(en, n); }
    else if (g.mode === 'l') { s = addDays(s, n); if (s > en) s = en; }
    else if (g.mode === 'r') { en = addDays(en, n); if (en < s) en = s; }
    g.ns = ymd(s); g.ne = ymd(en);
    const x = daysBetween(gantt.min, s) * gantt.dayW;
    if (g.ms) g.bar.style.left = (x + gantt.dayW / 2) + 'px';
    else { g.bar.style.left = x + 'px'; g.bar.style.width = Math.max(gantt.dayW, (daysBetween(s, en) + 1) * gantt.dayW) + 'px'; }
    tip(g.ms ? fmtDate(g.ns, { dow: true }) : `${fmtRange(g.ns, g.ne)} · ${plural(daysBetween(s, en) + 1, 'day')}`, e.clientX, e.clientY);
  });
  el.view.addEventListener('pointerup', async () => {
    if (S.view !== 'gantt' || !gDrag) return;
    const g = gDrag;
    gDrag = null;
    tip(null);
    if (!g.moved) { FWB.openItem(g.it.id); return; }
    if (!g.ns || (g.ns === g.s0 && g.ne === g.e0)) { FWB.renderView(); return; }
    await FWB.setValue(g.it, g.c, g.c.type === 'timeline' ? { start: g.ns, end: g.ne } : g.ns);
    FWB.renderView();
  });
  el.view.addEventListener('pointercancel', () => { if (gDrag) { gDrag = null; tip(null); FWB.renderView(); } });
  el.view.addEventListener('pointerleave', () => { if (!gDrag) tip(null); });
  el.view.addEventListener('click', (e) => {
    if (S.view !== 'gantt') return;
    const grp = e.target.closest('[data-ggrp]');
    if (grp) { const id = Number(grp.dataset.ggrp); if (gantt.collapsed.has(id)) gantt.collapsed.delete(id); else gantt.collapsed.add(id); FWB.renderView(); return; }
    const gi = e.target.closest('[data-gitem]');
    if (gi) { FWB.openItem(gi.dataset.gitem); return; }
    const set = e.target.closest('[data-gset]');
    if (set) {
      const it = FWB.item(set.dataset.gset);
      const c = gantt.col();
      if (it && c) FWB.editCell(it, c, set);
      return;
    }
    const bar = e.target.closest('[data-gbar]');
    if (bar && !FWB.canEdit()) FWB.openItem(bar.dataset.gbar);
  });
  // hover a list row ↔ its lane
  el.view.addEventListener('mouseover', (e) => {
    if (S.view !== 'gantt') return;
    const r = e.target.closest('[data-gitem], [data-grow]');
    $$('.fwp-gantt__row.is-hover, .fwp-gantt__item.is-hover', el.view).forEach((x) => x.classList.remove('is-hover'));
    if (!r) return;
    const id = r.dataset.gitem || r.dataset.grow;
    $$(`[data-gitem="${id}"], [data-grow="${id}"]`, el.view).forEach((x) => x.classList.add('is-hover'));
  });
  FWB.registerView('gantt', gantt);

  // ===========================================================================
  // Workload (people × weeks)
  // ===========================================================================
  const WEEKS = 8;
  const workload = {
    offset: 0,
    col() { return pickCol('workload_column', ['people'], () => FWB.primaryPeople()); },
    dateCol() { return FWB.colsOf('timeline')[0] || FWB.primaryDate(); },
    /** Effort: an Hours / Effort / Estimate number column, else 1 per item. */
    effortCol() { return S.columns.find((c) => (c.type === 'number' || c.type === 'formula') && /hour|effort|estimate|days|man/i.test(c.name)) || null; },
    tools(box) {
      const cols = FWB.colsOf('people');
      box.innerHTML = `${colSelect('wlCol', cols, (this.col() || {}).id, 'By')}
        <button class="icon-btn icon-btn--sm" type="button" data-wlnav="-1" aria-label="Earlier weeks">${icon('chev-left', 'i--sm')}</button>
        <button class="btn btn--sm" type="button" data-wlnav="0">This week</button>
        <button class="icon-btn icon-btn--sm" type="button" data-wlnav="1" aria-label="Later weeks">${icon('chev-right', 'i--sm')}</button>`;
      const s = $('#wlCol', box);
      if (s) s.addEventListener('change', () => { prefs.workload_column = Number(s.value); FWB.savePrefsSoon({ workload_column: prefs.workload_column }); FWB.renderView(); });
      $$('[data-wlnav]', box).forEach((b) => b.addEventListener('click', () => { const n = Number(b.dataset.wlnav); this.offset = n ? this.offset + n * 4 : 0; FWB.renderView(); }));
    },
    weekStart(d) { return addDays(d, -((d.getDay() + 6) % 7)); },
    render(v) {
      const pc = this.col(), dc = this.dateCol();
      if (!pc || !dc) {
        v.innerHTML = emptyView('Workload needs people and dates', `Add ${!pc ? 'a People column' : ''}${!pc && !dc ? ' and ' : ''}${!dc ? 'a Date or Timeline column' : ''} to see who carries what, week by week.`, addColumnBtn(!pc ? 'people' : 'timeline', !pc ? 'Add a people column' : 'Add a timeline column'));
        return;
      }
      const w0 = addDays(this.weekStart(parseYmd(today())), this.offset * 7);
      const weeks = Array.from({ length: WEEKS }, (_, i) => addDays(w0, i * 7));
      const ec = this.effortCol();
      const cap = ec ? 40 : 5;
      const list = FWB.filtered().filter((it) => !it._done);
      const people = new Map();
      const ensure = (id) => { if (!people.has(id)) people.set(id, weeks.map(() => ({ items: [], load: 0 }))); return people.get(id); };
      S.members.forEach((m) => { if (personById(m.id)) ensure(m.id); });
      list.forEach((it) => {
        const v0 = it.values[dc.id];
        if (!v0) return;
        const a = parseYmd(dc.type === 'timeline' ? v0.start : v0), b = parseYmd(dc.type === 'timeline' ? v0.end : v0);
        const who = (it.values[pc.id] || []).length ? it.values[pc.id] : [0];
        const effort = ec && typeof it.values[ec.id] === 'number' ? it.values[ec.id] : 1;
        const spanW = Math.max(1, Math.floor(daysBetween(this.weekStart(a), this.weekStart(b)) / 7) + 1);
        weeks.forEach((ws, i) => {
          const we = addDays(ws, 6);
          if (b < ws || a > we) return;
          who.forEach((id) => { const row = ensure(id)[i]; row.items.push(it); row.load += effort / spanW; });
        });
      });
      const ids = Array.from(people.keys()).sort((x, y) => (x === 0) - (y === 0) || personName(x).localeCompare(personName(y)));
      const thisWeek = ymd(this.weekStart(parseYmd(today())));
      const fmtLoad = (n) => (ec ? fmtNum(n, n % 1 ? 1 : 0) + ' h' : plural(Math.round(n), FWB.noun()));
      v.innerHTML = `<div class="fwp-wl"><div class="fwp-wl__grid" style="grid-template-columns:minmax(180px,220px) repeat(${WEEKS}, minmax(140px, 1fr))">
        <div class="fwp-wl__h">Person<small>${ec ? `Load from ${esc(ec.name)} · capacity ${cap} h a week` : `Open ${esc(FWB.noun())}s · ${cap} a week is full`}</small></div>
        ${weeks.map((w) => `<div class="fwp-wl__h ${ymd(w) === thisWeek ? 'is-now' : ''}">${ymd(w) === thisWeek ? 'This week' : 'Week of'}<small>${esc(fmtDate(ymd(w)))}</small></div>`).join('')}
        ${ids.map((id) => {
          const row = people.get(id);
          const total = row.reduce((n, x) => n + x.load, 0);
          const p = id ? personById(id) : null;
          return `<div class="fwp-wl__who">${p ? avatar(p.name, p.email) : `<span class="fwm-avatar" style="--h:30">?</span>`}<span style="min-width:0"><b>${esc(id ? personName(id) : 'Unassigned')}</b><small>${esc(fmtLoad(total))} in ${WEEKS} weeks</small></span></div>
            ${row.map((cell, i) => {
              const pct = Math.min(100, Math.round(cell.load * 100 / cap));
              const cls = cell.load > cap ? 'is-over' : cell.load > cap * 0.75 ? 'is-busy' : '';
              return `<div class="fwp-wl__cell" data-wlcell="${id}:${ymd(weeks[i])}">${cell.items.length ? `<div class="fwp-wl__load ${cls}" title="${esc(fmtLoad(cell.load))}"><i style="--p:${pct}%"></i></div><span class="fwp-wl__n">${esc(fmtLoad(cell.load))}</span>` : ''}
                ${cell.items.slice(0, 4).map((it) => `<button class="fwp-wl__item" type="button" data-wlid="${it.id}" data-wlfrom="${id}:${ymd(weeks[i])}" ${FWB.canEdit() && finePointer() ? 'draggable="true"' : ''} style="--c:${itemColor(it)}" title="${esc(it.title)}">${esc(it.title)}</button>`).join('')}
                ${cell.items.length > 4 ? `<button class="fwp-cal__more" type="button" data-wlmore="${id}:${i}">+${cell.items.length - 4} more</button>` : ''}</div>`;
            }).join('')}`;
        }).join('')}
      </div></div>`;
      this.people = people;
      this.weeks = weeks;
    },
    async drop(it, from, to) {
      if (from === to) return;
      const [fid, fw] = from.split(':');
      const [tid, tw] = to.split(':');
      const pc = this.col(), dc = this.dateCol();
      if (fid !== tid) {
        const cur = (it.values[pc.id] || []).filter((x) => String(x) !== fid);
        await FWB.setValue(it, pc, Number(tid) ? Array.from(new Set(cur.concat([Number(tid)]))) : cur);
      }
      if (fw !== tw) {
        const shift = daysBetween(parseYmd(fw), parseYmd(tw));
        const v = it.values[dc.id];
        if (v) await FWB.setValue(it, dc, dc.type === 'timeline' ? { start: ymd(addDays(parseYmd(v.start), shift)), end: ymd(addDays(parseYmd(v.end), shift)) } : ymd(addDays(parseYmd(v), shift)));
      }
      FWB.renderView();
    },
  };
  let wlDrag = null;
  el.view.addEventListener('dragstart', (e) => {
    const b = e.target.closest('[data-wlid]');
    if (!b || S.view !== 'workload') return;
    wlDrag = { id: Number(b.dataset.wlid), from: b.dataset.wlfrom };
    b.classList.add('is-dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', 'fwp-wl');
  });
  el.view.addEventListener('dragover', (e) => {
    if (!wlDrag || S.view !== 'workload') return;
    const cell = e.target.closest('[data-wlcell]');
    $$('.fwp-wl__cell.is-drop', el.view).forEach((x) => { if (x !== cell) x.classList.remove('is-drop'); });
    if (cell) { e.preventDefault(); cell.classList.add('is-drop'); }
  });
  el.view.addEventListener('drop', (e) => {
    if (!wlDrag || S.view !== 'workload') return;
    e.preventDefault();
    const cell = e.target.closest('[data-wlcell]');
    const d = wlDrag;
    wlDrag = null;
    $$('.is-drop, .is-dragging', el.view).forEach((x) => x.classList.remove('is-drop', 'is-dragging'));
    const it = FWB.item(d.id);
    if (cell && it) workload.drop(it, d.from, cell.dataset.wlcell);
  });
  el.view.addEventListener('dragend', () => { if (S.view !== 'workload') return; wlDrag = null; $$('.is-drop, .is-dragging', el.view).forEach((x) => x.classList.remove('is-drop', 'is-dragging')); });
  el.view.addEventListener('click', (e) => {
    if (S.view !== 'workload') return;
    const more = e.target.closest('[data-wlmore]');
    if (more) {
      const [id, i] = more.dataset.wlmore.split(':');
      const items = workload.people.get(Number(id))[Number(i)].items;
      popover(more, `<div class="fwm-pop__title">${esc(Number(id) ? personName(id) : 'Unassigned')} · week of ${esc(fmtDate(ymd(workload.weeks[Number(i)])))}</div>${items.map((it) => `<button class="fwm-pop__item" data-v="${it.id}"><span class="fwp-dot" style="--c:${itemColor(it)}"></span><span style="min-width:0;overflow:hidden;text-overflow:ellipsis">${esc(it.title)}</span></button>`).join('')}`, { onClick: (b) => FWB.openItem(b.dataset.v) });
      return;
    }
    const b = e.target.closest('[data-wlid]');
    if (b) FWB.openItem(b.dataset.wlid);
  });
  FWB.registerView('workload', workload);

  // ===========================================================================
  // Chart (board dashboard)
  // ===========================================================================
  const svgNS = (w, h, body) => `<svg viewBox="0 0 ${w} ${h}" role="img" preserveAspectRatio="xMidYMid meet" style="max-width:${w}px">${body}</svg>`;
  function donut(parts, centre, sub) {
    const total = parts.reduce((n, p) => n + p.n, 0);
    const R = 62, r = 42, cx = 80, cy = 80;
    let a = -Math.PI / 2;
    const arcs = total ? parts.filter((p) => p.n).map((p) => {
      const da = (p.n / total) * Math.PI * 2;
      const a2 = a + da - (parts.filter((x) => x.n).length > 1 ? 0.025 : 0);
      const large = da > Math.PI ? 1 : 0;
      const pt = (rad, ang) => `${(cx + rad * Math.cos(ang)).toFixed(2)} ${(cy + rad * Math.sin(ang)).toFixed(2)}`;
      const d = da >= Math.PI * 2 - 0.001
        ? `M${cx} ${cy - R} A${R} ${R} 0 1 1 ${cx - 0.01} ${cy - R} L${cx - 0.01} ${cy - r} A${r} ${r} 0 1 0 ${cx} ${cy - r} Z`
        : `M${pt(R, a)} A${R} ${R} 0 ${large} 1 ${pt(R, a2)} L${pt(r, a2)} A${r} ${r} 0 ${large} 0 ${pt(r, a)} Z`;
      a += da;
      return `<path class="c-mark" d="${d}" fill="${p.color}"><title>${esc(p.label)}: ${p.n}</title></path>`;
    }).join('') : `<circle cx="${cx}" cy="${cy}" r="${(R + r) / 2}" fill="none" stroke="var(--line-strong)" stroke-width="${R - r}"/>`;
    const legend = parts.filter((p) => p.n).map((p) => `<div style="display:flex;align-items:center;gap:8px;font-size:12.5px;min-width:0"><span class="fwp-dot" style="--c:${p.color}"></span><span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(p.label)}">${esc(p.label)}</span><b style="font-variant-numeric:tabular-nums">${p.n}</b><small class="fwp-muted" style="width:34px;text-align:right">${total ? Math.round(p.n * 100 / total) : 0}%</small></div>`).join('');
    return `<div style="display:grid;grid-template-columns:160px minmax(0,1fr);gap:18px;align-items:center">
      ${svgNS(160, 160, `${arcs}<text x="80" y="80" text-anchor="middle" class="c-center">${esc(centre)}</text><text x="80" y="100" text-anchor="middle" class="c-center-sub">${esc(sub)}</text>`)}
      <div style="display:flex;flex-direction:column;gap:7px">${legend || '<span class="fwp-muted">Nothing yet.</span>'}</div></div>`;
  }
  /** Horizontal bars (HTML, so text stays crisp at any width); rows [{label, parts:[{n,color,label}], value?}] */
  function hbars(rows, fmt = (n) => String(n)) {
    if (!rows.length) return '<div class="fwp-panelcard__empty">Nothing to show.</div>';
    const max = Math.max(1, ...rows.map((r) => r.parts.reduce((n, p) => n + Math.max(0, p.n), 0)));
    return `<div class="fwp-hbars">${rows.map((r) => {
      const total = r.parts.reduce((n, p) => n + Math.max(0, p.n), 0);
      return `<div class="fwp-hbar"><span class="fwp-hbar__label" title="${esc(r.label)}">${esc(r.label)}</span>
        <span class="fwp-hbar__track">${r.parts.filter((p) => p.n > 0).map((p) => `<i style="--c:${p.color};width:${(p.n * 100 / max).toFixed(2)}%" title="${esc((p.label ? p.label + ': ' : '') + fmt(p.n))}"></i>`).join('')}</span>
        <b class="fwp-hbar__value">${esc(r.value != null ? r.value : fmt(total))}</b></div>`;
    }).join('')}</div>`;
  }
  const chart = {
    numCol() { const id = prefs.chart && prefs.chart.num; return (id && FWB.col(id)) || S.columns.find((c) => c.type === 'number' || c.type === 'formula') || null; },
    tools(box) { box.innerHTML = `<span class="fwp-muted" style="font-size:12px">${FWB.isFiltered() ? 'Charts follow your filters' : 'Every ' + esc(FWB.noun()) + ' on the board'}</span>`; },
    render(v) {
      const list = FWB.filtered();
      if (!list.length) { v.innerHTML = emptyView('Nothing to chart yet', FWB.isFiltered() ? 'No items match the filters.' : `Add ${FWB.noun()}s to the board and their numbers show here.`); return; }
      const ps = FWB.primaryStatus(), pc = FWB.primaryPeople(), pr = FWB.colsOf('priority')[0], dc = FWB.primaryDate(), nc = this.numCol();
      const cards = [];
      // 1. status
      if (ps) {
        const parts = ps.config.labels.map((l) => ({ label: l.label, color: safeColor(l.color), n: list.filter((it) => { const x = C.statusOf(ps, it.values[ps.id]); return x && x.key === l.key; }).length }));
        const none = list.filter((it) => !C.statusOf(ps, it.values[ps.id])).length;
        if (none) parts.push({ label: 'No status', color: '#bfb3a6', n: none });
        const done = list.filter((it) => it._done).length;
        cards.push(`<div class="fwp-chart"><h4>${icon('status')}${esc(ps.name)}</h4><p>${done} of ${plural(list.length, FWB.noun())} done</p>${donut(parts, Math.round(done * 100 / list.length) + '%', 'done')}</div>`);
      }
      // 2. by group
      const doneColor = ps ? safeColor((ps.config.labels.find((l) => l.done) || {}).color, '#2f8a5f') : '#2f8a5f';
      const groupRows = S.groups.map((g) => { const items = list.filter((it) => it.group_id === g.id); const d = items.filter((it) => it._done).length; return { label: g.name, parts: [{ n: d, color: doneColor, label: 'Done' }, { n: items.length - d, color: safeColor(g.color), label: 'Open' }], value: `${d}/${items.length}` }; }).filter((r) => r.parts[0].n + r.parts[1].n);
      cards.push(`<div class="fwp-chart"><h4>${icon('layers')}By group</h4><p>Done and still open in each group</p>${hbars(groupRows)}</div>`);
      // 3. people
      if (pc) {
        const by = new Map();
        list.forEach((it) => ((it.values[pc.id] || []).length ? it.values[pc.id] : [0]).forEach((id) => { const r = by.get(id) || { open: 0, done: 0 }; if (it._done) r.done++; else r.open++; by.set(id, r); }));
        const rows = Array.from(by.entries()).sort((a, b) => (b[1].open - a[1].open) || (b[1].done - a[1].done)).slice(0, 10)
          .map(([id, r]) => ({ label: id ? personName(id) : 'Unassigned', parts: [{ n: r.open, color: '#d39a32', label: 'Open' }, { n: r.done, color: doneColor, label: 'Done' }], value: `${r.open} open` }));
        cards.push(`<div class="fwp-chart"><h4>${icon('users')}${esc(pc.name)}</h4><p>Open work per person</p>${hbars(rows)}</div>`);
      }
      // 4. due (a date column, else a timeline's end)
      const due = dc || FWB.colsOf('timeline')[0];
      if (due) {
        const t = today();
        const end = ymd(addDays(parseYmd(t), 7));
        const open = list.filter((it) => !it._done);
        const b = { overdue: 0, week: 0, later: 0, none: 0 };
        open.forEach((it) => { const v0 = it.values[due.id]; const d = v0 && due.type === 'timeline' ? v0.end : v0; if (!d) b.none++; else if (d < t) b.overdue++; else if (d <= end) b.week++; else b.later++; });
        cards.push(`<div class="fwp-chart"><h4>${icon('clock')}${esc(due.name)}${due.type === 'timeline' ? ' <small class="fwp-muted" style="font-family:var(--font-ui);font-size:12px">ends</small>' : ''}</h4><p>${plural(open.length, 'open ' + FWB.noun())}${b.overdue ? ` · <b style="color:var(--danger)">${b.overdue} overdue</b>` : ''}</p>${hbars([
          { label: 'Overdue', parts: [{ n: b.overdue, color: '#c2412f' }] }, { label: 'Next 7 days', parts: [{ n: b.week, color: '#d39a32' }] },
          { label: 'Later', parts: [{ n: b.later, color: '#2f5c84' }] }, { label: 'No date', parts: [{ n: b.none, color: '#bfb3a6' }] }])}</div>`);
      }
      // 5. a number per group
      if (nc) {
        const nums = S.columns.filter((c) => c.type === 'number' || c.type === 'formula');
        const rows = S.groups.map((g) => { const items = list.filter((it) => it.group_id === g.id); const s = items.reduce((n, it) => n + (typeof it.values[nc.id] === 'number' ? it.values[nc.id] : 0), 0); return { label: g.name, parts: [{ n: s, color: safeColor(g.color), label: nc.name }], value: C.numText(nc, s) + (nc.config.unit && nc.config.unit_pos === 'suffix' ? ' ' + nc.config.unit : '') }; }).filter((r) => r.parts[0].n);
        const total = list.reduce((n, it) => n + (typeof it.values[nc.id] === 'number' ? it.values[nc.id] : 0), 0);
        cards.push(`<div class="fwp-chart fwp-chart--wide"><h4>${icon('hash')}<span>${esc(nc.name)} by group</span>${nums.length > 1 ? `<select class="select" id="chartNum" aria-label="Number column">${nums.map((c) => `<option value="${c.id}" ${c.id === nc.id ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select>` : ''}</h4>
          <p>Total ${C.unitWrap(nc, esc(C.numText(nc, total)))}</p>${hbars(rows, (n) => C.numText(nc, n))}</div>`);
      }
      // 6. priority
      if (pr) {
        const parts = C.PRIORITIES.map((p) => ({ label: p.label, color: p.color, n: list.filter((it) => !it._done && it.values[pr.id] === p.key).length }));
        cards.push(`<div class="fwp-chart"><h4>${icon('flag')}${esc(pr.name)}</h4><p>Open ${esc(FWB.noun())}s by priority</p>${donut(parts, String(parts[0].n + parts[1].n), 'urgent + high')}</div>`);
      }
      v.innerHTML = `<div class="fwp-charts">${cards.join('')}</div>`;
      const sel = $('#chartNum', v);
      if (sel) sel.addEventListener('change', () => { prefs.chart = Object.assign({}, prefs.chart || {}, { num: Number(sel.value) }); FWB.savePrefsSoon({ chart: prefs.chart }); FWB.renderView(); });
    },
  };
  FWB.registerView('chart', chart);
})();
