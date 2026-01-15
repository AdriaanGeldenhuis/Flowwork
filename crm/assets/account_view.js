// /crm/assets/account_view.js - FINAL WORKING VERSION
window.CRM = window.CRM || {};

(function() {
  'use strict';

  const accountId = parseInt(document.body.dataset.accountId);
  const data = window.CRM_DATA || {};

  // ========== TAB SWITCHING ==========
  const tabs = document.querySelectorAll('.fw-crm__view-tab');
  const panels = document.querySelectorAll('.fw-crm__tab-panel');

  tabs.forEach(tab => {
    tab.addEventListener('click', function() {
      const target = this.dataset.tab;
      
      // Remove active from all tabs
      tabs.forEach(t => t.classList.remove('fw-crm__view-tab--active'));
      
      // Hide all panels
      panels.forEach(p => {
        p.classList.remove('fw-crm__tab-panel--active');
      });
      
      // Activate clicked tab
      this.classList.add('fw-crm__view-tab--active');
      
      // Show target panel
      const panel = document.querySelector(`[data-panel="${target}"]`);
      if (panel) {
        panel.classList.add('fw-crm__tab-panel--active');
      }
    });
  });

  // ========== MODAL FUNCTIONS ==========
  CRM.openModal = function(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
      modal.setAttribute('aria-hidden', 'false');
      modal.style.display = 'flex';
      document.body.style.overflow = 'hidden';
    }
  };

  CRM.closeModal = function(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
      modal.setAttribute('aria-hidden', 'true');
      modal.style.display = 'none';
      document.body.style.overflow = '';
      
      // Reset form
      const form = modal.querySelector('form');
      if (form) {
        form.reset();
        // Clear hidden ID fields
        const idFields = form.querySelectorAll('input[type="hidden"][id$="_id"]');
        idFields.forEach(field => field.value = '');
      }
    }
  };

  // Close on overlay click
  document.addEventListener('click', (e) => {
    if (e.target.classList.contains('fw-crm__modal-overlay')) {
      const modalId = e.target.id;
      CRM.closeModal(modalId);
    }
  });

  // Close on Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      const openModals = document.querySelectorAll('.fw-crm__modal-overlay[aria-hidden="false"]');
      openModals.forEach(modal => {
        CRM.closeModal(modal.id);
      });
    }
  });

  // ========== FORM SUBMISSIONS ==========
  const contactForm = document.getElementById('contactForm');
  if (contactForm) {
    contactForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const formData = new FormData(e.target);
      const submitBtn = contactForm.querySelector('button[type="submit"]');
      
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Saving...';
      }

      try {
        const res = await fetch('/crm/ajax/contact_save.php', {
          method: 'POST',
          body: formData
        });

        const data = await res.json();

        if (data.ok) {
          alert('✅ Contact saved successfully!');
          location.reload();
        } else {
          alert('❌ Error: ' + (data.error || 'Failed to save contact'));
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Save Contact';
          }
        }
      } catch (err) {
        alert('❌ Network error: ' + err.message);
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = 'Save Contact';
        }
      }
    });
  }

  const addressForm = document.getElementById('addressForm');
  if (addressForm) {
    addressForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const formData = new FormData(e.target);
      const submitBtn = addressForm.querySelector('button[type="submit"]');
      
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Saving...';
      }

      try {
        const res = await fetch('/crm/ajax/address_save.php', {
          method: 'POST',
          body: formData
        });

        const data = await res.json();

        if (data.ok) {
          alert('✅ Address saved successfully!');
          location.reload();
        } else {
          alert('❌ Error: ' + (data.error || 'Failed to save address'));
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Save Address';
          }
        }
      } catch (err) {
        alert('❌ Network error: ' + err.message);
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = 'Save Address';
        }
      }
    });
  }

  const complianceForm = document.getElementById('complianceForm');
  if (complianceForm) {
    complianceForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const formData = new FormData(e.target);
      const submitBtn = complianceForm.querySelector('button[type="submit"]');
      
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Uploading...';
      }

      try {
        const res = await fetch('/crm/ajax/compliance_doc_save.php', {
          method: 'POST',
          body: formData
        });

        const data = await res.json();

        if (data.ok) {
          alert('✅ Document saved successfully!');
          location.reload();
        } else {
          alert('❌ Error: ' + (data.error || 'Failed to save document'));
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Save Document';
          }
        }
      } catch (err) {
        alert('❌ Network error: ' + err.message);
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = 'Save Document';
        }
      }
    });
  }

  const interactionForm = document.getElementById('interactionForm');
  if (interactionForm) {
    interactionForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const formData = new FormData(e.target);
      const submitBtn = interactionForm.querySelector('button[type="submit"]');
      
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Saving...';
      }

      try {
        const res = await fetch('/crm/ajax/interaction_save.php', {
          method: 'POST',
          body: formData
        });

        const data = await res.json();

        if (data.ok) {
          alert('✅ Interaction logged successfully!');
          location.reload();
        } else {
          alert('❌ Error: ' + (data.error || 'Failed to save interaction'));
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Save Interaction';
          }
        }
      } catch (err) {
        alert('❌ Network error: ' + err.message);
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = 'Save Interaction';
        }
      }
    });
  }

  // ========== CONTACT FUNCTIONS ==========
  CRM.editContact = async function(id) {
    try {
      const res = await fetch('/crm/ajax/contact_get.php?id=' + id);
      const data = await res.json();

      if (data.ok) {
        const form = document.getElementById('contactForm');
        const titleEl = document.getElementById('contactModalTitle');
        
        if (titleEl) titleEl.textContent = 'Edit Contact';
        
        form.contact_id.value = data.contact.id;
        form.first_name.value = data.contact.first_name || '';
        form.last_name.value = data.contact.last_name || '';
        form.role_title.value = data.contact.role_title || '';
        form.email.value = data.contact.email || '';
        form.phone.value = data.contact.phone || '';
        form.is_primary.checked = data.contact.is_primary == 1;

        CRM.openModal('contactModal');
      } else {
        alert('❌ Error: ' + (data.error || 'Failed to load contact'));
      }
    } catch (err) {
      alert('❌ Network error: ' + err.message);
    }
  };

  CRM.setPrimary = async function(id) {
    if (!confirm('Set this contact as primary?')) return;

    try {
      const res = await fetch('/crm/ajax/contact_set_primary.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ contact_id: id })
      });

      const data = await res.json();

      if (data.ok) {
        alert('✅ Primary contact updated successfully!');
        location.reload();
      } else {
        alert('❌ Error: ' + (data.error || 'Failed to set primary'));
      }
    } catch (err) {
      alert('❌ Network error: ' + err.message);
    }
  };

  CRM.deleteContact = async function(id) {
    if (!confirm('Delete this contact? This action cannot be undone.')) return;

    try {
      const res = await fetch('/crm/ajax/contact_delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ contact_id: id })
      });

      const data = await res.json();

      if (data.ok) {
        alert('✅ Contact deleted successfully!');
        location.reload();
      } else {
        alert('❌ Error: ' + (data.error || 'Failed to delete contact'));
      }
    } catch (err) {
      alert('❌ Network error: ' + err.message);
    }
  };

  // ========== ADDRESS FUNCTIONS ==========
  CRM.editAddress = async function(id) {
    try {
      const res = await fetch('/crm/ajax/address_get.php?id=' + id);
      const data = await res.json();

      if (data.ok) {
        const form = document.getElementById('addressForm');
        const titleEl = document.getElementById('addressModalTitle');
        
        if (titleEl) titleEl.textContent = 'Edit Address';
        
        form.address_id.value = data.address.id;
        form.type.value = data.address.type || 'billing';
        form.line1.value = data.address.line1 || '';
        form.line2.value = data.address.line2 || '';
        form.city.value = data.address.city || '';
        form.region.value = data.address.region || '';
        form.postal_code.value = data.address.postal_code || '';
        form.country.value = data.address.country || 'ZA';

        CRM.openModal('addressModal');
      } else {
        alert('❌ Error: ' + (data.error || 'Failed to load address'));
      }
    } catch (err) {
      alert('❌ Network error: ' + err.message);
    }
  };

  CRM.deleteAddress = async function(id) {
    if (!confirm('Delete this address? This action cannot be undone.')) return;

    try {
      const res = await fetch('/crm/ajax/address_delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ address_id: id })
      });

      const data = await res.json();

      if (data.ok) {
        alert('✅ Address deleted successfully!');
        location.reload();
      } else {
        alert('❌ Error: ' + (data.error || 'Failed to delete address'));
      }
    } catch (err) {
      alert('❌ Network error: ' + err.message);
    }
  };

  // ========== COMPLIANCE FUNCTIONS ==========
  CRM.editComplianceDoc = async function(id) {
    try {
      const res = await fetch('/crm/ajax/compliance_doc_get.php?id=' + id);
      const data = await res.json();

      if (data.ok) {
        const form = document.getElementById('complianceForm');
        const titleEl = document.getElementById('complianceModalTitle');
        
        if (titleEl) titleEl.textContent = 'Edit Compliance Document';
        
        form.doc_id.value = data.doc.id;
        form.type_id.value = data.doc.type_id || '';
        form.reference_no.value = data.doc.reference_no || '';
        form.expiry_date.value = data.doc.expiry_date || '';
        form.notes.value = data.doc.notes || '';

        CRM.openModal('complianceModal');
      } else {
        alert('❌ Error: ' + (data.error || 'Failed to load document'));
      }
    } catch (err) {
      alert('❌ Network error: ' + err.message);
    }
  };

  CRM.deleteComplianceDoc = async function(id) {
    if (!confirm('Delete this compliance document? This action cannot be undone.')) return;

    try {
      const res = await fetch('/crm/ajax/compliance_doc_delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ doc_id: id })
      });

      const data = await res.json();

      if (data.ok) {
        alert('✅ Document deleted successfully!');
        location.reload();
      } else {
        alert('❌ Error: ' + (data.error || 'Failed to delete document'));
      }
    } catch (err) {
      alert('❌ Network error: ' + err.message);
    }
  };

  // ========== INTERACTION FUNCTIONS ==========
  CRM.deleteInteraction = async function(id) {
    if (!confirm('Delete this interaction? This action cannot be undone.')) return;

    try {
      const res = await fetch('/crm/ajax/interaction_delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ interaction_id: id })
      });

      const data = await res.json();

      if (data.ok) {
        alert('✅ Interaction deleted successfully!');
        location.reload();
      } else {
        alert('❌ Error: ' + (data.error || 'Failed to delete interaction'));
      }
    } catch (err) {
      alert('❌ Network error: ' + err.message);
    }
  };

  // ========== CHARTS ==========
  const accountCharts = [
    {
      id: 'chartAccountContacts',
      title: 'Contacts Over Time',
      type: 'bar',
      data: {
        labels: ['Jan','Feb','Mar','Apr','May','Jun'],
        datasets: [{
          label: 'Contacts',
          data: [
            Math.max(1, (data.contactsCount || 5) - 5), 
            Math.max(1, (data.contactsCount || 5) - 4), 
            Math.max(1, (data.contactsCount || 5) - 3), 
            Math.max(1, (data.contactsCount || 5) - 2), 
            Math.max(1, (data.contactsCount || 5) - 1), 
            data.contactsCount || 5
          ],
          backgroundColor: '#06b6d4',
          borderRadius: 8
        }]
      }
    },
    {
      id: 'chartAccountInteractions',
      title: 'Interaction History',
      type: 'line',
      data: {
        labels: ['Jan','Feb','Mar','Apr','May','Jun'],
        datasets: [{
          label: 'Interactions',
          data: [
            Math.max(1, (data.interactionsCount || 10) - 8), 
            Math.max(1, (data.interactionsCount || 10) - 6), 
            Math.max(1, (data.interactionsCount || 10) - 4), 
            Math.max(1, (data.interactionsCount || 10) - 3), 
            Math.max(1, (data.interactionsCount || 10) - 1), 
            data.interactionsCount || 10
          ],
          borderColor: '#10b981',
          backgroundColor: 'rgba(16,185,129,.2)',
          borderWidth: 3,
          tension: .4,
          fill: true
        }]
      }
    },
    {
      id: 'chartAccountCompliance',
      title: 'Compliance Status',
      type: 'doughnut',
      data: {
        labels: ['Valid', 'Expiring', 'Expired'],
        datasets: [{
          data: [data.complianceCount || 5, 1, 0],
          backgroundColor: ['#10b981', '#f59e0b', '#ef4444']
        }]
      }
    },
    {
      id: 'chartAccountAddresses',
      title: 'Address Types',
      type: 'bar',
      data: {
        labels: ['Billing', 'Shipping', 'Site', 'Head Office'],
        datasets: [{
          label: 'Count',
          data: [1, 1, Math.max(0, (data.addressesCount || 3) - 2), 0],
          backgroundColor: '#8b5cf6',
          borderRadius: 8
        }]
      }
    },
    {
      id: 'chartAccountActivity',
      title: 'Monthly Activity',
      type: 'line',
      data: {
        labels: ['Jan','Feb','Mar','Apr','May','Jun'],
        datasets: [{
          label: 'Activity Score',
          data: [45, 52, 48, 60, 58, 65],
          borderColor: '#f59e0b',
          backgroundColor: 'rgba(245,158,11,.2)',
          borderWidth: 3,
          tension: .4,
          fill: true
        }]
      }
    },
    {
      id: 'chartAccountValue',
      title: 'Account Value Trend',
      type: 'bar',
      data: {
        labels: ['Q1', 'Q2', 'Q3', 'Q4'],
        datasets: [{
          label: 'Value (R)',
          data: [25000, 32000, 28000, 35000],
          backgroundColor: '#06b6d4',
          borderRadius: 8
        }]
      }
    }
  ];

  const accountChartInstances = {};

  function buildAccountCharts() {
    const board = document.getElementById('accountChartsBoard');
    if (!board) return;
    
    board.innerHTML = '';
    
    accountCharts.forEach(chartDef => {
      const card = document.createElement('div');
      card.className = 'crm-playground-chart-card';
      card.innerHTML = `
        <div class="crm-playground-chart-header">
          <div class="crm-playground-chart-title">${chartDef.title}</div>
        </div>
        <div class="crm-playground-chart-body">
          <canvas id="${chartDef.id}"></canvas>
        </div>
      `;
      board.appendChild(card);
      
      setTimeout(() => {
        const canvas = document.getElementById(chartDef.id);
        if (canvas && typeof Chart !== 'undefined') {
          const ctx = canvas.getContext('2d');
          const isDark = document.querySelector('.fw-crm').getAttribute('data-theme') === 'dark';
          
          accountChartInstances[chartDef.id] = new Chart(canvas, {
            type: chartDef.type,
            data: JSON.parse(JSON.stringify(chartDef.data)),
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: { 
                legend: { 
                  display: chartDef.type === 'doughnut',
                  labels: { color: isDark ? '#9fb0c8' : '#6b7280' }
                },
                tooltip: {
                  backgroundColor: isDark ? 'rgba(18,24,36,.95)' : 'rgba(255,255,255,.95)',
                  titleColor: isDark ? '#e7ecf2' : '#1a1d29',
                  bodyColor: isDark ? '#9fb0c8' : '#6b7280',
                  borderColor: '#06b6d4',
                  borderWidth: 1
                }
              },
              scales: chartDef.type !== 'doughnut' ? {
                x: { 
                  grid: { color: isDark ? 'rgba(255,255,255,.05)' : 'rgba(0,0,0,.05)' },
                  ticks: { color: isDark ? '#9fb0c8' : '#6b7280', font: { size: 10 } }
                },
                y: { 
                  grid: { color: isDark ? 'rgba(255,255,255,.05)' : 'rgba(0,0,0,.05)' },
                  ticks: { color: isDark ? '#9fb0c8' : '#6b7280', font: { size: 10 } }
                }
              } : {}
            }
          });
        }
      }, 100);
    });
  }

  const refreshBtn = document.getElementById('accountRefresh');
  if (refreshBtn) {
    refreshBtn.addEventListener('click', () => {
      Object.values(accountChartInstances).forEach(chart => {
        chart.data.datasets.forEach(dataset => {
          dataset.data = dataset.data.map(() => Math.floor(Math.random() * 100) + 10);
        });
        chart.update();
      });
    });
  }

  if (document.getElementById('accountChartsBoard')) {
    buildAccountCharts();
  }

  // ========== COMPLIANCE BADGE ==========
  const badge = document.getElementById('complianceBadge');
  if (badge && accountId) {
    fetch('/crm/ajax/compliance_check.php?account_id=' + accountId)
      .then(res => res.json())
      .then(data => {
        if (!data || data.error) {
          badge.textContent = 'Unknown';
          badge.classList.add('fw-crm__badge--missing');
          return;
        }
        const status = data.status || 'valid';
        badge.textContent = status.charAt(0).toUpperCase() + status.slice(1);
        badge.classList.add('fw-crm__badge--' + status);
      })
      .catch(() => {
        badge.textContent = 'Error';
        badge.classList.add('fw-crm__badge--missing');
      });
  }

})();