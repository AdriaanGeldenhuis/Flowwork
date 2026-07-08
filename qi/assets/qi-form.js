// /qi/assets/qi-form.js
window.QI = window.QI || {};

(function() {
  'use strict';

  let lineItemCounter = 0;

  // Symbol of the document's currency (falls back to ZAR's "R")
  function curSym() {
    return (window.QICurrency) ? QICurrency.symbol() : 'R';
  }

  // ========== LINE ITEMS ==========
  QI.addLineItem = function() {
    lineItemCounter++;
    const container = document.getElementById('lineItemsContainer');
    
    const lineDiv = document.createElement('div');
    lineDiv.className = 'fw-qi__line-item';
    lineDiv.dataset.lineId = lineItemCounter;
    
    // Build options for the inventory item selector if available
    let itemOptionsHtml = '';
    if (window.FW_INV_ITEMS && Array.isArray(window.FW_INV_ITEMS)) {
      window.FW_INV_ITEMS.forEach(function(it) {
        // Use SKU and name for display if both exist; fallback to name only
        const label = (it.sku && it.sku.trim() !== '') ? (it.sku + ' - ' + it.name) : it.name;
        itemOptionsHtml += '<option value="' + it.id + '">' + label + '</option>';
      });
    }

    lineDiv.innerHTML = `
      <div class="fw-qi__line-item-header">
        <span class="fw-qi__line-item-number">#${lineItemCounter}</span>
        <button type="button" class="fw-qi__btn fw-qi__btn--small fw-qi__btn--danger" onclick="QI.removeLineItem(${lineItemCounter})">
          Remove
        </button>
      </div>
      
      <div class="fw-qi__form-row">
        <div class="fw-qi__form-group">
          <label class="fw-qi__label">Item</label>
          <select name="lines[${lineItemCounter}][inventory_item_id]" class="fw-qi__input line-item-select">
            <option value="">Select item…</option>
            ${itemOptionsHtml}
          </select>
        </div>
        <div class="fw-qi__form-group" style="grid-column: span 2;">
          <label class="fw-qi__label">Description</label>
          <input type="text" name="lines[${lineItemCounter}][description]" class="fw-qi__input" placeholder="Item description" required>
        </div>
      </div>

      <div class="fw-qi__form-row">
        <div class="fw-qi__form-group">
          <label class="fw-qi__label">Quantity</label>
          <input type="number" name="lines[${lineItemCounter}][quantity]" class="fw-qi__input line-quantity" step="0.001" value="1.000" min="0" required>
        </div>

        <div class="fw-qi__form-group">
          <label class="fw-qi__label">Unit</label>
          <input type="text" name="lines[${lineItemCounter}][unit]" class="fw-qi__input" placeholder="e.g. m², kg, hrs" value="unit">
        </div>

        <div class="fw-qi__form-group">
          <label class="fw-qi__label">Unit Price (<span class="qi-currency-symbol">${curSym()}</span>)</label>
          <input type="number" name="lines[${lineItemCounter}][unit_price]" class="fw-qi__input line-price" step="0.01" value="0.00" min="0" required>
        </div>

        <div class="fw-qi__form-group">
          <label class="fw-qi__label">Discount (<span class="qi-currency-symbol">${curSym()}</span>)</label>
          <input type="number" name="lines[${lineItemCounter}][discount]" class="fw-qi__input line-discount" step="0.01" value="0.00" min="0">
        </div>

        <div class="fw-qi__form-group">
          <label class="fw-qi__label">VAT</label>
          <select name="lines[${lineItemCounter}][tax_rate]" class="fw-qi__input line-tax-rate">
            <option value="15.00" data-tax-code="STD">VAT (15%)</option>
            <option value="0.00" data-tax-code="ZERO">No VAT (Zero-rated)</option>
            <option value="0.00" data-tax-code="EXEMPT">No VAT (Exempt)</option>
          </select>
          <input type="hidden" name="lines[${lineItemCounter}][tax_code]" class="line-tax-code" value="STD">
        </div>

        <div class="fw-qi__form-group">
          <label class="fw-qi__label">Line Total</label>
          <div class="fw-qi__line-total" data-line-id="${lineItemCounter}">${curSym()} 0.00</div>
        </div>
      </div>
    `;

    container.appendChild(lineDiv);

    // Attach change listeners
    lineDiv.querySelectorAll('.line-quantity, .line-price, .line-discount, .line-tax-rate').forEach(input => {
      input.addEventListener('input', QI.calculateTotals);
    });
    // Keep the hidden tax_code input in sync for classic form POST flows
    const taxSelect = lineDiv.querySelector('.line-tax-rate');
    taxSelect.addEventListener('change', function () {
      const hidden = lineDiv.querySelector('.line-tax-code');
      if (hidden) hidden.value = taxSelect.selectedOptions[0]?.dataset?.taxCode || 'STD';
    });

    QI.calculateTotals();
  };

  QI.removeLineItem = function(lineId) {
    const lineDiv = document.querySelector(`[data-line-id="${lineId}"]`);
    if (lineDiv) {
      lineDiv.remove();
      QI.calculateTotals();
    }
  };

  // ========== CALCULATIONS ==========
  QI.calculateTotals = function() {
    let subtotal = 0;
    let totalDiscount = 0;
    let totalTax = 0;

    document.querySelectorAll('.fw-qi__line-item').forEach(lineDiv => {
      const lineId = lineDiv.dataset.lineId;
      const quantity = parseFloat(lineDiv.querySelector('.line-quantity').value) || 0;
      const unitPrice = parseFloat(lineDiv.querySelector('.line-price').value) || 0;
      const discount = parseFloat(lineDiv.querySelector('.line-discount').value) || 0;
      const taxRate = parseFloat(lineDiv.querySelector('.line-tax-rate').value) || 0;

      const lineSubtotal = quantity * unitPrice;
      const lineNet = lineSubtotal - discount;
      const lineTax = lineNet * (taxRate / 100);
      const lineTotal = lineNet + lineTax;

      // Update line total display
      const lineTotalDisplay = document.querySelector(`.fw-qi__line-total[data-line-id="${lineId}"]`);
      if (lineTotalDisplay) {
        lineTotalDisplay.textContent = curSym() + ' ' + lineTotal.toFixed(2);
      }

      subtotal += lineSubtotal;
      totalDiscount += discount;
      totalTax += lineTax;
    });

    const grandTotal = subtotal - totalDiscount + totalTax;

    // Update displays (displayDiscount is absent on the credit note form)
    const setDisplay = (id, val) => {
      const el = document.getElementById(id);
      if (el) el.textContent = curSym() + ' ' + val.toFixed(2);
    };
    setDisplay('displaySubtotal', subtotal);
    setDisplay('displayDiscount', totalDiscount);
    setDisplay('displayTax', totalTax);
    setDisplay('displayTotal', grandTotal);

    // Update hidden inputs (inputDiscount is absent on the credit note form)
    const setInput = (id, val) => {
      const el = document.getElementById(id);
      if (el) el.value = val.toFixed(2);
    };
    setInput('inputSubtotal', subtotal);
    setInput('inputDiscount', totalDiscount);
    setInput('inputTax', totalTax);
    setInput('inputTotal', grandTotal);

    // Recalculate milestone amounts when total changes
    if (document.getElementById('enableMilestones')?.checked) {
      QI.calculateMilestones();
    }
  };

  // ========== MILESTONES ==========
  let milestoneCounter = 0;

  QI.toggleMilestones = function() {
    const enabled = document.getElementById('enableMilestones')?.checked;
    const section = document.getElementById('milestonesSection');
    if (!section) return;

    section.style.display = enabled ? 'block' : 'none';

    // Add default milestones when first enabled
    if (enabled && document.querySelectorAll('.fw-qi__milestone-row').length === 0) {
      QI.addMilestone('Deposit', 50);
      QI.addMilestone('Final Payment', 50);
    }
  };

  QI.addMilestone = function(defaultLabel, defaultPct, defaultAmount) {
    milestoneCounter++;
    const container = document.getElementById('milestonesContainer');
    if (!container) return;

    const row = document.createElement('div');
    row.className = 'fw-qi__milestone-row';
    row.dataset.milestoneId = milestoneCounter;

    row.innerHTML = `
      <div class="fw-qi__milestone-fields">
        <div class="fw-qi__form-group">
          <label class="fw-qi__label">Phase Name</label>
          <input type="text" class="fw-qi__input ms-label" value="${defaultLabel || ''}" placeholder="e.g. Deposit, Progress, Final" required>
        </div>
        <div class="fw-qi__form-group">
          <label class="fw-qi__label">Type</label>
          <select class="fw-qi__input ms-type" onchange="QI.onMilestoneTypeChange(${milestoneCounter})">
            <option value="percentage" ${!defaultAmount ? 'selected' : ''}>Percentage (%)</option>
            <option value="fixed" ${defaultAmount ? 'selected' : ''}>Fixed Amount (${curSym()})</option>
          </select>
        </div>
        <div class="fw-qi__form-group ms-pct-group" ${defaultAmount ? 'style="display:none;"' : ''}>
          <label class="fw-qi__label">Percentage (%)</label>
          <input type="number" class="fw-qi__input ms-percentage" value="${defaultPct || ''}" step="0.01" min="0.01" max="100" placeholder="e.g. 50">
        </div>
        <div class="fw-qi__form-group ms-fixed-group" ${!defaultAmount ? 'style="display:none;"' : ''}>
          <label class="fw-qi__label">Amount (<span class="qi-currency-symbol">${curSym()}</span>)</label>
          <input type="number" class="fw-qi__input ms-fixed-amount" value="${defaultAmount || ''}" step="0.01" min="0.01" placeholder="e.g. 5000">
        </div>
        <div class="fw-qi__form-group">
          <label class="fw-qi__label">Due Date</label>
          <input type="date" class="fw-qi__input ms-due-date">
        </div>
        <div class="fw-qi__form-group">
          <label class="fw-qi__label">Calculated</label>
          <div class="fw-qi__milestone-amount" data-ms-id="${milestoneCounter}">${curSym()} 0.00</div>
        </div>
        <div class="fw-qi__form-group fw-qi__milestone-remove-col">
          <button type="button" class="fw-qi__btn fw-qi__btn--small fw-qi__btn--danger" onclick="QI.removeMilestone(${milestoneCounter})">Remove</button>
        </div>
      </div>
    `;

    container.appendChild(row);

    // Attach listeners
    row.querySelector('.ms-percentage').addEventListener('input', QI.calculateMilestones);
    row.querySelector('.ms-fixed-amount').addEventListener('input', QI.calculateMilestones);
    QI.calculateMilestones();
  };

  QI.onMilestoneTypeChange = function(msId) {
    const row = document.querySelector(`.fw-qi__milestone-row[data-milestone-id="${msId}"]`);
    if (!row) return;
    const type = row.querySelector('.ms-type').value;
    const pctGroup = row.querySelector('.ms-pct-group');
    const fixedGroup = row.querySelector('.ms-fixed-group');
    if (type === 'percentage') {
      pctGroup.style.display = '';
      fixedGroup.style.display = 'none';
    } else {
      pctGroup.style.display = 'none';
      fixedGroup.style.display = '';
    }
    QI.calculateMilestones();
  };

  QI.removeMilestone = function(msId) {
    const row = document.querySelector(`.fw-qi__milestone-row[data-milestone-id="${msId}"]`);
    if (row) {
      row.remove();
      QI.calculateMilestones();
    }
  };

  QI.calculateMilestones = function() {
    const grandTotal = parseFloat(document.getElementById('inputTotal')?.value) || 0;
    let totalAllocated = 0;

    document.querySelectorAll('.fw-qi__milestone-row').forEach(function(row) {
      const msId = row.dataset.milestoneId;
      const type = row.querySelector('.ms-type')?.value || 'percentage';
      let amount = 0;

      if (type === 'percentage') {
        const pct = parseFloat(row.querySelector('.ms-percentage')?.value) || 0;
        amount = grandTotal * (pct / 100);
      } else {
        amount = parseFloat(row.querySelector('.ms-fixed-amount')?.value) || 0;
      }

      totalAllocated += amount;

      const amountDisplay = document.querySelector(`.fw-qi__milestone-amount[data-ms-id="${msId}"]`);
      if (amountDisplay) {
        amountDisplay.textContent = curSym() + ' ' + amount.toFixed(2);
      }
    });

    // Calculate effective percentage of total
    const totalPct = grandTotal > 0 ? (totalAllocated / grandTotal) * 100 : 0;

    // Update summary
    const pctDisplay = document.getElementById('milestoneTotalPct');
    if (pctDisplay) {
      pctDisplay.textContent = totalPct.toFixed(2) + '% (' + curSym() + ' ' + totalAllocated.toFixed(2) + ')';
      pctDisplay.style.color = Math.abs(totalPct - 100) < 0.01 ? 'var(--accent-qi, #10b981)' : '#ef4444';
    }

    // Validation message
    const validationEl = document.getElementById('milestoneValidation');
    if (validationEl) {
      if (Math.abs(totalPct - 100) < 0.01) {
        validationEl.style.display = 'none';
      } else if (totalPct < 100) {
        validationEl.textContent = `${(100 - totalPct).toFixed(2)}% (${curSym()} ${(grandTotal - totalAllocated).toFixed(2)}) still unallocated`;
        validationEl.style.display = 'block';
      } else {
        validationEl.textContent = `${(totalPct - 100).toFixed(2)}% (${curSym()} ${(totalAllocated - grandTotal).toFixed(2)}) over-allocated`;
        validationEl.style.display = 'block';
      }
    }
  };

  QI.validateMilestones = function() {
    const grandTotal = parseFloat(document.getElementById('inputTotal')?.value) || 0;
    let totalAllocated = 0;

    document.querySelectorAll('.fw-qi__milestone-row').forEach(function(row) {
      const type = row.querySelector('.ms-type')?.value || 'percentage';
      if (type === 'percentage') {
        const pct = parseFloat(row.querySelector('.ms-percentage')?.value) || 0;
        totalAllocated += grandTotal * (pct / 100);
      } else {
        totalAllocated += parseFloat(row.querySelector('.ms-fixed-amount')?.value) || 0;
      }
    });

    const totalPct = grandTotal > 0 ? (totalAllocated / grandTotal) * 100 : 0;
    return Math.abs(totalPct - 100) < 0.01;
  };

  QI.collectMilestones = function() {
    const enabled = document.getElementById('enableMilestones')?.checked;
    if (!enabled) return [];

    const grandTotal = parseFloat(document.getElementById('inputTotal')?.value) || 0;
    const milestones = [];
    document.querySelectorAll('.fw-qi__milestone-row').forEach(function(row) {
      const type = row.querySelector('.ms-type')?.value || 'percentage';
      let percentage, fixedAmount;

      if (type === 'percentage') {
        percentage = parseFloat(row.querySelector('.ms-percentage')?.value) || 0;
        fixedAmount = null;
      } else {
        fixedAmount = parseFloat(row.querySelector('.ms-fixed-amount')?.value) || 0;
        // Calculate equivalent percentage for the backend
        percentage = grandTotal > 0 ? (fixedAmount / grandTotal) * 100 : 0;
      }

      milestones.push({
        label: row.querySelector('.ms-label')?.value || '',
        percentage: percentage,
        fixed_amount: fixedAmount,
        due_date: row.querySelector('.ms-due-date')?.value || null
      });
    });
    return milestones;
  };

  // ========== FORM SUBMIT ==========
  QI.initForm = function() {
    const form = document.getElementById('quoteForm');
    const btnSave = document.getElementById('btnSaveQuote');
    const formMessage = document.getElementById('formMessage');

    if (!form) return;

    // Add initial line item
    QI.addLineItem();

    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      // Validate at least one line item
      if (document.querySelectorAll('.fw-qi__line-item').length === 0) {
        showMessage('Please add at least one line item', 'error');
        return;
      }

      // Disable button
      btnSave.disabled = true;
      btnSave.querySelector('.fw-qi__btn-text').style.display = 'none';
      btnSave.querySelector('.fw-qi__spinner').style.display = 'inline-block';

      try {
        // Build JSON payload (matches save_quote.php expected format)
        const payload = {};
        payload.customer_id = form.querySelector('[name="customer_id"]')?.value || '';
        payload.contact_id = form.querySelector('[name="contact_id"]')?.value || null;
        payload.project_id = form.querySelector('[name="project_id"]')?.value || null;
        payload.issue_date = form.querySelector('[name="issue_date"]')?.value || '';
        payload.expiry_date = form.querySelector('[name="expiry_date"]')?.value || '';
        payload.subtotal = parseFloat(document.getElementById('inputSubtotal')?.value) || 0;
        payload.discount = parseFloat(document.getElementById('inputDiscount')?.value) || 0;
        payload.tax = parseFloat(document.getElementById('inputTax')?.value) || 0;
        payload.total = parseFloat(document.getElementById('inputTotal')?.value) || 0;
        payload.terms = form.querySelector('[name="terms"]')?.value || '';
        payload.notes = form.querySelector('[name="notes"]')?.value || '';

        // Check for edit mode
        const editQuoteId = form.querySelector('[name="quote_id"]');
        const editModeInput = form.querySelector('[name="edit_mode"]');
        if (editQuoteId) payload.quote_id = editQuoteId.value;
        if (editModeInput) payload.edit_mode = editModeInput.value;

        // Collect line items — the tax CODE travels with the rate so the
        // server can classify zero-rated vs exempt on the VAT201.
        payload.line_items = [];
        document.querySelectorAll('.fw-qi__line-item').forEach(function(lineDiv) {
          const description = lineDiv.querySelector('input[name*="[description]"]')?.value || '';
          const quantity = parseFloat(lineDiv.querySelector('.line-quantity')?.value) || 0;
          const unit_price = parseFloat(lineDiv.querySelector('.line-price')?.value) || 0;
          const discount = parseFloat(lineDiv.querySelector('.line-discount')?.value) || 0;
          const taxSelect = lineDiv.querySelector('.line-tax-rate');
          const tax_rate = parseFloat(taxSelect?.value) || 0;
          const tax_code = taxSelect?.selectedOptions?.[0]?.dataset?.taxCode || null;
          const inventory_item_id = lineDiv.querySelector('select[name*="[inventory_item_id]"]')?.value || null;
          const lineNet = (quantity * unit_price) - discount;
          const line_total = lineNet + (lineNet * tax_rate / 100);
          payload.line_items.push({ description, quantity, unit_price, discount, tax_rate, tax_code, inventory_item_id, line_total });
        });

        // Include milestones if enabled
        payload.milestones = QI.collectMilestones();
        if (payload.milestones.length > 0 && !QI.validateMilestones()) {
          showMessage('Payment milestone percentages must add up to 100%', 'error');
          btnSave.disabled = false;
          btnSave.querySelector('.fw-qi__btn-text').style.display = 'inline';
          btnSave.querySelector('.fw-qi__spinner').style.display = 'none';
          return;
        }

        const res = await fetch('/qi/ajax/save_quote.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
          },
          body: JSON.stringify(payload)
        });

        const data = await res.json();

        if (data.ok) {
          showMessage('Quote created successfully!', 'success');
          setTimeout(() => {
            window.location.href = '/qi/quote_view.php?id=' + data.quote_id;
          }, 1000);
        } else {
          showMessage(data.error || 'Failed to create quote', 'error');
          btnSave.disabled = false;
          btnSave.querySelector('.fw-qi__btn-text').style.display = 'inline';
          btnSave.querySelector('.fw-qi__spinner').style.display = 'none';
        }
      } catch (err) {
        showMessage('Network error. Please try again.', 'error');
        btnSave.disabled = false;
        btnSave.querySelector('.fw-qi__btn-text').style.display = 'inline';
        btnSave.querySelector('.fw-qi__spinner').style.display = 'none';
      }
    });

    function showMessage(msg, type) {
      formMessage.className = 'fw-qi__form-message fw-qi__form-message--' + type;
      formMessage.textContent = msg;
      formMessage.style.display = 'block';

      if (type === 'success') {
        setTimeout(() => {
          formMessage.style.display = 'none';
        }, 5000);
      }
    }
  };

  // Auto-init on page load
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', QI.initForm);
  } else {
    QI.initForm();
  }

})();