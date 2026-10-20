// /admin/js/companies.js — "Add a company": a name and a kind of business;
// the new company opens straight away (/admin/api/companies.php).
(function () {
  'use strict';
  const { $, esc, icon, api, toastError, modal, openModal, closeModal, busy } = window.FWA;
  const btn = $('#addCompany');
  if (!btn) return;

  function open() {
    if (FWA.boot.atLimit) return;
    const types = FWA.boot.types || {};
    const m = modal(`<div class="fwm-dialog__head"><h2>Add a company</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body" novalidate id="coForm">
        <p class="fwm-dialog__lead">It starts empty, with its own books and settings, on your plan. You are its admin and can invite people once it opens.</p>
        <div class="fwa-form">
          <div class="field is-full"><label class="label" for="coName">Company name<span class="req">*</span></label><input class="input" id="coName" name="name" maxlength="255" placeholder="e.g. Concreto Civils (Pty) Ltd" autofocus></div>
          <div class="field is-full"><label class="label" for="coType">Kind of business</label><select class="select" id="coType" name="business_type">${Object.entries(types).map(([k, l]) => `<option value="${esc(k)}">${esc(l)}</option>`).join('')}</select></div>
        </div>
      </form>
      <div class="fwm-dialog__foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" id="coGo">${icon('plus', 'i--sm')}Create and open it</button></div>`, { width: 520 });
    const go = async () => {
      const f = $('#coForm', m);
      m.querySelectorAll('.field__error').forEach((x) => x.remove());
      if (!f.name.value.trim()) { const d = document.createElement('div'); d.className = 'field__error'; d.textContent = 'Enter the company’s name.'; f.name.parentElement.appendChild(d); f.name.focus(); return; }
      const b = $('#coGo', m);
      busy(b, true);
      try {
        await api.post('companies.php', { action: 'create', name: f.name.value.trim(), business_type: f.business_type.value });
        location.assign('/admin/?welcome=1');
      } catch (e) { toastError(e); busy(b, false); }
    };
    $('#coGo', m).addEventListener('click', go);
    $('#coForm', m).addEventListener('submit', (e) => { e.preventDefault(); go(); });
    openModal(m);
  }
  btn.addEventListener('click', open);
  if (new URLSearchParams(location.search).get('new') === '1') { history.replaceState(null, '', location.pathname); open(); }
})();
