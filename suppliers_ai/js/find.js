// /suppliers_ai/js/find.js — Find suppliers: the box reads what is typed (the
// trade, the place, how many, when, the budget, requirements) as you type;
// a search shows your suppliers first and businesses near the site on Google,
// each with its match and the reasons, and ideas from AI. Shortlist the right
// ones (kept on this device), then ask them all for quotes in one go.
(function () {
  'use strict';
  const F = window.FSA;
  const { $, $$, esc, icon, api, toast, toastError, modal, openModal, closeModal, dialog, busy, money, toCents, ring, avatar, fmtDay, due } = F;
  const BOOT = F.boot;
  const text = $('#findText');
  const resultsEl = $('#results');
  const home = $('#home');
  const S = { result: null, sort: 'match', showBelow: false, read: null, whereTouched: false, ideasOpen: true };
  const EXAMPLES = [
    'Need 3 plumbers in Vanderbijlpark for geysers tomorrow, under R5k, with COC',
    '20 cubes river sand delivered to Sasolburg by Friday',
    'Electrician with COC near Centurion next week',
    '19mm crushed stone, 10 tons, CIDB grade 5GB',
    'Aircon service for 6 units at the office in Sandton',
    'Ons soek ’n loodgieter naby Meyerton môre, begroting R8000',
  ];
  let exampleAt = 0;

  // ------------------------------------------------------------ shortlist (this device)
  const KEY = 'fsa-shortlist-v1:' + (BOOT.user || 0);
  const store = {
    load() { try { const v = JSON.parse(localStorage.getItem(KEY) || '{}'); return v && typeof v === 'object' && v.items ? v : { items: {} }; } catch (e) { return { items: {} }; } },
    save(v) { try { localStorage.setItem(KEY, JSON.stringify(v)); } catch (e) { /* private mode */ } },
  };
  let shortlist = store.load();
  const picked = (id) => !!shortlist.items[id];
  function togglePick(row) {
    if (picked(row.id)) delete shortlist.items[row.id];
    else {
      if (Object.keys(shortlist.items).length >= 50) { toast('The shortlist holds 50 suppliers.', { type: 'error' }); return; }
      shortlist.items[row.id] = { id: row.id, name: row.name, source: row.source, account_id: row.account_id, query_id: S.result ? S.result.query_id : null };
    }
    store.save(shortlist);
    drawTray();
    $$(`.fsa-sup[data-id="${row.id}"]`).forEach((card) => {
      card.classList.toggle('is-picked', picked(row.id));
      const b = $('.fsa-pick', card);
      if (b) { b.classList.toggle('is-on', picked(row.id)); b.setAttribute('aria-pressed', String(picked(row.id))); b.innerHTML = picked(row.id) ? icon('check') + 'On the shortlist' : icon('isa-pin-plus') + 'Shortlist'; }
    });
  }
  function drawTray() {
    const tray = $('#tray');
    if (!tray) return;
    const items = Object.values(shortlist.items);
    tray.hidden = !items.length;
    if (!items.length) return;
    $('#trayCount').textContent = items.length + ' on the shortlist';
    const max = (BOOT.settings && BOOT.settings.max_recipients) || 8;
    $('#trayHint').textContent = items.length > max ? `A request goes to at most ${max} — take some off` : items.map((i) => i.name).join(', ');
    $('#trayFaces').innerHTML = items.slice(0, 5).map((i) => avatar(i.name)).join('');
  }

  // ------------------------------------------------------------ reading as you type
  const chip = (ic, label, sub, color, title) => `<span class="fsa-chip" style="--c:${color || 'var(--gold)'}"${title ? ` title="${esc(title)}"` : ''}>${icon(ic, 'i--sm')}<b>${esc(label)}</b>${sub ? `<em>${esc(sub)}</em>` : ''}</span>`;
  function drawReading(r, town) {
    const el = $('#reading');
    if (!el) return;
    const parts = [];
    if (r.trade) parts.push(chip(r.trade.icon, r.trade.label, '', r.trade.color));
    (r.trades || []).forEach((t) => parts.push(chip(t.icon, t.label, '', t.color)));
    if (!r.trade && r.keywords && r.keywords.length) parts.push(chip('search', r.keywords.slice(0, 3).join(' '), 'what', '#85766b'));
    if (r.location) parts.push(chip('pin', r.location, '', '#2f5c84'));
    else if (town) parts.push(chip('pin', town, 'your town', '#85766b'));
    if (r.quantity) parts.push(chip('hash', r.quantity, '', '#6b3a6e'));
    if (r.needed_by) parts.push(chip('calendar', fmtDay(r.needed_by), r.urgent ? 'urgent' : (r.when || due(r.needed_by)), r.urgent ? '#a8281e' : '#2f6b6a'));
    else if (r.urgent) parts.push(chip('zap', 'Urgent', '', '#a8281e'));
    if (r.budget) parts.push(chip('lock', money(r.budget, { round: true }), 'budget · stays with your team', '#9a6512', 'The budget is never sent to suppliers'));
    (r.requirements || []).forEach((q) => parts.push(chip('shield', q, '', '#2f7457')));
    el.hidden = !parts.length;
    el.innerHTML = parts.length ? `<span class="fsa-reading__label">Read</span>${parts.join('')}` : '';
    const where = $('#findWhere');
    if (where && !S.whereTouched) where.placeholder = r.location ? r.location + ' (from what you typed)' : (town || 'Town or area');
  }
  const parse = F.debounce(async () => {
    const t = text.value.trim();
    if (t.length < 3) { drawReading({}, ''); return; }
    try {
      const res = await api.get('search.php', { op: 'parse', text: t });
      S.read = res.read;
      drawReading(res.read, res.town);
    } catch (e) { /* the reading is a nicety */ }
  }, 260);

  // ------------------------------------------------------------ search
  async function run() {
    const t = text.value.trim();
    if (t.length < 2) { toast('Say what you need — a trade or a product, and where.'); text.focus(); return; }
    const go = $('#findGo');
    busy(go, true);
    $('#find').classList.add('is-busy');
    resultsEl.hidden = false;
    home.hidden = true;
    resultsEl.innerHTML = `<div class="fsa-results__head"><h2>Looking for suppliers…<small>Your CRM${$('#srcGoogle') && $('#srcGoogle').checked ? ', Google' : ''}${$('#srcAi') && $('#srcAi').checked ? ' and AI' : ''}</small></h2></div><div class="fsa-sups">${'<div class="fsa-skel"></div>'.repeat(3)}</div>`;
    try {
      const where = $('#findWhere').value.trim();
      const res = await api.post('search.php', {
        op: 'run', text: t, location: where, radius: +$('#findRadius').value,
        sources: { crm: $('#srcCrm').checked, google: $('#srcGoogle').checked, ai: $('#srcAi').checked },
        compliant_only: $('#srcCompliant').checked,
      });
      show(res, true);
    } catch (e) {
      resultsEl.innerHTML = '';
      resultsEl.hidden = true;
      home.hidden = false;
      toastError(e);
    } finally {
      busy(go, false);
      $('#find').classList.remove('is-busy');
    }
  }

  function show(res, fresh) {
    S.result = res;
    S.showBelow = false;
    if (fresh) history.replaceState(null, '', '/suppliers_ai/?search=' + res.query_id);
    render();
    if (fresh) resultsEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function sorted(rows) {
    const r = rows.slice();
    if (S.sort === 'near') r.sort((a, b) => (a.distance_km ?? 1e9) - (b.distance_km ?? 1e9) || b.score - a.score);
    else if (S.sort === 'rating') r.sort((a, b) => (b.rating ?? -1) - (a.rating ?? -1) || (b.rating_count ?? 0) - (a.rating_count ?? 0) || b.score - a.score);
    else r.sort((a, b) => b.score - a.score);
    return r;
  }

  function render() {
    const res = S.result;
    if (!res) return;
    home.hidden = true;
    resultsEl.hidden = false;
    const all = res.rows || [];
    const above = all.filter((r) => !r.below);
    const below = all.filter((r) => r.below);
    const visible = S.showBelow ? all : above;
    const crm = sorted(visible.filter((r) => r.source === 'crm'));
    const google = sorted(visible.filter((r) => r.source === 'google'));
    const legacy = sorted(visible.filter((r) => r.source === 'legacy'));
    const read = res.read || {};
    const what = read.trade ? read.trade.label.toLowerCase() : (read.keywords || []).slice(0, 3).join(' ') || 'this';
    const place = res.place ? ` near ${esc(res.place)}` : '';
    const n = visible.length;
    const blurb = [res.by && res.legacy ? '' : '', res.ms ? (res.ms < 1000 ? res.ms + ' ms' : (res.ms / 1000).toFixed(1) + ' s') : '', read.ai ? 'read with AI' : '', res.google ? 'with Google' : '', res.at ? F.ago(res.at) + (res.by ? ' by ' + res.by : '') : ''].filter(Boolean).join(' · ');
    let html = `<div class="fsa-results__head">
        <h2>${n ? `<em>${n}</em> ${n === 1 ? 'supplier' : 'suppliers'}` : 'No suppliers yet'} for ${esc(what)}${place}<small>${esc(blurb)}${read.summary ? ' — “' + esc(read.summary) + '”' : ''}</small></h2>
        <div class="fsa-results__acts">
          <label class="sr-only" for="sortBy">Sort</label>
          <select class="select" id="sortBy"><option value="match"${S.sort === 'match' ? ' selected' : ''}>Best match</option><option value="near"${S.sort === 'near' ? ' selected' : ''}>Closest</option><option value="rating"${S.sort === 'rating' ? ' selected' : ''}>Google rating</option></select>
          ${res.maps ? `<a class="btn" href="${esc(res.maps)}" target="_blank" rel="noopener">${icon('i-globe')}Google Maps</a>` : ''}
          <button class="btn" type="button" data-act="new">${icon('isa-seal')}New search</button>
        </div></div>`;
    const notes = (res.notes || []).map((nt) => `<div class="fsa-note ${nt.tone === 'warn' ? 'fsa-note--warn' : ''}">${icon(nt.tone === 'warn' ? 'alert' : 'i-info', 'i--sm')}<span>${esc(nt.text)}${nt.key === 'google' && BOOT.caps.keys ? ' <a href="/suppliers_ai/settings.php#sources">Add the key</a>' : ''}</span></div>`);
    if (res.legacy) notes.push(`<div class="fsa-note fsa-note--warn">${icon('alert', 'i--sm')}<span>This search was made by the old Suppliers AI, which asked the AI for phone numbers and emails — many were made up. Search again for checked results.</span></div>`);
    if (notes.length) html += `<div class="fsa-notes">${notes.join('')}</div>`;
    const group = (title, ic, rows, sub) => rows.length ? `<section class="fsa-group"><h3 class="fsa-group__head">${icon(ic, 'i--sm')}${esc(title)} <span>${sub || rows.length}</span></h3><div class="fsa-sups">${rows.map(card).join('')}</div></section>` : '';
    html += group('Your suppliers', 'i-building', crm);
    html += group('On Google' + (res.place ? ' near ' + res.place : ''), 'i-globe', google);
    html += group('From the old search', 'i-alert', legacy);
    if (below.length) html += `<div class="fsa-more"><button class="btn" type="button" data-act="below">${S.showBelow ? icon('chev-up') + 'Fold away' : icon('chev-down') + 'Show ' + below.length + ' more that ' + (below.length === 1 ? 'matches' : 'match')} less well</button></div>`;
    if (res.ideas && res.ideas.length) {
      html += `<section class="fsa-group"><h3 class="fsa-group__head">${icon('i-sparkle', 'i--sm')}Ideas to look at <span>from AI — names only, nothing made up</span></h3><div class="fsa-ideas">${res.ideas.map((i) => `
        <div class="fsa-idea"><small>${esc(i.kind || 'Idea')}</small><b>${esc(i.name)}</b><p>${esc(i.why)}</p><a class="fsa-mini" href="${esc(i.maps)}" target="_blank" rel="noopener">${icon('i-globe')}Look it up</a></div>`).join('')}</div></section>`;
    }
    if (!all.length) {
      html += `<div class="fsa-empty"><span class="fsa-empty__seal">${icon('isa-seal')}</span><div><h3>Nobody yet for this one</h3>
        <p>None of your suppliers match those words${res.google ? ', and Google found nothing close' : ''}. Try the trade (“plumber”, “sand supplier”), fewer words, or a wider radius.</p>
        <div class="fsa-chips">${res.maps ? `<a class="fsa-mini" href="${esc(res.maps)}" target="_blank" rel="noopener">${icon('i-globe')}Search Google Maps</a>` : ''}
          <a class="fsa-mini" href="/crm/account_new.php?type=supplier">${icon('plus')}Add a supplier to the CRM</a>
          ${BOOT.caps.configure ? `<a class="fsa-mini" href="/suppliers_ai/settings.php#words">${icon('tag')}Teach it your words</a>` : ''}</div></div></div>`;
    }
    resultsEl.innerHTML = html;
    $('#sortBy').addEventListener('change', (e) => { S.sort = e.target.value; render(); });
  }

  const complianceFlag = (c) => {
    if (!c || c.status === 'none') return '';
    const tone = { valid: 'ok', expiring: 'warn', expired: 'danger', missing: 'warn' }[c.status] || '';
    return `<span class="fsa-flag fsa-flag--${tone}" title="${esc((c.detail || []).join(' · ') || c.label)}">${icon('shield')}${esc(c.blocking ? 'Blocked — ' + c.label.toLowerCase() : c.label)}</span>`;
  };

  function card(r, i) {
    const flags = [];
    if (r.preferred) flags.push(`<span class="fsa-flag fsa-flag--gold">${icon('star')}Preferred</span>`);
    if (r.prospect) flags.push(`<span class="fsa-flag fsa-flag--info">Prospect</span>`);
    if (r.rating != null) flags.push(`<span class="fsa-flag">${icon('star')}${r.rating.toFixed(1)}${r.rating_count ? ' (' + r.rating_count + ')' : ''}</span>`);
    flags.push(complianceFlag(r.compliance));
    const meta = [];
    if (r.contact) meta.push(`<span>${icon('user')}${esc(r.contact)}</span>`);
    if (r.phone) meta.push(`<span>${icon('i-phone')}<a href="tel:${esc(r.dial ? '+' + r.dial : r.phone)}" data-log="call">${esc(r.phone)}</a></span>`);
    if (r.address || r.city) meta.push(`<span>${icon('pin')}${esc(r.address || r.city)}</span>`);
    if (r.distance_km != null) meta.push(`<span>${icon('isa-route')}${r.distance_km < 10 ? r.distance_km.toFixed(1) : Math.round(r.distance_km)} km</span>`);
    const reasons = (r.reasons || []);
    const shownReasons = reasons.slice(0, 4);
    const why = `<ul class="fsa-why">${shownReasons.map((x) => `<li data-tone="${esc(x.tone || '')}">${icon(x.i || 'check')}<span>${esc(x.t)}</span></li>`).join('')}
      ${reasons.length > 4 ? `<li><button class="fsa-why__more" type="button" data-act="why">+ ${reasons.length - 4} more</button></li>` : ''}</ul>`;
    const acts = [];
    if (r.dial) acts.push(`<a class="fsa-mini fsa-mini--wa" href="https://wa.me/${esc(r.dial)}" target="_blank" rel="noopener" data-log="whatsapp" aria-label="WhatsApp ${esc(r.name)}">${icon('i-whatsapp')}WhatsApp</a>`);
    if (r.email) acts.push(`<a class="fsa-mini" href="mailto:${esc(r.email)}" data-log="email" aria-label="Email ${esc(r.name)}">${icon('mail')}Email</a>`);
    if (r.website) acts.push(`<a class="fsa-mini" href="${esc(r.website)}" target="_blank" rel="noopener nofollow" data-log="website">${icon('external')}Website</a>`);
    if (r.directions) acts.push(`<a class="fsa-mini" href="${esc(r.directions)}" target="_blank" rel="noopener" data-log="directions" aria-label="Directions">${icon('isa-route')}</a>`);
    if (r.account_id) acts.push(`<a class="fsa-mini" href="/crm/account_view.php?id=${r.account_id}">${icon('i-building')}CRM</a>`);
    else if (BOOT.caps.write) acts.push(`<button class="fsa-mini" type="button" data-act="crm">${icon('plus')}Add to CRM</button>`);
    const blocked = r.blocked;
    const name = r.account_id ? `<a href="/crm/account_view.php?id=${r.account_id}">${esc(r.name)}</a>` : esc(r.name);
    return `<article class="fsa-sup${picked(r.id) ? ' is-picked' : ''}${blocked ? ' is-blocked' : ''}" data-id="${r.id}" data-source="${esc(r.source)}" style="--i:${i}">
      <div class="fsa-sup__top">${avatar(r.name)}<div class="fsa-sup__name"><h3>${name}</h3><div class="fsa-sup__flags">${flags.join('')}</div></div>${ring(r.score, { small: 'match' })}</div>
      ${meta.length ? `<div class="fsa-sup__meta">${meta.join('')}</div>` : ''}
      ${why}
      <div class="fsa-sup__acts">${acts.join('')}<span class="fsa-grow"></span>
        ${BOOT.caps.write ? `<button class="fsa-pick${picked(r.id) ? ' is-on' : ''}" type="button" data-act="pick" aria-pressed="${picked(r.id)}"${blocked ? ' disabled title="Your company’s policy blocks this supplier until their documents are renewed"' : ''}>${picked(r.id) ? icon('check') + 'On the shortlist' : icon('isa-pin-plus') + 'Shortlist'}</button>` : ''}
      </div></article>`;
  }

  const rowOf = (el) => {
    const c = el.closest('.fsa-sup');
    if (!c || !S.result) return null;
    return (S.result.rows || []).find((r) => r.id === +c.dataset.id) || null;
  };

  // One listener for every card, drawn now or later
  resultsEl.addEventListener('click', async (e) => {
    const logEl = e.target.closest('[data-log]');
    if (logEl) {
      const row = rowOf(logEl);
      if (row) api.post('suppliers.php', { op: 'log', candidate_id: row.id, action: logEl.dataset.log }).catch(() => {});
      return;
    }
    const b = e.target.closest('[data-act]');
    if (!b) return;
    const act = b.dataset.act;
    if (act === 'below') { S.showBelow = !S.showBelow; render(); return; }
    if (act === 'new') { text.focus(); text.select(); $('#find').scrollIntoView({ behavior: 'smooth' }); return; }
    const row = rowOf(b);
    if (!row) return;
    if (act === 'pick') togglePick(row);
    if (act === 'why') {
      const ul = b.closest('.fsa-why');
      ul.innerHTML = row.reasons.map((x) => `<li data-tone="${esc(x.tone || '')}">${icon(x.i || 'check')}<span>${esc(x.t)}</span></li>`).join('');
    }
    if (act === 'crm') addToCrm(row, b);
  });

  async function addToCrm(row, btn, extra = {}) {
    busy(btn, true);
    try {
      const res = await api.post('suppliers.php', { op: 'add', candidate_id: row.id, ...extra });
      if (res.duplicates) {
        busy(btn, false);
        const choice = await dialog({
          title: 'You may have them already', body: `Your CRM has ${res.duplicates.length === 1 ? 'a supplier' : 'suppliers'} like <b>${esc(row.name)}</b>.`,
          choices: [...res.duplicates.map((d) => ({ value: 'link:' + d.id, label: 'Use ' + d.name, hint: d.why.join(', ') })), { value: 'new', label: 'Add ' + row.name + ' as a new supplier', hint: 'As a prospect, tagged with the trade' }],
          confirm: 'Go on',
        });
        if (!choice) return;
        return addToCrm(row, btn, choice === 'new' ? { confirm: 1 } : { link: +choice.slice(5) });
      }
      row.account_id = res.account_id;
      row.source = row.source === 'legacy' ? 'crm' : row.source;
      row.prospect = !res.linked && !res.existing;
      toast(res.linked ? 'Linked to the supplier you have' : 'Added to the CRM as a prospect', { action: { label: 'Open', run: () => { location.href = res.url; } } });
      const el = resultsEl.querySelector(`.fsa-sup[data-id="${row.id}"]`);
      if (el) { el.outerHTML = card(row, 0); const n = resultsEl.querySelector(`.fsa-sup[data-id="${row.id}"]`); if (n) n.classList.add('is-flash'); }
      if (shortlist.items[row.id]) { shortlist.items[row.id].account_id = res.account_id; store.save(shortlist); }
    } catch (e) {
      toastError(e);
    } finally {
      if (btn.isConnected) busy(btn, false);
    }
  }

  // ------------------------------------------------------------ ask for quotes
  function defaultQuoteBy(needed) {
    let q = F.addDays(5);
    if (needed && needed <= q) {
      const d = new Date(needed + 'T00:00:00');
      d.setDate(d.getDate() - 1);
      q = F.ymd(d);
      if (q < F.addDays(1)) q = F.addDays(1);
    }
    return q;
  }
  function askDialog() {
    const items = Object.values(shortlist.items);
    if (!items.length) return;
    const r = (S.result && S.result.read) || S.read || {};
    const max = (BOOT.settings && BOOT.settings.max_recipients) || 8;
    let reqs = (r.requirements || []).slice();
    const m = modal(`<div class="fwm-dialog__head"><h2>Ask for quotes</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body fsa-form" novalidate>
        <p class="fsa-dlg-lead">Say what you need. Next, you will see the email each supplier gets — nothing is sent yet.</p>
        <div class="field"><label class="label" for="rqTitle">What you need quotes for</label><input class="input" id="rqTitle" maxlength="190" value="${esc(r.title || text.value.trim())}" autofocus></div>
        <div class="field"><label class="label" for="rqDetails">Details <span class="fsa-muted">(optional — sizes, brands, access to site…)</span></label><textarea class="textarea" id="rqDetails" rows="3" maxlength="4000" placeholder="E.g. 2 × 150 L geysers to replace, ground floor, access from 07:00"></textarea></div>
        <div class="row">
          <div class="field"><label class="label" for="rqQty">Quantity <span class="fsa-muted">(optional)</span></label><input class="input" id="rqQty" maxlength="80" value="${esc(r.quantity || '')}"></div>
          <div class="field"><label class="label" for="rqWhere">Where</label><input class="input" id="rqWhere" maxlength="160" value="${esc((S.result && S.result.place) || r.location || BOOT.town || '')}"></div>
        </div>
        <div class="row">
          <div class="field"><label class="label" for="rqNeed">Needed by</label><input class="input" id="rqNeed" type="date" value="${esc(r.needed_by || '')}"></div>
          <div class="field"><label class="label" for="rqBy">Quotes by</label><input class="input" id="rqBy" type="date" min="${F.ymd(new Date())}" value="${esc(defaultQuoteBy(r.needed_by))}"></div>
        </div>
        <div class="row">
          <div class="field"><label class="label" for="rqBudget">Budget <span class="fsa-muted">(optional — never sent to suppliers)</span></label><span class="fsa-money"><span>R</span><input id="rqBudget" inputmode="decimal" value="${r.budget ? (r.budget / 100).toFixed(2) : ''}" placeholder="0.00"></span></div>
          <div class="field"><span class="label">Ask them to include</span><div class="fsa-reqs-edit" id="rqReqs"></div></div>
        </div>
        <div class="fsa-form__title">Suppliers <small id="rqCount"></small></div>
        <div class="fsa-picklist" id="rqSups"></div>
      </form>
      <div class="fwm-dialog__foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" id="rqGo">${icon('isa-quote')}Make the request</button></div>`, { width: 640 });
    const drawReqs = () => {
      $('#rqReqs', m).innerHTML = reqs.map((q, i) => `<span class="fsa-tag">${icon('shield', 'i--sm')}${esc(q)}<button type="button" data-rm="${i}" aria-label="Remove ${esc(q)}">${icon('x', 'i--xs')}</button></span>`).join('')
        + `<input class="input" id="rqReqAdd" maxlength="80" placeholder="${reqs.length ? 'Add…' : 'COC, B-BBEE certificate, delivery…'}">`;
      const add = $('#rqReqAdd', m);
      add.addEventListener('keydown', (e) => { if ((e.key === 'Enter' || e.key === ',') && add.value.trim()) { e.preventDefault(); reqs.push(add.value.trim()); drawReqs(); $('#rqReqAdd', m).focus(); } });
    };
    const drawSups = () => {
      const list = Object.values(shortlist.items);
      $('#rqCount', m).textContent = list.length + ' of at most ' + max + (list.some((i) => !i.account_id) ? ' · found on Google: they join your CRM as prospects' : '');
      $('#rqSups', m).innerHTML = list.map((i) => `<div class="fsa-pickrow">${avatar(i.name)}<span class="fsa-pickrow__text"><b>${esc(i.name)}</b><small>${i.account_id ? 'In your CRM' : 'From Google — added to the CRM as a prospect'}</small></span>
        <button class="icon-btn icon-btn--sm" type="button" data-unpick="${i.id}" aria-label="Take ${esc(i.name)} off">${icon('x')}</button></div>`).join('') || '<p class="fsa-panel__empty">Nobody on the shortlist.</p>';
    };
    drawReqs();
    drawSups();
    m.addEventListener('click', (e) => {
      const rm = e.target.closest('[data-rm]');
      if (rm) { reqs.splice(+rm.dataset.rm, 1); drawReqs(); }
      const un = e.target.closest('[data-unpick]');
      if (un) { const row = shortlist.items[+un.dataset.unpick]; if (row) togglePick(row); drawSups(); if (!Object.keys(shortlist.items).length) closeModal(m); }
    });
    $('#rqGo', m).addEventListener('click', async () => {
      const btn = $('#rqGo', m);
      const pending = $('#rqReqAdd', m).value.trim();
      if (pending) reqs.push(pending);
      const budget = $('#rqBudget', m).value.trim();
      if (budget && toCents(budget) === null) { toast('The budget is not an amount in rand.', { type: 'error' }); return; }
      const list = Object.values(shortlist.items);
      if (list.length > max) { toast(`A request goes to at most ${max} suppliers — take some off.`, { type: 'error' }); return; }
      if (!$('#rqTitle', m).value.trim()) { toast('Say what you need quotes for.', { type: 'error' }); $('#rqTitle', m).focus(); return; }
      busy(btn, true);
      try {
        const res = await api.post('requests.php', {
          op: 'create', title: $('#rqTitle', m).value.trim(), details: $('#rqDetails', m).value, quantity: $('#rqQty', m).value.trim(), location: $('#rqWhere', m).value.trim(),
          needed_by: $('#rqNeed', m).value, quote_by: $('#rqBy', m).value, budget: budget ? toCents(budget) / 100 : '', requirements: reqs,
          category: r.trade ? r.trade.key : '', query_id: S.result ? S.result.query_id : 0,
          suppliers: list.map((i) => (i.account_id && i.source === 'crm' ? { account_id: i.account_id, candidate_id: i.id } : { candidate_id: i.id })),
        });
        shortlist = { items: {} };
        store.save(shortlist);
        if (res.skipped && res.skipped.length) sessionStorage.setItem('fsa-skipped', JSON.stringify(res.skipped));
        location.href = res.url + '&ask=1';
      } catch (e) { busy(btn, false); toastError(e); }
    });
    openModal(m);
  }

  // ------------------------------------------------------------ wiring
  if (text) {
    text.addEventListener('input', () => { parse(); text.style.height = 'auto'; text.style.height = Math.min(220, text.scrollHeight) + 'px'; });
    text.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); run(); } });
    $('#findGo').addEventListener('click', run);
    $('#findWhere').addEventListener('input', () => { S.whereTouched = $('#findWhere').value.trim() !== ''; });
    $('#findWhere').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); run(); } });
    $('#exampleBtn').addEventListener('click', () => { text.value = EXAMPLES[exampleAt++ % EXAMPLES.length]; text.dispatchEvent(new Event('input')); text.focus(); });
  }
  const tray = $('#tray');
  if (tray) {
    $('#trayClear').addEventListener('click', async () => {
      if (!(await dialog({ title: 'Clear the shortlist?', body: 'The suppliers stay in the search — only the shortlist is emptied.', confirm: 'Clear' }))) return;
      const ids = Object.keys(shortlist.items);
      shortlist = { items: {} };
      store.save(shortlist);
      drawTray();
      ids.forEach((id) => $$(`.fsa-sup[data-id="${id}"]`).forEach((c) => { c.classList.remove('is-picked'); const b = $('.fsa-pick', c); if (b) { b.classList.remove('is-on'); b.innerHTML = icon('isa-pin-plus') + 'Shortlist'; } }));
    });
    $('#trayAsk').addEventListener('click', askDialog);
  }
  drawTray();

  (async () => {
    if (BOOT.open_search) {
      try {
        const res = await api.get('search.php', { op: 'get', id: BOOT.open_search });
        if (text) { text.value = res.text; parse(); }
        show(res, false);
      } catch (e) { toastError(e); }
    } else if (BOOT.q && text) {
      text.value = BOOT.q;
      parse();
      if (BOOT.go) run(); else text.focus();
    } else if (location.hash === '#find' && text) {
      text.focus();
    }
  })();
})();
