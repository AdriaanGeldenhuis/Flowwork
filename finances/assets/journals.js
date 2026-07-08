// /finances/assets/journals.js
// Journal Entry Management — Production Build

(function() {
  'use strict';

  let journals = [];
  let accounts = [];
  let currentPage = 1;
  let totalPages = 1;
  let totalEntries = 0;
  let currentFilters = {
    dateFrom: '',
    dateTo: '',
    module: '',
    status: '',
    search: ''
  };

  // DOM Elements
  const journalList    = document.getElementById('journalList');
  const filterDateFrom = document.getElementById('filterDateFrom');
  const filterDateTo   = document.getElementById('filterDateTo');
  const filterModule   = document.getElementById('filterModule');
  const filterStatus   = document.getElementById('filterStatus');
  const searchInput    = document.getElementById('searchInput');
  const addJournalBtn  = document.getElementById('addJournalBtn');
  const journalForm    = document.getElementById('journalForm');
  const modalClose     = document.getElementById('modalClose');
  const cancelBtn      = document.getElementById('cancelBtn');
  const addLineBtn     = document.getElementById('addLineBtn');
  const linesContainer = document.getElementById('linesContainer');
  const journalCount   = document.getElementById('journalCount');
  const pagination     = document.getElementById('pagination');

  let lineCounter = 0;

  // ─── Helpers ────────────────────────────────────────────────────────

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
  }

  function statusLabel(status) {
    const map = {
      draft:    'Draft',
      approved: 'Approved',
      posted:   'Posted'
    };
    return map[status] || status || 'Unknown';
  }

  function statusClass(status) {
    const map = {
      draft:    'draft',
      approved: 'approved',
      posted:   'posted'
    };
    return map[status] || 'draft';
  }

  // Friendly labels for the module buckets actually written to journal_entries.
  // Unknown values fall back to a title-cased version of the raw code.
  const MODULE_LABELS = {
    manual:     'Manual',
    fin:        'System / Finance',
    payroll:    'Payroll',
    bad_debt:   'Bad Debt',
    vat_settle: 'VAT Settlement',
    vat_adjust: 'VAT Adjustment',
    year_end:   'Year-End Close'
  };

  function moduleLabel(code) {
    if (MODULE_LABELS[code]) return MODULE_LABELS[code];
    return String(code || '')
      .replace(/[_-]+/g, ' ')
      .replace(/\b\w/g, c => c.toUpperCase());
  }

  let moduleFilterPopulated = false;

  // Populate the module filter from the modules actually present in the data,
  // preserving the current selection. Only runs once (the set is company-wide).
  function populateModuleFilter(modules) {
    if (moduleFilterPopulated || !filterModule || !Array.isArray(modules)) return;
    const current = filterModule.value;
    let html = '<option value="">All Modules</option>';
    modules.forEach(code => {
      if (!code) return;
      html += `<option value="${escapeHtml(code)}">${escapeHtml(moduleLabel(code))}</option>`;
    });
    filterModule.innerHTML = html;
    if (current) filterModule.value = current;
    moduleFilterPopulated = true;
  }

  // ─── Load Data ──────────────────────────────────────────────────────

  async function loadJournals() {
    journalList.innerHTML = '<div class="fw-finance__loading">Loading journal entries...</div>';

    const params = new URLSearchParams(currentFilters);
    params.set('page', currentPage);
    params.set('per_page', 25);

    const result = await FinanceAPI.request(`/finances/ajax/journal_list.php?${params}`);

    if (result && result.ok) {
      journals     = result.data;
      totalPages   = result.total_pages || 1;
      totalEntries = result.total || 0;
      currentPage  = result.page || 1;
      populateModuleFilter(result.modules);
      renderJournals();
      renderPagination();
      updateJournalCount();
    } else {
      journalList.innerHTML = '<div class="fw-finance__empty-state">Failed to load journals</div>';
    }
  }

  async function loadAccounts() {
    const result = await FinanceAPI.request('/finances/ajax/account_list.php');
    if (result && result.ok) {
      accounts = result.data.filter(a => a.is_active == 1 && a.allow_manual_journal == 1);
    }
  }

  // ─── Render Journal Cards ──────────────────────────────────────────

  function renderJournals() {
    if (journals.length === 0) {
      journalList.innerHTML = `
        <div class="fw-finance__empty-state">
          <div style="font-size:32px;margin-bottom:12px">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:0.4">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
              <polyline points="14 2 14 8 20 8"/>
              <line x1="16" y1="13" x2="8" y2="13"/>
              <line x1="16" y1="17" x2="8" y2="17"/>
              <polyline points="10 9 9 9 8 9"/>
            </svg>
          </div>
          No journal entries found
        </div>`;
      return;
    }

    const html = journals.map(journal => {
      const totalCents = parseInt(journal.total_debits_cents, 10) || 0;
      const createdBy = [journal.created_first_name, journal.created_last_name].filter(Boolean).join(' ');

      return `
        <div class="fw-finance__journal-card" data-id="${journal.journal_id}">
          <div class="fw-finance__journal-header">
            <span class="fw-finance__journal-date">${formatDate(journal.entry_date)}</span>
            <div class="fw-finance__journal-badges">
              ${journal.module ? `<span class="fw-finance__journal-module">${escapeHtml(journal.module)}</span>` : ''}
              ${journal.reversed_by_journal_id ? `<span class="fw-finance__journal-status fw-finance__journal-status--reversed">Reversed</span>` : ''}
              ${journal.reverses_journal_id ? `<span class="fw-finance__journal-status fw-finance__journal-status--reversal">Reversal</span>` : ''}
              <span class="fw-finance__journal-status fw-finance__journal-status--${statusClass(journal.status)}">${statusLabel(journal.status)}</span>
            </div>
          </div>
          <div class="fw-finance__journal-memo">${escapeHtml(journal.memo) || '<span style="opacity:0.5">No memo</span>'}</div>
          ${journal.reference ? `<div class="fw-finance__journal-ref">Ref: ${escapeHtml(journal.reference)}</div>` : ''}
          <div class="fw-finance__journal-summary">
            <span class="fw-finance__journal-meta">${journal.line_count || 0} line(s)${createdBy ? ' &middot; ' + escapeHtml(createdBy) : ''}</span>
            <span class="fw-finance__journal-amount">${formatCurrency(totalCents)}</span>
          </div>
        </div>`;
    }).join('');

    journalList.innerHTML = html;
    attachCardListeners();
  }

  function attachCardListeners() {
    document.querySelectorAll('.fw-finance__journal-card').forEach(card => {
      card.addEventListener('click', () => viewJournal(card.dataset.id));
    });
  }

  // ─── Pagination ────────────────────────────────────────────────────

  function renderPagination() {
    if (totalPages <= 1) {
      pagination.innerHTML = '';
      return;
    }

    let html = '';
    html += `<button class="fw-finance__page-btn" ${currentPage <= 1 ? 'disabled' : ''} data-page="${currentPage - 1}">&laquo; Prev</button>`;
    html += `<span class="fw-finance__page-info">Page ${currentPage} of ${totalPages}</span>`;
    html += `<button class="fw-finance__page-btn" ${currentPage >= totalPages ? 'disabled' : ''} data-page="${currentPage + 1}">Next &raquo;</button>`;

    pagination.innerHTML = html;

    pagination.querySelectorAll('.fw-finance__page-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const page = parseInt(btn.dataset.page, 10);
        if (page >= 1 && page <= totalPages) {
          currentPage = page;
          loadJournals();
        }
      });
    });
  }

  function updateJournalCount() {
    journalCount.textContent = `${totalEntries} entr${totalEntries !== 1 ? 'ies' : 'y'}`;
  }

  // ─── View Journal (Detail Modal) ──────────────────────────────────

  async function viewJournal(journalId) {
    const result = await FinanceAPI.request(`/finances/ajax/journal_get.php?journal_id=${journalId}`);
    if (!result || !result.ok) {
      // The modal is still hidden here, so #viewMessage would be invisible.
      // Surface the failure through the always-visible toast channel instead.
      FIN3D.toast(result?.error || 'Failed to load journal', 'error');
      return;
    }

    const j = result.data;
    const lines = j.lines || [];
    const createdBy = [j.first_name, j.last_name].filter(Boolean).join(' ') || 'Unknown';

    // Header info
    document.getElementById('viewModalTitle').textContent = `Journal #${j.journal_id}`;
    document.getElementById('viewHeader').innerHTML = `
      <div class="fw-finance__view-grid">
        <div class="fw-finance__view-field">
          <div class="fw-finance__view-label">Date</div>
          <div class="fw-finance__view-value">${formatDate(j.entry_date)}</div>
        </div>
        <div class="fw-finance__view-field">
          <div class="fw-finance__view-label">Status</div>
          <div class="fw-finance__view-value">
            <span class="fw-finance__journal-status fw-finance__journal-status--${statusClass(j.status)}">${statusLabel(j.status)}</span>
          </div>
        </div>
        <div class="fw-finance__view-field">
          <div class="fw-finance__view-label">Reference</div>
          <div class="fw-finance__view-value">${escapeHtml(j.reference) || '-'}</div>
        </div>
        <div class="fw-finance__view-field">
          <div class="fw-finance__view-label">Module</div>
          <div class="fw-finance__view-value">${escapeHtml(j.module) || '-'}</div>
        </div>
        ${j.source_link ? `
        <div class="fw-finance__view-field">
          <div class="fw-finance__view-label">Source Document</div>
          <div class="fw-finance__view-value"><a href="${escapeHtml(j.source_link.url)}">${escapeHtml(j.source_link.label)}</a></div>
        </div>` : ''}
        <div class="fw-finance__view-field" style="grid-column: 1 / -1">
          <div class="fw-finance__view-label">Memo</div>
          <div class="fw-finance__view-value">${escapeHtml(j.memo) || '-'}</div>
        </div>
        <div class="fw-finance__view-field">
          <div class="fw-finance__view-label">Created By</div>
          <div class="fw-finance__view-value">${escapeHtml(createdBy)}</div>
        </div>
        <div class="fw-finance__view-field">
          <div class="fw-finance__view-label">Created At</div>
          <div class="fw-finance__view-value">${formatDate(j.created_at)}</div>
        </div>
        ${j.approved_at ? `
        <div class="fw-finance__view-field">
          <div class="fw-finance__view-label">Approved At</div>
          <div class="fw-finance__view-value">${formatDate(j.approved_at)}</div>
        </div>` : ''}
        ${j.posted_at ? `
        <div class="fw-finance__view-field">
          <div class="fw-finance__view-label">Posted At</div>
          <div class="fw-finance__view-value">${formatDate(j.posted_at)}</div>
        </div>` : ''}
        ${j.reversed_by_journal_id ? `
        <div class="fw-finance__view-field" style="grid-column: 1 / -1">
          <div class="fw-finance__view-label" style="color:#ef4444">Reversed</div>
          <div class="fw-finance__view-value" style="color:#ef4444">This journal was reversed by Journal #${j.reversed_by_journal_id}</div>
        </div>` : ''}
        ${j.reverses_journal_id ? `
        <div class="fw-finance__view-field" style="grid-column: 1 / -1">
          <div class="fw-finance__view-label" style="color:#f59e0b">Reversal Of</div>
          <div class="fw-finance__view-value">This journal reverses Journal #${j.reverses_journal_id}</div>
        </div>` : ''}
      </div>
    `;

    // Lines table
    let totalDebits = 0;
    let totalCredits = 0;
    const linesHtml = lines.map(line => {
      const debit = parseFloat(line.debit) || 0;
      const credit = parseFloat(line.credit) || 0;
      totalDebits += debit;
      totalCredits += credit;
      return `
        <tr>
          <td><strong>${escapeHtml(line.account_code)}</strong>${line.account_name ? ' — ' + escapeHtml(line.account_name) : ''}</td>
          <td>${escapeHtml(line.description) || '-'}</td>
          <td style="text-align:right;font-family:'Courier New',monospace">${debit > 0 ? formatCurrency(Math.round(debit * 100)) : ''}</td>
          <td style="text-align:right;font-family:'Courier New',monospace">${credit > 0 ? formatCurrency(Math.round(credit * 100)) : ''}</td>
        </tr>`;
    }).join('');

    document.getElementById('viewLinesBody').innerHTML = linesHtml;
    document.getElementById('viewLinesTotals').innerHTML = `
      <tr style="font-weight:700;border-top:2px solid var(--fw-border-strong)">
        <td colspan="2" style="text-align:right">Totals</td>
        <td style="text-align:right;font-family:'Courier New',monospace">${formatCurrency(Math.round(totalDebits * 100))}</td>
        <td style="text-align:right;font-family:'Courier New',monospace">${formatCurrency(Math.round(totalCredits * 100))}</td>
      </tr>
    `;

    // Footer action buttons
    const footer = document.getElementById('viewModalFooter');
    let buttons = `<button type="button" class="fw-finance__btn fw-finance__btn--secondary" id="viewCloseBtn">Close</button>`;

    if (j.status === 'draft') {
      buttons += `
        <button type="button" class="fw-finance__btn fw-finance__btn--danger fw-finance__btn--small" id="viewDeleteBtn" data-id="${j.journal_id}">Delete</button>
        <button type="button" class="fw-finance__btn fw-finance__btn--secondary" id="viewEditBtn" data-id="${j.journal_id}">Edit</button>
        <button type="button" class="fw-finance__btn fw-finance__btn--primary" id="viewApproveBtn" data-id="${j.journal_id}">Approve</button>
      `;
    } else if (j.status === 'approved') {
      buttons += `
        <button type="button" class="fw-finance__btn fw-finance__btn--secondary" id="viewUnapproveBtn" data-id="${j.journal_id}">Unapprove</button>
        <button type="button" class="fw-finance__btn fw-finance__btn--primary" id="viewPostBtn" data-id="${j.journal_id}">Post to GL</button>
      `;
    } else if (j.status === 'posted' && !j.reversed_by_journal_id) {
      buttons += `
        <button type="button" class="fw-finance__btn fw-finance__btn--danger" id="viewReverseBtn" data-id="${j.journal_id}">Reverse</button>
      `;
    }

    footer.innerHTML = buttons;

    // Attach footer events
    document.getElementById('viewCloseBtn').addEventListener('click', () => FinanceModal.close('viewModal'));

    const editBtn = document.getElementById('viewEditBtn');
    if (editBtn) editBtn.addEventListener('click', () => {
      FinanceModal.close('viewModal');
      editJournal(editBtn.dataset.id);
    });

    const approveBtn = document.getElementById('viewApproveBtn');
    if (approveBtn) approveBtn.addEventListener('click', () => approveJournal(approveBtn.dataset.id));

    const unapproveBtn = document.getElementById('viewUnapproveBtn');
    if (unapproveBtn) unapproveBtn.addEventListener('click', () => unapproveJournal(unapproveBtn.dataset.id));

    const postBtn = document.getElementById('viewPostBtn');
    if (postBtn) postBtn.addEventListener('click', () => postJournal(postBtn.dataset.id));

    const deleteBtn = document.getElementById('viewDeleteBtn');
    if (deleteBtn) deleteBtn.addEventListener('click', () => deleteJournal(deleteBtn.dataset.id));

    const reverseBtn = document.getElementById('viewReverseBtn');
    if (reverseBtn) reverseBtn.addEventListener('click', () => reverseJournal(reverseBtn.dataset.id));

    FinanceModal.open('viewModal');
  }

  // ─── Approve / Post / Delete / Reverse ──────────────────────────────

  function disableActionButtons() {
    document.querySelectorAll('#viewModalFooter .fw-finance__btn').forEach(btn => {
      btn.disabled = true;
    });
  }

  // Re-enable the footer buttons after a failed action so the user can fix the
  // problem and retry without having to close and reopen the modal.
  function reEnableActionButtons() {
    document.querySelectorAll('#viewModalFooter .fw-finance__btn').forEach(btn => {
      btn.disabled = false;
    });
  }

  async function approveJournal(journalId) {
    disableActionButtons();
    const result = await FinanceAPI.request('/finances/ajax/journal_approve.php', 'POST', { journal_id: parseInt(journalId, 10) });
    if (result && result.ok) {
      showMessage('viewMessage', 'Journal approved successfully', 'success');
      setTimeout(() => {
        FinanceModal.close('viewModal');
        loadJournals();
      }, 800);
    } else {
      reEnableActionButtons();
      showMessage('viewMessage', result?.error || 'Failed to approve', 'error');
    }
  }

  async function unapproveJournal(journalId) {
    if (!confirm('Send this approved journal back to draft for editing?')) return;
    disableActionButtons();
    const result = await FinanceAPI.request('/finances/ajax/journal_unapprove.php', 'POST', { journal_id: parseInt(journalId, 10) });
    if (result && result.ok) {
      showMessage('viewMessage', 'Journal returned to draft', 'success');
      setTimeout(() => {
        FinanceModal.close('viewModal');
        loadJournals();
      }, 800);
    } else {
      reEnableActionButtons();
      showMessage('viewMessage', result?.error || 'Failed to unapprove', 'error');
    }
  }

  async function postJournal(journalId) {
    disableActionButtons();
    const result = await FinanceAPI.request('/finances/ajax/journal_post.php', 'POST', { journal_id: parseInt(journalId, 10) });
    if (result && result.ok) {
      showMessage('viewMessage', 'Journal posted to GL successfully', 'success');
      setTimeout(() => {
        FinanceModal.close('viewModal');
        loadJournals();
      }, 800);
    } else {
      reEnableActionButtons();
      showMessage('viewMessage', result?.error || 'Failed to post', 'error');
    }
  }

  async function deleteJournal(journalId) {
    if (!confirm('Delete this draft journal? This cannot be undone.')) return;
    disableActionButtons();
    const result = await FinanceAPI.request('/finances/ajax/journal_delete.php', 'POST', { journal_id: parseInt(journalId, 10) });
    if (result && result.ok) {
      FinanceModal.close('viewModal');
      loadJournals();
    } else {
      reEnableActionButtons();
      showMessage('viewMessage', result?.error || 'Failed to delete', 'error');
    }
  }

  async function reverseJournal(journalId) {
    const reason = prompt('Reason for reversal (required for SARS audit trail):');
    if (reason === null) return; // cancelled
    if (!reason.trim()) {
      showMessage('viewMessage', 'A reason is required for SARS compliance', 'error');
      return;
    }
    disableActionButtons();
    const result = await FinanceAPI.request('/finances/ajax/journal_reverse.php', 'POST', {
      journal_id: parseInt(journalId, 10),
      reason: reason.trim()
    });
    if (result && result.ok) {
      showMessage('viewMessage', 'Journal reversed successfully (Reversal #' + result.data.reversal_journal_id + ')', 'success');
      setTimeout(() => {
        FinanceModal.close('viewModal');
        loadJournals();
      }, 1200);
    } else {
      reEnableActionButtons();
      showMessage('viewMessage', result?.error || 'Failed to reverse journal', 'error');
    }
  }

  // ─── Edit Journal ──────────────────────────────────────────────────

  async function editJournal(journalId) {
    const result = await FinanceAPI.request(`/finances/ajax/journal_get.php?journal_id=${journalId}`);
    if (!result || !result.ok) return;

    const j = result.data;
    document.getElementById('modalTitle').textContent = 'Edit Journal Entry';
    document.getElementById('journalId').value = j.journal_id;
    document.getElementById('entryDate').value = j.entry_date;
    document.getElementById('reference').value = j.reference || '';
    document.getElementById('memo').value = j.memo || '';

    // Populate lines
    linesContainer.innerHTML = '';
    lineCounter = 0;
    (j.lines || []).forEach(line => {
      addJournalLine({
        account_code: line.account_code,
        description: line.description || '',
        debit: parseFloat(line.debit) || '',
        credit: parseFloat(line.credit) || '',
        // Carry the analytic tags through the edit round-trip. The modal does
        // not surface them, but journal_save.php deletes and re-inserts every
        // line, so without this they would be silently reset to NULL.
        tax_code_id: line.tax_code_id,
        project_id: line.project_id,
        board_id: line.board_id,
        item_id: line.item_id
      });
    });

    if ((j.lines || []).length < 2) {
      while (linesContainer.children.length < 2) addJournalLine();
    }

    updateBalance();
    FinanceModal.open('journalModal');
  }

  // ─── Add Modal ─────────────────────────────────────────────────────

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

  // ─── Journal Lines ─────────────────────────────────────────────────

  function addJournalLine(data) {
    data = data || {};
    lineCounter++;

    const line = document.createElement('div');
    line.className = 'fw-finance__journal-line';
    line.dataset.lineId = lineCounter;

    // Stash analytic tags on the row so an edit preserves them on save even
    // though there is no input for them in the modal.
    if (data.tax_code_id) line.dataset.taxCodeId = data.tax_code_id;
    if (data.project_id)  line.dataset.projectId = data.project_id;
    if (data.board_id)    line.dataset.boardId   = data.board_id;
    if (data.item_id)     line.dataset.itemId    = data.item_id;

    line.innerHTML = `
      <div class="fw-finance__line-col fw-finance__line-col--account">
        <select class="fw-finance__input line-account" required>
          <option value="">Select Account</option>
          ${accounts.map(a => `
            <option value="${escapeHtml(a.account_code)}" ${data.account_code == a.account_code ? 'selected' : ''}>
              ${escapeHtml(a.account_code)} — ${escapeHtml(a.account_name)}
            </option>
          `).join('')}
        </select>
      </div>
      <div class="fw-finance__line-col fw-finance__line-col--desc">
        <input type="text" class="fw-finance__input line-description" placeholder="Description" value="${escapeHtml(String(data.description || ''))}">
      </div>
      <div class="fw-finance__line-col fw-finance__line-col--debit">
        <input type="number" class="fw-finance__input line-debit" placeholder="0.00" step="0.01" min="0" value="${data.debit || ''}">
      </div>
      <div class="fw-finance__line-col fw-finance__line-col--credit">
        <input type="number" class="fw-finance__input line-credit" placeholder="0.00" step="0.01" min="0" value="${data.credit || ''}">
      </div>
      <div class="fw-finance__line-col fw-finance__line-col--action">
        <button type="button" class="fw-finance__line-remove" title="Remove line">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
            <path d="M18 6L6 18M6 6l12 12"/>
          </svg>
        </button>
      </div>
    `;

    linesContainer.appendChild(line);

    line.querySelector('.fw-finance__line-remove').addEventListener('click', () => {
      line.remove();
      updateBalance();
    });

    line.querySelectorAll('.line-debit, .line-credit').forEach(input => {
      // Single listener: clear the opposite field FIRST (a programmatic
      // .value = '' fires no 'input' event), then recompute the balance so the
      // totals never reflect a debit and a credit on the same line at once.
      input.addEventListener('input', (e) => {
        const isDebit = e.target.classList.contains('line-debit');
        const opposite = isDebit ?
          line.querySelector('.line-credit') :
          line.querySelector('.line-debit');
        if (e.target.value && opposite.value) {
          opposite.value = '';
        }
        updateBalance();
      });
    });
  }

  // ─── Balance ───────────────────────────────────────────────────────

  function updateBalance() {
    let totalDebits = 0;
    let totalCredits = 0;

    linesContainer.querySelectorAll('.fw-finance__journal-line').forEach(line => {
      totalDebits  += parseFloat(line.querySelector('.line-debit').value) || 0;
      totalCredits += parseFloat(line.querySelector('.line-credit').value) || 0;
    });

    const debitsCents  = Math.round(totalDebits * 100);
    const creditsCents = Math.round(totalCredits * 100);
    const difference   = Math.abs(debitsCents - creditsCents);

    document.getElementById('totalDebits').textContent  = formatCurrency(debitsCents);
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

    const saveBtn = document.getElementById('saveBtn');
    if (saveBtn) {
      saveBtn.disabled = difference !== 0 || debitsCents === 0;
    }
  }

  // ─── Save Journal ──────────────────────────────────────────────────

  async function saveJournal(e) {
    e.preventDefault();

    const lines = [];
    linesContainer.querySelectorAll('.fw-finance__journal-line').forEach(line => {
      const accountCode = line.querySelector('.line-account').value;
      const description = line.querySelector('.line-description').value.trim();
      const debit  = parseFloat(line.querySelector('.line-debit').value) || 0;
      const credit = parseFloat(line.querySelector('.line-credit').value) || 0;

      if (accountCode && (debit > 0 || credit > 0)) {
        const entry = {
          account_code: accountCode,
          description: description,
          debit: Math.round(debit * 100) / 100,
          credit: Math.round(credit * 100) / 100
        };
        // Preserve analytic tags stashed on the row during an edit.
        if (line.dataset.taxCodeId) entry.tax_code_id = parseInt(line.dataset.taxCodeId, 10);
        if (line.dataset.projectId) entry.project_id  = parseInt(line.dataset.projectId, 10);
        if (line.dataset.boardId)   entry.board_id    = parseInt(line.dataset.boardId, 10);
        if (line.dataset.itemId)    entry.item_id     = parseInt(line.dataset.itemId, 10);
        lines.push(entry);
      }
    });

    if (lines.length < 2) {
      showMessage('formMessage', 'Journal must have at least 2 lines', 'error');
      return;
    }

    const totalDebits  = lines.reduce((sum, l) => sum + Math.round(l.debit * 100), 0);
    const totalCredits = lines.reduce((sum, l) => sum + Math.round(l.credit * 100), 0);

    if (totalDebits !== totalCredits) {
      showMessage('formMessage', 'Journal is not balanced', 'error');
      return;
    }

    const formData = {
      journal_id: document.getElementById('journalId').value || null,
      entry_date: document.getElementById('entryDate').value,
      reference:  document.getElementById('reference').value.trim(),
      memo:       document.getElementById('memo').value.trim(),
      lines:      lines
    };

    const result = await FinanceAPI.request('/finances/ajax/journal_save.php', 'POST', formData);

    if (result && result.ok) {
      showMessage('formMessage', 'Journal saved as draft', 'success');
      setTimeout(() => {
        FinanceModal.close('journalModal');
        loadJournals();
      }, 800);
    } else {
      showMessage('formMessage', result?.error || 'Failed to save journal', 'error');
    }
  }

  // ─── Event Listeners ──────────────────────────────────────────────

  if (filterDateFrom) {
    filterDateFrom.addEventListener('change', (e) => {
      currentFilters.dateFrom = e.target.value;
      currentPage = 1;
      loadJournals();
    });
  }

  if (filterDateTo) {
    filterDateTo.addEventListener('change', (e) => {
      currentFilters.dateTo = e.target.value;
      currentPage = 1;
      loadJournals();
    });
  }

  if (filterModule) {
    filterModule.addEventListener('change', (e) => {
      currentFilters.module = e.target.value;
      currentPage = 1;
      loadJournals();
    });
  }

  if (filterStatus) {
    filterStatus.addEventListener('change', (e) => {
      currentFilters.status = e.target.value;
      currentPage = 1;
      loadJournals();
    });
  }

  if (searchInput) {
    searchInput.addEventListener('input', debounce((e) => {
      currentFilters.search = e.target.value;
      currentPage = 1;
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

  const viewModalClose = document.getElementById('viewModalClose');
  if (viewModalClose) {
    viewModalClose.addEventListener('click', () => FinanceModal.close('viewModal'));
  }

  // ─── Modal dismissal: Escape + backdrop click ──────────────────────
  // Scoped to this page's two overlays; the shared FinanceModal contract in
  // finance.js is intentionally left untouched.
  ['journalModal', 'viewModal'].forEach(id => {
    const overlay = document.getElementById(id);
    if (!overlay) return;
    let downOnBackdrop = false;
    overlay.addEventListener('mousedown', (e) => { downOnBackdrop = (e.target === overlay); });
    overlay.addEventListener('click', (e) => {
      if (downOnBackdrop && e.target === overlay) FinanceModal.close(id);
      downOnBackdrop = false;
    });
  });

  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    ['viewModal', 'journalModal'].forEach(id => {
      const overlay = document.getElementById(id);
      if (overlay && overlay.getAttribute('aria-hidden') === 'false') {
        FinanceModal.close(id);
      }
    });
  });

  // ─── Initialize ───────────────────────────────────────────────────

  document.addEventListener('DOMContentLoaded', () => {
    loadAccounts().then(() => {
      loadJournals();
      // Deep link: /finances/journals.php?id=N (legacy ?jid=N also accepted)
      // opens that journal directly in the detail modal, so links from other
      // modules (Fixed Assets, GL Detail, dashboard) resolve to this page.
      const params = new URLSearchParams(window.location.search);
      const deepId = params.get('id') || params.get('jid');
      if (deepId && /^\d+$/.test(deepId)) {
        viewJournal(deepId);
      }
    });
  });

})();
