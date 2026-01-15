(function() {
  'use strict';

  window.PayrollPayitems = {
    currentFilters: {
      type: ''
    },

    init: function() {
      this.bindEvents();
      this.loadPayitems();
    },

    bindEvents: function() {
      const filterType = document.getElementById('filterType');
      if (filterType) {
        filterType.addEventListener('change', () => {
          this.currentFilters.type = filterType.value;
          this.loadPayitems();
        });
      }
    },

    loadPayitems: function() {
      const listContainer = document.getElementById('payitemsList');
      if (!listContainer) return;

      const params = new URLSearchParams(this.currentFilters);
      listContainer.innerHTML = '<div class="fw-payroll__loading">Loading pay items...</div>';

      fetch('/payroll/ajax/payitem_list.php?' + params.toString())
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            this.renderPayitems(data.payitems);
          } else {
            listContainer.innerHTML = '<div class="fw-payroll__loading">Error: ' + (data.error || 'Unknown error') + '</div>';
          }
        })
        .catch(err => {
          listContainer.innerHTML = '<div class="fw-payroll__loading">Network error</div>';
          console.error(err);
        });
    },

    renderPayitems: function(payitems) {
      const listContainer = document.getElementById('payitemsList');
      if (payitems.length === 0) {
        listContainer.innerHTML = '<div class="fw-payroll__empty-state">No pay items found</div>';
        return;
      }

      // Group by type
      const grouped = {
        earning: [],
        deduction: [],
        contribution: [],
        benefit: [],
        reimbursement: []
      };

      payitems.forEach(item => {
        if (grouped[item.type]) {
          grouped[item.type].push(item);
        }
      });

      let html = '';

      for (const [type, items] of Object.entries(grouped)) {
        if (items.length === 0) continue;

        const typeLabel = type.charAt(0).toUpperCase() + type.slice(1) + 's';
        html += `
          <div class="fw-payroll__payitem-section">
            <h3 class="fw-payroll__payitem-section-title">${typeLabel}</h3>
            <div class="fw-payroll__payitem-grid">
        `;

        items.forEach(item => {
          const badges = [];
          if (item.taxable == 1) badges.push('<span class="fw-payroll__tag fw-payroll__tag--taxable">Tax</span>');
          if (item.uif_subject == 1) badges.push('<span class="fw-payroll__tag fw-payroll__tag--uif">UIF</span>');
          if (item.sdl_subject == 1) badges.push('<span class="fw-payroll__tag fw-payroll__tag--sdl">SDL</span>');
          
          const activeClass = item.active == 1 ? '' : 'fw-payroll__payitem-card--inactive';

          html += `
            <div class="fw-payroll__payitem-card ${activeClass}" onclick="PayrollPayitems.openEditModal(${item.id})">
              <div class="fw-payroll__payitem-header">
                <div class="fw-payroll__payitem-code">${item.code}</div>
                ${item.active == 1 ? '' : '<span class="fw-payroll__badge fw-payroll__badge--inactive">Inactive</span>'}
              </div>
              <div class="fw-payroll__payitem-name">${item.name}</div>
              <div class="fw-payroll__payitem-badges">
                ${badges.join('')}
              </div>
              ${item.gl_account_code ? `<div class="fw-payroll__payitem-gl">GL: ${item.gl_account_code}</div>` : ''}
            </div>
          `;
        });

        html += `
            </div>
          </div>
        `;
      }

      listContainer.innerHTML = html;
    },

    openNewModal: function() {
      document.getElementById('payitemModalTitle').textContent = 'New Pay Item';
      document.getElementById('payitemForm').reset();
      document.getElementById('payitemId').value = '';
      document.querySelector('[name="taxable"]').checked = true;
      document.querySelector('[name="uif_subject"]').checked = true;
      document.querySelector('[name="sdl_subject"]').checked = true;
      document.querySelector('[name="active"]').checked = true;
      document.getElementById('payitemModal').setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
    },

    openEditModal: function(id) {
      document.getElementById('payitemModalTitle').textContent = 'Edit Pay Item';
      document.getElementById('payitemModal').setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';

      fetch('/payroll/ajax/payitem_get.php?id=' + id)
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            this.populateForm(data.payitem);
          } else {
            alert('Error loading pay item: ' + (data.error || 'Unknown'));
          }
        })
        .catch(err => {
          alert('Network error');
          console.error(err);
        });
    },

    populateForm: function(item) {
      document.getElementById('payitemId').value = item.id;
      document.querySelector('[name="code"]').value = item.code || '';
      document.querySelector('[name="name"]').value = item.name || '';
      document.querySelector('[name="type"]').value = item.type || 'earning';
      document.querySelector('[name="taxable"]').checked = item.taxable == 1;
      document.querySelector('[name="uif_subject"]').checked = item.uif_subject == 1;
      document.querySelector('[name="sdl_subject"]').checked = item.sdl_subject == 1;
      document.querySelector('[name="active"]').checked = item.active == 1;
      document.querySelector('[name="gl_account_code"]').value = item.gl_account_code || '';
    },

    closeModal: function() {
      document.getElementById('payitemModal').setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      document.getElementById('formMessage').innerHTML = '';
    },

    savePayitem: function() {
      const form = document.getElementById('payitemForm');
      const formData = new FormData(form);

      fetch('/payroll/ajax/payitem_save.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          this.closeModal();
          this.loadPayitems();
          showToast('Pay item saved successfully', 'success');
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

  function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = 'fw-payroll__toast fw-payroll__toast--' + type;
    toast.textContent = message;
    toast.style.cssText = 'position:fixed;top:20px;right:20px;padding:16px 24px;background:var(--accent-success);color:#fff;border-radius:8px;box-shadow:var(--fw-shadow-lg);z-index:9999;';
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => PayrollPayitems.init());
  } else {
    PayrollPayitems.init();
  }
})();