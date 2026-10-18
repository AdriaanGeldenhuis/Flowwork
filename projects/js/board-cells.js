/* =============================================================================
   Flowwork Projects — board cells
   For every column type: how a value looks in a cell, how it is edited, how it
   reads as text (search, sort, export) and how a group of items sums up.
   Values arrive decoded from the server (see projects/lib/Columns.php):
     status/priority/date/text… string · people [ids] · timeline {start, end}
     number/formula/progress/rating number · checkbox bool · tags/dropdown [strings]
     link {url, text} · supplier id
   Exposes window.FWBCells; board.js supplies the commit callback.
   ============================================================================= */
(function () {
  'use strict';

  const {
    $, $$, esc, icon, safeColor, popover, closePop, faces, personName, personAvatar, avatar, P, BOOT,
    parseYmd, ymd, today, addDays, daysBetween, fmtDate, fmtRange, fmtNum, fileKind, isImage, fmtSize, pickPeople, pickDate, toast,
  } = window.FWP;

  const PRIORITIES = [
    { key: 'urgent', label: 'Urgent', color: '#9e1f2e', rank: 4 },
    { key: 'high', label: 'High', color: '#c2412f', rank: 3 },
    { key: 'medium', label: 'Medium', color: '#b08d57', rank: 2 },
    { key: 'low', label: 'Low', color: '#5f7a8c', rank: 1 },
  ];
  const TYPE_ICON = { status: 'status', people: 'users', date: 'calendar', timeline: 'timeline', priority: 'flag', text: 'text', longtext: 'file-text',
    number: 'hash', formula: 'function', progress: 'progress', dropdown: 'chev-down', tags: 'tag', checkbox: 'check-square', rating: 'star', link: 'link',
    email: 'mail', phone: 'phone', supplier: 'building', files: 'clip', created: 'clock', updated: 'refresh' };
  const COMPUTED = ['formula', 'created', 'updated', 'files'];
  const NUMERIC = ['number', 'formula', 'progress', 'rating'];

  // ===========================================================================
  // Helpers
  // ===========================================================================
  const val = (item, col) => {
    const v = item.values ? item.values[col.id] : undefined;
    return v === undefined ? null : v;
  };
  function statusOf(col, key) {
    if (key == null || key === '') return null;
    const labels = (col.config && col.config.labels) || [];
    return labels.find((l) => l.key === key) || labels.find((l) => String(l.key).toLowerCase() === String(key).toLowerCase() || String(l.label).toLowerCase() === String(key).toLowerCase()) || null;
  }
  const priorityOf = (key) => PRIORITIES.find((p) => p.key === key) || null;
  function optionOf(col, label) {
    return ((col.config && col.config.options) || []).find((o) => o.label === label) || { label, color: '#85766b' };
  }
  function unitWrap(col, s) {
    const cfg = col.config || {};
    if (cfg.format === 'percent') return `${s}<small class="is-suffix">%</small>`;
    if (!cfg.unit) return s;
    return cfg.unit_pos === 'suffix' ? `${s}<small class="is-suffix">${esc(cfg.unit)}</small>` : `<small>${esc(cfg.unit)}</small>${s}`;
  }
  function numText(col, v) {
    if (v == null || v === '') return '';
    if (typeof v !== 'number') return String(v);
    const cfg = col.config || {};
    const p = cfg.precision != null ? cfg.precision : 2;
    return fmtNum(v, p);
  }
  const supplierName = (id) => { const s = (window.FWB && window.FWB.S.suppliers || []).find((x) => x.id === Number(id)); return s ? s.name : (id ? 'Supplier #' + id : ''); };
  const tlState = (tl, done) => {
    const t = parseYmd(today()), s = parseYmd(tl.start), e = parseYmd(tl.end);
    const total = Math.max(1, daysBetween(s, e) + 1);
    const pct = done ? 100 : Math.max(0, Math.min(100, Math.round((daysBetween(s, t) + 1) * 100 / total)));
    return { pct, late: !done && t > e };
  };

  // ===========================================================================
  // Display
  // ===========================================================================
  const plus = `<span class="fwp-empty-cell">${icon('plus')}</span>`;

  /** innerHTML of a cell. ctx: {done: bool, files: [...], item} */
  function render(col, item, ctx = {}) {
    const v = val(item, col);
    switch (col.type) {
      case 'status': {
        const l = statusOf(col, v);
        if (!l) return v ? `<span class="fwp-st" style="--c:#85766b"><span>${esc(v)}</span></span>` : `<span class="fwp-st is-empty"><span></span></span>`;
        return `<span class="fwp-st ${l.done ? 'is-done' : ''}" style="--c:${safeColor(l.color)}"><span>${esc(l.label)}</span></span>`;
      }
      case 'priority': {
        const p = priorityOf(v);
        return p ? `<span class="fwp-st" style="--c:${p.color}"><span>${esc(p.label)}</span></span>` : `<span class="fwp-st is-empty"><span></span></span>`;
      }
      case 'people': {
        if (!v || !v.length) return plus;
        if (v.length === 1) { const pp = window.FWP.personById(v[0]); return `<span class="fwp-person">${personAvatar(v[0])}<span>${esc(pp ? pp.name.split(' ')[0] : 'Former member')}</span></span>`; }
        return faces(v, 3);
      }
      case 'date': {
        if (!v) return plus;
        const t = today();
        const cls = ctx.done ? 'is-done' : v < t ? 'is-overdue' : v === t ? 'is-today' : '';
        return `<span class="fwp-date ${cls}">${cls === 'is-overdue' ? icon('alert') : ''}${esc(fmtDate(v))}</span>`;
      }
      case 'timeline': {
        if (!v) return `<span class="fwp-tl is-empty"><span></span></span>`;
        const st = tlState(v, ctx.done);
        return `<span class="fwp-tl ${st.late ? 'is-late' : ''}" style="--c:${safeColor(ctx.groupColor, '#7a1f30')};--p:${st.pct}%" title="${esc(fmtRange(v.start, v.end))} · ${daysBetween(parseYmd(v.start), parseYmd(v.end)) + 1} days"><i></i><span>${esc(fmtRange(v.start, v.end))}</span></span>`;
      }
      case 'number':
      case 'formula': {
        if (v == null || v === '') return col.type === 'formula' ? '' : plus;
        if (typeof v !== 'number') return `<span class="fwp-numv">${esc(v)}</span>`;
        return `<span class="fwp-numv ${v < 0 ? 'is-neg' : ''}" ${col.type === 'formula' ? `title="${esc((col.config || {}).formula || '')}"` : ''}>${unitWrap(col, esc(numText(col, v)))}</span>`;
      }
      case 'progress': {
        if (v == null) return (col.config || {}).mode === 'manual' ? plus : '';
        const p = Math.round(v);
        return `<span class="fwp-prog"><span class="fwp-bar ${p >= 100 ? 'is-done' : ''}" style="--p:${p}%"><i></i></span><b>${p}%</b></span>`;
      }
      case 'checkbox':
        return `<span class="fwp-cb ${v ? 'is-on' : ''}" role="checkbox" aria-checked="${v ? 'true' : 'false'}">${icon('check')}</span>`;
      case 'rating': {
        const max = (col.config || {}).max || 5;
        let s = '';
        for (let i = 1; i <= max; i++) s += `<button type="button" class="${v >= i ? 'is-on' : ''}" data-rate="${i}" aria-label="${i} star${i > 1 ? 's' : ''}" tabindex="-1">${icon('star')}</button>`;
        return `<span class="fwp-stars">${s}</span>`;
      }
      case 'dropdown': {
        if (!v || !v.length) return plus;
        const shown = v.slice(0, 2).map((o) => { const op = optionOf(col, o); return `<span class="fwp-chip" style="--c:${safeColor(op.color)}">${esc(o)}</span>`; }).join('');
        return `<span class="fwp-chips">${shown}${v.length > 2 ? `<span class="fwp-chips__more">+${v.length - 2}</span>` : ''}</span>`;
      }
      case 'tags': {
        if (!v || !v.length) return plus;
        const color = (t) => ['#2f5c84', '#7a5ab8', '#2f7457', '#b4577a', '#c06a2b', '#1f7a7a', '#7a1f30', '#b08d57'][window.FWP.hueOf(t) % 8];
        return `<span class="fwp-chips">${v.slice(0, 3).map((t) => `<span class="fwp-chip fwp-chip--tag" style="--c:${color(t)}">#${esc(t)}</span>`).join('')}${v.length > 3 ? `<span class="fwp-chips__more">+${v.length - 3}</span>` : ''}</span>`;
      }
      case 'link': {
        if (!v || !v.url) return plus;
        const safe = /^https?:\/\//i.test(v.url) ? v.url : '';
        const label = v.text || v.url.replace(/^https?:\/\/(www\.)?/i, '').replace(/\/$/, '');
        return safe ? `<a class="fwp-txt" href="${esc(safe)}" target="_blank" rel="noopener noreferrer" data-stop>${icon('external', 'i--xs')} ${esc(label)}</a>` : `<span class="fwp-txt">${esc(label)}</span>`;
      }
      case 'email':
        return v ? `<a class="fwp-txt" href="mailto:${esc(v)}" data-stop>${esc(v)}</a>` : plus;
      case 'phone':
        return v ? `<a class="fwp-txt" href="tel:${esc(String(v).replace(/[^0-9+]/g, ''))}" data-stop>${esc(v)}</a>` : plus;
      case 'supplier':
        return v ? `<span class="fwp-person"><span class="fwp-btile__icon" style="width:24px;height:24px;border-radius:7px">${icon('building', 'i--xs')}</span><span>${esc(supplierName(v))}</span></span>` : plus;
      case 'files': {
        const files = (item.files || []).filter((f) => !col || f.column_id === col.id || (f.column_id == null && ctx.firstFiles));
        if (!files.length) return plus;
        return `<span class="fwp-filechips">${files.slice(0, 3).map((f) => {
          const [kind, label] = fileKind(f.name, f.mime);
          return `<span class="fwp-filechip ${kind}" title="${esc(f.name)}">${isImage(f) ? `<img src="${esc(fileUrl(f))}" alt="" loading="lazy">` : esc(label)}</span>`;
        }).join('')}${files.length > 3 ? `<span class="fwp-chips__more">+${files.length - 3}</span>` : ''}</span>`;
      }
      case 'created':
        return `<span class="fwp-meta-cell">${personAvatar(item.created_by)}${esc(fmtDate(String(item.created_at).slice(0, 10)))}</span>`;
      case 'updated':
        return `<span class="fwp-meta-cell">${esc(window.FWP.relTime(item.updated_at))}</span>`;
      case 'longtext':
        return v ? `<span class="fwp-txt" title="${esc(String(v).slice(0, 500))}">${esc(String(v).split('\n')[0])}</span>` : plus;
      default:
        return v ? `<span class="fwp-txt" title="${esc(v)}">${esc(v)}</span>` : plus;
    }
  }
  const fileUrl = (f) => (f.guest && window.FWB && window.FWB.guestFileUrl) ? window.FWB.guestFileUrl(f) : '/projects/api/files.php?id=' + f.id;

  /** Plain text of a value (search, sort, CSV). */
  function text(col, item) {
    const v = val(item, col);
    if (v == null) return col.type === 'created' ? String(item.created_at || '') : col.type === 'updated' ? String(item.updated_at || '') : '';
    switch (col.type) {
      case 'status': { const l = statusOf(col, v); return l ? l.label : String(v); }
      case 'priority': { const p = priorityOf(v); return p ? p.label : ''; }
      case 'people': return v.map(personName).join(', ');
      case 'timeline': return v.start + ' ' + v.end;
      case 'checkbox': return v ? 'yes' : 'no';
      case 'tags': case 'dropdown': return v.join(', ');
      case 'link': return (v.text || '') + ' ' + v.url;
      case 'supplier': return supplierName(v);
      default: return String(v);
    }
  }

  /** Sort key: numbers for numbers, dates as YYYY-MM-DD, labels by their position. */
  function sortKey(col, item) {
    const v = val(item, col);
    switch (col.type) {
      case 'status': { const labels = (col.config && col.config.labels) || []; const i = labels.findIndex((l) => l.key === v); return i < 0 ? 999 : i; }
      case 'priority': { const p = priorityOf(v); return p ? -p.rank : 9; }
      case 'timeline': return v ? v.start : '9999';
      case 'date': return v || '9999';
      case 'number': case 'formula': case 'progress': case 'rating': return typeof v === 'number' ? v : -Infinity;
      case 'checkbox': return v ? 1 : 0;
      case 'created': return item.created_at || '';
      case 'updated': return item.updated_at || '';
      default: return text(col, item).toLowerCase() || '￿';
    }
  }

  // ===========================================================================
  // Summaries (group footer)
  // ===========================================================================
  function aggregate(col, items) {
    const nums = items.map((it) => val(it, col)).filter((v) => typeof v === 'number');
    const agg = (col.config && col.config.agg) || 'sum';
    if (col.type === 'progress' || col.type === 'rating') return nums.length ? nums.reduce((a, b) => a + b, 0) / nums.length : null;
    if (!nums.length) return null;
    switch (agg) {
      case 'avg': return nums.reduce((a, b) => a + b, 0) / nums.length;
      case 'min': return Math.min(...nums);
      case 'max': return Math.max(...nums);
      case 'count': return nums.length;
      case 'none': return null;
      default: return nums.reduce((a, b) => a + b, 0);
    }
  }
  function battery(parts, total) {
    if (!total) return '';
    return `<div class="fwp-battery" title="${esc(parts.map((p) => `${p.label}: ${p.n} (${Math.round(p.n * 100 / total)}%)`).join('\n'))}">${parts.map((p) => `<i style="--c:${safeColor(p.color, '#c8bfb4')};--n:${p.n}"></i>`).join('')}</div>`;
  }
  function summary(col, items) {
    if (!items.length) return '';
    switch (col.type) {
      case 'status': {
        const labels = (col.config && col.config.labels) || [];
        const parts = labels.map((l) => ({ label: l.label, color: l.color, n: items.filter((it) => val(it, col) === l.key).length })).filter((p) => p.n);
        const empty = items.filter((it) => !statusOf(col, val(it, col))).length;
        if (empty) parts.push({ label: 'No status', color: '#d9d0c4', n: empty });
        return `<span class="fwp-sum">${battery(parts, items.length)}</span>`;
      }
      case 'priority': {
        const parts = PRIORITIES.map((p) => ({ label: p.label, color: p.color, n: items.filter((it) => val(it, col) === p.key).length })).filter((p) => p.n);
        const empty = items.length - parts.reduce((a, p) => a + p.n, 0);
        if (empty) parts.push({ label: 'No priority', color: '#d9d0c4', n: empty });
        return `<span class="fwp-sum">${battery(parts, items.length)}</span>`;
      }
      case 'dropdown': {
        const counts = new Map();
        items.forEach((it) => (val(it, col) || []).forEach((o) => counts.set(o, (counts.get(o) || 0) + 1)));
        const parts = Array.from(counts.entries()).map(([o, n]) => ({ label: o, color: optionOf(col, o).color, n }));
        const empty = items.filter((it) => !(val(it, col) || []).length).length;
        if (empty) parts.push({ label: 'Empty', color: '#d9d0c4', n: empty });
        return `<span class="fwp-sum">${battery(parts, parts.reduce((a, p) => a + p.n, 0))}</span>`;
      }
      case 'people': {
        const ids = Array.from(new Set(items.flatMap((it) => val(it, col) || [])));
        return ids.length ? faces(ids, 4, 'fwp-faces--sm') : '';
      }
      case 'number':
      case 'formula':
      case 'progress':
      case 'rating': {
        const a = aggregate(col, items);
        if (a == null) return '';
        const agg = col.type === 'progress' || col.type === 'rating' ? 'avg' : ((col.config && col.config.agg) || 'sum');
        if (col.type === 'progress') return `<span class="fwp-prog"><span class="fwp-bar ${a >= 100 ? 'is-done' : ''}" style="--p:${Math.round(a)}%"><i></i></span><b>${Math.round(a)}%</b></span>`;
        if (col.type === 'rating') return `<span class="fwp-sum"><b>${a.toFixed(1)} ★</b><small>average</small></span>`;
        const shown = agg === 'count' ? String(a) : unitWrap(col, esc(numText(col, a)));
        return `<span class="fwp-sum" style="align-items:flex-end"><b>${shown}</b><small>${agg === 'sum' ? 'sum' : agg === 'avg' ? 'average' : agg}</small></span>`;
      }
      case 'date': {
        const ds = items.map((it) => val(it, col)).filter(Boolean).sort();
        return ds.length ? `<span class="fwp-sum"><b>${esc(fmtRange(ds[0], ds[ds.length - 1]))}</b><small>${ds.length} dated</small></span>` : '';
      }
      case 'timeline': {
        const tls = items.map((it) => val(it, col)).filter(Boolean);
        if (!tls.length) return '';
        const s = tls.map((t) => t.start).sort()[0];
        const e = tls.map((t) => t.end).sort().pop();
        const done = items.filter((it) => it._done).length;
        return `<span class="fwp-tl" style="--c:var(--gold);--p:${Math.round(done * 100 / items.length)}%"><i></i><span>${esc(fmtRange(s, e))}</span></span>`;
      }
      case 'checkbox': {
        const n = items.filter((it) => val(it, col)).length;
        return `<span class="fwp-sum"><b>${n} / ${items.length}</b><small>checked</small></span>`;
      }
      case 'files': {
        const n = items.reduce((a, it) => a + (it.files || []).filter((f) => f.column_id === col.id || f.column_id == null).length, 0);
        return n ? `<span class="fwp-sum"><b>${n}</b><small>files</small></span>` : '';
      }
    }
    return '';
  }

  // ===========================================================================
  // Editors
  // ===========================================================================
  const editable = (col) => !COMPUTED.includes(col.type) && !(col.type === 'progress' && (col.config || {}).mode !== 'manual');
  /** Edited right in the cell (an input replaces the content). */
  const inlineTypes = ['text', 'number', 'email', 'phone'];

  /**
   * Open the editor for a cell.
   * @param cellEl  the cell element (anchor)
   * @param commit  (value) => Promise — writes the value
   * @param opts    {canManage, onEditLabels(col), allTags(), openFiles(item, col), upload(item, col)}
   */
  function edit(col, item, cellEl, commit, opts = {}) {
    const v = val(item, col);
    switch (col.type) {
      case 'status': return statusPicker(cellEl, col, v, commit, opts);
      case 'priority': return popover(cellEl, `<div class="fwp-pick fwp-pick--status"><div class="fwp-statuses">${PRIORITIES.map((p) => `<button class="fwp-st ${v === p.key ? 'is-current' : ''}" style="--c:${p.color}" data-v="${p.key}" type="button"><span>${p.label}</span></button>`).join('')}
          <button class="fwp-st is-empty" data-v="" type="button"><span>Clear</span></button></div></div>`, { onClick: (b) => { commit(b.dataset.v || null); } });
      case 'people': {
        const before = (v || []).slice().sort().join(',');
        return pickPeople(cellEl, v || [], { title: col.name, onClose: (ids) => { if (ids.slice().sort().join(',') !== before) commit(ids); } });
      }
      case 'date': return pickDate(cellEl, v, (d) => commit(d), { title: col.name });
      case 'timeline': return timelinePicker(cellEl, col, v, commit);
      case 'longtext': return textPopover(cellEl, col, v, commit);
      case 'link': return linkPopover(cellEl, col, v, commit);
      case 'dropdown': return dropdownPicker(cellEl, col, v, commit, opts);
      case 'tags': return tagsPicker(cellEl, col, v, commit, opts);
      case 'checkbox': return commit(!v);
      case 'progress': return progressPopover(cellEl, col, v, commit);
      case 'supplier': return supplierPicker(cellEl, col, v, commit);
      case 'files': return opts.openFiles ? opts.openFiles(item, col) : null;
      case 'rating': return ratingPicker(cellEl, col, v, commit); // the stars are mouse targets: Enter opens this
      default:
        if (inlineTypes.includes(col.type)) return inlineInput(cellEl, col, v, commit);
    }
    return null;
  }

  function ratingPicker(anchor, col, v, commit) {
    const max = (col.config || {}).max || 5;
    return popover(anchor, `<div class="fwm-pop__title">${esc(col.name)}</div>${Array.from({ length: max + 1 }, (_, n) => `<button class="fwm-pop__item ${(v || 0) === n ? 'is-on' : ''}" data-v="${n}" type="button" aria-label="${n ? n + ' star' + (n > 1 ? 's' : '') : 'No rating'}">${n ? '★'.repeat(n) : 'No rating'}</button>`).join('')}`, {
      onClick: (b) => { commit(Number(b.dataset.v) || null); },
    });
  }

  function statusPicker(anchor, col, v, commit, opts) {
    const labels = (col.config && col.config.labels) || [];
    popover(anchor, `<div class="fwp-pick fwp-pick--status"><div class="fwp-statuses">
        ${labels.map((l) => `<button class="fwp-st ${v === l.key ? 'is-current' : ''} ${l.done ? 'is-done' : ''}" style="--c:${safeColor(l.color)}" data-v="${esc(l.key)}" type="button"><span>${esc(l.label)}</span></button>`).join('')}
        <button class="fwp-st is-empty" data-v="" type="button"><span>Clear</span></button></div>
        ${opts.canManage && opts.onEditLabels ? `<div class="fwp-pick__foot"><button class="btn btn--sm btn--ghost" type="button" data-v="__labels">${icon('pen')}Edit labels</button></div>` : ''}</div>`, {
      onClick: (b) => {
        if (b.dataset.v === '__labels') { opts.onEditLabels(col); return true; }
        commit(b.dataset.v || null);
        return true;
      },
    });
  }

  function inlineInput(cellEl, col, v, commit) {
    if (cellEl.querySelector('input.fwp-inline')) return;
    const old = cellEl.innerHTML;
    const input = document.createElement('input');
    input.className = 'fwp-inline';
    input.type = col.type === 'email' ? 'email' : col.type === 'phone' ? 'tel' : 'text';
    if (col.type === 'number') input.inputMode = 'decimal';
    input.value = v == null ? '' : String(v);
    input.maxLength = col.type === 'text' ? 2000 : 255;
    cellEl.innerHTML = '';
    cellEl.appendChild(input);
    input.focus();
    input.select();
    let done = false;
    const finish = (save) => {
      if (done) return;
      done = true;
      const nv = input.value.trim();
      if (save && nv !== (v == null ? '' : String(v))) {
        cellEl.innerHTML = old;
        commit(nv === '' ? null : nv);
      } else cellEl.innerHTML = old;
      cellEl.focus({ preventScroll: true });
    };
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); finish(true); }
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); finish(false); }
      if (e.key === 'Tab') { finish(true); }
      e.stopPropagation();
    });
    input.addEventListener('blur', () => finish(true));
    input.addEventListener('click', (e) => e.stopPropagation());
  }

  function textPopover(anchor, col, v, commit) {
    const pop = popover(anchor, `<div class="fwp-pick" style="width:360px"><div class="fwm-pop__title">${esc(col.name)}</div><textarea class="textarea" maxlength="20000" placeholder="Write here…">${esc(v || '')}</textarea>
      <div class="fwp-pick__foot"><span class="hint">Ctrl + Enter to save</span><button class="btn btn--sm btn--primary" type="button" data-v="save">Save</button></div></div>`, {
      role: 'dialog',
      onClick: (b) => { if (b.dataset.v === 'save') { const t = $('textarea', pop.el).value; if (t !== (v || '')) commit(t.trim() || null); } return true; },
    });
    const ta = $('textarea', pop.el);
    ta.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); $('[data-v=save]', pop.el).click(); } });
    return pop;
  }

  function linkPopover(anchor, col, v, commit) {
    const pop = popover(anchor, `<div class="fwp-pick" style="width:340px"><div class="fwm-pop__title">${esc(col.name)}</div>
      <input class="input" type="url" placeholder="https://…" value="${esc(v ? v.url : '')}" data-k="url" style="margin-bottom:8px">
      <input class="input" placeholder="Text to show (optional)" value="${esc(v ? v.text : '')}" data-k="text">
      <div class="fwp-pick__foot"><button class="btn btn--sm btn--ghost" type="button" data-v="clear" ${v ? '' : 'disabled'}>Remove</button><button class="btn btn--sm btn--primary" type="button" data-v="save">Save</button></div></div>`, {
      role: 'dialog',
      onClick: (b) => {
        if (b.dataset.v === 'clear') commit(null);
        if (b.dataset.v === 'save') { const url = $('[data-k=url]', pop.el).value.trim(); commit(url ? { url, text: $('[data-k=text]', pop.el).value.trim() } : null); }
        return true;
      },
    });
    $$('input', pop.el).forEach((i) => i.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); $('[data-v=save]', pop.el).click(); } }));
    return pop;
  }

  function dropdownPicker(anchor, col, v, commit, opts) {
    const multi = !!(col.config && col.config.multi);
    let sel = new Set(v || []);
    const options = (col.config && col.config.options) || [];
    const listHTML = (q = '') => options.filter((o) => !q || o.label.toLowerCase().includes(q.toLowerCase())).map((o) =>
      `<button type="button" class="${sel.has(o.label) ? 'is-on' : ''}" data-v="${esc(o.label)}">${multi ? `<span class="fwm-pop__check ${sel.has(o.label) ? 'is-on' : ''}">${icon('check')}</span>` : ''}<span class="fwp-chip" style="--c:${safeColor(o.color)}">${esc(o.label)}</span></button>`).join('') || '<div class="fwm-nav__empty">No options match.</div>';
    const pop = popover(anchor, `<div class="fwp-pick" style="width:280px"><div class="fwm-pop__title">${esc(col.name)}</div>${options.length > 6 ? '<input class="fwm-pop__input" placeholder="Search options">' : ''}<div class="fwp-ddopts">${listHTML()}</div>
      <div class="fwp-pick__foot">${opts.canManage && opts.onEditLabels ? `<button class="btn btn--sm btn--ghost" type="button" data-v="__edit">${icon('pen')}Edit options</button>` : '<span></span>'}<button class="btn btn--sm btn--ghost" type="button" data-v="__clear">Clear</button></div></div>`, {
      onClick: (b) => {
        const k = b.dataset.v;
        if (k === '__edit') { opts.onEditLabels(col); return true; }
        if (k === '__clear') { sel = new Set(); commit(null); return true; }
        if (!multi) { commit(k); return true; }
        if (sel.has(k)) sel.delete(k); else sel.add(k);
        $('.fwp-ddopts', pop.el).innerHTML = listHTML(($('.fwm-pop__input', pop.el) || {}).value || '');
        return false;
      },
      onClose: () => { if (multi) { const now = Array.from(sel); if (now.slice().sort().join('|') !== (v || []).slice().sort().join('|')) commit(now.length ? now : null); } },
    });
    const q = $('.fwm-pop__input', pop.el);
    if (q) q.addEventListener('input', () => { $('.fwp-ddopts', pop.el).innerHTML = listHTML(q.value); });
    return pop;
  }

  function tagsPicker(anchor, col, v, commit, opts) {
    let tags = (v || []).slice();
    const all = opts.allTags ? opts.allTags(col) : [];
    const render = () => `<div class="fwp-chips" style="flex-wrap:wrap;margin-bottom:8px">${tags.map((t, i) => `<span class="fwp-chip fwp-chip--tag" style="--c:#2f5c84">#${esc(t)} <button type="button" data-v="__rm:${i}" style="border:0;background:none;color:inherit;cursor:pointer;padding:0 0 0 4px">×</button></span>`).join('') || '<span class="hint">No tags yet.</span>'}</div>`;
    const sugg = (q) => all.filter((t) => !tags.includes(t) && (!q || t.toLowerCase().includes(q.toLowerCase()))).slice(0, 8).map((t) => `<button class="fwm-pop__item" type="button" data-v="__add:${esc(t)}">#${esc(t)}</button>`).join('');
    const pop = popover(anchor, `<div class="fwp-pick" style="width:300px"><div class="fwm-pop__title">${esc(col.name)}</div><div data-box>${render()}</div><input class="fwm-pop__input" placeholder="Add a tag and press Enter" maxlength="40"><div data-sugg>${sugg('')}</div></div>`, {
      onClick: (b) => {
        const k = b.dataset.v;
        if (k.startsWith('__rm:')) tags.splice(Number(k.slice(5)), 1);
        if (k.startsWith('__add:')) { const t = k.slice(6); if (!tags.includes(t)) tags.push(t); }
        $('[data-box]', pop.el).innerHTML = render();
        $('[data-sugg]', pop.el).innerHTML = sugg($('input', pop.el).value);
        return false;
      },
      onClose: () => { if (tags.join('|') !== (v || []).join('|')) commit(tags.length ? tags : null); },
    });
    const input = $('input', pop.el);
    input.addEventListener('input', () => { $('[data-sugg]', pop.el).innerHTML = sugg(input.value); });
    input.addEventListener('keydown', (e) => {
      if ((e.key === 'Enter' || e.key === ',') && input.value.trim()) {
        e.preventDefault();
        const t = input.value.trim().replace(/^#/, '').slice(0, 40);
        if (!tags.some((x) => x.toLowerCase() === t.toLowerCase())) tags.push(t);
        input.value = '';
        $('[data-box]', pop.el).innerHTML = render();
        $('[data-sugg]', pop.el).innerHTML = sugg('');
      }
      if (e.key === 'Backspace' && !input.value && tags.length) { tags.pop(); $('[data-box]', pop.el).innerHTML = render(); }
    });
    return pop;
  }

  function timelinePicker(anchor, col, v, commit) {
    const t = parseYmd(today());
    const s0 = v ? v.start : ymd(t);
    const quick = [['1 week', 6], ['2 weeks', 13], ['1 month', 29], ['3 months', 89]];
    const pop = popover(anchor, `<div class="fwp-pick" style="width:300px"><div class="fwm-pop__title">${esc(col.name)}</div>
      <div class="fwp-pick__row"><div style="flex:1"><div class="fwp-pick__label">Start</div><input class="input" type="date" data-k="s" value="${esc(v ? v.start : '')}"></div>
        <div style="flex:1"><div class="fwp-pick__label">End</div><input class="input" type="date" data-k="e" value="${esc(v ? v.end : '')}"></div></div>
      <div class="fwp-quickdates" style="margin-top:10px">${quick.map(([l, n]) => `<button type="button" data-v="__q:${n}">${l}</button>`).join('')}</div>
      <div class="fwp-pick__foot"><button class="btn btn--sm btn--ghost" type="button" data-v="clear" ${v ? '' : 'disabled'}>Clear</button><button class="btn btn--sm btn--primary" type="button" data-v="save">Set</button></div></div>`, {
      role: 'dialog',
      onClick: (b) => {
        const k = b.dataset.v;
        const si = $('[data-k=s]', pop.el), ei = $('[data-k=e]', pop.el);
        if (k.startsWith('__q:')) { const st = si.value || s0; si.value = st; ei.value = ymd(addDays(parseYmd(st), Number(k.slice(4)))); return false; }
        if (k === 'clear') { commit(null); return true; }
        if (k === 'save') {
          if (!si.value && !ei.value) return false;
          commit({ start: si.value || ei.value, end: ei.value || si.value });
          return true;
        }
        return false;
      },
    });
    return pop;
  }

  function progressPopover(anchor, col, v, commit) {
    const cur = Math.round(v || 0);
    const pop = popover(anchor, `<div class="fwp-pick" style="width:260px"><div class="fwm-pop__title">${esc(col.name)}</div>
      <div class="fwp-pick__row"><input type="range" min="0" max="100" step="5" value="${cur}" style="flex:1;accent-color:var(--accent)"><input class="input" type="number" min="0" max="100" value="${cur}" style="width:72px"></div>
      <div class="fwp-quickdates" style="margin-top:10px">${[0, 25, 50, 75, 100].map((n) => `<button type="button" data-v="${n}">${n}%</button>`).join('')}</div></div>`, {
      onClick: (b) => { commit(Number(b.dataset.v)); return true; },
      onClose: () => { const n = Number($('input[type=number]', pop.el).value); if (n !== cur && !Number.isNaN(n)) commit(Math.max(0, Math.min(100, n))); },
    });
    const range = $('input[type=range]', pop.el), num = $('input[type=number]', pop.el);
    range.addEventListener('input', () => { num.value = range.value; });
    num.addEventListener('input', () => { range.value = num.value; });
    num.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); closePop(); } });
    return pop;
  }

  function supplierPicker(anchor, col, v, commit) {
    const list = (window.FWB && window.FWB.S.suppliers) || [];
    const rows = (q) => list.filter((s) => !q || s.name.toLowerCase().includes(q.toLowerCase())).slice(0, 60)
      .map((s) => `<button class="fwm-pop__item ${Number(v) === s.id ? 'is-on' : ''}" type="button" data-v="${s.id}">${icon('building')}<span style="overflow:hidden;text-overflow:ellipsis">${esc(s.name)}</span></button>`).join('')
      || `<div class="fwm-nav__empty">${list.length ? 'No supplier matches.' : 'No suppliers in the CRM yet.'}</div>`;
    const pop = popover(anchor, `<div class="fwp-pick" style="width:300px"><div class="fwm-pop__title">${esc(col.name)}</div><input class="fwm-pop__input" placeholder="Search suppliers"><div class="fwp-plist">${rows('')}</div>
      <div class="fwp-pick__foot"><a class="btn btn--sm btn--ghost" href="/crm/?type=supplier" target="_blank" rel="noopener">${icon('external')}CRM</a><button class="btn btn--sm btn--ghost" type="button" data-v="" ${v ? '' : 'disabled'}>Clear</button></div></div>`, {
      onClick: (b) => { commit(b.dataset.v ? Number(b.dataset.v) : null); return true; },
    });
    const q = $('.fwm-pop__input', pop.el);
    q.addEventListener('input', () => { $('.fwp-plist', pop.el).innerHTML = rows(q.value); });
    q.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); const f = $('.fwp-plist [data-v]', pop.el); if (f) f.click(); } });
    return pop;
  }

  window.FWBCells = {
    PRIORITIES, TYPE_ICON, COMPUTED, NUMERIC, val, statusOf, priorityOf, optionOf, render, text, sortKey, summary, aggregate, editable, inlineTypes, edit,
    numText, unitWrap, fileUrl, supplierName, tlState,
  };
})();
