(function() {
  'use strict';

  window.PayrollEmployees = {
    currentFilters: {
      search: '',
      status: 'active',
      frequency: ''
    },

    init: function() {
      this.bindEvents();
      this.loadEmployees();
    },

    bindEvents: function() {
      const searchInput = document.getElementById('searchInput');
      const filterStatus = document.getElementById('filterStatus');
      const filterFrequency = document.getElementById('filterFrequency');

      if (searchInput) {
        searchInput.addEventListener('input', debounce(() => {
          this.currentFilters.search = searchInput.value;
          this.loadEmployees();
        }, 300));
      }

      if (filterStatus) {
        filterStatus.addEventListener('change', () => {
          this.currentFilters.status = filterStatus.value;
          this.loadEmployees();
        });
      }

      if (filterFrequency) {
        filterFrequency.addEventListener('change', () => {
          this.currentFilters.frequency = filterFrequency.value;
          this.loadEmployees();
        });
      }
    },

    loadEmployees: function() {
      const listContainer = document.getElementById('employeesList');
      if (!listContainer) return;

      const params = new URLSearchParams(this.currentFilters);
      listContainer.innerHTML = '<div class="fw-payroll__loading">Loading employees...</div>';

      fetch('/payroll/ajax/employee_list.php?' + params.toString())
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            this.renderEmployees(data.employees);
          } else {
            listContainer.innerHTML = '<div class="fw-payroll__loading">Error: ' + (data.error || 'Unknown error') + '</div>';
          }
        })
        .catch(err => {
          listContainer.innerHTML = '<div class="fw-payroll__loading">Network error</div>';
          console.error(err);
        });
    },

    renderEmployees: function(employees) {
      const listContainer = document.getElementById('employeesList');
      if (employees.length === 0) {
        listContainer.innerHTML = '<div class="fw-payroll__empty-state">No employees found</div>';
        return;
      }

      const html = employees.map(emp => {
        const initials = (emp.first_name.charAt(0) + emp.last_name.charAt(0)).toUpperCase();
        const salary = parseFloat(emp.base_salary_cents) / 100;
        const statusBadge = emp.termination_date 
          ? '<span class="fw-payroll__badge fw-payroll__badge--terminated">Terminated</span>'
          : '<span class="fw-payroll__badge fw-payroll__badge--active">Active</span>';

        return `
          <div class="fw-payroll__employee-card" onclick="PayrollEmployees.openEditModal(${emp.id})">
            <div class="fw-payroll__employee-avatar">${initials}</div>
            <div class="fw-payroll__employee-info">
              <div class="fw-payroll__employee-name">${emp.first_name} ${emp.last_name}</div>
              <div class="fw-payroll__employee-meta">
                ${emp.employee_no} • ${emp.employment_type} • ${emp.pay_frequency}
              </div>
            </div>
            <div class="fw-payroll__employee-salary">
              R ${salary.toFixed(2)}
            </div>
            ${statusBadge}
          </div>
        `;
      }).join('');

      listContainer.innerHTML = html;
    },

    openNewModal: function() {
      document.getElementById('employeeModalTitle').textContent = 'New Employee';
      document.getElementById('employeeForm').reset();
      document.getElementById('employeeId').value = '';
      document.getElementById('employeeModal').setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
    },

    openEditModal: function(id) {
      document.getElementById('employeeModalTitle').textContent = 'Edit Employee';
      document.getElementById('employeeModal').setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';

      fetch('/payroll/ajax/employee_get.php?id=' + id)
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            this.populateForm(data.employee);
          } else {
            alert('Error loading employee: ' + (data.error || 'Unknown'));
          }
        })
        .catch(err => {
          alert('Network error');
          console.error(err);
        });
    },

    populateForm: function(emp) {
      document.getElementById('employeeId').value = emp.id;
      document.querySelector('[name="employee_no"]').value = emp.employee_no || '';
      document.querySelector('[name="first_name"]').value = emp.first_name || '';
      document.querySelector('[name="last_name"]').value = emp.last_name || '';
      document.querySelector('[name="id_number"]').value = emp.id_number || '';
      document.querySelector('[name="email"]').value = emp.email || '';
      document.querySelector('[name="phone"]').value = emp.phone || '';
      document.querySelector('[name="hire_date"]').value = emp.hire_date || '';
      document.querySelector('[name="employment_type"]').value = emp.employment_type || 'permanent';
      document.querySelector('[name="pay_frequency"]').value = emp.pay_frequency || 'monthly';
      document.querySelector('[name="base_salary"]').value = (parseFloat(emp.base_salary_cents) / 100).toFixed(2);
      document.querySelector('[name="tax_number"]').value = emp.tax_number || '';
      document.querySelector('[name="uif_included"]').checked = emp.uif_included == 1;
      document.querySelector('[name="sdl_included"]').checked = emp.sdl_included == 1;
      document.querySelector('[name="bank_name"]').value = emp.bank_name || '';
      document.querySelector('[name="branch_code"]').value = emp.branch_code || '';
      document.querySelector('[name="bank_account_no"]').value = emp.bank_account_no || '';
    },

    closeModal: function() {
      document.getElementById('employeeModal').setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      document.getElementById('formMessage').innerHTML = '';
    },

    saveEmployee: function() {
      const form = document.getElementById('employeeForm');
      const formData = new FormData(form);

      fetch('/payroll/ajax/employee_save.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          this.closeModal();
          this.loadEmployees();
          showToast('Employee saved successfully', 'success');
        } else {
          document.getElementById('formMessage').innerHTML = 
            '<div class="fw-payroll__form-message fw-payroll__form-message--error">' + 
            (data.error || 'Save failed') + 
            '</div>';
        }
      })
      .catch(err => {
        document.getElementById('formMessage').innerHTML = 
          '<div class="fw-payroll__form-message fw-payroll__form-message--error">Network error</div>';
        console.error(err);
      });
    }
  };

  function debounce(func, wait) {
    let timeout;
    return function(...args) {
      clearTimeout(timeout);
      timeout = setTimeout(() => func.apply(this, args), wait);
    };
  }

  function showToast(message, type = 'info') {
    // Simple toast - you can enhance this
    const toast = document.createElement('div');
    toast.className = 'fw-payroll__toast fw-payroll__toast--' + type;
    toast.textContent = message;
    toast.style.cssText = 'position:fixed;top:20px;right:20px;padding:16px 24px;background:var(--accent-success);color:#fff;border-radius:8px;box-shadow:var(--fw-shadow-lg);z-index:9999;';
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => PayrollEmployees.init());
  } else {
    PayrollEmployees.init();
  }
})();