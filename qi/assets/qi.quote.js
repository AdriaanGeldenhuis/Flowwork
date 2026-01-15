/*
 * Quote-specific UI logic for creating and editing quotes.
 * Handles dynamic line items, total calculations and form submission via AJAX.
 */

(function() {
    'use strict';

    // Add a new line item to the form
    function addLine() {
        const container = document.getElementById('lineItemsContainer');
        if (!container) return;
        const newLine = document.createElement('div');
        newLine.className = 'fw-qi-form__line-item';
        newLine.innerHTML = `
            <input type="text" class="fw-qi__input" placeholder="Description" name="item_description[]" required>
            <input type="number" class="fw-qi__input" placeholder="Qty" name="item_quantity[]" value="1" step="0.01" min="0" required>
            <input type="number" class="fw-qi__input" placeholder="Unit Price" name="item_price[]" step="0.01" min="0" required>
            <input type="text" class="fw-qi__input" placeholder="R 0.00" readonly name="item_total[]">
            <button type="button" class="fw-qi-form__remove-line" data-action="remove-line">×</button>
        `;
        container.appendChild(newLine);
        attachLineListeners(newLine);
    }

    // Remove a line item (only if more than one exists)
    function removeLine(button) {
        const container = document.getElementById('lineItemsContainer');
        if (!container || container.children.length <= 1) return;
        const lineItem = button.closest('.fw-qi-form__line-item');
        if (lineItem) {
            lineItem.remove();
            calculateTotals();
        }
    }

    // Attach listeners to quantity and price fields on a line item
    function attachLineListeners(line) {
        const qty = line.querySelector('[name="item_quantity[]"]');
        const price = line.querySelector('[name="item_price[]"]');
        if (qty) qty.addEventListener('input', calculateTotals);
        if (price) price.addEventListener('input', calculateTotals);
    }

    // Calculate subtotal, tax and total and update UI
    function calculateTotals() {
        let subtotal = 0;
        const lines = document.querySelectorAll('.fw-qi-form__line-item');
        lines.forEach(line => {
            const qty = parseFloat(line.querySelector('[name="item_quantity[]"]').value) || 0;
            const price = parseFloat(line.querySelector('[name="item_price[]"]').value) || 0;
            const lineTotal = qty * price;
            const totalField = line.querySelector('[name="item_total[]"]');
            if (totalField) totalField.value = 'R ' + lineTotal.toFixed(2);
            subtotal += lineTotal;
        });
        // Use global defaultTaxRate if provided (percentage), else default to 15%
        const rate = (typeof window !== 'undefined' && window.defaultTaxRate) ? (parseFloat(window.defaultTaxRate) / 100) : 0.15;
        const tax = subtotal * rate;
        const total = subtotal + tax;
        const subtotalDisplay = document.getElementById('subtotalDisplay');
        const taxDisplay = document.getElementById('taxDisplay');
        const totalDisplay = document.getElementById('totalDisplay');
        if (subtotalDisplay) subtotalDisplay.textContent = 'R ' + subtotal.toFixed(2);
        if (taxDisplay) taxDisplay.textContent = 'R ' + tax.toFixed(2);
        if (totalDisplay) totalDisplay.textContent = 'R ' + total.toFixed(2);
    }

    // Handle form submission to save quote
    async function handleFormSubmit(e) {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);

        // Build line items array
        const lineItems = [];
        document.querySelectorAll('.fw-qi-form__line-item').forEach(line => {
            const desc = line.querySelector('[name="item_description[]"]').value;
            const qty = parseFloat(line.querySelector('[name="item_quantity[]"]').value) || 0;
            const price = parseFloat(line.querySelector('[name="item_price[]"]').value) || 0;
            lineItems.push({
                description: desc,
                quantity: qty,
                unit_price: price,
                line_total: qty * price
            });
        });

        // Compute totals client-side for convenience (server will recompute). Use defaultTaxRate if provided
        const subtotal = lineItems.reduce((sum, item) => sum + item.line_total, 0);
        const rateSubmit = (typeof window !== 'undefined' && window.defaultTaxRate) ? (parseFloat(window.defaultTaxRate) / 100) : 0.15;
        const tax = subtotal * rateSubmit;
        const total = subtotal + tax;

        // Build payload
        const payload = {
            customer_id: formData.get('customer_id'),
            project_id: formData.get('project_id') || null,
            issue_date: formData.get('issue_date'),
            expiry_date: formData.get('expiry_date'),
            subtotal: subtotal,
            discount: 0,
            tax: tax,
            total: total,
            terms: formData.get('terms'),
            notes: formData.get('notes'),
            line_items: lineItems
        };
        // Handle edit
        const config = window.quoteConfig || {};
        if (config.editMode && config.quoteId) {
            payload.edit_mode = 1;
            payload.quote_id = config.quoteId;
        }
        try {
            const response = await fetch('/qi/ajax/save_quote.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload)
            });
            const result = await response.json();
            if (result.ok) {
                alert('✅ ' + result.message);
                window.location.href = '/qi/quote_view.php?id=' + result.quote_id;
            } else {
                alert('❌ Error: ' + (result.error || 'Save failed'));
            }
        } catch (err) {
            alert('❌ Network error: ' + err.message);
        }
    }

    // Initialize listeners on DOM ready
    function init() {
        const form = document.getElementById('quoteForm');
        if (form) {
            form.addEventListener('submit', handleFormSubmit);
        }
        // Attach listeners to existing lines
        document.querySelectorAll('.fw-qi-form__line-item').forEach(attachLineListeners);
        // Delegate remove-line buttons
        document.addEventListener('click', function(e) {
            if (e.target && e.target.dataset && e.target.dataset.action === 'remove-line') {
                removeLine(e.target);
            }
        });
        // Initialize totals
        calculateTotals();
        // Expose addLine/removeLine for inline button handlers
        window.addLine = addLine;
        window.removeLine = removeLine;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();