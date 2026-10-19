// /crm/js/settings.js — "Make it yours".
// Everyone: their own layout (theme, density, text size, colour, where the CRM
// opens, saved views) — saved as they go. Admins: the CRM for the whole
// company — colour, wording, parts, pipeline stages, statuses, activity types,
// custom fields, layout, tags, industries & regions, rules, compliance and
// email templates. Data: /crm/api/settings.php (company) and prefs.php (me).
(function () {
  'use strict';
  const { $, $$, esc, icon, api, toast, BOOT, L, dialog, tempModal, openModal, closeModal, sortable } = CRM;
  const IS_ADMIN = !!BOOT.is_admin;
  const PALETTE = ['#7a1f30', '#a8281e', '#c2562f', '#b08d57', '#8f7a2f', '#2f7457', '#2f8f8a', '#4f7cac', '#2f5c84', '#5b4b8a', '#8a6fb8', '#b0507a', '#85766b', '#3d3a36'];
  const nav = $('#setNav');
  const content = $('#setContent');
  let S = null;              // the company payload from settings.php
  const dirty = new Set();   // sections with unsaved changes

  const clone = (o) => JSON.parse(JSON.stringify(o));
  const hexOk = (c) => /^#[0-9a-f]{6}$/i.test(String(c || ''));
  const sw = (name, checked, title, hint = '', attrs = '') => `<label class="switch"><input type="checkbox" name="${esc(name)}" ${checked ? 'checked' : ''} ${attrs}><span class="switch__track"></span><span class="switch__text"><span class="switch__title">${title}</span>${hint ? `<span class="hint">${hint}</span>` : ''}</span></label>`;
  const seg = (name, value, options) => `<div class="seg" role="radiogroup" data-seg="${esc(name)}">${options.map(([v, label]) => `<button type="button" role="radio" aria-checked="${v === value}" class="${v === value ? 'is-on' : ''}" data-v="${esc(v)}">${label}</button>`).join('')}</div>`;
  const segValue = (root, name) => ($(`[data-seg="${name}"] button.is-on`, root) || {}).dataset?.v;
  const colordot = (name, color, label) => `<span class="fwc-colordot" style="--c:${esc(color)}" title="Colour"><input type="color" name="${esc(name)}" value="${esc(color)}" aria-label="${esc(label)}"></span>`;
  const swatches = (name, value, withNone, noneLabel) => `<div class="fwc-swatches" data-swatches="${esc(name)}">
      ${withNone ? `<button class="fwc-swatch fwc-swatch--none${!value ? ' is-on' : ''}" type="button" data-c="" aria-label="${esc(noneLabel)}" title="${esc(noneLabel)}"></button>` : ''}
      ${PALETTE.map((c) => `<button class="fwc-swatch${value && value.toLowerCase() === c ? ' is-on' : ''}" type="button" style="--c:${c}" data-c="${c}" aria-label="Colour ${c}" title="${c}"></button>`).join('')}
      <label class="fwc-swatch fwc-swatch--pick${value && !PALETTE.includes(value.toLowerCase()) ? ' is-on' : ''}" title="Pick any colour" style="${value && !PALETTE.includes(value.toLowerCase()) ? `--c:${esc(value)};background:${esc(value)}` : ''}">${icon('plus', 'i--sm')}<input type="color" value="${esc(value || '#7a1f30')}" aria-label="Pick any colour"></label>
    </div>`;
  const swatchValue = (root, name) => { const on = $(`[data-swatches="${name}"] .is-on`, root); if (!on) return ''; return on.dataset.c !== undefined ? on.dataset.c : ($('input', on) || {}).value || ''; };

  // ------------------------------------------------------------ accent colour
  function accentVars(hex, dark) {
    if (!hexOk(hex)) return '';
    const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.substr(i, 2), 16));
    const h = (x) => Math.max(0, Math.min(255, Math.floor(x))).toString(16).padStart(2, '0');
    const deep = '#' + h(r * 0.78) + h(g * 0.78) + h(b * 0.78);
    const light = '#' + h(r + (255 - r) * 0.45) + h(g + (255 - g) * 0.45) + h(b + (255 - b) * 0.45);
    return dark
      ? `--accent:${light};--accent-hover:${light};--accent-ink:${light};--accent-solid:${hex};--accent-grad:linear-gradient(140deg,${light} 0%,${hex} 46%,${deep} 100%);--accent-soft:rgba(${r},${g},${b},.16);--accent-softer:rgba(${r},${g},${b},.09);--accent-ring:rgba(${r},${g},${b},.42)`
      : `--accent:${hex};--accent-hover:${deep};--accent-ink:${hex};--accent-solid:${hex};--accent-grad:linear-gradient(140deg,${light} 0%,${hex} 46%,${deep} 100%);--accent-soft:rgba(${r},${g},${b},.08);--accent-softer:rgba(${r},${g},${b},.045);--accent-ring:rgba(${r},${g},${b},.32)`;
  }
  function applyAccent(hex) {
    let st = $('#crmVars');
    if (!st) { st = document.createElement('style'); st.id = 'crmVars'; document.head.appendChild(st); }
    st.textContent = hexOk(hex) ? `.fwm.fwc{${accentVars(hex, false)}}.fwm.fwc[data-theme="dark"]{${accentVars(hex, true)}}` : '';
  }
  const currentAccent = () => BOOT.prefs.accent || BOOT.config.accent || '';

  /** Sidebar, brand and search wording follow the saved company settings without a reload. */
  function applyChrome() {
    const c = BOOT.config;
    const brand = $('.fwm-brand__name');
    if (brand) brand.textContent = c.labels.crm;
    const map = { customers: 'customers', suppliers: 'suppliers', pipeline: 'pipeline', tasks: 'tasks', contacts: 'contacts' };
    $$('#nav [data-route]').forEach((a) => {
      const r = a.dataset.route;
      if (map[r]) $('.fwm-nav__label', a).textContent = c.labels[map[r]];
      const on = r === 'overview' || (r === 'compliance' ? c.modules.compliance && c.modules.suppliers : c.modules[r] !== false);
      a.hidden = !on;
    });
    const ph = $('.fwc-search__ph');
    if (ph) ph.textContent = `Search ${c.labels.customers.toLowerCase()}, people, ${c.labels.deals.toLowerCase()}…`;
    const nl = $('#newLabel'), nb = $('#newBtn');
    if (nl && nb) { const first = c.modules.customers ? 'customer' : 'supplier'; nb.dataset.new = first; nl.textContent = 'New ' + c.labels[first].toLowerCase(); }
  }

  // ------------------------------------------------------------- sections
  const SECTIONS = [
    { id: 'me', group: 'Just for me', icon: 'i-user', title: 'My layout', admin: false,
      lead: 'How the CRM looks for you. Changes save as you go and only affect you.',
      keys: 'theme dark light density compact comfortable text size font colour color accent open start landing views saved' },
    { id: 'brand', group: 'How it looks', icon: 'i-palette', title: 'Company colour', admin: true,
      lead: 'The colour everyone in the company sees on buttons, highlights and charts — unless they picked their own.',
      keys: 'colour color accent brand theme' },
    { id: 'words', group: 'How it looks', icon: 'ic-words', title: 'Words', admin: true,
      lead: 'Call things what your team calls them — clients instead of customers, vendors instead of suppliers, opportunities instead of deals.',
      keys: 'labels words names rename customer client supplier vendor deal opportunity pipeline contact task' },
    { id: 'parts', group: 'How it looks', icon: 'ic-power', title: 'Parts of the CRM', admin: true,
      lead: 'Switch off what your company does not use. Nothing is deleted — switch it back on any time.',
      keys: 'modules parts switch off on customers suppliers pipeline tasks contacts activity compliance' },
    { id: 'layout', group: 'How it looks', icon: 'ic-layout', title: 'Layout', admin: true,
      lead: 'Choose and order the panels on the overview and the tabs on every account page.',
      keys: 'layout overview panels tabs order account page dashboard' },
    { id: 'pipeline', group: 'How it works', icon: 'i-kanban', title: 'Pipeline stages', admin: true,
      lead: 'The steps a deal moves through, their colours and how likely a deal at each step is to close.',
      keys: 'pipeline stages deals probability won lost loss reasons stale forecast' },
    { id: 'statuses', group: 'How it works', icon: 'i-status', title: 'Account statuses', admin: true,
      lead: 'Rename and recolour the four statuses a customer or supplier can have.',
      keys: 'status statuses active prospect inactive blocked banned colour' },
    { id: 'activity', group: 'How it works', icon: 'ic-activity', title: 'Kinds of activity', admin: true,
      lead: 'What people can log on an account. Switched-off kinds disappear from the composer; history stays.',
      keys: 'activity call email meeting visit whatsapp note issue log' },
    { id: 'fields', group: 'How it works', icon: 'ic-field', title: 'Custom fields', admin: true,
      lead: 'Add your own fields to customers, suppliers, contacts and deals — dropdowns, dates, amounts, yes/no and more.',
      keys: 'custom fields extra fields dropdown date amount checkbox' },
    { id: 'rules', group: 'How it works', icon: 'ic-rules', title: 'Rules', admin: true,
      lead: 'What must be filled in, how new accounts start, duplicate checks, phone numbers and when an account goes cold.',
      keys: 'rules required compulsory vat registration duplicate phone format currency cold default status tags' },
    { id: 'tags', group: 'Lists', icon: 'i-tag', title: 'Tags', admin: true,
      lead: 'Rename, recolour or remove the tags people put on accounts.',
      keys: 'tags labels colour' },
    { id: 'lists', group: 'Lists', icon: 'i-list', title: 'Industries & regions', admin: true,
      lead: 'The choices for industry and region on every account. Add your own next to the built-in ones.',
      keys: 'industries industry regions region territory lookup list' },
    { id: 'compliance', group: 'Lists', icon: 'i-shield', title: 'Compliance', admin: true,
      lead: 'The documents suppliers must keep up to date, when to remind you and whether to block suppliers who fall behind.',
      keys: 'compliance documents tax clearance bbbee coid expiry reminder block suppliers' },
    { id: 'templates', group: 'Lists', icon: 'i-template', title: 'Email templates', admin: true,
      lead: 'The starting text for welcome emails, requests for quotes and reminders sent from the CRM.',
      keys: 'email templates welcome rfq request quote reminder mail' },
  ];
  const visible = () => SECTIONS.filter((s) => !s.admin || IS_ADMIN);
  const R = {};   // id → { html(), bind(el), save(el), reset(el) }

  function card(sec, body, opts = {}) {
    const footer = opts.footer !== false;
    return `<section class="fwc-card2" id="${sec.id}" data-sec="${sec.id}" aria-labelledby="h-${sec.id}">
      <header>${icon(sec.icon)}<div class="fwc-grow"><h2 id="h-${sec.id}">${esc(sec.title)}</h2><p>${sec.lead}</p></div>${opts.headExtra || ''}</header>
      <div class="fwc-card2__body">${body}</div>
      ${footer ? `<footer><span class="fwc-state" data-state>${esc(opts.state || 'All saved')}</span>
        ${opts.reset !== false ? `<button class="btn btn--ghost" type="button" data-reset>${icon('undo')}${esc(opts.resetLabel || 'Back to default')}</button>` : ''}
        ${opts.save !== false ? `<button class="btn btn--primary" type="button" data-save disabled>${icon('check')}Save</button>` : ''}</footer>` : ''}
    </section>`;
  }
  function setState(el, text, isDirty) {
    const st = $('[data-state]', el);
    if (st) { st.textContent = text; st.classList.toggle('is-dirty', !!isDirty); }
    const b = $('[data-save]', el);
    if (b) b.disabled = !isDirty;
    if (isDirty) dirty.add(el.dataset.sec); else dirty.delete(el.dataset.sec);
  }
  const markDirty = (el) => setState(el, 'Unsaved changes', true);

  function render(id) {
    const sec = SECTIONS.find((s) => s.id === id);
    const old = document.getElementById(id);
    const wrap = document.createElement('div');
    wrap.innerHTML = R[id].html(sec);
    const el = wrap.firstElementChild;
    if (old) old.replaceWith(el); else content.appendChild(el);
    bindCommon(el, id);
    if (R[id].bind) R[id].bind(el);
    dirty.delete(id);
    return el;
  }
  function bindCommon(el, id) {
    // segmented buttons and swatches behave like radio groups everywhere
    el.addEventListener('click', (e) => {
      const sb = e.target.closest('[data-seg] button');
      if (sb) {
        $$('button', sb.parentNode).forEach((b) => { b.classList.toggle('is-on', b === sb); b.setAttribute('aria-checked', b === sb ? 'true' : 'false'); });
        sb.parentNode.dispatchEvent(new CustomEvent('seg', { bubbles: true, detail: sb.dataset.v }));
        if (R[id].instant !== true) markDirty(el);
        return;
      }
      const sw = e.target.closest('.fwc-swatch:not(.fwc-swatch--pick)');
      if (sw) {
        $$('.fwc-swatch', sw.parentNode).forEach((b) => b.classList.toggle('is-on', b === sw));
        sw.parentNode.dispatchEvent(new CustomEvent('swatch', { bubbles: true, detail: sw.dataset.c }));
        if (R[id].instant !== true) markDirty(el);
      }
    });
    el.addEventListener('input', (e) => {
      const pick = e.target.closest('.fwc-swatch--pick input');
      if (pick) {
        const lab = pick.parentNode;
        $$('.fwc-swatch', lab.parentNode).forEach((b) => b.classList.toggle('is-on', b === lab));
        lab.style.background = pick.value;
        lab.parentNode.dispatchEvent(new CustomEvent('swatch', { bubbles: true, detail: pick.value }));
      }
      const dot = e.target.closest('.fwc-colordot input');
      if (dot) dot.parentNode.style.setProperty('--c', dot.value);
      if (R[id].instant !== true && !e.target.closest('[data-nodirty]')) markDirty(el);
    });
    el.addEventListener('change', (e) => { if (R[id].instant !== true && !e.target.closest('[data-nodirty]')) markDirty(el); });
    const save = $('[data-save]', el);
    if (save) save.addEventListener('click', async () => {
      save.disabled = true;
      const prev = save.innerHTML;
      save.innerHTML = '<span class="fwc-spin" aria-hidden="true"></span>Saving…';
      try {
        await R[id].save(el);
        const fresh = document.getElementById(id);
        setState(fresh, 'Saved just now', false);
        toast(SECTIONS.find((s) => s.id === id).title + ' saved');
      } catch (err) {
        save.disabled = false;
        save.innerHTML = prev;
        toast(err.message, { type: 'error' });
      }
    });
    const reset = $('[data-reset]', el);
    if (reset) reset.addEventListener('click', async () => {
      if (!R[id].reset) return;
      try { await R[id].reset(el); } catch (err) { toast(err.message, { type: 'error' }); }
    });
  }
  /** Save to settings.php, refresh the payload and BOOT, re-render the section. */
  async function post(body, rerender) {
    const d = await api.post('settings.php', body);
    S = d;
    BOOT.config = d.config;
    BOOT.stages = d.stages;
    BOOT.tags = d.tags;
    BOOT.lookups = d.lookups;
    BOOT.fields = d.fields.filter((f) => !f.archived);
    applyChrome();
    applyAccent(currentAccent());
    (rerender || []).forEach(render);
    return d;
  }
  async function serverReset(section, id, what) {
    const ok = await dialog({ title: 'Back to default?', body: `${esc(what)} go back to how Flowwork ships them. You can change them again any time.`, confirm: 'Reset' });
    if (!ok) return;
    await post({ op: 'reset', section }, [id]);
    setState(document.getElementById(id), 'Back to default', false);
    toast('Reset to default');
  }

  // ---------------------------------------------------------------- me
  R.me = {
    instant: true,
    html(sec) {
      const p = BOOT.prefs;
      const m = BOOT.config.modules;
      const landings = [['overview', 'Overview'], ['customers', L('customers')], ['suppliers', L('suppliers')], ['pipeline', L('pipeline')], ['tasks', L('tasks')], ['contacts', L('contacts')], ['activity', 'Activity']]
        .filter(([k]) => k === 'overview' || m[k]);
      const views = p.views || [];
      return card(sec, `
        <div class="fwc-me">
          <div class="fwc-me__row"><div><b>Theme</b><span class="hint">Also on the moon and sun button at the top.</span></div>${seg('theme', document.body.dataset.theme, [['light', icon('sun', 'i--sm') + 'Light'], ['dark', icon('moon', 'i--sm') + 'Dark']])}</div>
          <div class="fwc-me__row"><div><b>Density</b><span class="hint">Compact fits more rows on the screen.</span></div>${seg('density', p.density, [['comfortable', 'Comfortable'], ['compact', 'Compact']])}</div>
          <div class="fwc-me__row"><div><b>Text size</b><span class="hint">Easier on the eyes, or more on the page.</span></div>${seg('text', p.text, [['sm', '<span style="font-size:11px">Aa</span> Small'], ['md', '<span style="font-size:13px">Aa</span> Normal'], ['lg', '<span style="font-size:15px">Aa</span> Large']])}</div>
          <div class="fwc-me__row fwc-me__row--wide"><div><b>My colour</b><span class="hint">Only you see this. The first swatch follows the company colour${BOOT.config.accent ? '' : ' (the Flowwork wine)'}.</span></div>${swatches('accent', p.accent, true, 'Use the company colour')}</div>
          <div class="fwc-me__row"><div><b>Open the CRM on</b><span class="hint">Where <a href="/crm/">/crm/</a> starts when you arrive.</span></div>
            <select class="select" name="landing" style="max-width:220px">${landings.map(([k, label]) => `<option value="${k}" ${p.landing === k ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select></div>
          <div class="fwc-me__row fwc-me__row--wide"><div><b>My saved views</b><span class="hint">Save a filtered list with the bookmark button above any list. They show in the sidebar.</span></div>
            <div class="fwc-views">${views.length ? views.map((v) => `<div class="fwc-viewrow"><a href="/crm/#${esc(v.route)}${v.query ? '?' + esc(v.query) : ''}">${icon('bookmark')}<span>${esc(v.name)}</span><small>${esc(L(v.route) || v.route)}</small></a><button class="icon-btn" type="button" data-view-del="${esc(v.id)}" aria-label="Remove ${esc(v.name)}" data-tip="Remove">${icon('x')}</button></div>`).join('')
              : '<span class="hint">No saved views yet.</span>'}</div></div>
        </div>`, { state: 'Changes save as you go', resetLabel: 'Reset my layout', save: false });
    },
    bind(el) {
      const saveMe = async (changes, msg) => {
        setState(el, 'Saving…', false);
        try {
          await CRM.savePrefs(changes);
          setState(el, 'Saved just now', false);
          if (msg) toast(msg);
        } catch (err) { setState(el, 'Not saved', false); toast(err.message, { type: 'error' }); }
      };
      el.addEventListener('seg', (e) => {
        const name = e.target.dataset.seg, v = e.detail;
        if (name === 'theme') { if (document.body.dataset.theme !== v) $('#themeToggle').click(); return; }
        if (name === 'density') document.body.classList.toggle('is-compact', v === 'compact');
        if (name === 'text') { document.body.classList.remove('text-sm', 'text-md', 'text-lg'); document.body.classList.add('text-' + v); }
        saveMe({ [name]: v });
      });
      el.addEventListener('swatch', (e) => {
        BOOT.prefs.accent = e.detail || '';
        applyAccent(currentAccent());
        clearTimeout(el._t);
        el._t = setTimeout(() => saveMe({ accent: e.detail || '' }), 250);
      });
      $('[name=landing]', el).addEventListener('change', (e) => saveMe({ landing: e.target.value }, 'The CRM will open on this page'));
      el.addEventListener('click', async (e) => {
        const del = e.target.closest('[data-view-del]');
        if (!del) return;
        try {
          const d = await api.post('prefs.php', { op: 'view_remove', id: del.dataset.viewDel });
          BOOT.prefs = d.prefs;
          const link = $(`#viewsNav [data-view="${CSS.escape(del.dataset.viewDel)}"]`);
          if (link) link.remove();
          if (!BOOT.prefs.views.length && $('#savedViews')) $('#savedViews').hidden = true;
          render('me');
          toast('View removed');
        } catch (err) { toast(err.message, { type: 'error' }); }
      });
      // the top-bar theme button keeps this switch honest
      const tt = $('#themeToggle');
      if (tt && !tt._fwcBound) {
        tt._fwcBound = true;
        tt.addEventListener('click', () => setTimeout(() => {
          const me = document.getElementById('me');
          if (!me) return;
          $$('[data-seg="theme"] button', me).forEach((b) => { const on = b.dataset.v === document.body.dataset.theme; b.classList.toggle('is-on', on); b.setAttribute('aria-checked', on); });
          refreshPreview();
        }, 0));
      }
    },
    async reset() {
      const ok = await dialog({ title: 'Reset your layout?', body: 'Your density, text size, colour, landing page, list columns and saved views go back to the defaults. Company settings are not touched.', confirm: 'Reset my layout' });
      if (!ok) return;
      const d = await api.post('prefs.php', { op: 'reset' });
      BOOT.prefs = d.prefs;
      document.body.classList.remove('is-compact', 'text-sm', 'text-lg');
      document.body.classList.add('text-md');
      applyAccent(currentAccent());
      const sv = $('#savedViews');
      if (sv) { sv.hidden = true; $('#viewsNav').innerHTML = ''; }
      render('me');
      toast('Your layout is back to the defaults');
    },
  };

  // -------------------------------------------------------------- brand
  function previewHtml() {
    return `<div class="fwc-preview" id="brandPreview">
      <button class="btn btn--primary" type="button" tabindex="-1">${icon('plus')}New ${esc(L('customer').toLowerCase())}</button>
      <span class="fwc-prevchip">${icon('ic-trophy', 'i--sm')}Won this month</span>
      <label class="switch" style="padding:0"><input type="checkbox" checked tabindex="-1"><span class="switch__track"></span></label>
      <div class="seg"><button type="button" class="is-on" tabindex="-1">Board</button><button type="button" tabindex="-1">List</button></div>
      <a href="#brand" class="fwc-prevlink" tabindex="-1">A link looks like this</a>
      <span class="fwc-prevbar"><i style="width:68%"></i></span>
    </div>`;
  }
  function refreshPreview() {
    const box = $('#brandPreview');
    if (!box) return;
    const c = swatchValue(document.getElementById('brand'), 'accent');
    box.setAttribute('style', c ? accentVars(c, document.body.dataset.theme === 'dark') : '');
  }
  R.brand = {
    html(sec) {
      return card(sec, `${swatches('accent', S.config.accent, true, 'Flowwork wine (default)')}
        ${previewHtml()}
        ${BOOT.prefs.accent ? `<p class="hint" style="margin-top:10px">${icon('i-user', 'i--xs')} You picked your own colour under <a href="#me">My layout</a>, so the preview above shows what everyone else will see.</p>` : ''}`);
    },
    bind(el) { el.addEventListener('swatch', refreshPreview); refreshPreview(); },
    async save(el) { await post({ op: 'look', accent: swatchValue(el, 'accent') }, ['brand']); },
    reset: () => serverReset('look', 'brand', 'The company colour will'),
  };

  // -------------------------------------------------------------- words
  const WORDS = [['crm', 'The CRM itself', null], ['customer', 'One customer', 'customers'], ['supplier', 'One supplier', 'suppliers'], ['deal', 'One deal', 'deals'],
    ['contact', 'One contact', 'contacts'], ['task', 'One task', 'tasks'], ['pipeline', 'The pipeline', null]];
  R.words = {
    html(sec) {
      const l = S.config.labels, d = S.defaults.labels;
      const inp = (k) => `<input class="input" name="${k}" maxlength="30" value="${esc(l[k] !== d[k] ? l[k] : '')}" placeholder="${esc(d[k])}" aria-label="${esc(d[k])}">`;
      return card(sec, `<div class="fwc-words">
          <div class="fwc-words__head"><span></span><span class="label">One</span><span class="label">Many</span></div>
          ${WORDS.map(([k, hint, plural]) => `<div class="fwc-words__row"><span class="fwc-words__name">${esc(hint.replace(/^One /, ''))}</span>${inp(k)}${plural ? inp(plural) : '<span></span>'}</div>`).join('')}
        </div>
        <div class="fwc-preview fwc-preview--words" id="wordsPreview"></div>`);
    },
    bind(el) {
      const prev = () => {
        const v = (k) => ($(`[name="${k}"]`, el).value.trim() || S.defaults.labels[k]);
        $('#wordsPreview', el).innerHTML = `<span class="fwc-prevtitle">${icon('ic-handshake', 'i--sm')}${esc(v('crm'))}</span>
          <span class="fwc-prevchip">${icon('plus', 'i--sm')}New ${esc(v('customer').toLowerCase())}</span>
          <span class="fwc-prevchip">${icon('ic-supplier', 'i--sm')}${esc(v('suppliers'))}</span>
          <span class="fwc-prevchip">${icon('i-kanban', 'i--sm')}${esc(v('pipeline'))} · 12 open ${esc(v('deals').toLowerCase())}</span>
          <span class="fwc-prevchip">${icon('i-check-square', 'i--sm')}3 ${esc(v('tasks').toLowerCase())} due</span>
          <span class="fwc-prevchip">${icon('i-users', 'i--sm')}Add a ${esc(v('contact').toLowerCase())}</span>`;
      };
      el.addEventListener('input', prev);
      prev();
    },
    async save(el) {
      const labels = {};
      $$('input', el).forEach((i) => { labels[i.name] = i.value.trim(); });
      await post({ op: 'labels', labels }, ['words']);
    },
    reset: () => serverReset('labels', 'words', 'All the words will'),
  };

  // -------------------------------------------------------------- parts
  const PART_HINTS = {
    customers: () => `The people and companies you sell to.`,
    suppliers: () => `Who you buy from, with their documents and what you owe them.`,
    pipeline: () => `${L('deals')} moving through stages to won or lost, with a forecast.`,
    tasks: () => `Follow-ups and reminders, with a nudge on your phone when they are due.`,
    contacts: () => `Everyone at your ${L('customers').toLowerCase()} and ${L('suppliers').toLowerCase()} in one list.`,
    activity: () => `The company-wide feed of calls, meetings, emails and notes.`,
    compliance: () => `Supplier documents and expiry dates. Needs ${L('suppliers').toLowerCase()}.`,
  };
  const PART_NAMES = { customers: () => L('customers'), suppliers: () => L('suppliers'), pipeline: () => L('pipeline'), tasks: () => L('tasks'), contacts: () => L('contacts'), activity: () => 'Activity feed', compliance: () => 'Compliance' };
  R.parts = {
    html(sec) {
      const m = S.config.modules;
      return card(sec, `<div class="fwc-checks fwc-checks--wide">${Object.keys(PART_HINTS).map((k) => sw(k, m[k], esc(PART_NAMES[k]()), esc(PART_HINTS[k]()))).join('')}</div>`);
    },
    async save(el) {
      const modules = {};
      $$('input[type=checkbox]', el).forEach((i) => { modules[i.name] = i.checked; });
      await post({ op: 'modules', modules }, ['parts', 'me']);
    },
    reset: () => serverReset('modules', 'parts', 'All parts will be switched on and'),
  };

  // -------------------------------------------------------------- layout
  function sortRows(list, on, labels, locked = []) {
    return `<div class="fwc-sortable" data-list>${list.map((k) => `<div class="fwc-srow${on.includes(k) ? '' : ' is-off'}" draggable="true" data-k="${esc(k)}" tabindex="0" aria-label="${esc(labels[k])} — Alt and arrow keys to move">
      <span class="fwc-srow__grip">${icon('i-grip')}</span>
      <span class="fwc-srow__main"><b>${esc(labels[k])}</b></span>
      <span class="fwc-srow__acts">${sw('on_' + k, on.includes(k), '<span class="sr-only">Show</span>', '', locked.includes(k) ? 'disabled' : '')}</span></div>`).join('')}</div>`;
  }
  R.layout = {
    html(sec) {
      const c = S.config, o = S.options;
      const ovOn = c.overview.filter((k) => !c.overview_off.includes(k));
      const tabOn = c.account_tabs.filter((k) => !c.account_tabs_off.includes(k));
      return card(sec, `<div class="fwc-cols2">
          <div><h3 class="fwc-subh">${icon('ic-overview', 'i--sm')}Overview panels</h3><p class="hint" style="margin:0 0 10px">Drag to reorder. Panels for parts that are switched off hide by themselves.</p><div data-which="overview">${sortRows(c.overview, ovOn, o.overview)}</div></div>
          <div><h3 class="fwc-subh">${icon('i-panel-right', 'i--sm')}Account page tabs</h3><p class="hint" style="margin:0 0 10px">The first tab opens when you visit an account. Activity is always there.</p><div data-which="tabs">${sortRows(c.account_tabs, tabOn, o.account_tabs, ['activity'])}</div></div>
        </div>`);
    },
    bind(el) {
      $$('[data-list]', el).forEach((list) => sortable(list, '.fwc-srow', () => markDirty(el)));
      el.addEventListener('change', (e) => { const row = e.target.closest('.fwc-srow'); if (row) row.classList.toggle('is-off', !e.target.checked); });
    },
    async save(el) {
      const read = (which) => {
        const rows = $$(`[data-which="${which}"] .fwc-srow`, el);
        return [rows.map((r) => r.dataset.k), rows.filter((r) => !$('input', r).checked).map((r) => r.dataset.k)];
      };
      const [overview, overviewOff] = read('overview');
      const [tabs, tabsOff] = read('tabs');
      if (overviewOff.length === overview.length) throw new Error('Keep at least one panel on the overview.');
      await post({ op: 'layout', overview, overview_off: overviewOff, account_tabs: tabs, account_tabs_off: tabsOff }, ['layout']);
    },
    reset: () => serverReset('layout', 'layout', 'The overview panels and account tabs will'),
  };

  // -------------------------------------------------------------- pipeline
  function stageRow(s, n) {
    const open = s.kind === 'open';
    return `<div class="fwc-srow fwc-stagerow" ${open ? 'draggable="true" tabindex="0"' : ''} data-key="${esc(s.key)}" data-kind="${esc(s.kind)}">
      <span class="fwc-srow__grip">${open ? icon('i-grip') : icon(s.kind === 'won' ? 'ic-trophy' : 'ic-lost')}</span>
      <span class="fwc-srow__main">
        ${colordot('color', s.color, 'Colour of ' + s.label)}
        <input class="input" name="label" maxlength="60" value="${esc(s.label)}" aria-label="Stage name" style="flex:1;min-width:140px">
        ${open ? `<span class="fwc-prob"><input class="input" name="probability" type="number" min="0" max="100" step="5" value="${Number(s.probability) || 0}" aria-label="Chance to win (%)"><span>%</span></span>`
               : `<span class="fwc-locked">${icon('i-lock', 'i--xs')}${s.kind === 'won' ? '100%' : '0%'}</span>`}
        ${n ? `<span class="fwc-count" title="Deals at this stage">${n} ${n === 1 ? L('deal').toLowerCase() : L('deals').toLowerCase()}</span>` : ''}
      </span>
      <span class="fwc-srow__acts">${open ? `<button class="icon-btn" type="button" data-del aria-label="Remove ${esc(s.label)}" data-tip="Remove">${icon('trash')}</button>` : ''}</span>
    </div>`;
  }
  R.pipeline = {
    html(sec) {
      const st = S.stages, n = S.stage_deals || {};
      const reasons = S.config.loss_reasons || [];
      return card(sec, `
        <div class="fwc-stages fwc-stages--preview" id="stagePreview" aria-hidden="true"></div>
        <div class="fwc-sortable" id="stOpen">${st.filter((s) => s.kind === 'open').map((s) => stageRow(s, n[s.key] || 0)).join('')}</div>
        <button class="btn btn--ghost btn--sm" type="button" id="stAdd" style="margin-top:8px">${icon('plus')}Add a stage</button>
        <h3 class="fwc-subh" style="margin-top:18px">${icon('i-flag', 'i--sm')}How deals end</h3>
        <div class="fwc-sortable">${st.filter((s) => s.kind !== 'open').map((s) => stageRow(s, n[s.key] || 0)).join('')}</div>
        <div class="row" style="margin-top:18px">
          <div class="field"><label class="label" for="staleDays">Needs attention after</label><span class="fwc-inline"><input class="input" id="staleDays" name="stale_days" type="number" min="3" max="180" value="${S.config.stale_days}" style="width:90px"> days without a stage change</span></div>
        </div>
        <div class="field" style="margin-top:16px"><span class="label">Reasons for losing a deal</span>
          <div class="fwc-taginput" id="reasons">${reasons.map((r) => `<span class="fwc-tag" style="--c:#85766b" data-reason="${esc(r)}"><span>${esc(r)}</span><button type="button" data-unreason aria-label="Remove ${esc(r)}">${icon('x', 'i--xs')}</button></span>`).join('')}<input id="reasonIn" maxlength="60" placeholder="Add a reason and press Enter" aria-label="Add a reason"></div>
          <span class="hint">People pick one when a deal is lost, so you can see why deals slip away.</span></div>`);
    },
    bind(el) {
      const host = $('#stOpen', el);
      const preview = () => {
        $('#stagePreview', el).innerHTML = $$('.fwc-stagerow', el).map((r) => `<button type="button" tabindex="-1" style="--c:${esc($('[name=color]', r).value)}" class="is-on">${esc($('[name=label]', r).value || '…')}</button>`).join('');
      };
      sortable(host, '.fwc-srow', () => { markDirty(el); preview(); });
      el.addEventListener('input', preview);
      $('#stAdd', el).addEventListener('click', () => {
        if ($$('.fwc-srow', host).length >= 15) { toast('Use at most 15 open stages.', { type: 'error' }); return; }
        const used = $$('[name=color]', el).map((i) => i.value.toLowerCase());
        const color = PALETTE.find((c) => !used.includes(c)) || '#85766b';
        host.insertAdjacentHTML('beforeend', stageRow({ key: '', label: 'New stage', color, probability: 50, kind: 'open' }, 0));
        const inp = $('.fwc-srow:last-child [name=label]', host);
        inp.focus(); inp.select();
        markDirty(el); preview();
      });
      el.addEventListener('click', (e) => {
        const del = e.target.closest('[data-del]');
        if (del) {
          if ($$('.fwc-srow', host).length <= 1) { toast('Keep at least one open stage.', { type: 'error' }); return; }
          del.closest('.fwc-srow').remove();
          markDirty(el); preview();
          return;
        }
        const un = e.target.closest('[data-unreason]');
        if (un) { un.closest('.fwc-tag').remove(); markDirty(el); }
      });
      const rin = $('#reasonIn', el);
      const addReason = () => {
        const v = rin.value.trim();
        if (!v) return;
        if ($$('[data-reason]', el).some((t) => t.dataset.reason.toLowerCase() === v.toLowerCase())) { rin.value = ''; return; }
        rin.insertAdjacentHTML('beforebegin', `<span class="fwc-tag" style="--c:#85766b" data-reason="${esc(v)}"><span>${esc(v)}</span><button type="button" data-unreason aria-label="Remove ${esc(v)}">${icon('x', 'i--xs')}</button></span>`);
        rin.value = '';
        markDirty(el);
      };
      rin.setAttribute('data-nodirty', '');
      rin.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); addReason(); }
        if (e.key === 'Backspace' && !rin.value) { const last = $$('[data-reason]', el).pop(); if (last) { last.remove(); markDirty(el); } }
      });
      rin.addEventListener('blur', addReason);
      $('#reasons', el).addEventListener('click', (e) => { if (e.target.id === 'reasons') rin.focus(); });
      preview();
    },
    async save(el) {
      const stages = $$('.fwc-stagerow', el).map((r) => ({
        key: r.dataset.key, kind: r.dataset.kind, label: $('[name=label]', r).value.trim(), color: $('[name=color]', r).value,
        probability: r.dataset.kind === 'open' ? Number($('[name=probability]', r).value) || 0 : (r.dataset.kind === 'won' ? 100 : 0),
      }));
      if (stages.some((s) => !s.label)) throw new Error('Every stage needs a name.');
      const keep = stages.map((s) => s.key);
      const gone = S.stages.filter((s) => s.kind === 'open' && !keep.includes(s.key) && (S.stage_deals[s.key] || 0) > 0);
      if (gone.length) {
        const n = gone.reduce((a, s) => a + S.stage_deals[s.key], 0);
        const first = stages.find((s) => s.kind === 'open');
        const ok = await dialog({ title: `Move ${n} ${n === 1 ? L('deal').toLowerCase() : L('deals').toLowerCase()}?`,
          body: `${esc(gone.map((s) => s.label).join(', '))} still ${n === 1 ? 'has a deal' : 'have deals'}. ${n === 1 ? 'It moves' : 'They move'} to <b>${esc(first.label)}</b>.`, confirm: 'Move and save' });
        if (!ok) throw new Error('Nothing was saved.');
      }
      await api.post('settings.php', { op: 'stages', stages });
      await post({ op: 'thresholds', cold_days: S.config.cold_days, stale_days: Number($('[name=stale_days]', el).value) || 21,
        loss_reasons: $$('[data-reason]', el).map((t) => t.dataset.reason) }, ['pipeline']);
    },
    async reset() {
      const ok = await dialog({ title: 'Back to the standard stages?', body: 'Prospect, Qualifying, Proposal, Negotiation, Won and Lost come back. Deals on your own stages move to Prospect.', confirm: 'Reset stages' });
      if (!ok) return;
      await post({ op: 'reset', section: 'stages' }, ['pipeline']);
      toast('Stages reset');
    },
  };

  // -------------------------------------------------------------- statuses
  const STATUS_HINT = { active: 'Doing business with you now.', prospect: 'Not yet a customer or supplier — still talking.', inactive: 'No longer active, kept for the history.', banned: 'Do not trade. Flagged in red everywhere.' };
  R.statuses = {
    html(sec) {
      const st = S.config.statuses;
      return card(sec, `<div class="fwc-sortable">${Object.keys(st).map((k) => `<div class="fwc-srow fwc-srow--plain" data-k="${k}">
          <span class="fwc-srow__grip">${colordot('color', st[k].color, 'Colour')}</span>
          <span class="fwc-srow__main"><input class="input" name="label" maxlength="30" value="${esc(st[k].label)}" aria-label="Name for ${esc(S.defaults.statuses[k].label)}" style="max-width:220px"><span class="hint">${esc(STATUS_HINT[k] || '')}</span></span>
          <span class="fwc-srow__acts"><span class="fwc-pill" data-pill style="--c:${esc(st[k].color)}">${esc(st[k].label)}</span></span></div>`).join('')}</div>`);
    },
    bind(el) {
      el.addEventListener('input', (e) => {
        const row = e.target.closest('.fwc-srow');
        if (!row) return;
        const pill = $('[data-pill]', row);
        pill.textContent = $('[name=label]', row).value || '…';
        pill.style.setProperty('--c', $('[name=color]', row).value);
      });
    },
    async save(el) {
      const statuses = {};
      $$('.fwc-srow', el).forEach((r) => { statuses[r.dataset.k] = { label: $('[name=label]', r).value.trim(), color: $('[name=color]', r).value }; });
      await post({ op: 'statuses', statuses }, ['statuses']);
    },
    reset: () => serverReset('statuses', 'statuses', 'Status names and colours will'),
  };

  // -------------------------------------------------------------- activity
  const ACT_HINT = { note: 'A quick note for the team.', call: 'Phone calls, with a follow-up if needed.', email: 'Emails logged by hand (sent ones log themselves).', meeting: 'Meetings in person or online.', visit: 'Site or office visits.', whatsapp: 'WhatsApp conversations.', issue: 'Problems or complaints to keep an eye on.' };
  R.activity = {
    html(sec) {
      const a = S.config.activity, meta = S.options.activity;
      return card(sec, `<div class="fwc-checks fwc-checks--wide">${Object.keys(a).map((k) => `<label class="switch"><input type="checkbox" name="${k}" ${a[k] ? 'checked' : ''}><span class="switch__track"></span><span class="switch__text"><span class="switch__title fwc-acttitle">${CRM.actIcon(k)}${esc((meta[k] && meta[k].label) || k)}</span><span class="hint">${esc(ACT_HINT[k] || '')}</span></span></label>`).join('')}</div>`);
    },
    async save(el) {
      const activity = {};
      $$('input[type=checkbox]', el).forEach((i) => { activity[i.name] = i.checked; });
      await post({ op: 'activity', activity }, ['activity']);
    },
    reset: () => serverReset('activity', 'activity', 'All kinds of activity will be switched on and'),
  };

  // -------------------------------------------------------------- fields
  let fieldEntity = 'account';
  const ENT = () => ({ account: L('customers') + ' & ' + L('suppliers').toLowerCase(), contact: L('contacts'), deal: L('deals') });
  const APPLIES = () => ({ all: 'Both', customer: L('customers') + ' only', supplier: L('suppliers') + ' only' });
  R.fields = {
    instant: true,
    html(sec) {
      const all = S.fields.filter((f) => f.entity === fieldEntity);
      const live = all.filter((f) => !f.archived), gone = all.filter((f) => f.archived);
      const use = S.field_use || {};
      const row = (f) => `<div class="fwc-srow" ${f.archived ? '' : 'draggable="true" tabindex="0"'} data-id="${f.id}">
        <span class="fwc-srow__grip">${f.archived ? icon('i-archive') : icon('i-grip')}</span>
        <span class="fwc-srow__main"><span class="fwc-fieldname"><b>${esc(f.label)}</b><span class="fwc-fieldrow__type">${esc(S.field_types[f.type] || f.type)}${f.options.length ? ' · ' + f.options.length + ' choices' : ''}</span></span>
          ${fieldEntity === 'account' && f.applies_to !== 'all' ? `<span class="fwc-badge">${esc(APPLIES()[f.applies_to])}</span>` : ''}
          ${f.required ? '<span class="fwc-badge fwc-badge--warn">Required</span>' : ''}
          ${f.show_in_list ? `<span class="fwc-badge">${icon('i-columns', 'i--xs')}Column</span>` : ''}
          ${use[f.id] ? `<span class="fwc-count">${use[f.id]} filled in</span>` : ''}</span>
        <span class="fwc-srow__acts">${f.archived
          ? `<button class="btn btn--sm" type="button" data-restore="${f.id}">${icon('undo')}Restore</button><button class="icon-btn" type="button" data-purge="${f.id}" aria-label="Delete ${esc(f.label)} for good" data-tip="Delete for good">${icon('trash')}</button>`
          : `<button class="icon-btn" type="button" data-edit="${f.id}" aria-label="Edit ${esc(f.label)}" data-tip="Edit">${icon('pen')}</button><button class="icon-btn" type="button" data-archive="${f.id}" aria-label="Hide ${esc(f.label)}" data-tip="Hide">${icon('eye-off')}</button>`}</span></div>`;
      return card(sec, `
        <div class="fwc-fieldbar">${seg('entity', fieldEntity, Object.entries(ENT()))}<button class="btn btn--primary btn--sm" type="button" data-addfield>${icon('plus')}Add a field</button></div>
        ${live.length ? `<div class="fwc-sortable" id="fieldList">${live.map(row).join('')}</div>`
          : CRM.emptyState('ic-field', 'No custom fields here yet', `Add things like ${fieldEntity === 'deal' ? 'a lead source, a close reason or a project size' : fieldEntity === 'contact' ? 'a birthday, a LinkedIn link or a preferred language' : 'an account number, a credit limit or a delivery day'}.`, '', true)}
        ${gone.length ? `<details class="fwc-archived"><summary>${icon('i-archive', 'i--sm')}${gone.length} hidden field${gone.length === 1 ? '' : 's'}</summary><div class="fwc-sortable">${gone.map(row).join('')}</div></details>` : ''}`,
        { footer: false });
    },
    bind(el) {
      el.addEventListener('seg', (e) => { if (e.target.dataset.seg === 'entity') { fieldEntity = e.detail; render('fields'); } });
      const list = $('#fieldList', el);
      if (list) sortable(list, '.fwc-srow', async (rows) => {
        try { await post({ op: 'fields_order', ids: rows.map((r) => Number(r.dataset.id)) }); toast('Order saved'); } catch (err) { toast(err.message, { type: 'error' }); render('fields'); }
      });
      el.addEventListener('click', async (e) => {
        const b = e.target.closest('[data-addfield],[data-edit],[data-archive],[data-restore],[data-purge]');
        if (!b) return;
        const f = S.fields.find((x) => x.id === Number(b.dataset.edit || b.dataset.archive || b.dataset.restore || b.dataset.purge));
        try {
          if (b.dataset.addfield !== undefined) return fieldEditor(null);
          if (b.dataset.edit) return fieldEditor(f);
          if (b.dataset.archive) {
            await post({ op: 'field_delete', id: f.id, values: false }, ['fields']);
            toast(`“${f.label}” hidden — what people filled in is kept`, { action: { label: 'Undo', run: () => post({ op: 'field_restore', id: f.id }, ['fields']).catch(CRM.toastError) } });
          }
          if (b.dataset.restore) { await post({ op: 'field_restore', id: f.id }, ['fields']); toast(`“${f.label}” is back`); }
          if (b.dataset.purge) {
            const n = (S.field_use || {})[f.id] || 0;
            const ok = await dialog({ title: `Delete “${f.label}” for good?`, body: n ? `What was filled in on ${n} record${n === 1 ? '' : 's'} is deleted too. This cannot be undone.` : 'This cannot be undone.', confirm: 'Delete for good', danger: true });
            if (!ok) return;
            await post({ op: 'field_delete', id: f.id, values: true }, ['fields']);
            toast('Field deleted');
          }
        } catch (err) { toast(err.message, { type: 'error' }); }
      });
    },
  };
  function fieldEditor(f) {
    const isNew = !f;
    f = f || { id: 0, entity: fieldEntity, applies_to: 'all', label: '', type: 'text', options: [], help: '', required: false, show_in_list: false };
    const m = tempModal(`<div class="fwm-dialog__head"><h2>${isNew ? 'New field' : 'Edit field'}</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body" id="fieldForm" novalidate>
        <div class="field"><label class="label" for="fLabel">Name</label><input class="input" id="fLabel" maxlength="80" value="${esc(f.label)}" placeholder="e.g. Credit limit" required autofocus></div>
        <div class="row">
          <div class="field"><label class="label" for="fType">Kind</label><select class="select" id="fType">${Object.entries(S.field_types).map(([k, v]) => `<option value="${k}" ${f.type === k ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select></div>
          ${f.entity === 'account' ? `<div class="field"><label class="label" for="fApplies">Shows on</label><select class="select" id="fApplies">${Object.entries(APPLIES()).map(([k, v]) => `<option value="${k}" ${f.applies_to === k ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select></div>` : ''}
        </div>
        <div class="field" id="fOptsWrap"><label class="label" for="fOpts">Choices</label><textarea class="textarea" id="fOpts" rows="4" placeholder="One per line">${esc(f.options.join('\n'))}</textarea><span class="hint">One choice per line, up to 100.</span></div>
        <div class="field"><label class="label" for="fHelp">Help text <span style="text-transform:none;letter-spacing:0;font-weight:400">(optional)</span></label><input class="input" id="fHelp" maxlength="255" value="${esc(f.help)}" placeholder="Shown under the field"></div>
        <div style="margin-top:10px">${sw('required', f.required, 'Required', 'People cannot save without filling it in.')}
          ${f.entity === 'account' ? sw('show_in_list', f.show_in_list, 'Offer as a list column', 'Pick it in the column chooser above the lists.') : ''}</div>
        ${!isNew && (S.field_use || {})[f.id] ? `<p class="hint" style="margin-top:8px">${icon('alert', 'i--xs')} Filled in on ${S.field_use[f.id]} record(s). Changing the kind only works between kinds that keep the values.</p>` : ''}
      </form>
      <div class="fwm-dialog__foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="submit" form="fieldForm">${icon('check')}${isNew ? 'Add field' : 'Save'}</button></div>`, '', 'width:min(520px,100%)');
    const type = $('#fType', m);
    const sync = () => { $('#fOptsWrap', m).hidden = !['select', 'multiselect'].includes(type.value); };
    type.addEventListener('change', sync);
    sync();
    $('#fieldForm', m).addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = $('[type=submit]', m);
      btn.disabled = true;
      try {
        await post({ op: 'field_save', id: f.id, entity: f.entity, label: $('#fLabel', m).value.trim(), type: type.value,
          applies_to: ($('#fApplies', m) || {}).value || 'all', options: $('#fOpts', m).value, help: $('#fHelp', m).value.trim(),
          required: $('[name=required]', m).checked, show_in_list: !!($('[name=show_in_list]', m) || {}).checked }, ['fields']);
        closeModal(m);
        toast(isNew ? 'Field added' : 'Field saved');
      } catch (err) { btn.disabled = false; toast(err.message, { type: 'error' }); }
    });
    openModal(m);
  }

  // -------------------------------------------------------------- rules
  const RULE_DEFAULTS = { required: [], default_status: { customer: 'active', supplier: 'active' }, duplicate_check: true, duplicate_threshold: 0.85, tags: true, phone_format: 'international', default_currency: '', cold_days: 60 };
  function rulesState() {
    const r = S.rules;
    return {
      required: S.config.required || [],
      default_status: { customer: r.crm_default_customer_status, supplier: r.crm_default_supplier_status },
      duplicate_check: r.crm_enable_duplicate_check !== '0', duplicate_threshold: Number(r.crm_duplicate_threshold) || 0.85,
      tags: r.crm_enable_tags !== '0', phone_format: r.crm_phone_format === 'national' ? 'national' : 'international',
      default_currency: r.crm_default_currency || '', cold_days: S.config.cold_days,
    };
  }
  R.rules = {
    html(sec, st) {
      st = st || rulesState();
      const statuses = S.config.statuses;
      const sel = (t) => `<select class="select" name="ds_${t}">${Object.keys(statuses).map((k) => `<option value="${k}" ${st.default_status[t] === k ? 'selected' : ''}>${esc(statuses[k].label)}</option>`).join('')}</select>`;
      const pct = Math.round(st.duplicate_threshold * 100);
      return card(sec, `
        <h3 class="fwc-subh">${icon('alert', 'i--sm')}Must be filled in</h3>
        <div class="fwc-checks">${Object.entries(S.options.requirable).map(([k, v]) => sw('req_' + k, st.required.includes(k), esc(v))).join('')}</div>
        <p class="hint" style="margin:4px 0 0">VAT and registration numbers also apply to tax invoices in Quotes &amp; Invoices.</p>
        <h3 class="fwc-subh" style="margin-top:20px">${icon('i-status', 'i--sm')}New accounts start as</h3>
        <div class="row"><div class="field"><label class="label">${esc(L('customers'))}</label>${sel('customer')}</div><div class="field"><label class="label">${esc(L('suppliers'))}</label>${sel('supplier')}</div></div>
        <h3 class="fwc-subh" style="margin-top:20px">${icon('ic-merge', 'i--sm')}Duplicates</h3>
        ${sw('duplicate_check', st.duplicate_check, 'Warn about possible duplicates', 'When someone adds an account that looks like one you already have.')}
        <div class="field" style="margin-top:6px"><label class="label" for="dupT">Sensitivity <output id="dupOut">${pct}%</output></label>
          <input type="range" id="dupT" name="duplicate_threshold" min="60" max="98" step="1" value="${pct}" class="fwc-range">
          <span class="hint fwc-range__ends"><span>Catches more (some false alarms)</span><span>Only near-identical names</span></span></div>
        <h3 class="fwc-subh" style="margin-top:20px">${icon('i-phone', 'i--sm')}Phone numbers</h3>
        ${seg('phone_format', st.phone_format, [['international', '+27 82 555 0100'], ['national', '082 555 0100']])}
        <p class="hint" style="margin:6px 0 0">How new numbers are stored. Searching finds them either way.</p>
        <div class="row" style="margin-top:20px">
          <div class="field"><label class="label" for="defCur">Default currency for new accounts</label><select class="select" id="defCur" name="default_currency"><option value="">The company currency</option>${Object.entries(S.currencies || {}).map(([k, v]) => `<option value="${k}" ${st.default_currency === k ? 'selected' : ''}>${k} — ${esc(v)}</option>`).join('')}</select></div>
          <div class="field"><label class="label" for="coldDays">Going cold after</label><span class="fwc-inline"><input class="input" id="coldDays" name="cold_days" type="number" min="7" max="365" value="${st.cold_days}" style="width:90px"> days without contact</span></div>
        </div>
        <div style="margin-top:16px">${sw('tags', st.tags, 'Use tags', 'Let people tag accounts and filter by tag.')}</div>`, { resetLabel: 'Defaults' });
    },
    bind(el) {
      const r = $('#dupT', el);
      r.addEventListener('input', () => { $('#dupOut', el).textContent = r.value + '%'; });
    },
    async save(el) {
      const required = Object.keys(S.options.requirable).filter((k) => $(`[name="req_${k}"]`, el).checked);
      await api.post('settings.php', { op: 'rules', required,
        default_status: { customer: $('[name=ds_customer]', el).value, supplier: $('[name=ds_supplier]', el).value },
        duplicate_check: $('[name=duplicate_check]', el).checked, duplicate_threshold: Number($('[name=duplicate_threshold]', el).value) / 100,
        tags: $('[name=tags]', el).checked, phone_format: segValue(el, 'phone_format'), default_currency: $('[name=default_currency]', el).value });
      await post({ op: 'thresholds', cold_days: Number($('[name=cold_days]', el).value) || 60, stale_days: S.config.stale_days, loss_reasons: S.config.loss_reasons }, ['rules']);
      BOOT.rules = S.rules;
    },
    reset(el) {
      const sec = SECTIONS.find((s) => s.id === 'rules');
      const wrap = document.createElement('div');
      wrap.innerHTML = R.rules.html(sec, clone(RULE_DEFAULTS));
      const fresh = wrap.firstElementChild;
      el.replaceWith(fresh);
      bindCommon(fresh, 'rules');
      R.rules.bind(fresh);
      markDirty(fresh);
      toast('Defaults filled in — press Save to keep them');
    },
  };

  // -------------------------------------------------------------- tags
  R.tags = {
    instant: true,
    html(sec) {
      const tags = S.tags || [];
      return card(sec, tags.length ? `<div class="fwc-sortable">${tags.map((t) => `<div class="fwc-srow fwc-srow--plain" data-id="${t.id}">
          <span class="fwc-srow__grip">${colordot('color', t.color, 'Colour of ' + t.name)}</span>
          <span class="fwc-srow__main"><input class="input" name="name" maxlength="64" value="${esc(t.name)}" aria-label="Tag name" style="max-width:260px">
            <a class="fwc-count" href="/crm/#customers?tag=${t.id}" title="Accounts with this tag">${t.count} account${t.count === 1 ? '' : 's'}</a></span>
          <span class="fwc-srow__acts"><button class="icon-btn" type="button" data-del aria-label="Delete ${esc(t.name)}" data-tip="Delete">${icon('trash')}</button></span></div>`).join('')}</div>
          ${S.rules.crm_enable_tags === '0' ? `<p class="hint" style="margin-top:10px">${icon('alert', 'i--xs')} Tags are switched off under <a href="#rules">Rules</a>.</p>` : ''}`
        : CRM.emptyState('i-tag', 'No tags yet', 'Tag an account (on its page or from a list) and the tag shows up here to rename or recolour.', '', true), { footer: false });
    },
    bind(el) {
      const saveTag = async (row) => {
        const name = $('[name=name]', row).value.trim();
        const t = S.tags.find((x) => x.id === Number(row.dataset.id));
        const color = $('[name=color]', row).value;
        if (!t || (name === t.name && color.toLowerCase() === String(t.color).toLowerCase())) return;
        try {
          const d = await api.post('tags.php', { op: 'save', id: t.id, name, color });
          S.tags = d.tags; BOOT.tags = d.tags;
          toast('Tag saved');
        } catch (err) { toast(err.message, { type: 'error' }); $('[name=name]', row).value = t.name; }
      };
      el.addEventListener('change', (e) => { const row = e.target.closest('.fwc-srow'); if (row) saveTag(row); });
      el.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.name === 'name') { e.preventDefault(); e.target.blur(); } });
      el.addEventListener('click', async (e) => {
        const del = e.target.closest('[data-del]');
        if (!del) return;
        const t = S.tags.find((x) => x.id === Number(del.closest('.fwc-srow').dataset.id));
        const ok = await dialog({ title: `Delete the tag “${t.name}”?`, body: t.count ? `It comes off ${t.count} account${t.count === 1 ? '' : 's'}. The accounts stay.` : 'No accounts use it.', confirm: 'Delete tag', danger: true });
        if (!ok) return;
        try {
          const d = await api.post('tags.php', { op: 'delete', id: t.id });
          S.tags = d.tags; BOOT.tags = d.tags;
          render('tags');
          toast('Tag deleted');
        } catch (err) { toast(err.message, { type: 'error' }); }
      });
    },
  };

  // -------------------------------------------------------------- lists
  R.lists = {
    instant: true,
    html(sec) {
      const col = (list, title, ic) => {
        const items = S.lookups[list] || [];
        const use = (S.lookup_use || {})[list] || {};
        return `<div class="fwc-lookup" data-list="${list}"><h3 class="fwc-subh">${icon(ic, 'i--sm')}${title}</h3>
          <form class="fwc-lookup__add" data-add="${list}"><input class="input" maxlength="120" placeholder="Add ${title === 'Industries' ? 'an industry' : 'a region'}" aria-label="New ${title === 'Industries' ? 'industry' : 'region'}" data-nodirty><button class="btn btn--sm" type="submit">${icon('plus')}Add</button></form>
          <div class="fwc-lookup__list">${items.map((x) => x.custom
            ? `<div class="fwc-lookup__item" data-id="${x.id}"><input class="input" value="${esc(x.name)}" maxlength="120" aria-label="Name" data-nodirty><span class="fwc-count">${use[x.id] || 0}</span><button class="icon-btn" type="button" data-del aria-label="Remove ${esc(x.name)}" data-tip="Remove">${icon('x')}</button></div>`
            : `<div class="fwc-lookup__item is-builtin" title="Built in — cannot be changed"><span>${esc(x.name)}</span><span class="fwc-count">${use[x.id] || 0}</span>${icon('i-lock', 'i--xs')}</div>`).join('') || '<span class="hint">Nothing yet.</span>'}</div></div>`;
      };
      return card(sec, `<div class="fwc-cols2">${col('industries', 'Industries', 'i-building')}${col('regions', 'Regions', 'i-globe')}</div>`, { footer: false });
    },
    bind(el) {
      const run = async (body, msg) => { try { await post(body, ['lists']); toast(msg); } catch (err) { toast(err.message, { type: 'error' }); render('lists'); } };
      $$('[data-add]', el).forEach((f) => f.addEventListener('submit', (e) => {
        e.preventDefault();
        const name = $('input', f).value.trim();
        if (name) run({ op: 'lookup_save', list: f.dataset.add, name }, 'Added');
      }));
      el.addEventListener('change', (e) => {
        const item = e.target.closest('.fwc-lookup__item[data-id]');
        if (item) run({ op: 'lookup_save', list: item.closest('[data-list]').dataset.list, id: Number(item.dataset.id), name: e.target.value.trim() }, 'Renamed');
      });
      el.addEventListener('click', async (e) => {
        const del = e.target.closest('[data-del]');
        if (!del) return;
        const item = del.closest('.fwc-lookup__item');
        run({ op: 'lookup_delete', list: item.closest('[data-list]').dataset.list, id: Number(item.dataset.id) }, 'Removed');
      });
    },
  };

  // -------------------------------------------------------------- compliance
  R.compliance = {
    html(sec) {
      const p = S.compliance.policy, types = S.compliance.types;
      return card(sec, `
        <h3 class="fwc-subh">${icon('i-file-text', 'i--sm')}Documents suppliers must have</h3>
        ${types.length ? `<div class="fwc-sortable">${types.map((t) => `<div class="fwc-srow fwc-srow--plain" data-id="${t.id}">
            <span class="fwc-srow__grip">${icon(t.required ? 'i-shield' : 'i-file-text')}</span>
            <span class="fwc-srow__main"><span class="fwc-fieldname"><b>${esc(t.name)}</b><span class="fwc-fieldrow__type">${esc(t.code)} · ${t.expiry_months ? `renew every ${t.expiry_months} month${t.expiry_months === 1 ? '' : 's'}` : 'does not expire'}</span></span>
              ${t.required ? '<span class="fwc-badge fwc-badge--warn">Required</span>' : '<span class="fwc-badge">Optional</span>'}
              ${t.docs ? `<span class="fwc-count">${t.docs} on file</span>` : ''}</span>
            <span class="fwc-srow__acts"><button class="icon-btn" type="button" data-tedit="${t.id}" aria-label="Edit ${esc(t.name)}" data-tip="Edit">${icon('pen')}</button><button class="icon-btn" type="button" data-tdel="${t.id}" aria-label="Delete ${esc(t.name)}" data-tip="Delete">${icon('trash')}</button></span></div>`).join('')}</div>`
          : CRM.emptyState('i-shield', 'No document types yet', 'Start with the usual South African set — tax clearance, B-BBEE, COID, bank letter, CIPC and insurance — then adjust.', `<button class="btn btn--primary" type="button" data-seed>${icon('sparkle')}Add the starter set</button>`, true)}
        <button class="btn btn--ghost btn--sm" type="button" data-tadd style="margin-top:8px">${icon('plus')}Add a document type</button>
        <h3 class="fwc-subh" style="margin-top:20px">${icon('i-bell', 'i--sm')}Reminders and blocking</h3>
        <div class="field"><label class="label" for="remDays">Remind the account owner</label><span class="fwc-inline"><input class="input" id="remDays" name="reminder_days" value="${esc(p.reminder_days.join(', '))}" style="width:160px"> days before a document expires</span><span class="hint">A bell and phone notification at each step, and again when it expires.</span></div>
        <div style="margin-top:10px">${sw('block', p.block, 'Block suppliers who fall behind', 'Suppliers with a missing or expired required document are marked as blocked in red on lists, their page and the compliance board.')}
        ${sw('badge', p.badge, 'Show compliance badges', 'The green / amber / red badge next to suppliers.')}</div>`, { reset: false });
    },
    bind(el) {
      el.addEventListener('click', async (e) => {
        try {
          if (e.target.closest('[data-seed]')) {
            const d = await api.post('compliance.php', { op: 'seed' });
            S.compliance.types = d.types;
            render('compliance');
            toast('Starter set added');
          }
          if (e.target.closest('[data-tadd]')) typeEditor(null);
          const ed = e.target.closest('[data-tedit]');
          if (ed) typeEditor(S.compliance.types.find((t) => t.id === Number(ed.dataset.tedit)));
          const del = e.target.closest('[data-tdel]');
          if (del) {
            const t = S.compliance.types.find((x) => x.id === Number(del.dataset.tdel));
            const ok = await dialog({ title: `Delete “${t.name}”?`, body: t.docs ? `${t.docs} document(s) use it, so it cannot be deleted. Switch “required” off instead.` : 'Suppliers will no longer be asked for it.', confirm: 'Delete', danger: true });
            if (!ok) return;
            const d = await api.post('compliance.php', { op: 'type_delete', id: t.id });
            S.compliance.types = d.types;
            render('compliance');
            toast('Document type deleted');
          }
        } catch (err) { toast(err.message, { type: 'error' }); }
      });
    },
    async save(el) {
      const d = await api.post('compliance.php', { op: 'policy', reminder_days: $('[name=reminder_days]', el).value, block: $('[name=block]', el).checked, badge: $('[name=badge]', el).checked });
      S.compliance.policy = d.policy;
      render('compliance');
    },
  };
  function typeEditor(t) {
    const isNew = !t;
    t = t || { id: 0, name: '', code: '', expiry_months: 12, required: false };
    const m = tempModal(`<div class="fwm-dialog__head"><h2>${isNew ? 'New document type' : 'Edit document type'}</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body" id="typeForm" novalidate>
        <div class="field"><label class="label" for="tName">Name</label><input class="input" id="tName" maxlength="120" value="${esc(t.name)}" placeholder="e.g. Tax clearance (SARS PIN)" autofocus></div>
        <div class="row">
          <div class="field"><label class="label" for="tCode">Short code</label><input class="input" id="tCode" maxlength="32" value="${esc(t.code)}" placeholder="e.g. TAX"></div>
          <div class="field"><label class="label" for="tMonths">Renew every (months)</label><input class="input" id="tMonths" type="number" min="0" max="120" value="${t.expiry_months == null ? '' : t.expiry_months}" placeholder="Never"></div>
        </div>
        <p class="hint" style="margin:6px 0 0">The expiry date is filled in from the issue date when someone uploads the document. Leave empty when it does not expire.</p>
        <div style="margin-top:10px">${sw('required', t.required, 'Required from every supplier', 'Missing it makes the supplier non-compliant.')}</div>
      </form>
      <div class="fwm-dialog__foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="submit" form="typeForm">${icon('check')}${isNew ? 'Add' : 'Save'}</button></div>`, '', 'width:min(480px,100%)');
    $('#typeForm', m).addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = $('[type=submit]', m);
      btn.disabled = true;
      try {
        const d = await api.post('compliance.php', { op: 'type_save', id: t.id, name: $('#tName', m).value.trim(), code: $('#tCode', m).value.trim(), expiry_months: $('#tMonths', m).value, required: $('[name=required]', m).checked });
        S.compliance.types = d.types;
        closeModal(m);
        render('compliance');
        toast(isNew ? 'Document type added' : 'Document type saved');
      } catch (err) { btn.disabled = false; toast(err.message, { type: 'error' }); }
    });
    openModal(m);
  }

  // -------------------------------------------------------------- templates
  const PLACEHOLDERS = [['{first_name}', 'First name'], ['{contact}', 'Contact name'], ['{name}', 'Account name'], ['{company}', 'Your company'], ['{sender}', 'Your name'], ['{date}', 'Today + 7 days']];
  R.templates = {
    html(sec, list) {
      list = list || S.templates;
      return card(sec, `<div class="fwc-ph"><span class="hint">Click to insert:</span>${PLACEHOLDERS.map(([k, v]) => `<button class="fwc-chip fwc-chip--btn" type="button" data-ph="${esc(k)}" title="${esc(v)}">${esc(k)}</button>`).join('')}</div>
        ${list.map((t) => `<div class="fwc-tpl" data-k="${esc(t.key)}">
          <div class="fwc-tpl__head"><h3 class="fwc-subh" style="margin:0">${icon('mail', 'i--sm')}${esc(t.label)}</h3><button class="btn btn--ghost btn--sm" type="button" data-orig="${esc(t.key)}">${icon('undo')}Original text</button></div>
          <div class="field"><label class="label" for="ts_${esc(t.key)}">Subject</label><input class="input" id="ts_${esc(t.key)}" name="subject" maxlength="200" value="${esc(t.subject)}"></div>
          <div class="field"><label class="label" for="tb_${esc(t.key)}">Message</label><textarea class="textarea" id="tb_${esc(t.key)}" name="body" rows="7" maxlength="10000">${esc(t.body)}</textarea></div>
        </div>`).join('')}`, { resetLabel: 'All original' });
    },
    bind(el) {
      let last = null;
      el.addEventListener('focusin', (e) => { if (e.target.matches('input[name=subject], textarea[name=body]')) last = e.target; });
      el.addEventListener('click', (e) => {
        const ph = e.target.closest('[data-ph]');
        if (ph) {
          const f = last || $('textarea[name=body]', el);
          const s = f.selectionStart ?? f.value.length, en = f.selectionEnd ?? s;
          f.value = f.value.slice(0, s) + ph.dataset.ph + f.value.slice(en);
          f.focus();
          f.selectionStart = f.selectionEnd = s + ph.dataset.ph.length;
          markDirty(el);
          return;
        }
        const orig = e.target.closest('[data-orig]');
        if (orig) {
          const d = S.template_defaults[orig.dataset.orig];
          const box = orig.closest('.fwc-tpl');
          $('[name=subject]', box).value = d.subject;
          $('[name=body]', box).value = d.body;
          markDirty(el);
        }
      });
    },
    async save(el) {
      const templates = {};
      $$('.fwc-tpl', el).forEach((b) => { templates[b.dataset.k] = { subject: $('[name=subject]', b).value, body: $('[name=body]', b).value }; });
      await post({ op: 'templates', templates }, ['templates']);
    },
    reset(el) {
      const sec = SECTIONS.find((s) => s.id === 'templates');
      const wrap = document.createElement('div');
      wrap.innerHTML = R.templates.html(sec, S.templates.map((t) => ({ ...t, subject: S.template_defaults[t.key].subject, body: S.template_defaults[t.key].body })));
      const fresh = wrap.firstElementChild;
      el.replaceWith(fresh);
      bindCommon(fresh, 'templates');
      R.templates.bind(fresh);
      markDirty(fresh);
      toast('Original text filled in — press Save to keep it');
    },
  };

  // ------------------------------------------------------------- page
  function buildNav() {
    let html = `<div class="fwc-set__find"><span>${icon('search', 'i--sm')}</span><input type="search" id="setFind" placeholder="Find a setting" aria-label="Find a setting"></div>`;
    let group = '';
    visible().forEach((s) => {
      if (s.group !== group) { group = s.group; html += `<h5>${esc(group)}</h5>`; }
      html += `<a href="#${s.id}" data-nav="${s.id}">${icon(s.icon)}<span>${esc(s.title)}</span><i class="fwc-navdot" hidden></i></a>`;
    });
    if (!IS_ADMIN) html += `<p class="hint" style="padding:12px">Company settings — words, stages, fields and rules — are changed by your admins.</p>`;
    nav.innerHTML = html;
    const find = $('#setFind');
    find.addEventListener('input', () => {
      const q = find.value.trim().toLowerCase();
      visible().forEach((s) => {
        const hit = !q || (s.title + ' ' + s.keys + ' ' + s.lead).toLowerCase().includes(q);
        const sec = document.getElementById(s.id);
        if (sec) sec.hidden = !hit;
        $(`[data-nav="${s.id}"]`, nav).hidden = !hit;
      });
      $$('h5', nav).forEach((h) => { let n = h.nextElementSibling, any = false; while (n && n.tagName === 'A') { if (!n.hidden) any = true; n = n.nextElementSibling; } h.hidden = !any; });
    });
    nav.addEventListener('click', (e) => {
      const a = e.target.closest('[data-nav]');
      if (!a) return;
      e.preventDefault();
      jump(a.dataset.nav, true);
      if (CRM.narrow()) a.scrollIntoView({ inline: 'center', block: 'nearest' });
    });
  }
  function jump(id, smooth) {
    const sec = document.getElementById(id);
    if (!sec) return;
    sec.hidden = false;
    content.scrollTo({ top: sec.offsetTop - 14, behavior: smooth && !matchMedia('(prefers-reduced-motion: reduce)').matches ? 'smooth' : 'auto' });
    history.replaceState(null, '', '#' + id);
    setActive(id);
  }
  function setActive(id) { $$('[data-nav]', nav).forEach((a) => { const on = a.dataset.nav === id; a.classList.toggle('is-on', on); if (on) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current'); }); }
  function spy() {
    let raf = 0;
    content.addEventListener('scroll', () => {
      cancelAnimationFrame(raf);
      raf = requestAnimationFrame(() => {
        const top = content.getBoundingClientRect().top + 40;
        let cur = null;
        $$('.fwc-card2:not([hidden])', content).forEach((s) => { if (s.getBoundingClientRect().top <= top) cur = s.id; });
        setActive(cur || (visible()[0] || {}).id);
      });
    }, { passive: true });
  }
  function dirtyDots() {
    setInterval(() => { $$('[data-nav]', nav).forEach((a) => { const d = $('.fwc-navdot', a); if (d) d.hidden = !dirty.has(a.dataset.nav); }); }, 600);
  }

  async function init() {
    CRM.initChrome();
    buildNav();
    try {
      S = IS_ADMIN ? await api.get('settings.php') : null;
    } catch (err) {
      content.innerHTML = CRM.errorBox(err);
      return;
    }
    content.innerHTML = '';
    visible().forEach((s) => render(s.id));
    if (!IS_ADMIN) {
      content.insertAdjacentHTML('beforeend', `<section class="fwc-card2 fwc-card2--note"><header>${icon('i-lock')}<div class="fwc-grow"><h2>Company settings</h2><p>Words, pipeline stages, custom fields, statuses and rules are set by your admins for everyone. Ask an admin if something should change.</p></div></header></section>`);
    }
    spy();
    dirtyDots();
    const id = location.hash.slice(1);
    if (id && document.getElementById(id)) setTimeout(() => jump(id, false), 30);
    else setActive(visible()[0].id);
    window.addEventListener('hashchange', () => { const h = location.hash.slice(1); if (document.getElementById(h)) jump(h, true); });
    window.addEventListener('beforeunload', (e) => { if (dirty.size) { e.preventDefault(); e.returnValue = ''; } });
    content.addEventListener('click', (e) => {
      const a = e.target.closest('a[href^="#"]');
      if (a && document.getElementById(a.getAttribute('href').slice(1))) { e.preventDefault(); jump(a.getAttribute('href').slice(1), true); }
    });
  }
  init();
})();
