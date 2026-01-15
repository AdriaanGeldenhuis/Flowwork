// /finances/assets/vat.js
// VAT Returns Module

(function() {
  'use strict';

  let currentPeriod = null;

  // DOM Elements
  const tabButtons = document.querySelectorAll('.fw-finance__tab');
  const createPeriodBtn = document.getElementById('createPeriodBtn');
  const periodForm = document.getElementById('periodForm');
  const vat201Form = document.getElementById('vat201Form');
  const currentVatPosition = document.getElementById('currentVatPosition');

  // Load Current VAT Position
  async function loadCurrentPosition() {
    if (!currentVatPosition) return;

    currentVatPosition.innerHTML = '<div class="fw-finance__loading">Loading...</div>';

    const result = await FinanceAPI.request('/finances/ajax/vat_current_position.php');

    if (result.ok) {
      const data = result.data;
      currentVatPosition.innerHTML = `
        <div class="fw-finance__vat-summary-grid">
          <div class="fw-finance__vat-summary-card">
            <div class="fw-finance__vat-summary-value">${formatCurrency(data.output_vat_cents)}</div>
            <div class="fw-finance__vat-summary-label">Output VAT (Sales)</div>
            <div class="fw-finance__vat-summary-detail">${data.output_count} transactions</div>
          </div>
          <div class="fw-finance__vat-summary-card">
            <div class="fw-finance__vat-summary-value">${formatCurrency(data.input_vat_cents)}</div>
            <div class="fw-finance__vat-summary-label">Input VAT (Purchases)</div>
            <div class="fw-finance__vat-summary-detail">${data.input_count} transactions</div>
          </div>
          <div class="fw-finance__vat-summary-card fw-finance__vat-summary-card--highlight">
            <div class="fw-finance__vat-summary-value">${formatCurrency(data.net_vat_cents)}</div>
            <div class="fw-finance__vat-summary-label">Net VAT ${data.net_vat_cents >= 0 ? 'Payable' : 'Refundable'}</div>
            <div class="fw-finance__vat-summary-detail">Current unfiled position</div>
          </div>
        </div>
        <div style="margin-top: 2rem; padding: 1rem; background: var(--fw-highlight); border-radius: 8px;">
          <strong>Period Coverage:</strong> ${formatDate(data.earliest_date)} to ${formatDate(data.latest_date)}
        </div>
      `;
    } else {
      currentVatPosition.innerHTML = '<div class="fw-finance__empty-state">Unable to load VAT position</div>';
    }
  }

  // Prepare Period
  async function preparePeriod(periodId) {
    vat201Form.innerHTML = '<div class="fw-finance__loading">Preparing VAT201...</div>';

    // Switch to prepare tab
    document.querySelector('[data-tab="prepare"]').click();

    const result = await FinanceAPI.request('/finances/ajax/vat_prepare.php', 'POST', { period_id: periodId });

    if (result.ok) {
      currentPeriod = result.data;
      renderVAT201Form(result.data);
    } else {
      vat201Form.innerHTML = `<div class="fw-finance__alert fw-finance__alert--danger">Error: ${result.error || 'Failed to prepare return'}</div>`;
    }
  }

  // Render VAT201 Form
  function renderVAT201Form(data) {
    const html = `
      <div class="fw-finance__vat201-container">
        <div class="fw-finance__vat201-header">
          <h2>VAT201 Return</h2>
          <div class="fw-finance__vat201-period">
            Period: ${formatDate(data.period_start)} to ${formatDate(data.period_end)}
          </div>
        </div>

        <div class="fw-finance__vat201-section">
          <h3>Output Tax (Sales)</h3>
          <table class="fw-finance__vat201-table">
            <tr>
              <td>1. Standard rated supplies (15%)</td>
              <td class="fw-finance__vat201-amount">${formatCurrency(data.output_standard_base_cents)}</td>
              <td class="fw-finance__vat201-amount">${formatCurrency(data.output_standard_vat_cents)}</td>
            </tr>
            <tr>
              <td>2. Zero rated supplies (0%)</td>
              <td class="fw-finance__vat201-amount">${formatCurrency(data.output_zero_base_cents)}</td>
              <td class="fw-finance__vat201-amount">R 0.00</td>
            </tr>
            <tr>
              <td>3. Exempt supplies</td>
              <td class="fw-finance__vat201-amount">${formatCurrency(data.output_exempt_base_cents)}</td>
              <td class="fw-finance__vat201-amount">R 0.00</td>
            </tr>
            <tr class="fw-finance__vat201-total">
              <td><strong>Total Output Tax</strong></td>
              <td></td>
              <td class="fw-finance__vat201-amount"><strong>${formatCurrency(data.total_output_vat_cents)}</strong></td>
            </tr>
          </table>
        </div>

        <div class="fw-finance__vat201-section">
          <h3>Input Tax (Purchases)</h3>
          <table class="fw-finance__vat201-table">
            <tr>
              <td>4. Capital goods (fixed assets)</td>
              <td class="fw-finance__vat201-amount">${formatCurrency(data.input_capital_cents)}</td>
            </tr>
            <tr>
              <td>5. Other goods and services</td>
              <td class="fw-finance__vat201-amount">${formatCurrency(data.input_other_cents)}</td>
            </tr>
            <tr class="fw-finance__vat201-total">
              <td><strong>Total Input Tax</strong></td>
              <td class="fw-finance__vat201-amount"><strong>${formatCurrency(data.total_input_vat_cents)}</strong></td>
            </tr>
          </table>
        </div>

        <div class="fw-finance__vat201-section">
          <h3>Net VAT</h3>
          <table class="fw-finance__vat201-table">
            <tr class="fw-finance__vat201-net">
              <td><strong>${data.net_vat_cents >= 0 ? 'VAT Payable to SARS' : 'VAT Refundable from SARS'}</strong></td>
              <td class="fw-finance__vat201-amount fw-finance__vat201-amount--net">
                <strong>${formatCurrency(Math.abs(data.net_vat_cents))}</strong>
              </td>
            </tr>
          </table>
        </div>

        <div class="fw-finance__vat201-actions">
          ${data.status === 'open' ? `
            <button class="fw-finance__btn fw-finance__btn--primary" id="saveVAT201Btn">
              Save & Lock Period
            </button>
          ` : ''}
          <button class="fw-finance__btn fw-finance__btn--secondary" id="addVATAdjustmentBtn">
            Add Adjustment
          </button>
          ${data.status !== 'open' ? `
            <button class="fw-finance__btn fw-finance__btn--secondary" id="exportVAT201Btn">
              Export to CSV
            </button>
            <button class="fw-finance__btn fw-finance__btn--secondary" id="printVAT201Btn">
              Print VAT201
            </button>
          ` : ''}
        </div>

        <div id="vat201Message"></div>
      </div>
    `;

    vat201Form.innerHTML = html;

    // Attach events
    const saveBtn = document.getElementById('saveVAT201Btn');
    if (saveBtn) {
      saveBtn.addEventListener('click', () => saveVAT201(data.period_id));
    }

    const exportBtn = document.getElementById('exportVAT201Btn');
    if (exportBtn) {
      exportBtn.addEventListener('click', () => exportVAT201(data));
    }

    const printBtn = document.getElementById('printVAT201Btn');
    if (printBtn) {
      printBtn.addEventListener('click', () => window.print());
    }

    // Attach Add Adjustment button
    const addAdjBtn = document.getElementById('addVATAdjustmentBtn');
    if (addAdjBtn) {
      addAdjBtn.addEventListener('click', () => {
        openVatAdjustModal(data.period_id);
      });
    }
  }

  // Save VAT201
  async function saveVAT201(periodId) {
    if (!confirm('Lock this VAT period and mark return as prepared?\n\nYou will not be able to post journals to this period after locking.')) {
      return;
    }

    const result = await FinanceAPI.request('/finances/ajax/vat_save.php', 'POST', { period_id: periodId });

    if (result.ok) {
      showMessage('vat201Message', 'VAT return saved and period locked successfully', 'success');
      setTimeout(() => {
        location.reload();
      }, 1500);
    } else {
      showMessage('vat201Message', result.error || 'Failed to save return', 'error');
    }
  }

  // Export VAT201
  function exportVAT201(data) {
    let csv = 'VAT201 Return\n';
    csv += `Period:,${formatDate(data.period_start)},to,${formatDate(data.period_end)}\n\n`;
    csv += 'OUTPUT TAX\n';
    csv += `Standard rated supplies (15%),${data.output_standard_base_cents/100},${data.output_standard_vat_cents/100}\n`;
    csv += `Zero rated supplies (0%),${data.output_zero_base_cents/100},0.00\n`;
    csv += `Exempt supplies,${data.output_exempt_base_cents/100},0.00\n`;
    csv += `Total Output Tax,,${data.total_output_vat_cents/100}\n\n`;
    csv += 'INPUT TAX\n';
    csv += `Capital goods,${data.input_capital_cents/100}\n`;
    csv += `Other goods and services,${data.input_other_cents/100}\n`;
    csv += `Total Input Tax,,${data.total_input_vat_cents/100}\n\n`;
    csv += `NET VAT ${data.net_vat_cents >= 0 ? 'PAYABLE' : 'REFUNDABLE'},,${Math.abs(data.net_vat_cents)/100}\n`;

    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `vat201-${data.period_start}-${data.period_end}.csv`;
    a.click();
    window.URL.revokeObjectURL(url);
  }

  // View VAT201
  async function viewVAT201(periodId) {
    const result = await FinanceAPI.request(`/finances/ajax/vat_get.php?period_id=${periodId}`);

    if (result.ok) {
      document.querySelector('[data-tab="prepare"]').click();
      renderVAT201Form(result.data);
    } else {
      alert('Error: ' + (result.error || 'Failed to load return'));
    }
  }

  // File Period
  async function filePeriod(periodId) {
    if (!confirm('Mark this VAT period as FILED with SARS?\n\nThis action cannot be undone.')) {
      return;
    }

    const result = await FinanceAPI.request('/finances/ajax/vat_file.php', 'POST', { period_id: periodId });

    if (result.ok) {
      alert('VAT period marked as filed successfully');
      location.reload();
    } else {
      alert('Error: ' + (result.error || 'Failed to file period'));
    }
  }

  // Create Period
  async function createPeriod(e) {
    e.preventDefault();

    const data = {
      period_start: document.getElementById('periodStart').value,
      period_end: document.getElementById('periodEnd').value
    };

    if (!data.period_start || !data.period_end) {
      showMessage('periodMessage', 'Please enter both dates', 'error');
      return;
    }

    const result = await FinanceAPI.request('/finances/ajax/vat_period_create.php', 'POST', data);

    if (result.ok) {
      showMessage('periodMessage', 'VAT period created successfully', 'success');
      setTimeout(() => {
        FinanceModal.close('periodModal');
        location.reload();
      }, 1000);
    } else {
      showMessage('periodMessage', result.error || 'Failed to create period', 'error');
    }
  }

  // Attach events to period cards
  function attachPeriodEvents() {
    document.querySelectorAll('.prepare-period').forEach(btn => {
      btn.addEventListener('click', () => {
        const periodId = btn.dataset.id;
        preparePeriod(periodId);
      });
    });

    document.querySelectorAll('.view-vat201').forEach(btn => {
      btn.addEventListener('click', () => {
        const periodId = btn.dataset.id;
        viewVAT201(periodId);
      });
    });

    document.querySelectorAll('.file-period').forEach(btn => {
      btn.addEventListener('click', () => {
        const periodId = btn.dataset.id;
        filePeriod(periodId);
      });
    });
  }

  // Tab Switching
  tabButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      const tab = btn.dataset.tab;

      tabButtons.forEach(b => b.classList.remove('fw-finance__tab--active'));
      btn.classList.add('fw-finance__tab--active');

      document.querySelectorAll('.fw-finance__tab-panel').forEach(panel => {
        panel.classList.remove('fw-finance__tab-panel--active');
      });

      document.getElementById(tab + 'Panel').classList.add('fw-finance__tab-panel--active');

      if (tab === 'current') {
        loadCurrentPosition();
      }
    });
  });

  // Event Listeners
  if (createPeriodBtn) {
    createPeriodBtn.addEventListener('click', () => {
      periodForm.reset();
      FinanceModal.open('periodModal');
    });
  }

  if (periodForm) {
    periodForm.addEventListener('submit', createPeriod);
  }

  if (document.getElementById('modalClose')) {
    document.getElementById('modalClose').addEventListener('click', () => FinanceModal.close('periodModal'));
  }

  if (document.getElementById('cancelBtn')) {
    document.getElementById('cancelBtn').addEventListener('click', () => FinanceModal.close('periodModal'));
  }

  /**
   * VAT Adjustment Modal Helpers
   * Opens the VAT Adjustment modal for a specific period, resets the form and
   * populates the hidden period id. Exposed on window for use in renderVAT201Form.
   * @param {number|string} periodId
   */
  function openVatAdjustModal(periodId) {
    const adjustForm = document.getElementById('vatAdjustForm');
    if (adjustForm) {
      // reset any previous values on the form
      adjustForm.reset();
    }
    const adjustIdInput = document.getElementById('adjustPeriodId');
    if (adjustIdInput) {
      adjustIdInput.value = periodId;
    }
    // Clear any previous messages
    const msg = document.getElementById('adjustMessage');
    if (msg) {
      msg.innerHTML = '';
    }
    // Open the adjustment modal
    FinanceModal.open('vatAdjustModal');
  }

  // expose function globally so renderVAT201Form can call it
  window.openVatAdjustModal = openVatAdjustModal;

  /**
   * Handle submission of the VAT Adjustment form. Sends a request to the server
   * and displays feedback. If successful, closes the modal and refreshes the VAT201 form.
   * @param {Event} e
   */
  async function saveVatAdjustment(e) {
    e.preventDefault();
    const periodId = document.getElementById('adjustPeriodId')?.value;
    const accountType = document.getElementById('adjustAccount')?.value;
    const amountValue = document.getElementById('adjustAmount')?.value;
    const note = document.getElementById('adjustNote')?.value?.trim() || '';
    const amount = parseFloat(amountValue);
    if (!periodId || !accountType || isNaN(amount) || amount === 0) {
      showMessage('adjustMessage', 'Please enter a non-zero amount for the adjustment', 'error');
      return;
    }
    const payload = {
      period_id: parseInt(periodId, 10),
      lines: [
        {
          account: accountType,
          amount: amount,
          memo: note
        }
      ]
    };
    const result = await FinanceAPI.request('/finances/ajax/vat_adjust_post.php', 'POST', payload);
    if (result && result.ok) {
      showMessage('adjustMessage', 'Adjustment saved successfully', 'success');
      // After a short delay, close modal and reload the VAT201 form
      setTimeout(() => {
        FinanceModal.close('vatAdjustModal');
        // If currentPeriod is the period just adjusted, reload its VAT201; otherwise reload page
        if (currentPeriod && currentPeriod.id && parseInt(currentPeriod.id, 10) === parseInt(periodId, 10)) {
          viewVAT201(periodId);
        } else {
          location.reload();
        }
      }, 1200);
    } else {
      const errMsg = result && result.error ? result.error : 'Failed to save adjustment';
      showMessage('adjustMessage', errMsg, 'error');
    }
  }
  // Initialize
  document.addEventListener('DOMContentLoaded', () => {
    attachPeriodEvents();
    // VAT Adjustment modal event listeners (close/cancel and submit)
    const vatAdjustClose = document.getElementById('vatAdjustClose');
    if (vatAdjustClose) {
      vatAdjustClose.addEventListener('click', () => FinanceModal.close('vatAdjustModal'));
    }
    const adjustCancelBtn = document.getElementById('adjustCancelBtn');
    if (adjustCancelBtn) {
      adjustCancelBtn.addEventListener('click', () => FinanceModal.close('vatAdjustModal'));
    }
    const vatAdjustForm = document.getElementById('vatAdjustForm');
    if (vatAdjustForm) {
      vatAdjustForm.addEventListener('submit', saveVatAdjustment);
    }
  });

})();