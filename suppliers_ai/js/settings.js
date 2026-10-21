// /suppliers_ai/js/settings.js — Settings: the Google and OpenAI keys (saved,
// tested, removed — never shown again), the search settings, the company's own
// words (synonyms and trades), the never-suggest and always-ask lists, and the
// request email with a live preview. Changes wait in the save bar (Ctrl/⌘ S).
(function () {
  'use strict';
  const F = window.FSA;
  const { $, $$, esc, icon, api, toast, toastError, busy } = F;
  const BOOT = F.boot;
  const dirty = new Set();
  const bar = $('#savebar');
  let rules = JSON.parse(JSON.stringify(BOOT.rules || {}));
  let saved = { rules: JSON.stringify(rules), template: JSON.stringify(BOOT.template), form: '' };

  const mark = (what, on = true) => {
    if (on) dirty.add(what); else dirty.delete(what);
    bar.classList.toggle('is-on', dirty.size > 0);
    $('#savebarText').textContent = dirty.size ? 'Unsaved changes — ' + [...dirty].map((d) => ({ form: 'searching', rules: 'words & lists', template: 'the email' }[d])).join(', ') : '';
  };

  // ------------------------------------------------------------------ tabs
  $$('.fsa-tabs a').forEach((a) => a.addEventListener('click', () => { $$('.fsa-tabs a').forEach((x) => x.classList.toggle('is-on', x === a)); }));

  // ------------------------------------------------------------------ keys
  function test(kind, res, err) {
    const el = $(kind === 'google' ? '#googleTest' : '#aiTest');
    el.hidden = false;
    el.className = 'fsa-test ' + (err ? 'is-err' : 'is-ok');
    el.textContent = err ? err : kind === 'google'
      ? `Google answered in ${res.ms} ms — ${res.found} ${res.found === 1 ? 'business' : 'businesses'} near ${BOOT.town || 'your town'}${res.sample.length ? ': ' + res.sample.join(', ') : ''}.`
      : `OpenAI answered in ${res.ms} ms with ${res.model}. It is working.`;
  }
  $$('[data-key-save]').forEach((b) => b.addEventListener('click', async () => {
    const kind = b.dataset.keySave;
    const body = { op: 'keys' };
    if (kind === 'google') {
      const v = $('#googleKey').value.trim();
      if (!v) { toast('Paste the Google key first.'); return; }
      body.google_key = v;
    } else {
      const v = $('#aiKey').value.trim();
      if (v) body.openai_key = v;
      body.openai_on = $('#aiOn').checked ? 1 : 0;
      body.openai_model = $('#aiModel').value;
    }
    busy(b, true);
    try {
      await api.post('settings.php', body);
      toast(kind === 'google' ? 'Google key saved' : 'AI settings saved', { action: { label: 'Test it', run: () => $(`[data-key-test="${kind}"]`).click() } });
      setTimeout(() => location.reload(), 900);
    } catch (e) { toastError(e); busy(b, false); }
  }));
  $$('[data-key-test]').forEach((b) => b.addEventListener('click', async () => {
    const kind = b.dataset.keyTest;
    busy(b, true);
    try {
      const res = await api.post('settings.php', { op: kind === 'google' ? 'test_google' : 'test_ai' });
      test(kind, res.test, null);
    } catch (e) { test(kind, null, e.message); } finally { busy(b, false); }
  }));
  $$('[data-key-forget]').forEach((b) => b.addEventListener('click', async () => {
    const kind = b.dataset.keyForget;
    const ok = await F.dialog({ title: kind === 'google' ? 'Remove the Google key?' : 'Remove the OpenAI key?',
      body: kind === 'google' ? 'Searches will only look at your own suppliers.' : 'Receipts and Shopping use the same key — their AI stops too.', confirm: 'Remove', danger: true });
    if (!ok) return;
    try { await api.post('settings.php', { op: 'keys', forget: kind }); location.reload(); } catch (e) { toastError(e); }
  }));

  // ------------------------------------------------------------------ searching
  const form = $('#setForm');
  const formValue = () => JSON.stringify(Object.fromEntries([...new FormData(form).entries()].concat([['compliant_only', form.compliant_only.checked ? 1 : 0]])));
  saved.form = formValue();
  form.addEventListener('input', (e) => {
    const r = e.target.closest('.fsa-range');
    if (r) $('output', r).textContent = e.target.value;
    const set = e.target.closest('.fsa-set');
    if (set) set.classList.add('is-dirty');
    mark('form', formValue() !== saved.form);
  });

  // ------------------------------------------------------------------ words
  function pairRow(kind, key, words) {
    return `<div class="fsa-pairrow" data-kind="${kind}"><input class="input" data-k value="${esc(key)}" placeholder="${kind === 'synonyms' ? 'geyser' : 'Plumbing'}" maxlength="60" aria-label="${kind === 'synonyms' ? 'When someone types' : 'Trade'}">
      ${icon('chev-right')}<input class="input" data-w value="${esc(words.join(', '))}" placeholder="${kind === 'synonyms' ? 'water heater, plumbing' : 'loodgieter, geyser, blocked drain'}" maxlength="600" aria-label="Words, separated by commas">
      <button class="icon-btn icon-btn--sm" type="button" data-rm aria-label="Remove">${icon('x')}</button></div>`;
  }
  function drawPairs(kind) {
    const host = $('#' + kind);
    const entries = Object.entries(rules[kind] || {});
    host.innerHTML = entries.map(([k, w]) => pairRow(kind, k, w)).join('') || `<p class="fsa-pairs__empty">${kind === 'synonyms' ? 'None yet.' : 'None of your own yet — the built-in ones are below.'}</p>`;
  }
  function readPairs(kind) {
    const out = {};
    $$('#' + kind + ' .fsa-pairrow').forEach((row) => {
      const k = $('[data-k]', row).value.trim().toLowerCase();
      const w = $('[data-w]', row).value.split(',').map((x) => x.trim().toLowerCase()).filter(Boolean);
      if (k && w.length) out[k] = w;
    });
    return out;
  }
  ['synonyms', 'categories'].forEach(drawPairs);
  $$('[data-add]').forEach((b) => b.addEventListener('click', () => {
    const host = $('#' + b.dataset.add);
    const empty = $('.fsa-pairs__empty', host);
    if (empty) empty.remove();
    host.insertAdjacentHTML('beforeend', pairRow(b.dataset.add, '', []));
    $('.fsa-pairrow:last-child [data-k]', host).focus();
  }));
  $('#words').nextElementSibling.addEventListener('click', (e) => {
    const rm = e.target.closest('[data-rm]');
    if (rm) { rm.closest('.fsa-pairrow').remove(); rulesChanged(); }
  });
  $('#builtin').innerHTML = `<div class="fsa-builtin__grid">${(BOOT.builtin || []).map((t) => `<div class="fsa-builtin__item" style="--c:${esc(t.color)}"><b>${icon(t.icon)}${esc(t.label)}</b>${esc(t.words.slice(0, 9).join(', '))}${t.words.length > 9 ? '…' : ''}</div>`).join('')}</div>`;

  // ------------------------------------------------------------------ lists
  const LISTS = [['names', 'Names', 'One a line — “Joe’s Plumbing” leaves out every name with that in it'], ['phones', 'Phone numbers', '082 123 4567 (any format)'], ['domains', 'Websites and email domains', 'example.co.za']];
  $$('.fsa-lists').forEach((host) => {
    const which = host.dataset.list;
    host.innerHTML = LISTS.map(([k, label, hint]) => `<div class="field"><label class="label" for="${which}-${k}">${esc(label)}</label><textarea class="textarea" id="${which}-${k}" data-list-key="${k}" rows="3" placeholder="${esc(hint)}">${esc(((rules[which] || {})[k] || []).join('\n'))}</textarea></div>`).join('');
  });
  const readLists = (which) => {
    const out = { phones: [], domains: [], names: [], ids: ((rules[which] || {}).ids || []) };
    $$(`[data-list="${which}"] textarea`).forEach((t) => { out[t.dataset.listKey] = t.value.split('\n').map((x) => x.trim()).filter(Boolean); });
    return out;
  };
  function currentRules() {
    return { synonyms: readPairs('synonyms'), categories: readPairs('categories'), allow_list: readLists('allow_list'), deny_list: readLists('deny_list') };
  }
  const rulesChanged = () => mark('rules', JSON.stringify(currentRules()) !== JSON.stringify({ synonyms: rules.synonyms || {}, categories: rules.categories || {}, allow_list: rules.allow_list, deny_list: rules.deny_list }));
  ['#synonyms', '#categories'].forEach((s) => $(s).addEventListener('input', rulesChanged));
  $$('.fsa-lists').forEach((h) => h.addEventListener('input', rulesChanged));

  // ------------------------------------------------------------------ the email
  const EX = { first_name: 'Thandi', supplier: 'Vaal Plumbing Supplies', what: '2 × 150 L geysers for the site office', company: BOOT.companyName || ($('.fwm-brand__company') || {}).textContent || 'Your company',
    details: '- 2 × 150 L geysers, supply and install\n- Please include: COC', quantity: '2 geysers', where: BOOT.town || 'Vanderbijlpark',
    needed_by: F.longDay(F.addDays(10)) + ' ' + new Date().getFullYear(), quote_by: F.longDay(F.addDays(4)) + ' ' + new Date().getFullYear(), sender: 'You' };
  const fillEx = (s) => String(s).replace(/\{([a-z_]+)\}/gi, (m, k) => (EX[k.toLowerCase()] != null ? EX[k.toLowerCase()] : m));
  const preview = () => { $('#tplPreview').innerHTML = `<div class="fsa-paper__subject">${esc(fillEx($('#tplSubject').value))}</div>${esc(fillEx($('#tplBody').value))}`; };
  let lastField = $('#tplBody');
  ['#tplSubject', '#tplBody'].forEach((s) => {
    $(s).addEventListener('focus', () => { lastField = $(s); });
    $(s).addEventListener('input', () => { preview(); mark('template', JSON.stringify({ subject: $('#tplSubject').value, body: $('#tplBody').value }) !== JSON.stringify({ subject: BOOT.template.subject, body: BOOT.template.body })); });
  });
  $('#placeholders').innerHTML = (BOOT.placeholders || []).map((p) => `<button type="button" data-ph="${esc(p)}">{${esc(p)}}</button>`).join('')
    + `<button type="button" data-reset>${BOOT.template.custom ? 'Use the standard email' : 'The standard email'}</button>`;
  $('#placeholders').addEventListener('click', (e) => {
    const b = e.target.closest('[data-ph]');
    if (b) {
      const f = lastField;
      const at = f.selectionStart ?? f.value.length;
      f.value = f.value.slice(0, at) + '{' + b.dataset.ph + '}' + f.value.slice(f.selectionEnd ?? at);
      f.focus();
      f.dispatchEvent(new Event('input'));
    }
    if (e.target.closest('[data-reset]')) {
      $('#tplSubject').value = BOOT.defaults.subject;
      $('#tplBody').value = BOOT.defaults.body;
      $('#tplBody').dispatchEvent(new Event('input'));
    }
  });
  preview();

  // ------------------------------------------------------------------ save
  async function save() {
    const go = $('#saveGo');
    busy(go, true);
    try {
      if (dirty.has('form')) {
        const fd = new FormData(form);
        await api.post('settings.php', { op: 'save', radius_km: fd.get('radius_km'), min_score: fd.get('min_score'), max_recipients: fd.get('max_recipients'), per_hour: fd.get('per_hour'), per_day: fd.get('per_day'), compliant_only: form.compliant_only.checked ? 1 : 0 });
        saved.form = formValue();
        $$('.fsa-set.is-dirty').forEach((s) => s.classList.remove('is-dirty'));
        mark('form', false);
      }
      if (dirty.has('rules')) {
        const res = await api.post('settings.php', { op: 'rules', ...currentRules() });
        rules = res.settings.rules || currentRules();
        BOOT.rules = rules;
        mark('rules', false);
      }
      if (dirty.has('template')) {
        const isDefault = $('#tplSubject').value === BOOT.defaults.subject && $('#tplBody').value === BOOT.defaults.body;
        const res = await api.post('settings.php', isDefault ? { op: 'template', reset: true } : { op: 'template', subject: $('#tplSubject').value, body: $('#tplBody').value });
        BOOT.template = res.template;
        mark('template', false);
      }
      toast('Saved');
    } catch (e) { toastError(e); } finally { busy(go, false); }
  }
  $('#saveGo').addEventListener('click', save);
  $('#saveUndo').addEventListener('click', () => location.reload());
  document.addEventListener('keydown', (e) => { if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) { e.preventDefault(); if (dirty.size) save(); } });
  window.addEventListener('beforeunload', (e) => { if (dirty.size) { e.preventDefault(); e.returnValue = ''; } });
})();
