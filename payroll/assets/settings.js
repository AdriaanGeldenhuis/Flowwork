(function() {
  'use strict';

  window.PayrollSettings = {
    saveSettings: function() {
      const form = document.getElementById('settingsForm');
      const formData = new FormData(form);
      const msgDiv = document.getElementById('settingsMessage');

      msgDiv.innerHTML = '<div class="fw-payroll__loading">Saving settings...</div>';

      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

      fetch('/payroll/ajax/settings_save.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          msgDiv.innerHTML = '<div class="fw-payroll__alert fw-payroll__alert--success">Settings saved successfully!</div>';
          setTimeout(() => {
            msgDiv.innerHTML = '';
          }, 3000);
        } else {
          msgDiv.innerHTML = '<div class="fw-payroll__alert fw-payroll__alert--error">' + (data.error || 'Save failed') + '</div>';
        }
      })
      .catch(err => {
        msgDiv.innerHTML = '<div class="fw-payroll__alert fw-payroll__alert--error">Network error</div>';
        console.error(err);
      });
    }
  };
})();