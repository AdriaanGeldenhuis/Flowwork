/*
 * Quote-specific UI logic for creating and editing quotes.
 * Handles dynamic line items, total calculations and form submission via AJAX.
 */

(function() {
    'use strict';

    // Symbol of the document's currency (falls back to ZAR's "R")
    function curSym() {
        return (window.QICurrency) ? QICurrency.symbol() : 'R';
    }

    function handleHtml() {
        return (window.QIDnD) ? QIDnD.handleHtml() : '';
    }

    // Add a new line item to the form
    function addLine() {
        const container = document.getElementById('lineItemsContainer');
        if (!container) return;
        const newLine = document.createElement('div');
        newLine.className = 'fw-qi-form__line-item';
        newLine.innerHTML = `
            ${handleHtml()}
            <input type="text" class="fw-qi__input" placeholder="Description" name="item_description[]" required>
            <input type="number" class="fw-qi__input" placeholder="Qty" name="item_quantity[]" value="1" step="0.01" min="0" required>
            <input type="number" class="fw-qi__input" placeholder="Unit Price" name="item_price[]" step="0.01" min="0" required>
            <select class="fw-qi__input line-tax-rate" name="item_tax_rate[]">
                <option value="15.00" data-tax-code="STD">VAT (15%)</option>
                <option value="0.00" data-tax-code="ZERO">No VAT (Zero-rated)</option>
                <option value="0.00" data-tax-code="EXEMPT">No VAT (Exempt)</option>
            </select>
            <input type="text" class="fw-qi__input" placeholder="R 0.00" readonly name="item_total[]">
            <button type="button" class="fw-qi-form__remove-line" data-action="remove-line">×</button>
        `;
        container.appendChild(newLine);
        attachLineListeners(newLine);
    }

    // Add a section heading row (board-group style). Returns the row so
    // import can set the title safely via the input's value property.
    function addHeading(title) {
        const container = document.getElementById('lineItemsContainer');
        if (!container) return null;
        const row = document.createElement('div');
        row.className = 'fw-qi-form__heading-row';
        row.innerHTML = `
            ${handleHtml()}
            <input type="text" class="fw-qi__input" placeholder="Section heading (e.g. Aluminium)" name="item_heading[]">
            <button type="button" class="fw-qi-form__remove-line" data-action="remove-heading" title="Remove heading">×</button>
        `;
        const input = row.querySelector('input');
        if (input && title) input.value = title;
        container.appendChild(row);
        return row;
    }

    // Remove a line item (only if more than one exists)
    function removeLine(button) {
        const container = document.getElementById('lineItemsContainer');
        if (!container || container.querySelectorAll('.fw-qi-form__line-item').length <= 1) return;
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
        const tax = line.querySelector('.line-tax-rate');
        if (qty) qty.addEventListener('input', calculateTotals);
        if (price) price.addEventListener('input', calculateTotals);
        if (tax) tax.addEventListener('change', calculateTotals);
    }

    // Calculate subtotal, tax and total and update UI
    function calculateTotals() {
        let subtotal = 0;
        let tax = 0;
        const lines = document.querySelectorAll('.fw-qi-form__line-item');
        lines.forEach(line => {
            const qty = parseFloat(line.querySelector('[name="item_quantity[]"]').value) || 0;
            const price = parseFloat(line.querySelector('[name="item_price[]"]').value) || 0;
            const lineTotal = qty * price;
            const totalField = line.querySelector('[name="item_total[]"]');
            if (totalField) totalField.value = curSym() + ' ' + lineTotal.toFixed(2);
            subtotal += lineTotal;
            // Per-line VAT: the selected option's rate (VAT 15% or No VAT 0%).
            const taxSel = line.querySelector('.line-tax-rate');
            const lineRate = taxSel ? (parseFloat(taxSel.value) || 0) / 100 : 0.15;
            tax += lineTotal * lineRate;
        });
        const total = subtotal + tax;
        const subtotalDisplay = document.getElementById('subtotalDisplay');
        const taxDisplay = document.getElementById('taxDisplay');
        const totalDisplay = document.getElementById('totalDisplay');
        if (subtotalDisplay) subtotalDisplay.textContent = curSym() + ' ' + subtotal.toFixed(2);
        if (taxDisplay) taxDisplay.textContent = curSym() + ' ' + tax.toFixed(2);
        if (totalDisplay) totalDisplay.textContent = curSym() + ' ' + total.toFixed(2);
    }

    // Handle form submission to save quote
    async function handleFormSubmit(e) {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);

        // Build line items array in DOM order, carrying each line's VAT choice
        // (rate + SARS tax code) so the server stores it and the converted
        // invoice inherits it. Section headings share the same sort_order
        // sequence so the server can interleave them back into this order.
        const lineItems = [];
        const headings = [];
        let sortIndex = 0;
        document.querySelectorAll('#lineItemsContainer .fw-qi-form__line-item, #lineItemsContainer .fw-qi-form__heading-row').forEach(line => {
            if (line.classList.contains('fw-qi-form__heading-row')) {
                const title = (line.querySelector('[name="item_heading[]"]')?.value || '').trim();
                if (title) headings.push({ title: title, sort_order: sortIndex++ });
                return;
            }
            const desc = line.querySelector('[name="item_description[]"]').value;
            const qty = parseFloat(line.querySelector('[name="item_quantity[]"]').value) || 0;
            const price = parseFloat(line.querySelector('[name="item_price[]"]').value) || 0;
            const taxSel = line.querySelector('.line-tax-rate');
            const taxRate = taxSel ? (parseFloat(taxSel.value) || 0) : 15;
            const taxCode = taxSel ? (taxSel.selectedOptions[0]?.dataset?.taxCode || 'STD') : 'STD';
            lineItems.push({
                description: desc,
                quantity: qty,
                unit_price: price,
                line_total: qty * price,
                tax_rate: taxRate,
                tax_code: taxCode,
                sort_order: sortIndex++
            });
        });

        // Compute totals client-side for convenience (server recomputes and is
        // authoritative). VAT is summed per line from each line's chosen rate.
        const subtotal = lineItems.reduce((sum, item) => sum + item.line_total, 0);
        const tax = lineItems.reduce((sum, item) => sum + item.line_total * ((parseFloat(item.tax_rate) || 0) / 100), 0);
        const total = subtotal + tax;

        // Build payload
        const payload = {
            customer_id: formData.get('customer_id'),
            project_id: formData.get('project_id') || null,
            issue_date: formData.get('issue_date'),
            expiry_date: formData.get('expiry_date'),
            currency: (window.QICurrency) ? QICurrency.code() : (formData.get('currency') || 'ZAR'),
            exchange_rate: (window.QICurrency) ? QICurrency.rate() : 1,
            subtotal: subtotal,
            discount: 0,
            tax: tax,
            total: total,
            terms: formData.get('terms'),
            notes: formData.get('notes'),
            line_items: lineItems,
            headings: headings
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
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
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
        // Delegate remove-line / remove-heading buttons
        document.addEventListener('click', function(e) {
            if (e.target && e.target.dataset && e.target.dataset.action === 'remove-line') {
                removeLine(e.target);
            }
            if (e.target && e.target.dataset && e.target.dataset.action === 'remove-heading') {
                const row = e.target.closest('.fw-qi-form__heading-row');
                if (row) row.remove();
            }
        });
        // Drag & drop reordering (items move alone; a heading moves its section)
        if (window.QIDnD) {
            QIDnD.attach({
                container: '#lineItemsContainer',
                rowSelector: '.fw-qi-form__line-item',
                headingSelector: '.fw-qi-form__heading-row',
                onReorder: calculateTotals
            });
        }
        // Currency selector: live rate lookup + optional price conversion on switch
        if (window.QICurrency) {
            QICurrency.init(window.qiCurrency || {});
            QICurrency.attach({
                select: document.getElementById('currencySelect'),
                rateInput: document.getElementById('exchangeRateInput'),
                rateRow: document.getElementById('exchangeRateGroup'),
                getDate: () => document.querySelector('[name="issue_date"]')?.value || '',
                getPriceInputs: () => Array.from(document.querySelectorAll('[name="item_price[]"]')),
                recalc: calculateTotals,
                customerSelect: document.querySelector('select[name="customer_id"]')
            });
        }
        // Initialize totals
        calculateTotals();
        // Expose for inline button handlers and the page's import script
        window.addLine = addLine;
        window.addHeading = addHeading;
        window.removeLine = removeLine;
        window.calculateTotals = calculateTotals;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();