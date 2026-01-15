(function() {
  'use strict';

  window.PayrollSettings = {
    saveSettings: function() {
      const form = document.getElementById('settingsForm');
      const formData = new FormData(form);
      const msgDiv = document.getElementById('settingsMessage');

      msgDiv.innerHTML = '<div class="fw-payroll__loading">Saving settings...</div>';

      fetch('/payroll/ajax/settings_save.php', {
        method: 'POST',
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