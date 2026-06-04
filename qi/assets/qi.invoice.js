// qi/assets/qi.invoice.js - Invoice specific actions
// Provides actions for viewing an invoice such as sending, downloading PDF, deleting, etc.
const InvoiceView = {
    invoiceId: null,
    customerEmail: '',
    customerName: '',
    balanceDue: 0,
    invoiceTotal: 0,
    hasMilestones: false,

    init: function (opts) {
        this.invoiceId = opts.invoiceId || null;
        this.customerEmail = opts.customerEmail || '';
        this.customerName = opts.customerName || '';
        this.balanceDue = opts.balanceDue || 0;
        this.invoiceTotal = opts.invoiceTotal || 0;
        this.hasMilestones = opts.hasMilestones || false;
    },

    /**
     * Recompute the live "paid / outstanding / %" breakdown in the payment modal
     * as the user types an amount. Mirrors the server's logic: the amount is simply
     * subtracted from the outstanding balance and the percentage is worked out from
     * the actual paid total.
     */
    updatePaymentCalc: function () {
        const form = document.getElementById('paymentForm');
        if (!form) return;
        const total = this.invoiceTotal || 0;
        const alreadyPaid = Math.max(0, total - this.balanceDue);

        let amt = parseFloat(form.amount.value);
        if (isNaN(amt) || amt < 0) amt = 0;

        const newPaid = Math.min(total, alreadyPaid + amt);
        const remaining = Math.max(0, this.balanceDue - amt);
        const pct = total > 0 ? Math.min(100, (newPaid / total) * 100) : 0;

        const fmt = (n) => 'R ' + Number(n).toFixed(2);
        const setText = (id, txt) => { const el = document.getElementById(id); if (el) el.textContent = txt; };

        setText('calcPaid', fmt(alreadyPaid));
        setText('calcThis', fmt(amt));
        setText('calcRemaining', fmt(remaining));
        setText('calcPct', pct.toFixed(1) + '% paid');
        const bar = document.getElementById('calcBar');
        if (bar) bar.style.width = pct.toFixed(1) + '%';
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
        // Route through generate_pdf.php so the browser's own print engine
        // renders the same HTML/CSS as the on-screen invoice. The print dialog
        // auto-opens; the user picks "Save as PDF" for pixel-perfect output.
        window.open('/qi/ajax/generate_pdf.php?type=invoice&id=' + this.invoiceId, '_blank');
    },

    async _callAction(url, body, successMsg, reloadUrl) {
        const btn = (typeof event !== 'undefined') ? event.target : null;
        let originalHTML = '';
        if (btn) {
            originalHTML = btn.innerHTML;
            btn.innerHTML = 'Working...';
            btn.disabled = true;
        }
        try {
            const data = await UI.fetchJSON(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            if (data.ok) {
                if (successMsg) UI.toast(successMsg);
                if (reloadUrl === false) return data;
                if (reloadUrl) window.location.href = reloadUrl;
                else window.location.reload();
                return data;
            }
            UI.toast('Error: ' + (data.error || 'Action failed'));
        } catch (err) {
            UI.toast('Network error: ' + err.message);
        }
        if (btn) {
            btn.innerHTML = originalHTML;
            btn.disabled = false;
        }
        return null;
    },

    async deleteInvoice() {
        if (!this.invoiceId) return;
        if (!UI.confirm('Delete this invoice? This action cannot be undone.')) return;
        await this._callAction('/qi/ajax/delete_invoice.php',
            { invoice_id: this.invoiceId, mode: 'hard' },
            'Invoice deleted',
            '/qi/?tab=invoices');
    },

    async softDeleteInvoice() {
        if (!this.invoiceId) return;
        const reason = prompt('Reason for deletion (optional):') || '';
        if (!UI.confirm('Move this invoice to trash? It can be restored later.')) return;
        await this._callAction('/qi/ajax/delete_invoice.php',
            { invoice_id: this.invoiceId, mode: 'soft', reason },
            'Invoice moved to trash',
            '/qi/?tab=invoices');
    },

    async forceDeleteInvoice() {
        if (!this.invoiceId) return;
        const reason = prompt('Admin force delete — enter a reason (required):') || '';
        if (!reason.trim()) { UI.toast('Reason is required'); return; }
        if (!UI.confirm('FORCE DELETE this invoice and all related records (payments, allocations, lines)?\n\nThis cannot be undone.')) return;
        await this._callAction('/qi/ajax/delete_invoice.php',
            { invoice_id: this.invoiceId, mode: 'force', reason },
            'Invoice force-deleted',
            '/qi/?tab=invoices');
    },

    async restoreInvoice() {
        if (!this.invoiceId) return;
        await this._callAction('/qi/ajax/invoice_action.php',
            { invoice_id: this.invoiceId, action: 'restore' }, 'Invoice restored');
    },

    async voidInvoice() {
        if (!this.invoiceId) return;
        const reason = prompt('Reason for voiding (optional):') || '';
        if (!UI.confirm('Void this invoice?\n\nStatus will change to "cancelled" and any posted journal will be reversed. The record stays for audit.')) return;
        await this._callAction('/qi/ajax/invoice_action.php',
            { invoice_id: this.invoiceId, action: 'void', reason }, 'Invoice voided');
    },

    async revertToDraft() {
        if (!this.invoiceId) return;
        if (!UI.confirm('Revert this invoice to draft? You will be able to edit it again.')) return;
        await this._callAction('/qi/ajax/invoice_action.php',
            { invoice_id: this.invoiceId, action: 'revert_to_draft' }, 'Reverted to draft');
    },

    async writeOffInvoice() {
        if (!this.invoiceId) return;
        const amtStr = prompt('Amount to write off (leave blank for full outstanding balance):');
        const amount = amtStr ? parseFloat(amtStr) : null;
        if (amtStr && (isNaN(amount) || amount <= 0)) { UI.toast('Invalid amount'); return; }
        const reason = prompt('Reason (e.g. Bad debt):') || '';
        if (!UI.confirm('Write off this amount as bad debt?')) return;
        await this._callAction('/qi/ajax/invoice_action.php',
            { invoice_id: this.invoiceId, action: 'write_off', amount, reason },
            'Invoice written off');
    },

    async markUncollectible() {
        if (!this.invoiceId) return;
        const reason = prompt('Reason (optional):') || '';
        if (!UI.confirm('Mark this invoice as uncollectible?')) return;
        await this._callAction('/qi/ajax/invoice_action.php',
            { invoice_id: this.invoiceId, action: 'mark_uncollectible', reason },
            'Marked uncollectible');
    },

    async archiveInvoice() {
        if (!this.invoiceId) return;
        if (!UI.confirm('Archive this invoice? It will be hidden from the default list but kept for reporting.')) return;
        await this._callAction('/qi/ajax/invoice_action.php',
            { invoice_id: this.invoiceId, action: 'archive' }, 'Invoice archived');
    },

    async unarchiveInvoice() {
        if (!this.invoiceId) return;
        await this._callAction('/qi/ajax/invoice_action.php',
            { invoice_id: this.invoiceId, action: 'unarchive' }, 'Invoice unarchived');
    },

    async unapplyPayments() {
        if (!this.invoiceId) return;
        if (!UI.confirm('Unapply ALL payments from this invoice?\n\nAllocations will be removed and the balance recalculated. The payment records themselves are kept.')) return;
        await this._callAction('/qi/ajax/invoice_action.php',
            { invoice_id: this.invoiceId, action: 'unapply_payments' }, 'Payments unapplied');
    },

    async duplicateInvoice() {
        if (!this.invoiceId) return;
        if (!UI.confirm('Create a duplicate of this invoice as a new draft?')) return;
        const data = await this._callAction('/qi/ajax/duplicate_invoice.php',
            { invoice_id: this.invoiceId }, null, false);
        if (data && data.invoice_id) {
            window.location.href = '/qi/invoice_view.php?id=' + data.invoice_id;
        }
    },

    async issueFullCredit() {
        if (!this.invoiceId) return;
        const reason = prompt('Reason for credit note:') || 'Full credit';
        if (!UI.confirm('Issue a draft credit note that fully offsets this invoice?')) return;
        const data = await this._callAction('/qi/ajax/issue_full_credit.php',
            { invoice_id: this.invoiceId, reason }, null, false);
        if (data && data.credit_note_id) {
            UI.toast('Credit note ' + data.credit_note_number + ' created');
            window.location.href = '/qi/credit_note_view.php?id=' + data.credit_note_id;
        }
    },

    async refundInvoice() {
        if (!this.invoiceId) return;
        const amtStr = prompt('Refund amount:');
        const amount = parseFloat(amtStr);
        if (!amount || amount <= 0) { UI.toast('Invalid amount'); return; }
        const reference = prompt('Refund reference (bank ref, etc.):') || '';
        const reason = prompt('Reason (optional):') || '';
        if (!UI.confirm('Record a refund of R ' + amount.toFixed(2) + ' against this invoice?')) return;
        await this._callAction('/qi/ajax/refund_invoice.php',
            { invoice_id: this.invoiceId, amount, reference, reason }, 'Refund recorded');
    },

    async exportBeforeDelete() {
        if (!this.invoiceId) return;
        window.open('/qi/ajax/download_pdf.php?type=invoice&id=' + this.invoiceId, '_blank');
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
        // Render the initial paid / outstanding / % breakdown
        this.updatePaymentCalc();
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
            // The payment is allocated across the schedule automatically server-side
            // (oldest unpaid phase first), so no per-phase selection is sent.
            const data = await UI.fetchJSON('/qi/ajax/record_payment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    invoice_id: this.invoiceId,
                    payment_date: paymentDate,
                    amount: amount,
                    method: method,
                    reference: reference,
                    notes: notes
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