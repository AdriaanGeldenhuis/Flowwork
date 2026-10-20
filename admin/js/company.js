// /admin/js/company.js — Company profile: the logo. Upload and removal go
// through the document designer's endpoint (/qi/api/design.php), which checks
// the image, re-encodes it as WebP and keeps one copy per company. The rest
// of the page is a settings form handled by admin.js.
(function () {
  'use strict';
  const { $, icon, toast, toastError, dialog, busy } = window.FWA;
  const box = $('#logoBox');
  const pick = $('#logoPick');
  const file = $('#logoFile');
  const remove = $('#logoRemove');
  if (!box || !pick || !file) return;
  const csrf = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  function show(url) {
    box.dataset.url = url || '';
    box.innerHTML = url ? `<img src="${url.replace(/"/g, '&quot;')}" alt="Your logo">` : '<span class="fwa-logo__empty">No logo yet</span>';
    pick.innerHTML = icon('upload') + (url ? 'Replace' : 'Upload a logo');
    remove.hidden = !url;
  }
  async function send(body, isForm) {
    const res = await fetch('/qi/api/design.php', {
      method: 'POST', credentials: 'same-origin', body: isForm ? body : JSON.stringify(body),
      headers: Object.assign({ Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf() }, isForm ? {} : { 'Content-Type': 'application/json' }),
    });
    let data = null;
    try { data = await res.json(); } catch (e) { data = null; }
    if (!res.ok || !data || data.ok === false) throw new Error((data && data.error) || 'The logo could not be saved.');
    return data;
  }

  pick.addEventListener('click', () => file.click());
  file.addEventListener('change', async () => {
    const f = file.files && file.files[0];
    file.value = '';
    if (!f) return;
    if (!/^image\/(png|jpeg|webp)$/.test(f.type)) return toast('Use a PNG, JPG or WebP image.', { type: 'error' });
    if (f.size > 3 * 1024 * 1024) return toast('That image is larger than 3 MB.', { type: 'error' });
    const fd = new FormData();
    fd.append('action', 'logo');
    fd.append('logo', f);
    busy(pick, true);
    box.classList.add('is-busy');
    try {
      const r = await send(fd, true);
      show(r.logo_url);
      toast('Logo updated — it is on your documents from now on');
    } catch (e) { toastError(e); } finally { busy(pick, false); box.classList.remove('is-busy'); }
  });
  remove.addEventListener('click', async () => {
    if (!(await dialog({ title: 'Remove the logo?', body: 'Your documents show the business name in its place.', confirm: 'Remove', danger: true }))) return;
    try {
      await send({ action: 'logo_remove' });
      show('');
      toast('Logo removed');
    } catch (e) { toastError(e); }
  });
  // Drop an image on the logo box
  box.addEventListener('dragover', (e) => { e.preventDefault(); box.classList.add('is-over'); });
  box.addEventListener('dragleave', () => box.classList.remove('is-over'));
  box.addEventListener('drop', (e) => {
    e.preventDefault();
    box.classList.remove('is-over');
    const f = e.dataTransfer.files && e.dataTransfer.files[0];
    if (!f) return;
    const dt = new DataTransfer();
    dt.items.add(f);
    file.files = dt.files;
    file.dispatchEvent(new Event('change'));
  });
})();
