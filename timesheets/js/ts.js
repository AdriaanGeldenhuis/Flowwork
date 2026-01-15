// /timesheets/js/ts.js
// Timesheets module JavaScript for capturing and approving employee timesheets.
(function() {
  'use strict';

  /**
   * Display a toast notification.
   * @param {string} message
   * @param {string} type 'success', 'error', 'info'
   */
  function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = 'fw-toast fw-toast--' + type;
    toast.textContent = message;
    toast.style.cssText = `
      position: fixed;
      top: 20px;
      right: 20px;
      padding: 12px 20px;
      background: ${type === 'error' ? '#ef4444' : type === 'success' ? '#10b981' : '#192f59'};
      color: white;
      border-radius: 8px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      z-index: 10000;
      animation: fadeIn 0.3s ease;
    `;
    document.body.appendChild(toast);
    setTimeout(() => {
      toast.style.animation = 'fadeOut 0.3s ease';
      setTimeout(() => toast.remove(), 300);
    }, 3000);
  }

  /**
   * Simple fetch wrapper for POSTing JSON to an endpoint and parsing JSON response.
   * @param {string} endpoint
   * @param {Object} data
   * @returns {Promise<Object>}
   */
  async function apiCall(endpoint, data = {}) {
    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
      });
      return await response.json();
    } catch (err) {
      console.error('API call error:', err);
      return { ok: false, error: 'Network error' };
    }
  }

  window.TimesheetPage = {
    /**
     * Initialize the “My Timesheets” page.
     */
    initMy: function() {
      const projectSelect = document.getElementById('projectId');
      const boardSelect = document.getElementById('boardItemId');
      const tsForm = document.getElementById('tsForm');

      if (projectSelect && boardSelect) {
        projectSelect.addEventListener('change', async function() {
          const projectId = this.value;
          // Reset board select
          boardSelect.innerHTML = '<option value="">None</option>';
          boardSelect.disabled = true;
          if (projectId) {
            const result = await apiCall('/timesheets/ajax/board_items.php', { project_id: projectId });
            if (result.ok) {
              result.items.forEach(function(item) {
                const opt = document.createElement('option');
                opt.value = item.id;
                opt.textContent = item.name;
                boardSelect.appendChild(opt);
              });
              boardSelect.disabled = false;
            } else {
              showToast(result.error || 'Failed to load tasks', 'error');
            }
          }
        });
      }

      if (tsForm) {
        tsForm.addEventListener('submit', async function(e) {
          e.preventDefault();
          // Gather values
          const dateVal = document.getElementById('tsDate').value;
          const regHrs = parseFloat(document.getElementById('regularHours').value || '0');
          const otHrs  = parseFloat(document.getElementById('otHours').value || '0');
          const sundayHrs = parseFloat(document.getElementById('sundayHours').value || '0');
          const phHrs = parseFloat(document.getElementById('publicHours').value || '0');
          const projId = projectSelect ? projectSelect.value || null : null;
          const itemId = boardSelect ? boardSelect.value || null : null;

          if (!dateVal) {
            showToast('Please select a date', 'error');
            return;
          }
          // At least one hour must be > 0
          if (regHrs <= 0 && otHrs <= 0 && sundayHrs <= 0 && phHrs <= 0) {
            showToast('Enter hours in at least one category', 'error');
            return;
          }

          const payload = {
            ts_date: dateVal,
            regular_hours: regHrs,
            ot_hours: otHrs,
            sunday_hours: sundayHrs,
            public_holiday_hours: phHrs,
            project_id: projId ? parseInt(projId) : null,
            item_id: itemId ? parseInt(itemId) : null
          };

          const submitBtn = tsForm.querySelector('button[type="submit"]');
          const original = submitBtn.innerHTML;
          submitBtn.disabled = true;
          submitBtn.innerHTML = '<span class="fw-spinner"></span> Saving...';
          const result = await apiCall('/timesheets/ajax/ts.save.php', payload);
          submitBtn.disabled = false;
          submitBtn.innerHTML = original;

          if (result.ok) {
            showToast('Timesheet saved!', 'success');
            // Reset form fields
            // Optionally reload entries
            tsForm.reset();
            if (boardSelect) {
              boardSelect.innerHTML = '<option value="">None</option>';
              boardSelect.disabled = true;
            }
            // Reload page to show latest entries
            setTimeout(() => window.location.reload(), 800);
          } else {
            showToast(result.error || 'Failed to save', 'error');
          }
        });
      }

      // Approvals page not needed on My page
    },

    /**
     * Initialize the approvals index page.
     */
    initIndex: function() {
      const approveBtn = document.getElementById('approveSelected');
      if (!approveBtn) return;
      approveBtn.addEventListener('click', async function() {
        // Gather selected entry IDs
        const checkboxes = document.querySelectorAll('.ts-approve-checkbox:checked');
        if (checkboxes.length === 0) {
          showToast('No entries selected', 'error');
          return;
        }
        const ids = Array.from(checkboxes).map(cb => parseInt(cb.value));
        approveBtn.disabled = true;
        const original = approveBtn.innerHTML;
        approveBtn.innerHTML = '<span class="fw-spinner"></span> Approving...';
        const result = await apiCall('/timesheets/ajax/ts.approve.php', { entry_ids: ids });
        approveBtn.disabled = false;
        approveBtn.innerHTML = original;
        if (result.ok) {
          showToast('Approved ' + ids.length + ' entries', 'success');
          // Remove rows or reload
          ids.forEach(id => {
            const row = document.querySelector('tr[data-entry-id="' + id + '"]');
            if (row) row.remove();
          });
        } else {
          showToast(result.error || 'Approval failed', 'error');
        }
      });
    }
  };
})();