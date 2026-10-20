// /admin/js/invite.js — "Add someone": email an invitation, or create the
// account straight away with a one-time password. Used on People,
// Invitations and the Overview. FWA.addPerson({email, mode}) → Promise (true when someone was added).
// Also FWA.roleCards(name, selected) for any role picker.
(function () {
  'use strict';
  const { $, $$, esc, icon, api, toast, toastError, modal, openModal, closeModal, copy, busy } = window.FWA;
  const ROLES = (FWA.boot.roles && Object.keys(FWA.boot.roles).length) ? FWA.boot.roles : {
    admin: ['Admin', 'Everything, including these admin settings, people and billing.'],
    member: ['Member', 'Works in every module: projects, customers, quotes, mail and more.'],
    bookkeeper: ['Bookkeeper', 'Finance, invoices and payroll. Does not use a paid seat.'],
    viewer: ['Viewer', 'Can look at everything they are shown, but not change it.'],
    pos: ['Counter staff', 'Limited, view-only access for front-of-house staff.'],
  };

  function roleCards(name, selected) {
    return `<div class="fwa-roles" role="radiogroup" aria-label="Role">${Object.entries(ROLES).map(([k, [label, hint]]) => `
      <label class="fwa-role"><input type="radio" name="${esc(name)}" value="${esc(k)}"${k === selected ? ' checked' : ''}>
        <b>${esc(label)}</b><small>${esc(hint)}</small></label>`).join('')}</div>`;
  }

  function fieldError(m, key, msg) {
    const f = $(`[name="${key}"]`, m);
    const box = f && (f.closest('.field') || f.closest('.fwa-roles'));
    if (!box) return false;
    const holder = box.classList.contains('field') ? box : box.parentElement;
    holder.classList.add('is-invalid');
    const e = document.createElement('div');
    e.className = 'field__error';
    e.textContent = msg;
    holder.appendChild(e);
    return true;
  }
  function clearErrors(m) {
    $$('.is-invalid', m).forEach((x) => x.classList.remove('is-invalid'));
    $$('.field__error', m).forEach((x) => x.remove());
  }

  function addPerson(opts = {}) {
    return new Promise((resolve) => {
      let mode = opts.mode || 'invite';
      let added = false;
      const m = modal(`
        <div class="fwm-dialog__head"><h2>Add someone</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
        <form class="fwm-dialog__body" novalidate id="addForm">
          <div class="seg fwm-proj-mode" role="tablist" aria-label="How to add them" style="display:flex;margin-bottom:18px">
            <button type="button" data-mode="invite" class="${mode === 'invite' ? 'is-on' : ''}" style="flex:1">${icon('i-send', 'i--sm')} Email an invitation</button>
            <button type="button" data-mode="create" class="${mode === 'create' ? 'is-on' : ''}" style="flex:1">${icon('ia-key', 'i--sm')} Create the account now</button>
          </div>
          <p class="fwm-dialog__lead" data-for="invite">They get an email with a link to join and choose their own password. Nothing uses a seat until they accept.</p>
          <p class="fwm-dialog__lead" data-for="create">Their account is ready at once. You get a one-time password to pass on privately.</p>
          <div class="fwa-form">
            <div class="field is-full"><label class="label" for="addEmail">Email address<span class="req">*</span></label>
              <input class="input" id="addEmail" name="email" type="email" maxlength="190" autocomplete="off" value="${esc(opts.email || '')}" placeholder="name@company.co.za" autofocus></div>
            <div class="field" data-for="create"><label class="label" for="addFirst">First name<span class="req">*</span></label><input class="input" id="addFirst" name="first_name" maxlength="100" autocomplete="off"></div>
            <div class="field" data-for="create"><label class="label" for="addLast">Surname<span class="req">*</span></label><input class="input" id="addLast" name="last_name" maxlength="100" autocomplete="off"></div>
            <div class="field is-full"><span class="label">Role</span>${roleCards('role', 'member')}</div>
            <div class="field is-full" data-for="invite"><label class="label" for="addMsg">Personal note <span class="hint">(optional)</span></label>
              <textarea class="textarea" id="addMsg" name="message" maxlength="1000" rows="3" placeholder="Welcome aboard! You’ll find our projects under Projects."></textarea></div>
            <div class="field" data-for="invite"><label class="label" for="addDays">Link works for</label>
              <select class="select" id="addDays" name="days"><option value="3">3 days</option><option value="7" selected>7 days</option><option value="14">14 days</option><option value="30">30 days</option></select></div>
          </div>
        </form>
        <div class="fwm-dialog__foot"><span class="grow hint" id="addSeatHint"></span><button class="btn" type="button" data-close>Cancel</button>
          <button class="btn btn--primary" type="button" id="addGo">${icon('i-send', 'i--sm')}<span>Send invitation</span></button></div>`, { width: 620 });

      const seats = FWA.boot.seats;
      const sync = () => {
        $$('.seg button', m).forEach((b) => b.classList.toggle('is-on', b.dataset.mode === mode));
        $$('[data-for]', m).forEach((el) => { el.hidden = el.dataset.for !== mode; });
        $('#addGo span', m).textContent = mode === 'invite' ? 'Send invitation' : 'Create account';
        $('#addGo use', m).setAttribute('href', mode === 'invite' ? '#i-send' : '#ia-key');
        const role = ($('input[name=role]:checked', m) || {}).value;
        const hint = $('#addSeatHint', m);
        hint.textContent = seats && role !== 'bookkeeper' ? (seats.free > 0 ? `${seats.free} of ${seats.max} seats free` : `All ${seats.max} seats are in use`) : (role === 'bookkeeper' ? 'Bookkeepers don’t use a seat' : '');
        hint.style.color = seats && role !== 'bookkeeper' && seats.free <= 0 ? 'var(--danger)' : '';
      };
      $$('.seg button', m).forEach((b) => b.addEventListener('click', () => { mode = b.dataset.mode; clearErrors(m); sync(); }));
      $$('input[name=role]', m).forEach((r) => r.addEventListener('change', sync));
      sync();

      async function submit() {
        clearErrors(m);
        const f = $('#addForm', m);
        const data = {
          email: f.email.value.trim(), role: ($('input[name=role]:checked', m) || {}).value,
          first_name: f.first_name.value.trim(), last_name: f.last_name.value.trim(), message: f.message.value.trim(), days: f.days.value,
        };
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email)) { fieldError(m, 'email', 'Enter a valid email address.'); f.email.focus(); return; }
        if (mode === 'create' && !data.first_name) { fieldError(m, 'first_name', 'Enter their first name.'); f.first_name.focus(); return; }
        if (mode === 'create' && !data.last_name) { fieldError(m, 'last_name', 'Enter their surname.'); f.last_name.focus(); return; }
        const btn = $('#addGo', m);
        busy(btn, true);
        try {
          if (mode === 'invite') {
            const r = await api.post('invites.php', { action: 'send', email: data.email, role: data.role, days: data.days, message: data.message });
            added = true;
            showInvited(r, data.email);
          } else {
            const r = await api.post('people.php', { action: 'create', email: data.email, role: data.role, first_name: data.first_name, last_name: data.last_name });
            added = true;
            showCreated(r, data);
          }
        } catch (e) {
          const errs = e.data && e.data.errors;
          if (errs) Object.entries(errs).forEach(([k, msg]) => { if (!fieldError(m, k, msg)) toastError(msg); });
          else toastError(e);
        } finally {
          busy(btn, false);
        }
      }
      function result(html, again) {
        $('.fwm-dialog__body', m).outerHTML = `<div class="fwm-dialog__body">${html}</div>`;
        $('.fwm-dialog__foot', m).innerHTML = `<span class="grow"></span>${again ? `<button class="btn" type="button" data-again>${icon('ia-user-plus', 'i--sm')}Add someone else</button>` : ''}<button class="btn btn--primary" type="button" data-close>Done</button>`;
        const a = $('[data-again]', m);
        if (a) a.addEventListener('click', () => { m._onClose = null; closeModal(m); addPerson(opts).then(() => resolve(true)); });
        $$('[data-copy]', m).forEach((b) => b.addEventListener('click', () => copy(b.dataset.copy, b.dataset.copyLabel || 'Copied')));
        const done = $('.fwm-dialog__foot .btn--primary', m);
        if (done) done.focus();
      }
      function showInvited(r, email) {
        const ok = r.emailed;
        result(`<div class="fwa-banner ${ok ? 'fwa-banner--ok' : 'fwa-banner--warn'}">${icon(ok ? 'check' : 'alert')}<div class="fwa-banner__body">
            ${ok ? `<b>Invitation sent to ${esc(email)}.</b> ${r.via === 'mailbox' ? 'It went from your mailbox (' + esc(r.from || '') + ').' : 'It went from Flowwork’s mail.'}`
                 : `<b>The invitation is ready, but it could not be emailed.</b> ${esc(r.email_error || '')} Copy the link below and send it to ${esc(email)} yourself — by WhatsApp or your own email.`}</div></div>
          <div class="field"><span class="label">Invitation link · works until ${esc(FWA.fmtDate(r.invite.expires_at))}</span>
            <div class="fwa-secret"><code>${esc(r.link)}</code><button class="btn btn--sm" type="button" data-copy="${esc(r.link)}" data-copy-label="Link copied">${icon('i-copy', 'i--sm')}Copy</button></div></div>`, true);
      }
      function showCreated(r, data) {
        const login = location.origin + '/login.php';
        if (r.reactivated) {
          result(`<div class="fwa-banner fwa-banner--ok">${icon('check')}<div class="fwa-banner__body"><b>${esc(data.first_name)} is back.</b> Their earlier account is active again as ${esc((ROLES[data.role] || [data.role])[0])}. They sign in with their old password, or choose a new one with “Forgot password?” at ${esc(login)}.</div></div>`, true);
          return;
        }
        result(`<div class="fwa-banner fwa-banner--ok">${icon('check')}<div class="fwa-banner__body"><b>${esc(data.first_name)}’s account is ready.</b> Pass these on privately — in person or by phone is best.</div></div>
          <div class="field"><span class="label">Sign in at</span><div class="fwa-secret"><code>${esc(login)}</code><button class="btn btn--sm" type="button" data-copy="${esc(login)}" data-copy-label="Address copied">${icon('i-copy', 'i--sm')}Copy</button></div></div>
          <div class="field"><span class="label">Email</span><div class="fwa-secret"><code>${esc(data.email)}</code></div></div>
          <div class="field"><span class="label">One-time password · shown only now</span><div class="fwa-secret"><code>${esc(r.temp_password)}</code><button class="btn btn--sm" type="button" data-copy="${esc(r.temp_password)}" data-copy-label="Password copied">${icon('i-copy', 'i--sm')}Copy</button></div>
            <p class="hint">They can choose their own any time with “Forgot password?” on the sign-in page.</p></div>`, true);
      }
      $('#addGo', m).addEventListener('click', submit);
      $('#addForm', m).addEventListener('submit', (e) => { e.preventDefault(); submit(); });
      m._onClose = () => resolve(added);
      openModal(m);
    });
  }

  FWA.roleCards = roleCards;
  FWA.addPerson = addPerson;
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-add-person]');
    if (!b) return;
    addPerson({ mode: b.dataset.addPerson || 'invite' }).then((added) => { if (added) location.reload(); });
  });
  if (new URLSearchParams(location.search).get('add') === '1') {
    history.replaceState(null, '', location.pathname);
    addPerson().then((added) => { if (added) location.reload(); });
  }
})();
