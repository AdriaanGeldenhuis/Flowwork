(function () {
  'use strict';

  function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  async function switchCompany(button) {
    const companyId = button.getAttribute('data-fw-switch-company');
    if (!companyId || button.disabled) return;

    const originalLabel = button.textContent;
    button.disabled = true;
    button.classList.add('is-loading');

    try {
      const formData = new FormData();
      formData.append('company_id', companyId);
      formData.append('csrf_token', getCsrfToken());

      const response = await fetch('/admin/ajax/switch_company.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': getCsrfToken() }
      });

      const data = await response.json().catch(() => ({}));

      if (!response.ok || !data.ok) {
        throw new Error(data.error || 'Could not switch company.');
      }

      window.location.reload();
    } catch (err) {
      button.disabled = false;
      button.classList.remove('is-loading');
      button.textContent = originalLabel;
      alert(err.message || 'Could not switch company.');
    }
  }

  document.addEventListener('click', function (event) {
    const target = event.target.closest('[data-fw-switch-company]');
    if (!target) return;
    event.preventDefault();
    switchCompany(target);
  });
})();
