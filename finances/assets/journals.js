// /finances/assets/journals.js
// Journal Entry Management

(function() {
  'use strict';

  let journals = [];
  let accounts = [];
  let currentFilters = {
    dateFrom: '',
    dateTo: '',
    module: '',
    search: ''
  };

  // DOM Elements
  const journalList = document.getElementById('journalList');
  const filterDateFrom = document.getElementById('filterDateFrom');
  const filterDateTo = document.getElementById('filterDateTo');
  const filterModule = document.getElementById('filterModule');
  const searchInput = document.getElementById('searchInput');
  const addJournalBtn = document.getElementById('addJournalBtn');
  const journalModal = document.getElementById('journalModal');
  const journalForm = document.getElementById('journalForm');
  const modalClose = document.getElementById('modalClose');
  const cancelBtn = document.getElementById('cancelBtn');
  const addLineBtn = document.getElementById('addLineBtn');
  const linesContainer = document.getElementById('linesContainer');
  const journalCount = document.getElementById('journalCount');

  let lineCounter = 0;

  // Load Journals
  async function loadJournals() {
    journalList.innerHTML = '<div class="fw-finance__loading">Loading journal entries...</div>';

    const params = new URLSearchParams(currentFilters);
    const result = await FinanceAPI.request(`/finances/ajax/journal_list.php?${params}`);
    
    if (result.ok) {
      journals = result.data;
      renderJournals();
      updateJournalCount();
    } else {
      journalList.innerHTML = '<div class="fw-finance__empty-state">Failed to load journals</div>';
    }
  }

  // Load Accounts for Dropdown
  async function loadAccounts() {
    const result = await FinanceAPI.request('/finances/ajax/account_list.php');
    
    if (result.ok) {
      accounts = result.data.filter(a => a.is_active == 1);
    }
  }

  // Render Journals
  function renderJournals() {
    if (journals.length === 0) {
      journalList.innerHTML = '<div class="fw-finance__empty-state">No journal entries found</div>';
      return;
    }

    const html = journals.map(journal => `
      <div class="fw-finance__journal-card" data-id="${journal.journal_id}">
        <div class="fw-finance__journal-header">
          <span class="fw-finance__journal-date">${formatDate(journal.entry_date)}</span>
          ${journal.module ? `<span class="fw-finance__journal-module">${journal.module}</span>` : ''}
        </div>
        <div class="fw-finance__journal-memo">${journal.memo || 'No memo'}</div>
        <div class="fw-finance__journal-summary">
          <span>${journal.line_count || 0} line(s)</span>
          <span>${formatCurrency(journal.total_debits || 0)}</span>
        </div>
      </div>
    `).join('');

    journalList.innerHTML = html;
    attachJournalEventListeners();
  }

  // Attach Event Listeners
  function attachJournalEventListeners() {
    document.querySelectorAll('.fw-finance__journal-card').forEach(card => {
      card.addEventListener('click', () => {
        const journalId = card.dataset.id;
        viewJournal(journalId);
      });
    });
  }

  // Update Journal Count
  function updateJournalCount() {
    journalCount.textContent = `${journals.length} entr${journals.length !== 1 ? 'ies' : 'y'}`;
  }

  // Open Add Modal
  function openAddModal() {
    document.getElementById('modalTitle').textContent = 'New Journal Entry';
    journalForm.reset();
    document.getElementById('journalId').value = '';
    document.getElementById('entryDate').value = new Date().toISOString().split('T')[0];
    
    linesContainer.innerHTML = '';
    lineCounter = 0;
    addJournalLine();
    addJournalLine();
    
    updateBalance();
    FinanceModal.open('journalModal');
  }

  // View Journal (read-only for now)
  async function viewJournal(journalId) {
    const result = await FinanceAPI.request(`/finances/ajax/journal_get.php?journal_id=${journalId}`);
    
    if (result.ok) {
      alert('Journal view coming soon!\n\n' + JSON.stringify(result.data, null, 2));
    }
  }

  // Add Journal Line
  function addJournalLine(data = {}) {
    lineCounter++;
    
    const line = document.createElement('div');
    line.className = 'fw-finance__journal-line';
    line.dataset.lineId = lineCounter;
    
    line.innerHTML = `
      <div class="fw-finance__line-col fw-finance__line-col--account">
        <select class="line-account" required>
          <option value="">Select Account</option>
          ${accounts.map(a => `
            <option value="${a.account_id}" ${data.account_id == a.account_id ? 'selected' : ''}>
              ${a.account_code} - ${a.account_name}
            </option>
          `).join('')}
        </select>
      </div>
      <div class="fw-finance__line-col fw-finance__line-col--desc">
        <input type="text" class="line-description" placeholder="Description" value="${data.description || ''}">
      </div>
      <div class="fw-finance__line-col fw-finance__line-col--debit">
        <input type="number" class="line-debit" placeholder="0.00" step="0.01" min="0" value="${data.debit || ''}">
      </div>
      <div class="fw-finance__line-col fw-finance__line-col--credit">
        <input type="number" class="line-credit" placeholder="0.00" step="0.01" min="0" value="${data.credit || ''}">
      </div>
      <div class="fw-finance__line-col fw-finance__line-col--action">
        <button type="button" class="fw-finance__line-remove" title="Remove line">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M18 6L6 18M6 6l12 12"/>
          </svg>
        </button>
      </div>
    `;

    linesContainer.appendChild(line);

    // Attach events
    line.querySelector('.line-remove').addEventListener('click', () => {
      line.remove();
      updateBalance();
    });

    line.querySelectorAll('.line-debit, .line-credit').forEach(input => {
      input.addEventListener('input', updateBalance);
      input.addEventListener('input', (e) => {
        // Clear opposite field
        const isDebit = e.target.classList.contains('line-debit');
        const opposite = isDebit ? 
          line.querySelector('.line-credit') : 
          line.querySelector('.line-debit');
        
        if (e.target.value && opposite.value) {
          opposite.value = '';
        }
      });
    });
  }

  // Update Balance
  function updateBalance() {
    let totalDebits = 0;
    let totalCredits = 0;

    linesContainer.querySelectorAll('.fw-finance__journal-line').forEach(line => {
      const debit = parseFloat(line.querySelector('.line-debit').value) || 0;
      const credit = parseFloat(line.querySelector('.line-credit').value) || 0;
      
      totalDebits += debit;
      totalCredits += credit;
    });

    const debitsCents = Math.round(totalDebits * 100);
    const creditsCents = Math.round(totalCredits * 100);
    const difference = Math.abs(debitsCents - creditsCents);

    document.getElementById('totalDebits').textContent = formatCurrency(debitsCents);
    document.getElementById('totalCredits').textContent = formatCurrency(creditsCents);
    
    const diffElement = document.getElementById('difference');
    diffElement.textContent = formatCurrency(difference);
    
    if (difference === 0 && debitsCents > 0) {
      diffElement.classList.add('fw-finance__balance-value--balanced');
      diffElement.classList.remove('fw-finance__balance-value--unbalanced');
    } else {
      diffElement.classList.add('fw-finance__balance-value--unbalanced');
      diffElement.classList.remove('fw-finance__balance-value--balanced');
    }

    // Enable/disable save button
    const saveBtn = document.getElementById('saveBtn');
    if (saveBtn) {
      saveBtn.disabled = difference !== 0 || debitsCents === 0;
    }
  }

  // Save Journal
  async function saveJournal(e) {
    e.preventDefault();

    const lines = [];
    linesContainer.querySelectorAll('.fw-finance__journal-line').forEach(line => {
      const accountId = line.querySelector('.line-account').value;
      const description = line.querySelector('.line-description').value.trim();
      const debit = parseFloat(line.querySelector('.line-debit').value) || 0;
      const credit = parseFloat(line.querySelector('.line-credit').value) || 0;

      if (accountId && (debit > 0 || credit > 0)) {
        lines.push({
          account_id: accountId,
          description: description,
          debit_cents: Math.round(debit * 100),
          credit_cents: Math.round(credit * 100)
        });
      }
    });

    if (lines.length < 2) {
      showMessage('formMessage', 'Journal must have at least 2 lines', 'error');
      return;
    }

    const totalDebits = lines.reduce((sum, l) => sum + l.debit_cents, 0);
    const totalCredits = lines.reduce((sum, l) => sum + l.credit_cents, 0);

    if (totalDebits !== totalCredits) {
      showMessage('formMessage', 'Journal is not balanced', 'error');
      return;
    }

    const formData = {
      journal_id: document.getElementById('journalId').value || null,
      entry_date: document.getElementById('entryDate').value,
      reference: document.getElementById('reference').value.trim(),
      memo: document.getElementById('memo').value.trim(),
      lines: lines
    };

    const result = await FinanceAPI.request('/finances/ajax/journal_post.php', 'POST', formData);

    if (result.ok) {
      showMessage('formMessage', 'Journal posted successfully', 'success');
      setTimeout(() => {
        FinanceModal.close('journalModal');
        loadJournals();
      }, 1000);
    } else {
      showMessage('formMessage', result.error || 'Failed to post journal', 'error');
    }
  }

  // Event Listeners
  if (filterDateFrom) {
    filterDateFrom.addEventListener('change', (e) => {
      currentFilters.dateFrom = e.target.value;
      loadJournals();
    });
  }

  if (filterDateTo) {
    filterDateTo.addEventListener('change', (e) => {
      currentFilters.dateTo = e.target.value;
      loadJournals();
    });
  }

  if (filterModule) {
    filterModule.addEventListener('change', (e) => {
      currentFilters.module = e.target.value;
      loadJournals();
    });
  }

  if (searchInput) {
    searchInput.addEventListener('input', debounce((e) => {
      currentFilters.search = e.target.value;
      loadJournals();
    }, 300));
  }

  if (addJournalBtn) {
    addJournalBtn.addEventListener('click', openAddModal);
  }

  if (addLineBtn) {
    addLineBtn.addEventListener('click', () => addJournalLine());
  }

  if (journalForm) {
    journalForm.addEventListener('submit', saveJournal);
  }

  if (modalClose) {
    modalClose.addEventListener('click', () => FinanceModal.close('journalModal'));
  }

  if (cancelBtn) {
    cancelBtn.addEventListener('click', () => FinanceModal.close('journalModal'));
  }

  // Initialize
  document.addEventListener('DOMContentLoaded', () => {
    loadAccounts().then(() => {
      loadJournals();
    });
  });

})();