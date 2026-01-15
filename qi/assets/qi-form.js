// /qi/assets/qi-form.js
window.QI = window.QI || {};

(function() {
  'use strict';

  let lineItemCounter = 0;

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
          <label class="fw-qi__label">Unit Price (R)</label>
          <input type="number" name="lines[${lineItemCounter}][unit_price]" class="fw-qi__input line-price" step="0.01" value="0.00" min="0" required>
        </div>

        <div class="fw-qi__form-group">
          <label class="fw-qi__label">Discount (R)</label>
          <input type="number" name="lines[${lineItemCounter}][discount]" class="fw-qi__input line-discount" step="0.01" value="0.00" min="0">
        </div>

        <div class="fw-qi__form-group">
          <label class="fw-qi__label">Tax Rate (%)</label>
          <select name="lines[${lineItemCounter}][tax_rate]" class="fw-qi__input line-tax-rate">
            <option value="15.00">15% (Standard)</option>
            <option value="0.00">0% (Zero-rated)</option>
          </select>
        </div>

        <div class="fw-qi__form-group">
          <label class="fw-qi__label">Line Total</label>
          <div class="fw-qi__line-total" data-line-id="${lineItemCounter}">R 0.00</div>
        </div>
      </div>
    `;

    container.appendChild(lineDiv);

    // Attach change listeners
    lineDiv.querySelectorAll('.line-quantity, .line-price, .line-discount, .line-tax-rate').forEach(input => {
      input.addEventListener('input', QI.calculateTotals);
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
        lineTotalDisplay.textContent = 'R ' + lineTotal.toFixed(2);
      }

      subtotal += lineSubtotal;
      totalDiscount += discount;
      totalTax += lineTax;
    });

    const grandTotal = subtotal - totalDiscount + totalTax;

    // Update displays
    document.getElementById('displaySubtotal').textContent = 'R ' + subtotal.toFixed(2);
    document.getElementById('displayDiscount').textContent = 'R ' + totalDiscount.toFixed(2);
    document.getElementById('displayTax').textContent = 'R ' + totalTax.toFixed(2);
    document.getElementById('displayTotal').textContent = 'R ' + grandTotal.toFixed(2);

    // Update hidden inputs
    document.getElementById('inputSubtotal').value = subtotal.toFixed(2);
    document.getElementById('inputDiscount').value = totalDiscount.toFixed(2);
    document.getElementById('inputTax').value = totalTax.toFixed(2);
    document.getElementById('inputTotal').value = grandTotal.toFixed(2);
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
        const formData = new FormData(form);
        const res = await fetch('/qi/ajax/save_quote.php', {
          method: 'POST',
          body: formData
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
        console.error(err);
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