// /shopping/js/prefs.js — preferences: what changed is marked, the save bar
// appears, Ctrl S saves; leaving with unsaved changes asks first.
(function () {
  'use strict';
  const { $, $$, icon, api, toast, toastError, busy } = window.FWS;
  const form = $('#prefs');
  if (!form) return;
  const bar = document.createElement('div');
  bar.className = 'fws-savebar';
  bar.setAttribute('role', 'region');
  bar.setAttribute('aria-label', 'Unsaved changes');
  bar.innerHTML = `<span>Unsaved changes</span><button class="btn btn--ghost" type="button" data-discard>Discard</button><button class="btn btn--primary" type="button" data-save>${icon('check', 'i--sm')}Save</button>`;
  document.body.appendChild(bar);
  const fields = () => $$('[name]', form).filter((f) => !f.disabled);
  const val = (f) => (f.type === 'checkbox' ? (f.checked ? '1' : '0') : f.value);
  const saved = new Map(fields().map((f) => [f.name, val(f)]));
  const changed = () => fields().filter((f) => val(f) !== saved.get(f.name));
  function refresh() {
    $$('.fws-set', form).forEach((r) => r.classList.remove('is-dirty'));
    const c = changed();
    c.forEach((f) => { const r = f.closest('.fws-set'); if (r) r.classList.add('is-dirty'); });
    bar.classList.toggle('is-on', c.length > 0);
  }
  form.addEventListener('input', refresh);
  form.addEventListener('change', refresh);
  async function save() {
    const c = changed();
    if (!c.length) return;
    const body = { op: 'save' };
    c.forEach((f) => { body[f.name] = f.type === 'checkbox' ? (f.checked ? 1 : 0) : f.value; });
    const btn = $('[data-save]', bar);
    busy(btn, true);
    try {
      await api.post('settings.php', body);
      c.forEach((f) => saved.set(f.name, val(f)));
      refresh();
      toast('Saved.');
    } catch (e) { toastError(e); } finally { busy(btn, false); }
  }
  $('[data-save]', bar).addEventListener('click', save);
  $('[data-discard]', bar).addEventListener('click', () => {
    fields().forEach((f) => { const v = saved.get(f.name); if (f.type === 'checkbox') f.checked = v === '1'; else f.value = v; });
    refresh();
  });
  form.addEventListener('submit', (e) => { e.preventDefault(); save(); });
  document.addEventListener('keydown', (e) => { if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) { e.preventDefault(); save(); } });
  window.addEventListener('beforeunload', (e) => { if (changed().length) { e.preventDefault(); e.returnValue = ''; } });
})();
