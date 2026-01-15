// /finances/assets/ap.js
// Accounts Payable Module

(function() {
  'use strict';

  let suppliers = [];
  let accounts = [];

  // DOM Elements
  const tabButtons = document.querySelectorAll('.fw-finance__tab');
  const manualBillForm = document.getElementById('manualBillForm');
  const supplierSelect = document.getElementById('supplierId');
  const expenseAccountSelect = document.getElementById('expenseAccount');
  const billAmountInput = document.getElementById('billAmount');
  const vatAmountInput = document.getElementById('vatAmount');
  const totalAmountInput = document.getElementById('totalAmount');

  // Load Suppliers
  async function loadSuppliers() {
    const result = await FinanceAPI.request('/finances/ajax/ap_supplier_list.php');
    if (result.ok) {
      suppliers = result.data;
      populateSupplierSelect();
    }
  }

  // Populate Supplier Select
  function populateSupplierSelect() {
    let html = '<option value="">Select Supplier</option>';
    suppliers.forEach(s => {
      html += `<option value="${s.id}">${s.name}</option>`;
    });
    supplierSelect.innerHTML = html;
  }

  // Load Accounts
  async function loadAccounts() {
    const result = await FinanceAPI.request('/finances/ajax/account_list.php');
    if (result.ok) {
      accounts = result.data.filter(a => a.is_active == 1 && a.account_type === 'expense');
      populateAccountSelect();
    }
  }

  // Populate Account Select
  function populateAccountSelect() {
    let html = '<option value="">Select Account</option>';
    accounts.forEach(a => {
      html += `<option value="${a.account_id}">${a.account_code} - ${a.account_name}</option>`;
    });
    expenseAccountSelect.innerHTML = html;
  }

  // Calculate Total
  function calculateTotal() {
    const amount = parseFloat(billAmountInput.value) || 0;
    const vat = parseFloat(vatAmountInput.value) || 0;
    totalAmountInput.value = (amount + vat).toFixed(2);
  }

  // Submit Manual Bill
  async function submitManualBill(e) {
    e.preventDefault();

    const data = {
      supplier_id: supplierSelect.value,
      bill_date: document.getElementById('billDate').value,
      invoice_number: document.getElementById('invoiceNumber').value,
      due_date: document.getElementById('dueDate').value,
      expense_account_id: expenseAccountSelect.value,
      amount: parseFloat(billAmountInput.value) || 0,
      vat_amount: parseFloat(vatAmountInput.value) || 0,
      description: document.getElementById('description').value
    };

    if (!data.supplier_id || !data.expense_account_id || data.amount <= 0) {
      showMessage('billFormMessage', 'Please fill in all required fields', 'error');
      return;
    }

    const result = await FinanceAPI.request('/finances/ajax/ap_post_bill.php', 'POST', data);

    if (result.ok) {
      showMessage('billFormMessage', 'Bill posted to GL successfully!', 'success');
      manualBillForm.reset();
      document.getElementById('billDate').value = new Date().toISOString().split('T')[0];
      calculateTotal();
      
      setTimeout(() => {
        window.location.href = '/finances/journals.php';
      }, 1500);
    } else {
      showMessage('billFormMessage', result.error || 'Failed to post bill', 'error');
    }
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

      if (tab === 'manual') {
        document.getElementById('manualPanel').classList.add('fw-finance__tab-panel--active');
      } else if (tab === 'receipts') {
        document.getElementById('receiptsPanel').classList.add('fw-finance__tab-panel--active');
      }
    });
  });

  // Event Listeners
  if (billAmountInput) {
    billAmountInput.addEventListener('input', calculateTotal);
  }

  if (vatAmountInput) {
    vatAmountInput.addEventListener('input', calculateTotal);
  }

  if (manualBillForm) {
    manualBillForm.addEventListener('submit', submitManualBill);
  }

  // Initialize
  document.addEventListener('DOMContentLoaded', () => {
    loadSuppliers();
    loadAccounts();
  });

})();