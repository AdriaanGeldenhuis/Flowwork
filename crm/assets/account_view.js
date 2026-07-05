// /crm/assets/account_view.js - FINAL WORKING VERSION
window.CRM = window.CRM || {};

(function() {
  'use strict';

  const accountId = parseInt(document.body.dataset.accountId);

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

      // Lazy-load data tabs on first open
      if (target === 'timeline') loadTimeline();
      if (target === 'linked') loadLinkedItems();
    });
  });

  function toast(msg, type) {
    if (CRM.toast) { CRM.toast(msg, type); } else { alert(msg); }
  }

  // ========== TIMELINE TAB (ajax/account_timeline.php) ==========
  let timelineLoaded = false;
  function loadTimeline(force) {
    const container = document.getElementById('timelineContainer');
    if (!container || (timelineLoaded && !force)) return;
    timelineLoaded = true;

    container.innerHTML = '<div class="fw-crm__loading">Loading timeline...</div>';
    fetch('/crm/ajax/account_timeline.php?account_id=' + accountId)
      .then(res => res.json())
      .then(events => {
        if (!Array.isArray(events)) {
          container.innerHTML = '<div class="fw-crm__empty-state">' +
            escapeHtml((events && events.error) || 'Failed to load timeline') + '</div>';
          return;
        }
        if (events.length === 0) {
          container.innerHTML = '<div class="fw-crm__empty-state">No activity yet</div>';
          return;
        }
        const icons = { interaction: '📝', email: '📧', quote: '📄', invoice: '💰' };
        const items = events.map(ev => `
          <div class="fw-crm__timeline-item">
            <div class="fw-crm__timeline-icon">${icons[ev.type] || '•'}</div>
            <div class="fw-crm__timeline-content">
              <div class="fw-crm__timeline-title">${escapeHtml(ev.title || '')}</div>
              <div class="fw-crm__timeline-meta">
                ${escapeHtml(ev.ts || '')}${ev.by ? ' • by ' + escapeHtml(ev.by) : ''}
                <span class="fw-crm__badge" style="margin-left:6px; font-size:10px;">${escapeHtml(ev.type)}</span>
              </div>
            </div>
          </div>`).join('');
        container.innerHTML = '<div class="fw-crm__timeline">' + items + '</div>';
      })
      .catch(() => {
        timelineLoaded = false;
        container.innerHTML = '<div class="fw-crm__empty-state">Network error loading timeline</div>';
      });
  }
  CRM.reloadTimeline = () => loadTimeline(true);

  // ========== LINKED ITEMS TAB (ajax/account_linked.php) ==========
  let linkedLoaded = false;
  function loadLinkedItems(force) {
    const container = document.getElementById('linkedItemsContainer');
    if (!container || (linkedLoaded && !force)) return;
    linkedLoaded = true;

    container.innerHTML = '<div class="fw-crm__loading">Loading linked items...</div>';
    fetch('/crm/ajax/account_linked.php?account_id=' + accountId)
      .then(res => res.json())
      .then(data => {
        if (!data || data.error) {
          container.innerHTML = '<div class="fw-crm__empty-state">' +
            escapeHtml((data && data.error) || 'Failed to load linked items') + '</div>';
          return;
        }

        const sections = [];

        function table(title, rows, headers, rowHtml) {
          if (!rows || rows.length === 0) return;
          sections.push(`
            <div class="fw-crm__linked-section">
              <div class="fw-crm__linked-title">${title} (${rows.length})</div>
              <div style="overflow-x:auto;">
                <table class="fw-crm__linked-table">
                  <thead><tr>${headers.map(h => '<th>' + h + '</th>').join('')}</tr></thead>
                  <tbody>${rows.map(rowHtml).join('')}</tbody>
                </table>
              </div>
            </div>`);
        }

        table('Quotes', data.quotes, ['Number', 'Status', 'Created'], q => `
          <tr>
            <td><a href="/qi/quote_view.php?id=${parseInt(q.id, 10)}">${escapeHtml(q.number || ('#' + q.id))}</a></td>
            <td>${escapeHtml(q.status || '')}</td>
            <td>${escapeHtml((q.created_at || '').substring(0, 10))}</td>
          </tr>`);

        table('Invoices', data.invoices, ['Number', 'Status', 'Created'], inv => `
          <tr>
            <td><a href="/qi/invoice_view.php?id=${parseInt(inv.id, 10)}">${escapeHtml(inv.number || ('#' + inv.id))}</a></td>
            <td>${escapeHtml(inv.status || '')}</td>
            <td>${escapeHtml((inv.created_at || '').substring(0, 10))}</td>
          </tr>`);

        table('Projects', data.projects, ['Name', 'Status', 'Created'], p => `
          <tr>
            <td><a href="/projects/view.php?project_id=${parseInt(p.id, 10)}">${escapeHtml(p.name || ('#' + p.id))}</a></td>
            <td>${escapeHtml(p.status || '')}</td>
            <td>${escapeHtml((p.created_at || '').substring(0, 10))}</td>
          </tr>`);

        table('RFQs', data.rfqs, ['Reference', 'Due date', 'Created'], r => `
          <tr>
            <td>${escapeHtml(r.number || (r.stub_id ? 'Draft #' + r.stub_id : '#' + (r.id || '')))}</td>
            <td>${escapeHtml(r.due_date || '')}</td>
            <td>${escapeHtml((r.created_at || '').substring(0, 10))}</td>
          </tr>`);

        container.innerHTML = sections.length
          ? sections.join('')
          : '<div class="fw-crm__empty-state">No linked quotes, invoices, projects or RFQs yet</div>';
      })
      .catch(() => {
        linkedLoaded = false;
        container.innerHTML = '<div class="fw-crm__empty-state">Network error loading linked items</div>';
      });
  }

  // Shared implementation lives in crm.js (loaded first on this page)
  const escapeHtml = CRM.escapeHtml;

  // ========== TAG EDITOR ==========
  (function initTagEditor() {
    const wrap = document.getElementById('accountTags');
    const addBtn = document.getElementById('tagAddBtn');
    const popover = document.getElementById('tagPopover');
    if (!wrap || !addBtn || !popover) return;

    const nameInput = document.getElementById('tagNameInput');
    const colorInput = document.getElementById('tagColorInput');
    const suggestBox = document.getElementById('tagSuggests');
    let suggestionsLoaded = false;

    function openPopover() {
      popover.classList.add('fw-crm__tag-popover--open');
      nameInput.value = '';
      nameInput.focus();
      if (!suggestionsLoaded) {
        suggestionsLoaded = true;
        fetch('/crm/ajax/tag_suggest.php?account_id=' + accountId)
          .then(res => res.json())
          .then(data => {
            const names = (data && (data.suggestions || data.tags)) || [];
            suggestBox.innerHTML = names.slice(0, 6).map(s => {
              const name = typeof s === 'string' ? s : (s.name || '');
              return name ? '<button type="button" class="fw-crm__tag-suggest">' + escapeHtml(name) + '</button>' : '';
            }).join('');
            suggestBox.querySelectorAll('.fw-crm__tag-suggest').forEach(btn => {
              btn.addEventListener('click', () => { nameInput.value = btn.textContent; nameInput.focus(); });
            });
          })
          .catch(() => { /* suggestions are optional */ });
      }
    }

    function closePopover() {
      popover.classList.remove('fw-crm__tag-popover--open');
    }

    addBtn.addEventListener('click', () => {
      popover.classList.contains('fw-crm__tag-popover--open') ? closePopover() : openPopover();
    });
    document.getElementById('tagCancelBtn').addEventListener('click', closePopover);
    document.addEventListener('click', (e) => {
      if (!wrap.contains(e.target)) closePopover();
    });

    function saveTag() {
      const name = nameInput.value.trim();
      if (!name) { toast('Enter a tag name', 'error'); return; }
      const fd = new FormData();
      fd.append('account_id', accountId);
      fd.append('name', name);
      fd.append('color', colorInput.value);
      fetch('/crm/ajax/tag_save.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            addTagChip(data.tag_id, name, colorInput.value);
            closePopover();
            toast('Tag added');
          } else {
            toast(data.error || 'Failed to add tag', 'error');
          }
        })
        .catch(() => toast('Network error', 'error'));
    }

    document.getElementById('tagSaveBtn').addEventListener('click', saveTag);
    nameInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); saveTag(); }
    });

    function addTagChip(tagId, name, color) {
      // Skip if the tag is already displayed
      if (wrap.querySelector('.fw-crm__tag[data-tag-id="' + tagId + '"]')) return;
      const chip = document.createElement('span');
      chip.className = 'fw-crm__tag';
      chip.dataset.tagId = tagId;
      chip.style.background = color;
      chip.appendChild(document.createTextNode(name + ' '));
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'fw-crm__tag-remove';
      btn.dataset.tagId = tagId;
      btn.setAttribute('aria-label', 'Remove tag ' + name);
      btn.innerHTML = '&times;';
      chip.appendChild(btn);
      wrap.insertBefore(chip, addBtn);
    }

    // Remove — delegated so it covers chips added after load
    wrap.addEventListener('click', (e) => {
      const btn = e.target.closest('.fw-crm__tag-remove');
      if (!btn) return;
      const tagId = parseInt(btn.dataset.tagId, 10);
      if (!tagId) return;
      fetch('/crm/ajax/tag_remove.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ account_id: accountId, tag_id: tagId })
      })
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            const chip = wrap.querySelector('.fw-crm__tag[data-tag-id="' + tagId + '"]');
            if (chip) chip.remove();
            toast('Tag removed');
          } else {
            toast(data.error || 'Failed to remove tag', 'error');
          }
        })
        .catch(() => toast('Network error', 'error'));
    });
  })();

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
          location.reload();
        } else {
          toast(data.error || 'Failed to save contact', 'error');
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Save Contact';
          }
        }
      } catch (err) {
        toast('Network error', 'error');
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
          location.reload();
        } else {
          toast(data.error || 'Failed to save address', 'error');
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Save Address';
          }
        }
      } catch (err) {
        toast('Network error', 'error');
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
          location.reload();
        } else {
          toast(data.error || 'Failed to save document', 'error');
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Save Document';
          }
        }
      } catch (err) {
        toast('Network error', 'error');
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
          location.reload();
        } else {
          toast(data.error || 'Failed to save interaction', 'error');
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Save Interaction';
          }
        }
      } catch (err) {
        toast('Network error', 'error');
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
        toast(data.error || 'Failed to load contact', 'error');
      }
    } catch (err) {
      toast('Network error', 'error');
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
        location.reload();
      } else {
        toast(data.error || 'Failed to set primary', 'error');
      }
    } catch (err) {
      toast('Network error', 'error');
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
        location.reload();
      } else {
        toast(data.error || 'Failed to delete contact', 'error');
      }
    } catch (err) {
      toast('Network error', 'error');
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
        toast(data.error || 'Failed to load address', 'error');
      }
    } catch (err) {
      toast('Network error', 'error');
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
        location.reload();
      } else {
        toast(data.error || 'Failed to delete address', 'error');
      }
    } catch (err) {
      toast('Network error', 'error');
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
        toast(data.error || 'Failed to load document', 'error');
      }
    } catch (err) {
      toast('Network error', 'error');
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
        location.reload();
      } else {
        toast(data.error || 'Failed to delete document', 'error');
      }
    } catch (err) {
      toast('Network error', 'error');
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
        location.reload();
      } else {
        toast(data.error || 'Failed to delete interaction', 'error');
      }
    } catch (err) {
      toast('Network error', 'error');
    }
  };

  // ========== CHARTS (real data from ajax/account_stats.php) ==========
  const accountChartInstances = {};

  function chartOptions(type, isDark) {
    return {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: type === 'doughnut' || type === 'bar-grouped',
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
      scales: type !== 'doughnut' ? {
        x: {
          grid: { color: isDark ? 'rgba(255,255,255,.05)' : 'rgba(0,0,0,.05)' },
          ticks: { color: isDark ? '#9fb0c8' : '#6b7280', font: { size: 10 } }
        },
        y: {
          beginAtZero: true,
          grid: { color: isDark ? 'rgba(255,255,255,.05)' : 'rgba(0,0,0,.05)' },
          ticks: { color: isDark ? '#9fb0c8' : '#6b7280', font: { size: 10 } }
        }
      } : {}
    };
  }

  let lastAccountStats = null;

  function buildAccountCharts(stats) {
    const board = document.getElementById('accountChartsBoard');
    if (!board || typeof Chart === 'undefined') return;

    lastAccountStats = stats;
    Object.values(accountChartInstances).forEach(c => c.destroy());
    for (const k in accountChartInstances) delete accountChartInstances[k];
    board.innerHTML = '';

    const isDark = document.querySelector('.fw-crm').getAttribute('data-theme') === 'dark';
    const monthLabels = (stats.months || []).map(m => {
      const [y, mo] = m.split('-');
      return new Date(y, mo - 1, 1).toLocaleDateString(undefined, { month: 'short' });
    });

    const stageLabels = { prospect: 'Prospect', qualification: 'Qualification', proposal: 'Proposal',
      negotiation: 'Negotiation', won: 'Won', lost: 'Lost', converted: 'Converted' };
    const stageColors = { prospect: '#64748b', qualification: '#06b6d4', proposal: '#8b5cf6',
      negotiation: '#f59e0b', won: '#10b981', lost: '#ef4444', converted: '#0ea5e9' };
    const opps = stats.opps || [];

    const defs = [
      {
        id: 'chartAccountInteractions',
        title: 'Interactions (12 months)',
        type: 'line',
        empty: (stats.interactions || []).every(v => v === 0),
        data: {
          labels: monthLabels,
          datasets: [{
            label: 'Interactions',
            data: stats.interactions || [],
            borderColor: '#10b981',
            backgroundColor: 'rgba(16,185,129,.2)',
            borderWidth: 3,
            tension: .4,
            fill: true
          }]
        }
      },
      {
        id: 'chartAccountValue',
        title: 'Quotes vs Invoices (R, 12 months)',
        type: 'bar',
        legend: true,
        empty: (stats.quote_totals || []).every(v => v === 0) && (stats.invoice_totals || []).every(v => v === 0),
        data: {
          labels: monthLabels,
          datasets: [
            { label: 'Quotes', data: stats.quote_totals || [], backgroundColor: '#8b5cf6', borderRadius: 6 },
            { label: 'Invoices', data: stats.invoice_totals || [], backgroundColor: '#06b6d4', borderRadius: 6 }
          ]
        }
      },
      {
        id: 'chartAccountPipeline',
        title: 'Opportunities by Stage',
        type: 'doughnut',
        empty: opps.length === 0,
        data: {
          labels: opps.map(o => (stageLabels[o.stage] || o.stage) + ' (' + o.count + ')'),
          datasets: [{
            data: opps.map(o => o.count),
            backgroundColor: opps.map(o => stageColors[o.stage] || '#64748b')
          }]
        }
      },
      {
        id: 'chartAccountCompliance',
        title: 'Compliance Documents',
        type: 'doughnut',
        empty: !stats.compliance || (stats.compliance.valid + stats.compliance.expiring + stats.compliance.expired) === 0,
        data: {
          labels: ['Valid', 'Expiring (30d)', 'Expired'],
          datasets: [{
            data: stats.compliance ? [stats.compliance.valid, stats.compliance.expiring, stats.compliance.expired] : [0, 0, 0],
            backgroundColor: ['#10b981', '#f59e0b', '#ef4444']
          }]
        }
      }
    ];

    defs.forEach(def => {
      const card = document.createElement('div');
      card.className = 'crm-playground-chart-card';
      const header = document.createElement('div');
      header.className = 'crm-playground-chart-header';
      header.innerHTML = '<div class="crm-playground-chart-title">' + def.title + '</div>';
      const body = document.createElement('div');
      body.className = 'crm-playground-chart-body';
      card.appendChild(header);
      card.appendChild(body);
      board.appendChild(card);

      if (def.empty) {
        body.innerHTML = '<div class="fw-crm__empty-state" style="padding:24px 8px;">No data yet</div>';
        return;
      }

      const canvas = document.createElement('canvas');
      canvas.id = def.id;
      body.appendChild(canvas);

      const options = chartOptions(def.type, isDark);
      if (def.legend) options.plugins.legend.display = true;
      accountChartInstances[def.id] = new Chart(canvas, {
        type: def.type,
        data: def.data,
        options: options
      });
    });
  }

  function loadAccountStats() {
    const board = document.getElementById('accountChartsBoard');
    if (!board) return;
    board.innerHTML = '<div class="fw-crm__loading">Loading analytics...</div>';
    fetch('/crm/ajax/account_stats.php?account_id=' + accountId)
      .then(res => res.json())
      .then(data => {
        if (data && data.ok) {
          buildAccountCharts(data);
        } else {
          board.innerHTML = '<div class="fw-crm__empty-state">' +
            escapeHtml((data && data.error) || 'Failed to load analytics') + '</div>';
        }
      })
      .catch(() => {
        board.innerHTML = '<div class="fw-crm__empty-state">Network error loading analytics</div>';
      });
  }

  const refreshBtn = document.getElementById('accountRefresh');
  if (refreshBtn) {
    refreshBtn.addEventListener('click', loadAccountStats);
  }

  // Rebuild with the new palette when the header theme toggle fires
  document.addEventListener('crm:theme', function() {
    if (lastAccountStats) buildAccountCharts(lastAccountStats);
  });

  if (document.getElementById('accountChartsBoard')) {
    loadAccountStats();
  }

  // ========== FOLLOW-UPS ==========
  function loadFollowups() {
    const list = document.getElementById('followupsList');
    if (!list) return;
    fetch('/crm/ajax/followups_list.php?linked_type=account&linked_id=' + accountId)
      .then(res => res.json())
      .then(data => {
        if (!data.ok) {
          list.innerHTML = '<div class="fw-crm__empty-state">' + escapeHtml(data.error || 'Failed to load follow-ups') + '</div>';
          return;
        }
        if (!data.events.length) {
          list.innerHTML = '<div class="fw-crm__empty-state">No upcoming follow-ups — schedule one with the ⏰ button above</div>';
          return;
        }
        list.innerHTML = data.events.map(ev => `
          <div class="fw-crm__timeline-item">
            <div class="fw-crm__timeline-icon">⏰</div>
            <div class="fw-crm__timeline-content">
              <div class="fw-crm__timeline-title">${escapeHtml(ev.title)}</div>
              <div class="fw-crm__timeline-meta">
                ${escapeHtml((ev.start_datetime || '').substring(0, 16))}
                ${ev.channels && ev.channels.length ? ' • reminder: ' + escapeHtml(ev.channels.join(', ')) : ''}
              </div>
            </div>
          </div>`).join('');
      })
      .catch(() => {
        list.innerHTML = '<div class="fw-crm__empty-state">Network error loading follow-ups</div>';
      });
  }
  if (document.getElementById('followupsList')) loadFollowups();

  const followupForm = document.getElementById('followupForm');
  if (followupForm) {
    followupForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const submitBtn = document.querySelector('button[form="followupForm"]');
      if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Creating...'; }
      try {
        const res = await fetch('/crm/ajax/followup_create.php', { method: 'POST', body: new FormData(followupForm) });
        const data = await res.json();
        if (data.ok) {
          CRM.closeModal('followupModal');
          toast('Follow-up scheduled');
          loadFollowups();
        } else {
          toast(data.error || 'Failed to create follow-up', 'error');
        }
      } catch (err) {
        toast('Network error', 'error');
      } finally {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Create Follow-up'; }
      }
    });
  }

  // ========== SEND EMAIL ==========
  const emailForm = document.getElementById('emailForm');
  if (emailForm) {
    // Load templates once, on first open of the modal
    let templatesLoaded = false;
    const templateSelect = document.getElementById('emailTemplateSelect');
    const templateCache = {};

    const emailModal = document.getElementById('emailModal');
    const observer = new MutationObserver(() => {
      if (emailModal.getAttribute('aria-hidden') === 'false' && !templatesLoaded) {
        templatesLoaded = true;
        fetch('/crm/ajax/email_templates.php')
          .then(res => res.json())
          .then(data => {
            if (data.ok && Array.isArray(data.templates)) {
              data.templates.forEach(t => {
                templateCache[t.template_id] = t;
                const opt = document.createElement('option');
                opt.value = t.template_id;
                opt.textContent = t.name;
                templateSelect.appendChild(opt);
              });
            }
          })
          .catch(() => { /* templates are optional */ });
      }
    });
    observer.observe(emailModal, { attributes: true, attributeFilter: ['aria-hidden'] });

    if (templateSelect) {
      templateSelect.addEventListener('change', function() {
        const t = templateCache[this.value];
        if (!t) return;
        if (t.subject) emailForm.subject.value = t.subject;
        if (t.body_html) emailForm.body.value = t.body_html.replace(/<br\s*\/?>(\n)?/gi, '\n').replace(/<[^>]+>/g, '');
      });
    }

    emailForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const submitBtn = document.querySelector('button[form="emailForm"]');
      if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Sending...'; }
      try {
        const res = await fetch('/crm/ajax/email.compose.php', { method: 'POST', body: new FormData(emailForm) });
        const data = await res.json();
        if (data.ok) {
          CRM.closeModal('emailModal');
          toast('Email sent');
          // The sent mail shows on the account timeline — refresh it next open
          if (CRM.reloadTimeline) CRM.reloadTimeline();
        } else {
          toast(data.error || 'Failed to send email', 'error');
        }
      } catch (err) {
        toast('Network error', 'error');
      } finally {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Send Email'; }
      }
    });
  }

  // ========== COPY PORTAL LINK ==========
  const portalBtn = document.getElementById('copyPortalLink');
  if (portalBtn) {
    portalBtn.addEventListener('click', () => {
      const url = window.location.origin + portalBtn.dataset.portalUrl;
      (navigator.clipboard && navigator.clipboard.writeText
        ? navigator.clipboard.writeText(url)
        : Promise.reject())
        .then(() => toast('Portal link copied to clipboard'))
        .catch(() => {
          window.prompt('Copy the portal link:', url);
        });
    });
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