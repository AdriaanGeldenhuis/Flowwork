/* =============================================================================
   Flowwork Projects — board dialogs
   Column gallery and column settings (status labels, dropdown options,
   number formats, formulas, progress, ratings, date reminders), the filter
   builder, sort, hidden columns, saved views, sharing with guests,
   automations, board settings, CSV import, archived items, duplicating and
   moving the board. Sets window.FWB.dialogs.
   ============================================================================= */
(function () {
  'use strict';

  const FWB = window.FWB;
  if (!FWB) return;
  const {
    $, $$, esc, icon, debounce, api, toast, toastError, popover, closePop, dialog, tempModal, openModal, closeModal, P, personName, avatar,
    fmtDate, relTime, safeColor,
  } = window.FWP;
  const C = window.FWBCells;
  const S = FWB.S;
  const BOOT = FWB.BOOT;
  const TYPES = BOOT.types || {};
  const COLORS = ['#2f8a5f', '#d39a32', '#c2412f', '#2f5c84', '#7a5ab8', '#b4577a', '#1f7a7a', '#85766b', '#7a1f30', '#b08d57', '#4b4fa8', '#6b7d2b', '#c06a2b', '#3f8fb5', '#9e1f2e', '#5b6670'];
  const TEXT_FAMILY = ['text', 'longtext', 'email', 'phone', 'link'];
  const switchHTML = (id, title, hint, on) => `<label class="switch"><input type="checkbox" id="${id}" ${on ? 'checked' : ''}><span class="switch__track"></span><span class="switch__text"><span class="switch__title">${esc(title)}</span>${hint ? `<span class="hint">${esc(hint)}</span>` : ''}</span></label>`;
  const opt = (v, label, cur) => `<option value="${esc(v)}" ${String(v) === String(cur) ? 'selected' : ''}>${esc(label)}</option>`;
  /** Fold fresh column / items answers into the board. */
  function applyColumnAnswer(d, opts = {}) {
    if (d.column) { if (opts.insertAfter !== undefined) FWB.insertColumn(d.column, opts.insertAfter, opts.atStart); else FWB.replaceColumn(d.column); }
    if (d.items) FWB.applyItems(d.items);
    FWB.indexItems();
    FWB.renderTools();
    FWB.renderView();
    if (S.openItem && FWB.panel) FWB.panel.refresh(S.openItem, true);
  }
  function modal(title, body, foot, opts = {}) {
    const m = tempModal(`<div class="fwm-dialog__head"><h2>${esc(title)}</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <div class="fwm-dialog__body">${body}</div>${foot ? `<div class="fwm-dialog__foot">${foot}</div>` : ''}`, opts.wide ? 'fwm-dialog--wide' : '', opts.style || (opts.wide ? '' : 'width:min(560px,100%)'));
    return m;
  }

  // ===========================================================================
  // Columns
  // ===========================================================================
  /** The type gallery; a type opens its settings, ready to add. */
  function addColumn(anchor, afterId = 0, atStart = false, presetType = null) {
    if (!FWB.canEdit()) return;
    if (presetType && TYPES[presetType]) { columnSettings({ type: presetType, name: '' }, { create: true, after: afterId, atStart }); return; }
    const order = ['status', 'people', 'date', 'timeline', 'priority', 'number', 'formula', 'progress', 'text', 'longtext', 'dropdown', 'tags', 'checkbox', 'rating', 'files', 'link', 'email', 'phone', 'supplier', 'created', 'updated'];
    popover(anchor, `<div class="fwm-pop__title">Add a column</div><div class="fwp-types">${order.filter((t) => TYPES[t]).map((t) => `<button type="button" data-v="${t}"><span>${icon(TYPES[t].icon || 'text')}</span><span style="background:none;width:auto;height:auto;display:block;place-items:initial"><b>${esc(TYPES[t].label)}</b><small>${esc(TYPES[t].hint || '')}</small></span></button>`).join('')}</div>`, {
      align: 'end',
      cls: 'fwp-pop-wide',
      onClick: (b) => { columnSettings({ type: b.dataset.v, name: '' }, { create: true, after: afterId, atStart }); },
    });
  }

  function columnSettings(c, opts = {}) {
    if (!FWB.canEdit()) return;
    closePop();
    const create = !!opts.create;
    const type = c.type;
    const cfg = JSON.parse(JSON.stringify(c.config || {}));
    const T = TYPES[type] || { label: type };
    let labels = type === 'status' ? (cfg.labels && cfg.labels.length ? cfg.labels : [{ key: 'todo', label: 'To do', color: '#85766b' }, { key: 'working', label: 'Working on it', color: '#d39a32' }, { key: 'stuck', label: 'Stuck', color: '#c2412f' }, { key: 'done', label: 'Done', color: '#2f8a5f', done: true }]).map((l) => Object.assign({}, l)) : [];
    let options = type === 'dropdown' ? (cfg.options || []).map((o) => Object.assign({}, o)) : [];
    const others = S.columns.filter((x) => x.id !== c.id);
    let body = `<div class="fwp-form-grid">
      <div class="field"><label class="label" for="csName">Name</label><input class="input" id="csName" maxlength="100" value="${esc(c.name || '')}" placeholder="${esc(T.label)}"></div>
      ${TEXT_FAMILY.includes(type) && !create ? `<div class="field"><label class="label" for="csType">Type</label><select class="select" id="csType">${TEXT_FAMILY.map((t) => opt(t, TYPES[t].label, type)).join('')}</select></div>` : `<div class="field"><span class="label">Type</span><div style="display:flex;align-items:center;gap:8px;height:40px">${icon(T.icon || 'text')}<b style="font-weight:500">${esc(T.label)}</b></div></div>`}
      <div class="field is-wide"><label class="label" for="csDesc">Description <small style="text-transform:none;letter-spacing:0;font-weight:400">— shows when people hover the column</small></label><input class="input" id="csDesc" maxlength="255" value="${esc(c.description || '')}" placeholder="Optional"></div></div>`;
    if (type === 'status') body += `<div class="field" style="margin-top:18px"><span class="label">Labels</span><div class="hint">Drag to reorder. Labels marked <b>Done</b> count as finished — in progress, My work and reminders.</div><div class="fwp-labels" id="csLabels" style="margin-top:8px"></div>
      <button class="btn btn--sm btn--ghost" type="button" id="csAddLabel" style="margin-top:8px;align-self:flex-start">${icon('plus')}Add label</button></div>`;
    if (type === 'dropdown') body += `<div class="field" style="margin-top:18px"><span class="label">Options</span><div class="fwp-labels" id="csOpts" style="margin-top:4px"></div>
      <button class="btn btn--sm btn--ghost" type="button" id="csAddOpt" style="margin-top:8px;align-self:flex-start">${icon('plus')}Add option</button>
      ${switchHTML('csMulti', 'Allow several options', 'People can pick more than one.', cfg.multi)}</div>`;
    if (type === 'number' || type === 'formula') {
      if (type === 'formula') {
        body += `<div class="field" style="margin-top:18px"><label class="label" for="csFormula">Formula</label>
          <textarea class="textarea" id="csFormula" maxlength="1000" style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;min-height:84px" placeholder="{Qty} * {Rate}">${esc(cfg.formula || '')}</textarea>
          <div class="hint">Click a column to insert it. Functions: SUM, AVG, MIN, MAX, ROUND, ROUNDUP, ROUNDDOWN, ABS, IF, AND, OR, NOT, TODAY(), DAYS(end, start), WORKDAYS(start, end), CONCAT. Timelines: {Timeline.start}, {Timeline.end}.</div>
          <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px" id="csChips">${others.filter((x) => !['files', 'created', 'updated'].includes(x.type)).map((x) => `<button class="fwm-tag fwm-tag--plain" type="button" data-ins="${esc(x.name)}${x.type === 'timeline' ? '.start' : ''}" style="cursor:pointer;border:0">${icon(C.TYPE_ICON[x.type] || 'text', 'i--xs')}${esc(x.name)}</button>`).join('')}</div></div>`;
      }
      body += `<div class="fwp-form-grid" style="margin-top:18px">
        <div class="field"><label class="label" for="csFormat">Format</label><select class="select" id="csFormat">${opt('decimal', 'Number', cfg.format)}${opt('currency', 'Money', cfg.format)}${opt('percent', 'Percent', cfg.format)}${type === 'number' ? opt('integer', 'Whole number', cfg.format) : ''}</select></div>
        <div class="field"><label class="label" for="csPrec">Decimals</label><select class="select" id="csPrec">${[0, 1, 2, 3, 4].map((n) => opt(n, String(n), cfg.precision != null ? cfg.precision : 2)).join('')}</select></div>
        <div class="field"><label class="label" for="csUnit">Unit</label><input class="input" id="csUnit" maxlength="8" value="${esc(cfg.unit || '')}" placeholder="R, m², kg, h…"></div>
        <div class="field"><label class="label" for="csUnitPos">Unit goes</label><select class="select" id="csUnitPos">${opt('prefix', 'Before the number', cfg.unit_pos)}${opt('suffix', 'After the number', cfg.unit_pos)}</select></div>
        <div class="field is-wide"><label class="label" for="csAgg">Group summary</label><select class="select" id="csAgg">${[['sum', 'Sum'], ['avg', 'Average'], ['min', 'Lowest'], ['max', 'Highest'], ['count', 'Count'], ['none', 'None']].map(([v, l]) => opt(v, l, cfg.agg || 'sum')).join('')}</select></div></div>`;
    }
    if (type === 'progress') {
      const statusCols = S.columns.filter((x) => x.type === 'status');
      const src = new Set((cfg.sources || []).map(Number));
      body += `<div class="field" style="margin-top:18px"><span class="label">Progress comes from</span>
        <div class="fwm-results" style="margin-top:4px">
          ${[['manual', 'Typed in', 'People drag a slider on each item.'], ['status', 'Status columns', 'Each chosen status column that reaches a Done label adds its share.'], ['subitems', 'Subitems', 'Done subitems out of all subitems.']].map(([v, l, h]) => `<label class="fwm-result"><input type="radio" name="csMode" value="${v}" ${(cfg.mode || 'manual') === v ? 'checked' : ''} style="accent-color:var(--accent)"><span class="fwm-result__text"><b>${l}</b><small>${h}</small></span></label>`).join('')}
        </div>
        <div id="csSources" style="margin-top:8px;${(cfg.mode || 'manual') === 'status' ? '' : 'display:none'}">${statusCols.length ? statusCols.map((x) => `<label class="switch" style="padding:4px 0"><input type="checkbox" data-src="${x.id}" ${src.has(x.id) || !src.size ? 'checked' : ''}><span class="switch__track"></span><span class="switch__text"><span class="switch__title">${esc(x.name)}</span></span></label>`).join('') : '<div class="hint">Add a status column first.</div>'}</div></div>`;
    }
    if (type === 'rating') body += `<div class="field" style="margin-top:18px"><label class="label" for="csMax">Stars</label><select class="select" id="csMax" style="max-width:160px">${[3, 4, 5, 6, 7, 8, 9, 10].map((n) => opt(n, n + ' stars', cfg.max || 5)).join('')}</select></div>`;
    if (type === 'date') body += `<div style="margin-top:14px">${switchHTML('csRem', 'Due-date reminders', 'People assigned to an item hear about it the day before and on the day.', cfg.reminders !== false)}</div>`;
    const m = modal(create ? `New ${T.label.toLowerCase()} column` : `${c.name} settings`, body,
      `${!create ? `<button class="btn btn--ghost" type="button" data-cs="delete" style="color:var(--danger)">${icon('trash')}Delete</button><span class="grow"></span>` : '<span class="grow"></span>'}<button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" data-cs="save">${create ? 'Add column' : 'Save'}</button>`,
      { style: `width:min(${type === 'formula' || type === 'status' ? 620 : 540}px,100%)` });

    // ---- label / option editors
    const rowsHost = $('#csLabels', m) || $('#csOpts', m);
    const list = () => (type === 'status' ? labels : options);
    function renderRows() {
      if (!rowsHost) return;
      rowsHost.innerHTML = list().map((l, i) => `<div class="fwp-label-row" data-i="${i}" draggable="true">
        <span class="fwp-grip" style="opacity:1;cursor:grab">${icon('grip', 'i--sm')}</span>
        <button class="fwp-colorbtn" type="button" data-color="${i}" style="--c:${safeColor(l.color)}" aria-label="Colour"></button>
        <input class="input" data-lbl="${i}" maxlength="${type === 'status' ? 60 : 80}" value="${esc(l.label)}" placeholder="${type === 'status' ? 'Label' : 'Option'}">
        ${type === 'status' ? `<label class="switch" title="Counts as finished"><input type="checkbox" data-done="${i}" ${l.done ? 'checked' : ''}><span class="switch__track"></span><span class="switch__text" style="font-size:12px">Done</span></label>` : '<span></span>'}
        <button class="icon-btn icon-btn--sm" type="button" data-rm="${i}" aria-label="Remove">${icon('x', 'i--sm')}</button></div>`).join('');
    }
    renderRows();
    if (rowsHost) {
      rowsHost.addEventListener('input', (e) => { const i = e.target.dataset.lbl; if (i != null) list()[i].label = e.target.value; });
      rowsHost.addEventListener('change', (e) => { const i = e.target.dataset.done; if (i != null) labels[i].done = e.target.checked; });
      rowsHost.addEventListener('click', (e) => {
        const rm = e.target.closest('[data-rm]');
        if (rm) { list().splice(Number(rm.dataset.rm), 1); renderRows(); return; }
        const cb = e.target.closest('[data-color]');
        if (cb) {
          const i = Number(cb.dataset.color);
          popover(cb, `<div class="fwp-palette">${COLORS.map((x) => `<button type="button" data-v="${x}" style="--c:${x}" class="${list()[i].color === x ? 'is-on' : ''}" aria-label="${x}"></button>`).join('')}</div>`, { onClick: (b) => { list()[i].color = b.dataset.v; renderRows(); } });
        }
      });
      let dragI = null;
      rowsHost.addEventListener('dragstart', (e) => { const r = e.target.closest('[data-i]'); if (!r || e.target.closest('input')) return; dragI = Number(r.dataset.i); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', 'row'); });
      rowsHost.addEventListener('dragover', (e) => { if (dragI != null && e.target.closest('[data-i]')) e.preventDefault(); });
      rowsHost.addEventListener('drop', (e) => {
        const r = e.target.closest('[data-i]');
        if (dragI == null || !r) return;
        e.preventDefault();
        const to = Number(r.dataset.i);
        const [x] = list().splice(dragI, 1);
        list().splice(to, 0, x);
        dragI = null;
        renderRows();
      });
    }
    const addBtn = $('#csAddLabel', m) || $('#csAddOpt', m);
    if (addBtn) addBtn.addEventListener('click', () => {
      list().push({ label: '', color: COLORS[list().length % COLORS.length] });
      renderRows();
      const inputs = $$('[data-lbl]', rowsHost);
      if (inputs.length) inputs[inputs.length - 1].focus();
    });
    // ---- formula chips
    const fx = $('#csFormula', m);
    if (fx) $('#csChips', m).addEventListener('click', (e) => {
      const b = e.target.closest('[data-ins]');
      if (!b) return;
      const ins = '{' + b.dataset.ins + '}';
      const p = fx.selectionStart != null ? fx.selectionStart : fx.value.length;
      fx.value = fx.value.slice(0, p) + ins + fx.value.slice(fx.selectionEnd || p);
      fx.focus();
      fx.setSelectionRange(p + ins.length, p + ins.length);
    });
    // ---- progress mode
    $$('input[name=csMode]', m).forEach((r) => r.addEventListener('change', () => { const s = $('#csSources', m); if (s) s.style.display = r.value === 'status' && r.checked ? '' : 'none'; }));

    function collect() {
      const out = { name: $('#csName', m).value.trim() || T.label, description: $('#csDesc', m).value.trim() };
      const tsel = $('#csType', m);
      if (tsel && tsel.value !== type) out.type = tsel.value;
      let conf = null;
      if (type === 'status') {
        const clean = labels.filter((l) => String(l.label || '').trim());
        if (!clean.length) throw new Error('Keep at least one label.');
        // keep existing keys so items keep their status; new labels use their text
        conf = { labels: clean.map((l) => ({ key: l.key || l.label.trim(), label: l.label.trim(), color: l.color, done: !!l.done })) };
      }
      if (type === 'dropdown') conf = { options: options.filter((o) => String(o.label || '').trim()).map((o) => ({ label: o.label.trim(), color: o.color })), multi: $('#csMulti', m).checked };
      if (type === 'number' || type === 'formula') {
        conf = { format: $('#csFormat', m).value, precision: Number($('#csPrec', m).value), unit: $('#csUnit', m).value.trim(), unit_pos: $('#csUnitPos', m).value, agg: $('#csAgg', m).value };
        if (type === 'formula') { conf.formula = fx.value.trim(); if (!conf.formula) throw new Error('Write the formula first — for example {Qty} * {Rate}.'); }
      }
      if (type === 'progress') {
        const mode = ($('input[name=csMode]:checked', m) || {}).value || 'manual';
        conf = { mode, sources: mode === 'status' ? $$('[data-src]', m).filter((x) => x.checked).map((x) => Number(x.dataset.src)) : [] };
      }
      if (type === 'rating') conf = { max: Number($('#csMax', m).value) };
      if (type === 'date') conf = { reminders: $('#csRem', m).checked };
      if (conf) out.config = conf;
      return out;
    }
    m.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-cs]');
      if (!b) return;
      if (b.dataset.cs === 'delete') {
        closeModal(m);
        if (!(await dialog({ title: `Delete “${c.name}”?`, body: 'Its values on every item are deleted too. This cannot be undone.', confirm: 'Delete column', danger: true }))) return;
        try { const d = await api.post('columns.php', { action: 'delete', id: c.id }); S.columns = S.columns.filter((x) => x.id !== c.id); applyColumnAnswer(d); toast('Column deleted'); } catch (x) { toastError(x); }
        return;
      }
      let data;
      try { data = collect(); } catch (x) { toast(x.message, { type: 'error' }); return; }
      b.classList.add('is-busy');
      try {
        if (create) {
          const d = await api.post('columns.php', Object.assign({ action: 'create', board_id: S.board.id, type, after: opts.atStart ? 0 : opts.after || 0 }, data));
          if (opts.atStart) {
            const ids = [d.column.id].concat(S.columns.map((x) => x.id));
            api.post('columns.php', { action: 'reorder', board_id: S.board.id, ids }).catch(() => {});
          }
          applyColumnAnswer(d, { insertAfter: opts.atStart ? 0 : (opts.after || (S.columns.length ? S.columns[S.columns.length - 1].id : 0)), atStart: !!opts.atStart });
          toast(`${d.column.name} added`);
        } else {
          const patch = { action: 'update', id: c.id };
          if (data.name !== c.name) patch.name = data.name;
          if (data.description !== (c.description || '')) patch.description = data.description;
          if (data.type) patch.type = data.type;
          if (data.config) patch.config = data.config;
          const d = await api.post('columns.php', patch);
          applyColumnAnswer(d);
          toast('Column saved');
        }
        closeModal(m);
      } catch (x) { toastError(x); } finally { b.classList.remove('is-busy'); }
    });
    openModal(m);
  }

  // ===========================================================================
  // Filters
  // ===========================================================================
  const OPS = {
    choice: [['is', 'is'], ['is_not', 'is not'], ['empty', 'is empty'], ['not_empty', 'is not empty']],
    date: [['is', 'is on'], ['before', 'is before'], ['after', 'is after'], ['overdue', 'is overdue'], ['today', 'is today'], ['this_week', 'is this week'], ['next_week', 'is next week'], ['past', 'is in the past'], ['future', 'is in the future'], ['empty', 'is empty'], ['not_empty', 'is not empty']],
    number: [['=', '='], ['!=', '≠'], ['>', '>'], ['<', '<'], ['>=', '≥'], ['<=', '≤'], ['empty', 'is empty'], ['not_empty', 'is not empty']],
    text: [['contains', 'contains'], ['not_contains', 'does not contain'], ['empty', 'is empty'], ['not_empty', 'is not empty']],
    check: [['checked', 'is checked'], ['unchecked', 'is not checked']],
    group: [['is', 'is'], ['is_not', 'is not']],
  };
  function opsFor(colKey) {
    if (colKey === 'group') return OPS.group;
    if (colKey === 'name') return OPS.text.slice(0, 2);
    const c = FWB.col(colKey);
    if (!c) return OPS.text;
    if (['status', 'priority', 'people', 'dropdown', 'supplier'].includes(c.type)) return OPS.choice;
    if (['date', 'timeline'].includes(c.type)) return OPS.date;
    if (['number', 'formula', 'progress', 'rating'].includes(c.type)) return OPS.number;
    if (c.type === 'checkbox') return OPS.check;
    return OPS.text;
  }
  function choicesFor(colKey) {
    if (colKey === 'group') return S.groups.map((g) => [g.id, g.name]);
    const c = FWB.col(colKey);
    if (!c) return null;
    if (c.type === 'status') return c.config.labels.map((l) => [l.key, l.label]);
    if (c.type === 'priority') return C.PRIORITIES.map((p) => [p.key, p.label]);
    if (c.type === 'dropdown') return (c.config.options || []).map((o) => [o.label, o.label]);
    if (c.type === 'supplier') return Array.from(new Set(S.items.map((it) => it.values[c.id]).filter(Boolean))).map((id) => [id, C.supplierName(id)]);
    if (c.type === 'people') {
      const ids = new Set(S.items.flatMap((it) => it.values[c.id] || []));
      return [['me', 'Me']].concat(Array.from(ids).map((id) => [id, personName(id)]).sort((a, b) => a[1].localeCompare(b[1])));
    }
    return null;
  }
  function valueControl(r, i) {
    if (['empty', 'not_empty', 'overdue', 'today', 'this_week', 'next_week', 'past', 'future', 'checked', 'unchecked'].includes(r.op)) return '<span></span>';
    const ch = choicesFor(r.col);
    if (ch) {
      const cur = Array.isArray(r.value) ? r.value[0] : r.value;
      return `<select class="select" data-fval="${i}"><option value="">Choose…</option>${ch.map(([v, l]) => opt(v, l, cur)).join('')}</select>`;
    }
    const c = FWB.col(r.col);
    if (c && ['date', 'timeline'].includes(c.type)) return `<input class="input" type="date" data-fval="${i}" value="${esc(r.value || '')}">`;
    if (c && ['number', 'formula', 'progress', 'rating'].includes(c.type)) return `<input class="input" type="number" step="any" data-fval="${i}" value="${esc(r.value == null ? '' : r.value)}">`;
    return `<input class="input" data-fval="${i}" value="${esc(r.value || '')}" placeholder="Value">`;
  }
  function filters(anchor, colId) {
    if (!anchor) return;
    if (colId && !S.rules.some((r) => String(r.col) === String(colId))) S.rules.push({ col: colId, op: opsFor(colId)[0][0], value: null });
    const cols = [['name', FWB.Noun() + ' name'], ['group', 'Group']].concat(S.columns.filter((c) => !['files', 'created', 'updated'].includes(c.type)).map((c) => [c.id, c.name]));
    let pop;
    const apply = () => {
      S.activeView = 0;
      FWB.savePrefsSoon({ filters: S.rules.filter(complete) });
      FWB.renderView();
      const fb = $('#filterBtn');
      if (fb) { fb.classList.toggle('is-on', S.rules.length > 0); const badge = $('.fwp-badge', fb); if (badge) badge.textContent = S.rules.length; else if (S.rules.length) fb.insertAdjacentHTML('beforeend', `<span class="fwp-badge">${S.rules.length}</span>`); if (!S.rules.length && badge) badge.remove(); }
    };
    const applySoon = debounce(apply, 250);
    const render = () => {
      $('.fwp-frules', pop.el).innerHTML = S.rules.length ? S.rules.map((r, i) => `<div class="fwp-frule">
          <span>${i === 0 ? 'Where' : i === 1 ? `<select class="select" data-fmatch style="height:30px;padding:0 20px 0 6px;font-size:12px">${opt('all', 'and', S.match)}${opt('any', 'or', S.match)}</select>` : (S.match === 'any' ? 'or' : 'and')}</span>
          <select class="select" data-fcol="${i}">${cols.map(([v, l]) => opt(v, l, r.col)).join('')}</select>
          <select class="select" data-fop="${i}">${opsFor(r.col).map(([v, l]) => opt(v, l, r.op)).join('')}</select>
          ${valueControl(r, i)}
          <button class="icon-btn icon-btn--sm" type="button" data-frm="${i}" aria-label="Remove filter">${icon('x', 'i--sm')}</button></div>`).join('')
        : `<div class="hint" style="padding:6px 4px 10px">Show only the ${esc(FWB.noun())}s that match. Filters are yours — others see the board as they left it.</div>`;
    };
    pop = popover(anchor, `<div class="fwp-filters"><div class="fwm-pop__title" style="padding-left:2px">Filter ${esc(FWB.noun())}s</div><div class="fwp-frules"></div>
      <div style="display:flex;gap:6px;margin-top:6px"><button class="btn btn--sm btn--ghost" type="button" data-fadd>${icon('plus')}Add filter</button><span style="flex:1"></span>${S.rules.length ? `<button class="btn btn--sm btn--ghost" type="button" data-fclear>Clear all</button>` : ''}</div></div>`, { role: 'dialog', noFocus: true, cls: 'fwp-pop-wide' });
    render();
    pop.el.addEventListener('click', (e) => {
      if (e.target.closest('[data-fadd]')) { S.rules.push({ col: 'name', op: 'contains', value: '' }); render(); const inputs = $$('[data-fcol]', pop.el); if (inputs.length) inputs[inputs.length - 1].focus(); return; }
      if (e.target.closest('[data-fclear]')) { S.rules = []; render(); apply(); return; }
      const rm = e.target.closest('[data-frm]');
      if (rm) { S.rules.splice(Number(rm.dataset.frm), 1); render(); apply(); }
    });
    pop.el.addEventListener('change', (e) => {
      const t = e.target;
      if (t.matches('[data-fmatch]')) { S.match = t.value; render(); apply(); return; }
      if (t.matches('[data-fcol]')) { const r = S.rules[t.dataset.fcol]; r.col = /^\d+$/.test(t.value) ? Number(t.value) : t.value; r.op = opsFor(r.col)[0][0]; r.value = null; render(); apply(); return; }
      if (t.matches('[data-fop]')) { S.rules[t.dataset.fop].op = t.value; render(); apply(); return; }
      if (t.matches('select[data-fval]')) {
        const r = S.rules[t.dataset.fval];
        const v = t.value;
        const c = FWB.col(r.col);
        // group ids, people and suppliers are numbers; status keys and dropdown options stay text
        const numeric = r.col === 'group' || (c && ['people', 'supplier'].includes(c.type));
        r.value = v === '' ? [] : [numeric && /^\d+$/.test(v) ? Number(v) : v];
        apply();
        return;
      }
      if (t.matches('input[data-fval]')) { S.rules[t.dataset.fval].value = t.value; apply(); }
    });
    pop.el.addEventListener('input', (e) => { if (e.target.matches('input[data-fval]')) { S.rules[e.target.dataset.fval].value = e.target.value; applySoon(); } });
  }
  const complete = (r) => r && r.col != null && r.op;

  // ===========================================================================
  // Sort, hide
  // ===========================================================================
  function sort(anchor) {
    const cols = [['name', FWB.Noun() + ' name']].concat(S.columns.filter((c) => !['files'].includes(c.type)).map((c) => [c.id, c.name]));
    let pop;
    const apply = () => { S.activeView = 0; FWB.savePrefsSoon({ sort: S.sort }); FWB.renderView(); const sb = $('#sortBtn'); if (sb) sb.classList.toggle('is-on', S.sort.length > 0); };
    const render = () => {
      $('.fwp-srules', pop.el).innerHTML = S.sort.length ? S.sort.map((s, i) => `<div class="fwp-frule" style="grid-template-columns:54px minmax(0,1fr) minmax(0,.8fr) 30px">
          <span>${i === 0 ? 'Sort by' : 'then'}</span><select class="select" data-scol="${i}">${cols.map(([v, l]) => opt(v, l, s.col)).join('')}</select>
          <select class="select" data-sdir="${i}">${opt('asc', 'Ascending', s.dir)}${opt('desc', 'Descending', s.dir)}</select>
          <button class="icon-btn icon-btn--sm" type="button" data-srm="${i}" aria-label="Remove">${icon('x', 'i--sm')}</button></div>`).join('')
        : `<div class="hint" style="padding:6px 4px 10px">${esc(FWB.Noun())}s keep the order you dragged them into.</div>`;
    };
    pop = popover(anchor, `<div class="fwp-filters" style="width:440px"><div class="fwm-pop__title" style="padding-left:2px">Sort</div><div class="fwp-srules"></div>
      <div style="display:flex;gap:6px;margin-top:6px"><button class="btn btn--sm btn--ghost" type="button" data-sadd>${icon('plus')}Add sort</button></div></div>`, { role: 'dialog', noFocus: true, cls: 'fwp-pop-wide' });
    render();
    pop.el.addEventListener('click', (e) => {
      if (e.target.closest('[data-sadd]')) { const used = new Set(S.sort.map((s) => String(s.col))); const next = cols.find(([v]) => !used.has(String(v))); if (next) S.sort.push({ col: next[0], dir: 'asc' }); render(); apply(); return; }
      const rm = e.target.closest('[data-srm]');
      if (rm) { S.sort.splice(Number(rm.dataset.srm), 1); render(); apply(); }
    });
    pop.el.addEventListener('change', (e) => {
      const t = e.target;
      if (t.matches('[data-scol]')) { S.sort[t.dataset.scol].col = t.value === 'name' ? 'name' : Number(t.value); apply(); }
      if (t.matches('[data-sdir]')) { S.sort[t.dataset.sdir].dir = t.value; apply(); }
    });
  }
  function hide(anchor) {
    let pop;
    const apply = () => { S.activeView = 0; FWB.savePrefsSoon({ hidden: Array.from(S.hidden) }); FWB.renderView(); const hb = $('#hideBtn'); if (hb) hb.classList.toggle('is-on', S.hidden.size > 0); };
    const render = (q = '') => {
      const ql = q.toLowerCase();
      $('.fwp-colsel', pop.el).innerHTML = S.columns.filter((c) => !ql || c.name.toLowerCase().includes(ql)).map((c) => `<label>${icon(C.TYPE_ICON[c.type] || 'text')}<span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis">${esc(c.name)}</span>
        <span class="switch" style="padding:0"><input type="checkbox" data-hcol="${c.id}" ${S.hidden.has(c.id) ? '' : 'checked'}><span class="switch__track"></span></span></label>`).join('');
    };
    pop = popover(anchor, `<div class="fwp-pick" style="width:300px"><div class="fwm-pop__title">Columns you see</div><input class="fwm-pop__input" placeholder="Find a column" aria-label="Find a column">
      <div class="fwp-colsel"></div><div class="fwp-pick__foot"><button class="btn btn--sm btn--ghost" type="button" data-hall="show">Show all</button><button class="btn btn--sm btn--ghost" type="button" data-hall="hide">Hide all</button></div></div>`, { role: 'dialog' });
    render();
    const inp = $('.fwm-pop__input', pop.el);
    inp.addEventListener('input', () => render(inp.value.trim()));
    pop.el.addEventListener('change', (e) => { const id = Number(e.target.dataset.hcol); if (!id) return; if (e.target.checked) S.hidden.delete(id); else S.hidden.add(id); apply(); });
    pop.el.addEventListener('click', (e) => { const b = e.target.closest('[data-hall]'); if (!b) return; S.hidden = new Set(b.dataset.hall === 'hide' ? S.columns.map((c) => c.id) : []); render(inp.value.trim()); apply(); });
  }

  // ===========================================================================
  // Saved views
  // ===========================================================================
  const currentConfig = () => ({ filters: S.rules.filter(complete), match: S.match, sort: S.sort, hidden: Array.from(S.hidden), person: S.person || null });
  function views(anchor) {
    const mine = S.views;
    popover(anchor, `<div class="fwp-pick" style="width:320px"><div class="fwm-pop__title">Saved views</div>
      ${mine.length ? mine.map((v) => `<div style="display:flex;align-items:center;gap:2px"><button class="fwm-pop__item ${v.id === S.activeView ? 'is-on' : ''}" data-v="open:${v.id}" style="flex:1">${icon((FWB.VIEW_DEFS.find((x) => x[0] === v.view) || [0, 'table'])[1])}<span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis">${esc(v.name)}</span>${v.shared ? `<small title="Shared with the board">${icon('users', 'i--xs')}</small>` : ''}</button>
          ${v.mine || FWB.canManage() ? `<button class="icon-btn icon-btn--sm" type="button" data-v="menu:${v.id}" aria-label="View options">${icon('more-h', 'i--sm')}</button>` : ''}</div>`).join('') : '<div class="hint" style="padding:4px 10px 10px">Save the filters, sort, hidden columns and view you have now, and come back to them in one click.</div>'}
      <div class="fwm-pop__sep"></div>
      <button class="fwm-pop__item" data-v="save">${icon('plus')}Save current view…</button>
      ${S.activeView ? `<button class="fwm-pop__item" data-v="update">${icon('refresh')}Update “${esc((mine.find((x) => x.id === S.activeView) || {}).name || '')}”</button>` : ''}
      ${S.activeView || FWB.isFiltered() || S.sort.length || S.hidden.size ? `<button class="fwm-pop__item" data-v="reset">${icon('eraser')}Reset to the whole board</button>` : ''}</div>`, {
      onClick: async (b) => {
        const [k, id] = b.dataset.v.split(':');
        if (k === 'open') applyView(Number(id));
        if (k === 'save') saveView();
        if (k === 'update') {
          const v = mine.find((x) => x.id === S.activeView);
          if (v) try { const d = await api.post('views.php', { action: 'save', board_id: S.board.id, id: v.id, name: v.name, view: S.view, shared: v.shared, config: currentConfig() }); S.views = d.views; toast('View updated'); } catch (x) { toastError(x); }
        }
        if (k === 'reset') { S.rules = []; S.sort = []; S.hidden = new Set(); S.person = null; S.activeView = 0; FWB.savePrefsSoon({ filters: [], sort: [], hidden: [], person: null }); FWB.renderTools(); FWB.renderView(); }
        if (k === 'menu') { setTimeout(() => viewMenu(anchor, mine.find((x) => x.id === Number(id))), 0); }
      },
    });
  }
  function viewMenu(anchor, v) {
    if (!v) return;
    popover(anchor, `<div class="fwm-pop__title">${esc(v.name)}</div><button class="fwm-pop__item" data-v="rename">${icon('pen')}Rename</button>
      ${FWB.canEdit() ? `<button class="fwm-pop__item" data-v="share">${icon(v.shared ? 'lock' : 'users')}${v.shared ? 'Make it just mine' : 'Share with the board'}</button>` : ''}
      <button class="fwm-pop__item" data-v="link">${icon('link')}Copy link</button>
      <button class="fwm-pop__item is-danger" data-v="delete">${icon('trash')}Delete</button>`, {
      onClick: async (b) => {
        const k = b.dataset.v;
        try {
          if (k === 'rename') {
            const name = await dialog({ title: 'Rename view', input: { value: v.name, max: 100 }, confirm: 'Rename' });
            if (name) S.views = (await api.post('views.php', { action: 'save', board_id: S.board.id, id: v.id, name, view: v.view, shared: v.shared, config: v.config })).views;
          }
          if (k === 'share') S.views = (await api.post('views.php', { action: 'save', board_id: S.board.id, id: v.id, name: v.name, view: v.view, shared: !v.shared, config: v.config })).views;
          if (k === 'link') { const url = `${location.origin}/projects/board.php?board_id=${S.board.id}&view=${v.view}&view_id=${v.id}`; try { await navigator.clipboard.writeText(url); toast('Link copied'); } catch (e) { await dialog({ title: 'Link to this view', input: { value: url }, confirm: 'Done', cancel: null }); } }
          if (k === 'delete') {
            if (!(await dialog({ title: `Delete “${v.name}”?`, confirm: 'Delete', danger: true }))) return;
            S.views = (await api.post('views.php', { action: 'delete', board_id: S.board.id, id: v.id })).views;
            if (S.activeView === v.id) { S.activeView = 0; FWB.renderTools(); }
          }
        } catch (x) { toastError(x); }
      },
    });
  }
  async function saveView() {
    const m = modal('Save this view', `<div class="field"><label class="label" for="svName">Name</label><input class="input" id="svName" maxlength="100" placeholder="e.g. My open snags, This week, Overdue"></div>
      <div class="hint" style="margin-top:10px">Saves the ${esc((FWB.VIEW_DEFS.find((x) => x[0] === S.view) || [0, 0, 'table'])[2].toLowerCase())} view with ${S.rules.length ? S.rules.length + ' filter' + (S.rules.length > 1 ? 's' : '') : 'no filters'}${S.sort.length ? ', a sort' : ''}${S.hidden.size ? ' and ' + S.hidden.size + ' hidden column' + (S.hidden.size > 1 ? 's' : '') : ''}.</div>
      ${FWB.canEdit() ? `<div style="margin-top:8px">${switchHTML('svShared', 'Share with everyone on the board', 'Otherwise only you see it.', false)}</div>` : ''}`,
      `<button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" id="svSave">Save view</button>`, { style: 'width:min(460px,100%)' });
    const save = async () => {
      const name = $('#svName', m).value.trim();
      if (!name) { $('#svName', m).focus(); return; }
      try {
        const d = await api.post('views.php', { action: 'save', board_id: S.board.id, name, view: S.view, shared: $('#svShared', m) ? $('#svShared', m).checked : false, config: currentConfig() });
        S.views = d.views;
        S.activeView = d.view.id;
        closeModal(m);
        FWB.renderTools();
        const u = new URL(location.href); u.searchParams.set('view_id', d.view.id); history.replaceState(null, '', u.pathname + u.search);
        toast('View saved');
      } catch (x) { toastError(x); }
    };
    $('#svSave', m).addEventListener('click', save);
    $('#svName', m).addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); save(); } });
    openModal(m);
  }
  function applyView(id) {
    const v = S.views.find((x) => x.id === Number(id));
    if (!v) return;
    const cfg = v.config || {};
    S.rules = Array.isArray(cfg.filters) ? cfg.filters.slice() : [];
    S.match = cfg.match === 'any' ? 'any' : 'all';
    S.sort = Array.isArray(cfg.sort) ? cfg.sort.slice() : [];
    if (Array.isArray(cfg.hidden)) S.hidden = new Set(cfg.hidden.map(Number));
    else if (Array.isArray(cfg.visible) && cfg.visible.length) { const vis = new Set(cfg.visible.map(Number)); S.hidden = new Set(S.columns.filter((c) => !vis.has(c.id)).map((c) => c.id)); }
    else S.hidden = new Set();
    S.person = cfg.person || null;
    S.activeView = v.id;
    FWB.setView(v.view, { keepSaved: true, noSave: true });
    const u = new URL(location.href);
    u.searchParams.set('view_id', v.id);
    history.replaceState(null, '', u.pathname + u.search);
  }

  // ===========================================================================
  // Share (team + guests)
  // ===========================================================================
  async function share() {
    const link = `${location.origin}/projects/board.php?board_id=${S.board.id}`;
    const m = modal('Share this board', `<div class="field"><span class="label">People on the project</span>
        <div style="display:flex;align-items:center;gap:12px;margin-top:4px">${window.FWP.faces(S.members.map((x) => x.id), 6)}<span class="hint" style="flex:1">${S.members.length} ${S.members.length === 1 ? 'person' : 'people'} can open this board.</span>
        <a class="btn btn--sm" href="/projects/view.php?project_id=${S.project.id}&tab=team">${icon('team')}Manage team</a></div>
        <div class="fwp-copyline"><input class="input" readonly value="${esc(link)}" aria-label="Board link"><button class="btn btn--sm" type="button" data-copy="${esc(link)}">${icon('copy')}Copy</button></div></div>
      <div class="field" style="margin-top:22px"><span class="label">Guests — clients, architects, subcontractors</span>
        <div class="hint">Guests sign in with their email and see this board read-only: no other boards, money or people.</div>
        <form id="gInvite" style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap"><input class="input" type="email" id="gEmail" placeholder="name@company.co.za" required maxlength="255" style="flex:1 1 220px" aria-label="Guest email">
          <select class="select" id="gDays" style="width:auto" aria-label="Access for">${[[7, '7 days'], [30, '30 days'], [90, '90 days'], [365, 'A year']].map(([v, l]) => opt(v, l, 30)).join('')}</select>
          <button class="btn btn--primary" type="submit">${icon('send')}Invite</button></form>
        <div id="gLink"></div>
        <div id="gList" style="margin-top:10px"><div class="fwp-skel-line"></div></div></div>`,
      `<button class="btn" type="button" data-close>Done</button>`);
    const renderGuests = (list) => {
      $('#gList', m).innerHTML = list.length ? list.map((g) => `<div class="fwp-guest">${avatar(g.email, g.email)}<div class="fwp-guest__who"><b>${esc(g.email)}</b>
          <small>${g.status === 'active' ? `Joined · ${g.last_access_at ? 'last opened ' + esc(relTime(g.last_access_at)) : 'not opened yet'}` : g.status === 'expired' ? 'Invitation expired' : g.status === 'revoked' ? 'Access removed' : 'Invited ' + esc(relTime(g.invited_at)) + ' · not accepted yet'}${g.expires_at && g.status !== 'revoked' ? ' · until ' + esc(fmtDate(String(g.expires_at).slice(0, 10))) : ''}</small></div>
          ${g.status !== 'revoked' ? `<button class="btn btn--sm btn--ghost" type="button" data-gresend="${g.id}">${icon('refresh')}${g.status === 'active' ? 'Extend' : 'Resend'}</button><button class="icon-btn icon-btn--sm" type="button" data-grevoke="${g.id}" aria-label="Remove access">${icon('x', 'i--sm')}</button>` : `<button class="btn btn--sm btn--ghost" type="button" data-gresend="${g.id}">Re-invite</button>`}</div>`).join('')
        : '<div class="hint" style="padding:6px 0">No guests yet.</div>';
    };
    const showLink = (d) => {
      $('#gLink', m).innerHTML = `<div class="fwp-banner" style="margin-top:10px">${icon(d.sent ? 'check' : 'alert')}<span class="grow">${d.sent ? 'Invitation sent.' : 'The email could not be sent — share this link with them yourself.'} The link works once and expires with the access.</span></div>
        <div class="fwp-copyline"><input class="input" readonly value="${esc(d.link)}" aria-label="Invitation link"><button class="btn btn--sm" type="button" data-copy="${esc(d.link)}">${icon('copy')}Copy</button></div>`;
    };
    m.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = $('#gInvite [type=submit]', m);
      btn.classList.add('is-busy');
      try {
        const d = await api.post('guests.php', { action: 'invite', board_id: S.board.id, email: $('#gEmail', m).value.trim(), days: Number($('#gDays', m).value) });
        $('#gEmail', m).value = '';
        renderGuests(d.guests);
        showLink(d);
      } catch (x) { toastError(x); } finally { btn.classList.remove('is-busy'); }
    });
    m.addEventListener('click', async (e) => {
      const cp = e.target.closest('[data-copy]');
      if (cp) { try { await navigator.clipboard.writeText(cp.dataset.copy); toast('Link copied'); } catch (x) { const i = cp.previousElementSibling; if (i) { i.select(); } } return; }
      const rs = e.target.closest('[data-gresend]');
      if (rs) { try { const d = await api.post('guests.php', { action: 'resend', board_id: S.board.id, id: Number(rs.dataset.gresend), days: Number($('#gDays', m).value) }); renderGuests(d.guests); showLink(d); } catch (x) { toastError(x); } return; }
      const rv = e.target.closest('[data-grevoke]');
      if (rv) {
        if (!(await dialog({ title: 'Remove this guest’s access?', body: 'They are signed out and the link stops working.', confirm: 'Remove access', danger: true }))) return;
        try { renderGuests((await api.post('guests.php', { action: 'revoke', board_id: S.board.id, id: Number(rv.dataset.grevoke) })).guests); } catch (x) { toastError(x); }
      }
    });
    openModal(m);
    try { renderGuests((await api.get('guests.php', { board_id: S.board.id })).guests); } catch (x) { $('#gList', m).innerHTML = `<div class="hint">${esc(x.message)}</div>`; }
  }

  // ===========================================================================
  // Automations
  // ===========================================================================
  const RECIPES = [
    ['status_changed', 'move_to_group', 'When status changes to something, move the item to a group'],
    ['status_changed', 'notify', 'When status changes to something, notify someone'],
    ['person_assigned', 'set_value', 'When someone is assigned, set a status'],
    ['item_created', 'assign', 'When an item is created, assign the person who created it'],
    ['item_created', 'set_date', 'When an item is created, set a due date a few days out'],
    ['status_changed', 'set_date', 'When status changes to something, stamp today’s date'],
    ['item_moved', 'set_value', 'When an item moves to a group, set a status'],
    ['status_changed', 'archive', 'When status changes to something, archive the item'],
    ['column_changed', 'notify', 'When a column changes, notify someone'],
  ];
  const TRIGGER_ICON = { status_changed: 'status', column_changed: 'pen', person_assigned: 'user', item_created: 'plus', item_moved: 'move' };
  function colName(id) { const c = FWB.col(id); return c ? c.name : 'a deleted column'; }
  function valueText(colId, v) {
    const c = FWB.col(colId);
    if (!c) return String(v);
    if (c.type === 'status') { const l = C.statusOf(c, v); return l ? l.label : String(v); }
    if (c.type === 'priority') { const p = C.priorityOf(v); return p ? p.label : String(v); }
    if (c.type === 'checkbox') return v ? 'checked' : 'unchecked';
    if (c.type === 'people') return (Array.isArray(v) ? v : [v]).map(personName).join(', ');
    if (Array.isArray(v)) return v.join(', ');
    return String(v);
  }
  function sentence(a) {
    const t = a.trigger_config || {}, x = a.action_config || {};
    const g = (id) => (FWB.group(id) || {}).name || 'a deleted group';
    const em = (s) => `<em>${esc(s)}</em>`;
    const when = {
      status_changed: () => `When ${em(colName(t.column_id))} changes${t.to ? ' to ' + em(valueText(t.column_id, t.to)) : ''}`,
      column_changed: () => `When ${em(colName(t.column_id))} changes${t.to ? ' to ' + em(t.to) : ''}`,
      person_assigned: () => `When someone is assigned in ${em(colName(t.column_id))}`,
      item_created: () => `When an item is created${t.group_id ? ' in ' + em(g(t.group_id)) : ''}`,
      item_moved: () => `When an item moves to ${em(g(t.group_id))}`,
    }[a.trigger];
    const then = {
      move_to_group: () => `move it to ${em(g(x.group_id))}`,
      set_value: () => `set ${em(colName(x.column_id))} to ${em(valueText(x.column_id, x.value))}`,
      assign: () => `assign ${em(x.user_id === 'creator' ? 'the creator' : x.user_id === 'actor' ? 'the person who did it' : personName(x.user_id))} in ${em(colName(x.column_id))}`,
      set_date: () => `set ${em(colName(x.column_id))} to ${em(x.days ? `today + ${x.days} day${x.days === 1 ? '' : 's'}` : 'today')}`,
      clear: () => `clear ${em(colName(x.column_id))}`,
      notify: () => `notify ${em(Array.isArray(x.to) ? x.to.map(personName).join(', ') : x.to === 'creator' ? 'the creator' : 'the people on it')}${x.message ? ': “' + esc(x.message) + '”' : ''}`,
      archive: () => 'archive it',
    }[a.action];
    return `${when ? when() : esc(a.trigger)}, then ${then ? then() : esc(a.action)}.`;
  }
  async function automations() {
    const m = modal('Automations', `<p class="fwm-dialog__lead" style="margin-top:0">Let the board do the routine: <em>when</em> something happens, <em>then</em> do something. Automations run as the person who made the change.</p>
      <div class="fwp-auto" id="auList"><div class="fwp-skel-line"></div></div>`,
      `<span class="grow hint" id="auCount"></span><button class="btn" type="button" data-close>Close</button><button class="btn btn--primary" type="button" data-au="new">${icon('plus')}Add automation</button>`, { wide: true });
    let list = [];
    const render = () => {
      $('#auCount', m).textContent = list.length ? `${list.filter((a) => a.active).length} of ${list.length} on` : '';
      $('#auList', m).innerHTML = list.length ? list.map((a) => `<div class="fwp-autocard ${a.active ? '' : 'is-off'}"><span class="fwp-autocard__icon">${icon(TRIGGER_ICON[a.trigger] || 'zap')}</span>
          <div class="fwp-autocard__text"><b>${esc(a.name)}</b><p>${sentence(a)}</p><small>${a.runs ? `Ran ${a.runs} time${a.runs === 1 ? '' : 's'}${a.last_run ? ' · last ' + esc(relTime(a.last_run)) : ''}` : 'Has not run yet'}${a.failures ? ` · <b style="color:var(--danger)">${a.failures} failed</b>` : ''} · by ${esc(a.by)}</small></div>
          <label class="switch" style="padding:0" title="${a.active ? 'On' : 'Off'}"><input type="checkbox" data-autog="${a.id}" ${a.active ? 'checked' : ''} aria-label="On"><span class="switch__track"></span></label>
          <button class="icon-btn icon-btn--sm" type="button" data-auedit="${a.id}" aria-label="Edit">${icon('pen', 'i--sm')}</button>
          <button class="icon-btn icon-btn--sm" type="button" data-audel="${a.id}" aria-label="Delete">${icon('trash', 'i--sm')}</button></div>`).join('')
        : `<div class="fwp-panelcard__empty">No automations yet.</div>${gallery()}`;
    };
    const gallery = () => `<div class="hint" style="margin:6px 0 8px">Start from a recipe:</div><div class="fwp-types" style="width:auto;grid-template-columns:repeat(auto-fill,minmax(250px,1fr))">${RECIPES.map((r, i) => `<button type="button" data-recipe="${i}"><span>${icon(TRIGGER_ICON[r[0]] || 'zap')}</span><span style="background:none;width:auto;height:auto;display:block"><b>${esc(r[2])}</b></span></button>`).join('')}</div>`;
    const load = async () => { try { list = (await api.get('automations.php', { board_id: S.board.id })).automations; render(); } catch (x) { $('#auList', m).innerHTML = `<div class="hint">${esc(x.message)}</div>`; } };
    const after = (d) => { list = d.automations; S.board.automations = list.filter((a) => a.active).length; FWB.renderHead(); render(); };
    m.addEventListener('click', async (e) => {
      const t = e.target;
      if (t.closest('[data-au="new"]')) { const rs = $('#auList', m); rs.innerHTML = `<div class="fwm-dialog__lead" style="margin:0 0 6px">Choose a recipe, then fill in the blanks.</div>${gallery()}`; return; }
      const rc = t.closest('[data-recipe]');
      if (rc) { const r = RECIPES[Number(rc.dataset.recipe)]; builder(m, { trigger: r[0], action: r[1], trigger_config: {}, action_config: {} }, after, () => render()); return; }
      const ed = t.closest('[data-auedit]');
      if (ed) { builder(m, list.find((a) => a.id === Number(ed.dataset.auedit)), after, () => render()); return; }
      const del = t.closest('[data-audel]');
      if (del) {
        const a = list.find((x) => x.id === Number(del.dataset.audel));
        if (!(await dialog({ title: `Delete “${a.name}”?`, confirm: 'Delete', danger: true }))) return;
        try { after(await api.post('automations.php', { action: 'delete', board_id: S.board.id, id: a.id })); } catch (x) { toastError(x); }
      }
    });
    m.addEventListener('change', async (e) => {
      const tg = e.target.closest('[data-autog]');
      if (tg) { try { after(await api.post('automations.php', { action: 'toggle', board_id: S.board.id, id: Number(tg.dataset.autog), on: tg.checked })); } catch (x) { tg.checked = !tg.checked; toastError(x); } }
    });
    openModal(m);
    load();
  }
  /** The "When … then …" sentence with blanks to fill. */
  function builder(m, a, after, back) {
    const box = $('#auList', m);
    const tc = Object.assign({}, a.trigger_config || {});
    const ac = Object.assign({}, a.action_config || {});
    const cols = (types) => S.columns.filter((c) => !types || types.includes(c.type));
    const sel = (key, items, cur, ph) => `<select class="select" data-k="${key}">${ph ? `<option value="">${esc(ph)}</option>` : ''}${items.map(([v, l]) => opt(v, l, cur)).join('')}</select>`;
    const groupsOpts = S.groups.map((g) => [g.id, g.name]);
    const settable = cols().filter((c) => !['formula', 'files', 'created', 'updated', 'rating', 'tags', 'timeline', 'supplier', 'longtext', 'link'].includes(c.type) && !(c.type === 'progress' && c.config.mode !== 'manual'));
    function valueField(colId, cur) {
      const c = FWB.col(colId);
      if (!c) return '';
      if (c.type === 'status') return sel('value', c.config.labels.map((l) => [l.key, l.label]), cur, 'Choose a label');
      if (c.type === 'priority') return sel('value', C.PRIORITIES.map((p) => [p.key, p.label]), cur, 'Choose');
      if (c.type === 'checkbox') return sel('value', [['1', 'checked'], ['0', 'unchecked']], cur === true || cur === '1' || cur === 1 ? '1' : cur === false || cur === '0' || cur === 0 ? '0' : '', 'Choose');
      if (c.type === 'dropdown') return sel('value', (c.config.options || []).map((o) => [o.label, o.label]), Array.isArray(cur) ? cur[0] : cur, 'Choose');
      if (c.type === 'people') return sel('value', P.people.map((p) => [p.id, p.name]), Array.isArray(cur) ? cur[0] : cur, 'Choose someone');
      if (c.type === 'date') return `<input class="input" type="date" data-k="value" value="${esc(cur || '')}">`;
      if (c.type === 'number' || c.type === 'progress') return `<input class="input" type="number" step="any" data-k="value" value="${esc(cur == null ? '' : cur)}" style="min-width:100px;width:110px">`;
      return `<input class="input" data-k="value" value="${esc(cur || '')}" maxlength="200" placeholder="Value">`;
    }
    function triggerPart() {
      switch (a.trigger) {
        case 'status_changed': {
          const sc = FWB.col(tc.column_id) || cols(['status'])[0];
          if (sc) tc.column_id = sc.id;
          return `When ${sel('t.column_id', cols(['status']).map((c) => [c.id, c.name]), tc.column_id)} changes to ${sc ? sel('t.to', sc.config.labels.map((l) => [l.key, l.label]), tc.to || '', 'anything') : ''}`;
        }
        case 'column_changed': return `When ${sel('t.column_id', cols().filter((c) => !['formula', 'files', 'created', 'updated'].includes(c.type)).map((c) => [c.id, c.name]), tc.column_id, 'Choose a column')} changes`;
        case 'person_assigned': { const pc = FWB.col(tc.column_id) || cols(['people'])[0]; if (pc) tc.column_id = pc.id; return `When someone is assigned in ${sel('t.column_id', cols(['people']).map((c) => [c.id, c.name]), tc.column_id)}`; }
        case 'item_created': return `When an item is created in ${sel('t.group_id', groupsOpts, tc.group_id || '', 'any group')}`;
        case 'item_moved': return `When an item moves to ${sel('t.group_id', groupsOpts, tc.group_id || '', 'Choose a group')}`;
      }
      return '';
    }
    function actionPart() {
      switch (a.action) {
        case 'move_to_group': return `move it to ${sel('a.group_id', groupsOpts, ac.group_id || '', 'Choose a group')}`;
        case 'set_value': {
          if (!ac.column_id && a.trigger === 'person_assigned') { const s = cols(['status'])[0]; if (s) ac.column_id = s.id; }
          return `set ${sel('a.column_id', settable.map((c) => [c.id, c.name]), ac.column_id || '', 'Choose a column')} to ${valueField(ac.column_id, ac.value)}`;
        }
        case 'assign': { const pc = FWB.col(ac.column_id) || cols(['people'])[0]; if (pc) ac.column_id = pc.id; return `assign ${sel('a.user_id', [['creator', 'the person who created it'], ['actor', 'the person who did it']].concat(P.people.map((p) => [p.id, p.name])), ac.user_id || 'creator')} in ${sel('a.column_id', cols(['people']).map((c) => [c.id, c.name]), ac.column_id)}`; }
        case 'set_date': { const dc = FWB.col(ac.column_id) || cols(['date'])[0]; if (dc) ac.column_id = dc.id; return `set ${sel('a.column_id', cols(['date']).map((c) => [c.id, c.name]), ac.column_id)} to today + <input class="input" type="number" data-k="a.days" value="${esc(ac.days || 0)}" min="-3650" max="3650" style="min-width:70px;width:80px"> days`; }
        case 'clear': return `clear ${sel('a.column_id', settable.map((c) => [c.id, c.name]), ac.column_id || '', 'Choose a column')}`;
        case 'notify': return `notify ${sel('a.to', [['people', 'the people on it'], ['creator', 'the person who created it']].concat(P.people.map((p) => ['u' + p.id, p.name])), Array.isArray(ac.to) ? 'u' + ac.to[0] : (ac.to || 'people'))} with <input class="input" data-k="a.message" value="${esc(ac.message || '')}" maxlength="300" placeholder="a message (optional)" style="min-width:220px">`;
        case 'archive': return 'archive it';
      }
      return '';
    }
    const ACTIONS = [['move_to_group', 'move it to a group'], ['set_value', 'set a column'], ['assign', 'assign someone'], ['set_date', 'set a date'], ['clear', 'clear a column'], ['notify', 'notify someone'], ['archive', 'archive it']];
    const TRIGGERS = [['status_changed', 'status changes'], ['column_changed', 'a column changes'], ['person_assigned', 'someone is assigned'], ['item_created', 'an item is created'], ['item_moved', 'an item moves to a group']];
    function draw() {
      box.innerHTML = `<div class="field"><label class="label" for="auName">Name</label><input class="input" id="auName" maxlength="255" value="${esc(a.name || '')}" placeholder="Named after what it does if left empty"></div>
        <div class="fwp-recipe-kind"><span class="label">When</span>${sel('trigger', TRIGGERS, a.trigger)}<span class="label">Then</span>${sel('action', ACTIONS, a.action)}</div>
        <div class="fwp-recipe">${triggerPart()} <span class="fwp-recipe__then">then</span> ${actionPart()}</div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px"><button class="btn" type="button" data-b="back">Back</button><button class="btn btn--primary" type="button" data-b="save">${a.id ? 'Save automation' : 'Turn it on'}</button></div>`;
    }
    draw();
    const onChange = (e) => {
      const k = e.target.dataset.k;
      if (!k) return;
      const v = e.target.value;
      if (k === 'trigger') { a.trigger = v; Object.keys(tc).forEach((x) => delete tc[x]); draw(); return; }
      if (k === 'action') { a.action = v; Object.keys(ac).forEach((x) => delete ac[x]); draw(); return; }
      if (k === 'value') { const c = FWB.col(ac.column_id); ac.value = !c ? v : c.type === 'checkbox' ? v === '1' : c.type === 'people' ? (v ? [Number(v)] : []) : c.type === 'dropdown' ? (v ? [v] : []) : c.type === 'number' || c.type === 'progress' ? (v === '' ? null : Number(v)) : v; return; }
      const [scope, field] = k.split('.');
      const target = scope === 't' ? tc : ac;
      if (field === 'column_id' || field === 'group_id') target[field] = v ? Number(v) : null;
      else if (field === 'days') target.days = Number(v) || 0;
      else if (field === 'to' && scope === 'a') target.to = v.startsWith('u') ? [Number(v.slice(1))] : v;
      else if (field === 'user_id') target.user_id = /^\d+$/.test(v) ? Number(v) : v;
      else target[field] = v;
      if (field === 'column_id' && (a.action === 'set_value' || scope === 't')) { if (scope === 'a') ac.value = null; if (scope === 't') tc.to = null; draw(); }
    };
    box.onchange = onChange;
    box.oninput = (e) => { if (e.target.matches('input[data-k]')) onChange(e); };
    box.onclick = async (e) => {
      const b = e.target.closest('[data-b]');
      if (!b) return;
      if (b.dataset.b === 'back') { box.onchange = box.oninput = box.onclick = null; back(); return; }
      b.classList.add('is-busy');
      try {
        const name = $('#auName', box).value.trim() || $('.fwp-recipe', box).textContent.replace(/\s+/g, ' ').trim().slice(0, 120);
        const d = await api.post('automations.php', { action: 'save', board_id: S.board.id, id: a.id || undefined, name, trigger: a.trigger, trigger_config: tc, do: a.action, action_config: ac, active: a.id ? a.active : true });
        box.onchange = box.oninput = box.onclick = null;
        after(d);
        toast(a.id ? 'Automation saved' : 'Automation on');
      } catch (x) { toastError(x); } finally { b.classList.remove('is-busy'); }
    };
  }

  // ===========================================================================
  // Board settings, import, archived, duplicate, move
  // ===========================================================================
  function boardSettings() {
    const b = S.board;
    const m = modal('Board settings', `<div class="fwp-form-grid">
        <div class="field is-wide"><label class="label" for="bsTitle">Name</label><input class="input" id="bsTitle" maxlength="255" value="${esc(b.title)}"></div>
        <div class="field is-wide"><label class="label" for="bsDesc">About this board</label><textarea class="textarea" id="bsDesc" maxlength="2000" placeholder="What it tracks, how the team should use it">${esc(b.description || '')}</textarea></div>
        <div class="field"><label class="label" for="bsNoun">Rows are called</label><input class="input" id="bsNoun" maxlength="40" value="${esc(b.item_noun || 'item')}" placeholder="item, task, snag, cost line…"></div>
        <div class="field"><label class="label" for="bsView">Opens in</label><select class="select" id="bsView">${FWB.VIEW_DEFS.map(([k, , l]) => opt(k, l, b.default_view || 'table')).join('')}</select></div>
        <div class="field is-wide"><label class="label" for="bsType">Kind of board</label><select class="select" id="bsType">${[['general', 'General'], ['schedule', 'Programme / schedule'], ['costing', 'Costing / budget'], ['procurement', 'Procurement'], ['quality', 'Quality / snags'], ['safety', 'Health & safety']].map(([v, l]) => opt(v, l, b.type || 'general')).join('')}</select>
          <div class="hint">Sets the board’s icon and where it shows on the project overview.</div></div></div>`,
      `<button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" id="bsSave">Save</button>`);
    $('#bsSave', m).addEventListener('click', async (e) => {
      const btn = e.currentTarget;
      const patch = { action: 'update', id: b.id, title: $('#bsTitle', m).value.trim(), description: $('#bsDesc', m).value.trim(), item_noun: $('#bsNoun', m).value.trim(), default_view: $('#bsView', m).value, type: $('#bsType', m).value };
      if (!patch.title) { $('#bsTitle', m).focus(); return; }
      btn.classList.add('is-busy');
      try {
        const d = await api.post('boards.php', patch);
        Object.assign(S.board, d.board, { level: S.board.level, starred: S.board.starred, automations: S.board.automations, archived_items: S.board.archived_items, project_name: S.board.project_name });
        const sb = S.boards.find((x) => x.id === b.id);
        if (sb) Object.assign(sb, { title: d.board.title, type: d.board.type });
        document.title = d.board.title + document.title.slice(document.title.indexOf(' ·'));
        closeModal(m);
        FWB.render();
        toast('Board saved');
      } catch (x) { toastError(x); } finally { btn.classList.remove('is-busy'); }
    });
    openModal(m);
  }
  function importCsv() {
    if (!FWB.canEdit()) return;
    const m = modal('Import from a spreadsheet', `<p class="fwm-dialog__lead" style="margin-top:0">Save the sheet as <b>CSV (UTF-8)</b> in Excel or Google Sheets. The first row names the columns — they are matched to this board’s columns by name.</p>
      <ul class="hint" style="margin:0 0 14px;padding-left:18px;line-height:1.7"><li>A column called <b>Name</b>, <b>Item</b>, <b>Title</b> or <b>Task</b> becomes the ${esc(FWB.noun())} name.</li>
        <li>A <b>Group</b> column puts rows in groups of that name.</li><li>People may be names or email addresses; dates like 2026-10-21 or 21/10/2026.</li>
        <li>Up to 2 000 rows. Cells that don’t fit are skipped and listed.</li></ul>
      <div class="field"><label class="label" for="imGroup">Rows without a group go to</label><select class="select" id="imGroup">${S.groups.map((g) => opt(g.id, g.name, S.groups[0] && S.groups[0].id)).join('')}</select></div>
      <div class="hint" style="margin-top:12px"><a href="/projects/api/export.php?board_id=${S.board.id}">${icon('download', 'i--xs')} Export this board</a> to get a file with the right columns.</div>`,
      `<button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" id="imPick">${icon('upload')}Choose CSV file</button>`);
    const input = $('#importInput');
    $('#imPick', m).addEventListener('click', () => { input.value = ''; input.click(); });
    input.onchange = async () => {
      const f = input.files[0];
      if (!f) return;
      const btn = $('#imPick', m);
      btn.classList.add('is-busy');
      const fd = new FormData();
      fd.append('board_id', S.board.id);
      fd.append('group_id', $('#imGroup', m).value);
      fd.append('file', f);
      try {
        const d = await api.upload('import.php', fd);
        closeModal(m);
        await FWB.reloadBoard();
        toast(`${d.added} ${FWB.noun()}${d.added === 1 ? '' : 's'} imported`);
        if (d.skipped && d.skipped.length) {
          await dialog({ title: 'Imported, with notes', body: `<span style="display:block;max-height:280px;overflow:auto;white-space:pre-line">Matched columns: ${esc((d.matched || []).join(', ') || 'only the name')}\n\n${esc(d.skipped.slice(0, 60).join('\n'))}${d.skipped.length > 60 ? '\n…' : ''}</span>`, confirm: 'OK', cancel: null });
        }
      } catch (x) { toastError(x); } finally { btn.classList.remove('is-busy'); input.onchange = null; }
    };
    m._onClose = () => { input.onchange = null; };
    openModal(m);
  }
  async function archived() {
    const m = modal(`Archived ${FWB.noun()}s`, `<div id="arList"><div class="fwp-skel-line"></div><div class="fwp-skel-line"></div></div>`, `<button class="btn" type="button" data-close>Close</button>`);
    openModal(m);
    let items = [];
    const render = () => {
      $('#arList', m).innerHTML = items.length ? `<div class="fwm-results" style="margin-top:0">${items.map((it) => { const g = FWB.group(it.group_id); return `<div class="fwm-result" style="cursor:default"><span class="fwp-dot fwp-dot--sq" style="--c:${safeColor(g ? g.color : '')}"></span><span class="fwm-result__text"><b>${esc(it.title)}</b><small>${esc(g ? g.name : '')} · changed ${esc(relTime(it.updated_at))}</small></span>
          <button class="btn btn--sm btn--ghost" type="button" data-aropen="${it.id}">Open</button>${FWB.canEdit() ? `<button class="btn btn--sm" type="button" data-arrestore="${it.id}">${icon('undo')}Restore</button>` : ''}${FWB.canManage() ? `<button class="icon-btn icon-btn--sm" type="button" data-ardel="${it.id}" aria-label="Delete for good">${icon('trash', 'i--sm')}</button>` : ''}</div>`; }).join('')}</div>`
        : `<div class="fwp-panelcard__empty">Nothing is archived on this board.</div>`;
    };
    try { items = (await api.get('boards.php', { id: S.board.id, archived: 1 })).items; render(); } catch (x) { toastError(x); }
    m.addEventListener('click', async (e) => {
      const op = e.target.closest('[data-aropen]');
      if (op) { closeModal(m); FWB.openItem(op.dataset.aropen); return; }
      const rs = e.target.closest('[data-arrestore]');
      if (rs) {
        const id = Number(rs.dataset.arrestore);
        try { const d = await api.post('items.php', { action: 'restore', board_id: S.board.id, ids: [id] }); FWB.applyItems(d.items); items = items.filter((x) => x.id !== id); S.board.archived_items = items.length; render(); FWB.renderView(); toast('Restored'); } catch (x) { toastError(x); }
        return;
      }
      const dl = e.target.closest('[data-ardel]');
      if (dl) {
        const id = Number(dl.dataset.ardel);
        if (!(await dialog({ title: 'Delete for good?', body: 'Its updates, subitems and files are deleted too.', confirm: 'Delete', danger: true }))) return;
        try { await api.post('items.php', { action: 'delete', board_id: S.board.id, ids: [id] }); items = items.filter((x) => x.id !== id); S.board.archived_items = items.length; render(); } catch (x) { toastError(x); }
      }
    });
  }
  async function projectChoices(excludeCurrent) {
    try {
      const d = await api.get('pickers.php', { type: 'projects' });
      return d.projects.filter((p) => !excludeCurrent || p.id !== S.project.id);
    } catch (x) { toastError(x); return []; }
  }
  async function duplicateBoard() {
    const projects = await projectChoices(false);
    const m = modal('Duplicate board', `<div class="field"><span class="label">Copy</span><div class="fwm-results" style="margin-top:4px">
        <label class="fwm-result"><input type="radio" name="dbWith" value="0" checked style="accent-color:var(--accent)"><span class="fwm-result__text"><b>The structure</b><small>Groups, columns, views and automations — no ${esc(FWB.noun())}s.</small></span></label>
        <label class="fwm-result"><input type="radio" name="dbWith" value="1" style="accent-color:var(--accent)"><span class="fwm-result__text"><b>Structure and ${esc(FWB.noun())}s</b><small>Everything, including values and subitems (not updates or files).</small></span></label></div></div>
      <div class="field" style="margin-top:14px"><label class="label" for="dbProject">Into project</label><select class="select" id="dbProject">${projects.map((p) => opt(p.id, p.label, S.project.id)).join('') || opt(S.project.id, S.project.name, S.project.id)}</select></div>`,
      `<button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" id="dbGo">${icon('copy')}Duplicate</button>`);
    $('#dbGo', m).addEventListener('click', async (e) => {
      e.currentTarget.classList.add('is-busy');
      try {
        const d = await api.post('boards.php', { action: 'duplicate', id: S.board.id, with_items: ($('input[name=dbWith]:checked', m) || {}).value === '1', project_id: Number($('#dbProject', m).value) });
        location.href = d.url;
      } catch (x) { toastError(x); e.currentTarget.classList.remove('is-busy'); }
    });
    openModal(m);
  }
  async function moveBoard() {
    const projects = await projectChoices(true);
    if (!projects.length) { toast('There is no other open project to move it to.'); return; }
    const m = modal('Move to another project', `<div class="field"><label class="label" for="mbProject">Project</label><select class="select" id="mbProject">${projects.map((p) => opt(p.id, p.label, '')).join('')}</select>
      <div class="hint">You need to manage both projects. The board keeps its items, updates and files; people who are only on this project lose access to it.</div></div>`,
      `<button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" id="mbGo">${icon('move')}Move board</button>`);
    $('#mbGo', m).addEventListener('click', async (e) => {
      e.currentTarget.classList.add('is-busy');
      try { await api.post('boards.php', { action: 'move', id: S.board.id, project_id: Number($('#mbProject', m).value) }); location.reload(); }
      catch (x) { toastError(x); e.currentTarget.classList.remove('is-busy'); }
    });
    openModal(m);
  }

  FWB.dialogs = { addColumn, columnSettings, filters, sort, hide, views, applyView, saveView, share, automations, boardSettings, importCsv, archived, duplicateBoard, moveBoard };
})();
