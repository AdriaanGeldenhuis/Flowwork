(function() {
  'use strict';

  window.PayrollRuns = {
    currentFilters: {
      status: 'draft,calculated,review',
      frequency: ''
    },

    init: function() {
      this.bindEvents();
      this.loadRuns();
    },

    bindEvents: function() {
      const filterStatus = document.getElementById('filterStatus');
      const filterFrequency = document.getElementById('filterFrequency');

      if (filterStatus) {
        filterStatus.addEventListener('change', () => {
          this.currentFilters.status = filterStatus.value;
          this.loadRuns();
        });
      }

      if (filterFrequency) {
        filterFrequency.addEventListener('change', () => {
          this.currentFilters.frequency = filterFrequency.value;
          this.loadRuns();
        });
      }
    },

    loadRuns: function() {
      const listContainer = document.getElementById('runsList');
      if (!listContainer) return;

      const params = new URLSearchParams(this.currentFilters);
      listContainer.innerHTML = '<div class="fw-payroll__loading">Loading pay runs...</div>';

      fetch('/payroll/ajax/run_list.php?' + params.toString())
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            this.renderRuns(data.runs);
          } else {
            listContainer.innerHTML = '<div class="fw-payroll__loading">Error: ' + (data.error || 'Unknown error') + '</div>';
          }
        })
        .catch(err => {
          listContainer.innerHTML = '<div class="fw-payroll__loading">Network error</div>';
          console.error(err);
        });
    },

    renderRuns: function(runs) {
      const listContainer = document.getElementById('runsList');
      if (runs.length === 0) {
        listContainer.innerHTML = '<div class="fw-payroll__empty-state">No pay runs found</div>';
        return;
      }

      const html = runs.map(run => {
        const netTotal = parseFloat(run.net_total_cents || 0) / 100;
        const employeeCount = parseInt(run.employee_count || 0);

        return `
          <a href="/payroll/run_view.php?id=${run.id}" class="fw-payroll__run-card">
            <div class="fw-payroll__run-header">
              <div class="fw-payroll__run-name">${run.name}</div>
              <span class="fw-payroll__badge fw-payroll__badge--${run.status}">${this.formatStatus(run.status)}</span>
            </div>
            <div class="fw-payroll__run-meta">
              <div class="fw-payroll__run-meta-item">
                <svg viewBox="0 0 24 24" fill="none" width="16" height="16">
                  <rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/>
                  <line x1="16" y1="2" x2="16" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                  <line x1="8" y1="2" x2="8" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                  <line x1="3" y1="10" x2="21" y2="10" stroke="currentColor" stroke-width="2"/>
                </svg>
                ${this.formatDate(run.period_start)} - ${this.formatDate(run.period_end)}
              </div>
              <div class="fw-payroll__run-meta-item">
                <svg viewBox="0 0 24 24" fill="none" width="16" height="16">
                  <circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="2"/>
                  <path d="M6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2" stroke="currentColor" stroke-width="2"/>
                </svg>
                ${employeeCount} employee${employeeCount !== 1 ? 's' : ''}
              </div>
              <div class="fw-payroll__run-meta-item">
                <svg viewBox="0 0 24 24" fill="none" width="16" height="16">
                  <line x1="12" y1="1" x2="12" y2="23" stroke="currentColor" stroke-width="2"/>
                  <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" stroke="currentColor" stroke-width="2"/>
                </svg>
                Pay Date: ${this.formatDate(run.pay_date)}
              </div>
            </div>
            <div class="fw-payroll__run-total">
              Net Total: R ${netTotal.toLocaleString('en-ZA', {minimumFractionDigits: 2, maximumFractionDigits: 2})}
            </div>
          </a>
        `;
      }).join('');

      listContainer.innerHTML = html;
    },

    formatStatus: function(status) {
      const map = {
        'draft': 'Draft',
        'calculated': 'Calculated',
        'review': 'Review',
        'approved': 'Approved',
        'locked': 'Locked',
        'posted': 'Posted'
      };
      return map[status] || status;
    },

    formatDate: function(dateStr) {
      if (!dateStr) return '';
      const d = new Date(dateStr);
      return d.toLocaleDateString('en-ZA', {day: '2-digit', month: 'short', year: 'numeric'});
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => PayrollRuns.init());
  } else {
    PayrollRuns.init();
  }
})();