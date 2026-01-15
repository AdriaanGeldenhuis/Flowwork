(function() {
  'use strict';

  window.PayrollRunWizard = {
    settings: window.PAYROLL_SETTINGS || {},

    init: function() {
      this.autoFillDates();
    },

    autoFillDates: function() {
      const frequency = document.getElementById('frequency').value;
      const today = new Date();
      let periodStart, periodEnd, payDate;

      if (frequency === 'monthly') {
        // Current month, 1st to last day
        const year = today.getFullYear();
        const month = today.getMonth();
        periodStart = new Date(year, month, 1);
        periodEnd = new Date(year, month + 1, 0);
        
        // Pay date = anchor day of next month
        const anchorDay = parseInt(this.settings.monthly_anchor_day || 25);
        payDate = new Date(year, month + 1, anchorDay);
        
      } else if (frequency === 'fortnight') {
        // Last 14 days
        periodEnd = new Date(today);
        periodStart = new Date(today);
        periodStart.setDate(periodStart.getDate() - 13);
        
        const anchorDay = parseInt(this.settings.fortnight_anchor_day || 15);
        payDate = new Date(today);
        payDate.setDate(payDate.getDate() + 7);
        payDate.setDate(anchorDay);
        
      } else if (frequency === 'weekly') {
        // Last 7 days
        periodEnd = new Date(today);
        periodStart = new Date(today);
        periodStart.setDate(periodStart.getDate() - 6);
        
        // Pay date = next Friday (or anchor day)
        payDate = new Date(today);
        payDate.setDate(payDate.getDate() + 5);
      }

      document.getElementById('periodStart').value = this.formatDateInput(periodStart);
      document.getElementById('periodEnd').value = this.formatDateInput(periodEnd);
      document.getElementById('payDate').value = this.formatDateInput(payDate);
    },

    formatDateInput: function(date) {
      const year = date.getFullYear();
      const month = String(date.getMonth() + 1).padStart(2, '0');
      const day = String(date.getDate()).padStart(2, '0');
      return `${year}-${month}-${day}`;
    },

    createRun: function() {
      const form = document.getElementById('runWizardForm');
      if (!form.checkValidity()) {
        form.reportValidity();
        return;
      }

      const formData = new FormData(form);
      const msgDiv = document.getElementById('formMessage');
      msgDiv.innerHTML = '<div class="fw-payroll__loading">Creating pay run...</div>';

      fetch('/payroll/ajax/run_create.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          msgDiv.innerHTML = '<div class="fw-payroll__form-message fw-payroll__form-message--success">Pay run created! Redirecting...</div>';
          setTimeout(() => {
            window.location.href = '/payroll/run_view.php?id=' + data.id;
          }, 1000);
        } else {
          msgDiv.innerHTML = '<div class="fw-payroll__form-message fw-payroll__form-message--error">' + 
            (data.error || 'Failed to create run') + '</div>';
        }
      })
      .catch(err => {
        msgDiv.innerHTML = '<div class="fw-payroll__form-message fw-payroll__form-message--error">Network error</div>';
        console.error(err);
      });
    }
  };

  // Auto-fill on frequency change
  document.addEventListener('DOMContentLoaded', () => {
    PayrollRunWizard.init();
    
    const freqSelect = document.getElementById('frequency');
    if (freqSelect) {
      freqSelect.addEventListener('change', () => {
        PayrollRunWizard.autoFillDates();
      });
    }
  });
})();