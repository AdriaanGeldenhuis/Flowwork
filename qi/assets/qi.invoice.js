// qi/assets/qi.invoice.js - Invoice specific actions
// Provides actions for viewing an invoice such as sending, downloading PDF, deleting, etc.
const InvoiceView = {
    invoiceId: null,
    customerEmail: '',
    customerName: '',
    balanceDue: 0,
    hasMilestones: false,

    init: function (opts) {
        this.invoiceId = opts.invoiceId || null;
        this.customerEmail = opts.customerEmail || '';
        this.customerName = opts.customerName || '';
        this.balanceDue = opts.balanceDue || 0;
        this.hasMilestones = opts.hasMilestones || false;
    },

    /**
     * When a milestone is selected in the payment modal, pre-fill the amount
     */
    onMilestoneSelect: function() {
        const select = document.getElementById('milestoneSelect');
        if (!select) return;
        const option = select.options[select.selectedIndex];
        const remaining = option?.dataset?.remaining;
        if (remaining && parseFloat(remaining) > 0) {
            const amountInput = document.querySelector('#paymentForm input[name="amount"]');
            if (amountInput) {
                amountInput.value = remaining;
            }
        }
    },

    async sendInvoice() {
        if (!this.customerEmail) {
            UI.toast('No email address found for this customer.\n\nPlease add an email address in CRM first.');
            return;
        }
        const confirmMsg = 'Send invoice to customer?\n\nTo: ' + this.customerEmail + '\nCustomer: ' + (this.customerName || 'Customer') + '\n\nThis will mark the invoice as "Sent".\n\nContinue?';
        if (!UI.confirm(confirmMsg)) {
            return;
        }
        const btn = event.target;
        const originalHTML = btn.innerHTML;
        btn.innerHTML = '<span style="opacity:0.6">Sending...</span>';
        btn.disabled = true;
        try {
            const data = await UI.fetchJSON('/qi/ajax/send_invoice.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    invoice_id: this.invoiceId,
                    send_to: this.customerEmail
                })
            });
            if (data.ok) {
                UI.toast('Invoice sent successfully!\n\nSent to: ' + (data.recipient || this.customerEmail) + '\n\nStatus updated to "Sent"');
                location.reload();
            } else {
                UI.toast('Error: ' + (data.error || 'Send failed'));
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            }
        } catch (err) {
            UI.toast('Network error: ' + err.message);
            btn.innerHTML = originalHTML;
            btn.disabled = false;
        }
    },

    downloadPDF() {
        if (!this.invoiceId) return;
        window.location.href = '/qi/ajax/download_pdf.php?type=invoice&id=' + this.invoiceId;
    },

    printPDF() {
        if (!this.invoiceId) return;
        window.open('/qi/ajax/generate_pdf.php?type=invoice&id=' + this.invoiceId, '_blank');
    },

    async deleteInvoice() {
        if (!this.invoiceId) return;
        if (!UI.confirm('Delete this invoice? This action cannot be undone.')) return;
        const btn = event.target;
        const originalHTML = btn.innerHTML;
        btn.innerHTML = 'Deleting...';
        btn.disabled = true;
        try {
            const data = await UI.fetchJSON('/qi/ajax/delete_invoice.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ invoice_id: this.invoiceId })
            });
            if (data.ok) {
                UI.toast('Invoice deleted');
                window.location.href = '/qi/?tab=invoices';
            } else {
                UI.toast('Error: ' + (data.error || 'Delete failed'));
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            }
        } catch (err) {
            UI.toast('Network error: ' + err.message);
            btn.innerHTML = originalHTML;
            btn.disabled = false;
        }
    },

    /**
     * Create a Yoco payment link for this invoice
     */
    async createPaymentLink() {
        if (!this.invoiceId) return;
        if (!UI.confirm('Generate a payment link for this invoice?\n\nThe link will allow your customer to pay online via Yoco.\n\nContinue?')) {
            return;
        }
        const btn = event.target;
        const originalHTML = btn.innerHTML;
        btn.innerHTML = 'Creating...';
        btn.disabled = true;
        try {
            const data = await UI.fetchJSON('/qi/ajax/create_yoco_link.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ invoice_id: this.invoiceId })
            });
            if (data.ok) {
                UI.toast('Payment link created!\n\nLink: ' + data.payment_link);
                location.reload();
            } else {
                UI.toast('Error: ' + (data.error || 'Could not create link'));
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            }
        } catch (err) {
            UI.toast('Network error: ' + err.message);
            btn.innerHTML = originalHTML;
            btn.disabled = false;
        }
    },

    /**
     * Open the payment modal overlay
     */
    openPaymentModal() {
        const overlay = document.getElementById('paymentModalOverlay');
        if (overlay) {
            overlay.classList.add('fw-qi__modal-overlay--active');
        }
        // Auto-fill amount from pre-selected milestone
        this.onMilestoneSelect();
    },

    /**
     * Close the payment modal overlay
     */
    closePaymentModal() {
        const overlay = document.getElementById('paymentModalOverlay');
        if (overlay) {
            overlay.classList.remove('fw-qi__modal-overlay--active');
        }
    },

    /**
     * Validate and submit the payment form via AJAX
     */
    async recordPayment() {
        const form = document.getElementById('paymentForm');
        if (!form) return;
        const paymentDate = form.payment_date.value;
        const amountStr   = form.amount.value;
        const amount      = parseFloat(amountStr);
        const method      = form.method.value;
        const reference   = form.reference.value || '';
        const notes       = form.notes.value || '';
        if (!paymentDate) {
            UI.toast('Please select a payment date.');
            return;
        }
        if (!amount || isNaN(amount) || amount <= 0) {
            UI.toast('Please enter a valid payment amount.');
            return;
        }
        // Check if payment exceeds outstanding balance
        if (amount > this.balanceDue) {
            if (!UI.confirm('Payment amount exceeds the outstanding balance. Allocate anyway?')) {
                return;
            }
        }
        // Disable buttons to prevent double submit
        const submitBtn = form.closest('.fw-qi__modal').querySelector('button.fw-qi__btn--primary');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Recording...';
        }
        try {
            // Get milestone selection if present
            const milestoneSelect = document.getElementById('milestoneSelect');
            const milestoneId = milestoneSelect ? milestoneSelect.value || null : null;

            const data = await UI.fetchJSON('/qi/ajax/record_payment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    invoice_id: this.invoiceId,
                    payment_date: paymentDate,
                    amount: amount,
                    method: method,
                    reference: reference,
                    notes: notes,
                    milestone_id: milestoneId
                })
            });
            if (data.ok) {
                UI.toast('Payment recorded successfully!\n\nNew balance: R ' + parseFloat(data.new_balance).toFixed(2));
                window.location.reload();
            } else {
                UI.toast('Error: ' + (data.error || 'Payment could not be recorded'));
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Record Payment';
                }
            }
        } catch (err) {
            UI.toast('Network error: ' + err.message);
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Record Payment';
            }
        }
    },

    /**
     * Prompt for a credit note ID and apply it to this invoice via API
     */
    async applyCreditNote() {
        if (!this.invoiceId) return;
        const creditIdStr = prompt('Enter the Credit Note ID to apply to this invoice:');
        if (!creditIdStr) return;
        const creditId = parseInt(creditIdStr, 10);
        if (!creditId || isNaN(creditId)) {
            UI.toast('Invalid credit note ID');
            return;
        }
        if (!UI.confirm('Apply credit note #' + creditId + ' to this invoice?')) {
            return;
        }
        const btn = event.target;
        const originalHTML = btn.innerHTML;
        btn.innerHTML = 'Applying...';
        btn.disabled = true;
        try {
            const res = await UI.fetchJSON('/qi/ajax/apply_credit_note.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ credit_note_id: creditId, invoice_id: this.invoiceId })
            });
            if (res.ok) {
                UI.toast('Credit note applied successfully!\n\nNew balance: R ' + parseFloat(res.new_balance).toFixed(2));
                window.location.reload();
            } else {
                UI.toast('Error: ' + (res.error || 'Could not apply credit note'));
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            }
        } catch (err) {
            UI.toast('Network error: ' + err.message);
            btn.innerHTML = originalHTML;
            btn.disabled = false;
        }
    },

    /**
     * Fetch and display email log for this invoice in an alert-like window
     */
    async viewEmailLog() {
        if (!this.invoiceId) return;
        try {
            const res = await UI.fetchJSON('/qi/ajax/get_email_log.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ doc_type: 'invoice', doc_id: this.invoiceId })
            });
            if (res.ok) {
                const logs = res.data || [];
                if (logs.length === 0) {
                    UI.toast('No email history found for this invoice');
                    return;
                }
                let logMsg = 'Email History:\n\n';
                logs.forEach(log => {
                    logMsg += '- ' + log.created_at + ' → ' + (log.recipient || 'N/A') + '\n  ' + (log.subject || '(no subject)') + '\n\n';
                });
                UI.toast(logMsg);
            } else {
                UI.toast('Error: ' + (res.error || 'Could not fetch email log'));
            }
        } catch (err) {
            UI.toast('Network error: ' + err.message);
        }
    }
};