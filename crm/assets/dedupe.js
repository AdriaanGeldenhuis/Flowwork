(function() {
  'use strict';

  // Tab switching (reuse from main)
  const tabs = document.querySelectorAll('.fw-crm__view-tab');
  const panels = document.querySelectorAll('.fw-crm__tab-panel');

  tabs.forEach(tab => {
    tab.addEventListener('click', () => {
      const target = tab.dataset.tab;
      
      tabs.forEach(t => t.classList.remove('fw-crm__view-tab--active'));
      panels.forEach(p => p.classList.remove('fw-crm__tab-panel--active'));
      
      tab.classList.add('fw-crm__view-tab--active');
      document.querySelector(`[data-panel="${target}"]`).classList.add('fw-crm__tab-panel--active');
    });
  });

  // ========== DISMISS CANDIDATE ==========
  window.dismissCandidate = async function(candidateId) {
    if (!confirm('Mark this as not a duplicate? This cannot be undone.')) return;

    try {
      const res = await fetch('/crm/ajax/dedupe_dismiss.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: candidateId})
      });

      const data = await res.json();

      if (data.ok) {
        document.querySelector(`[data-candidate-id="${candidateId}"]`).remove();
        
        // Check if list is empty
        const list = document.querySelector('.fw-crm__dedupe-list');
        if (list && list.children.length === 0) {
          list.innerHTML = '<div class="fw-crm__empty-state">No more duplicate candidates</div>';
        }
      } else {
        alert(data.error || 'Failed to dismiss candidate');
      }
    } catch (err) {
      alert('Network error');
      console.error(err);
    }
  };

  // ========== SCAN FOR DUPLICATES ==========
  window.startScan = async function() {
    const scanBtn = document.getElementById('startScanBtn');
    const progressDiv = document.getElementById('scanProgress');
    const resultsDiv = document.getElementById('scanResults');
    const scanType = document.getElementById('scanType').value;
    const clearExisting = document.getElementById('clearExisting').checked;

    scanBtn.disabled = true;
    progressDiv.style.display = 'block';
    resultsDiv.style.display = 'none';

    try {
      const response = await fetch('/crm/ajax/dedupe_scan.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          type: scanType,
          clear_existing: clearExisting
        })
      });

      const reader = response.body.getReader();
      const decoder = new TextDecoder();

      while (true) {
        const { done, value } = await reader.read();
        if (done) break;

        const chunk = decoder.decode(value);
        const lines = chunk.split('\n');

        lines.forEach(line => {
          if (!line.trim()) return;

          try {
            const data = JSON.parse(line);

            if (data.progress !== undefined) {
              updateScanProgress(data.progress, data.message);
            }

            if (data.complete) {
              showScanResults(data);
              scanBtn.disabled = false;
            }
          } catch (e) {
            console.error('Parse error:', e);
          }
        });
      }

    } catch (err) {
      alert('Scan failed: ' + err.message);
      console.error(err);
      scanBtn.disabled = false;
    }
  };

  function updateScanProgress(percent, message) {
    const fill = document.getElementById('scanProgressFill');
    const text = document.getElementById('scanProgressText');

    fill.style.width = percent + '%';
    fill.textContent = Math.round(percent) + '%';
    text.textContent = message;
  }

  function showScanResults(data) {
    const resultsDiv = document.getElementById('scanResults');
    
    const html = `
      <div class="fw-crm__info-card" style="background:var(--fw-highlight);">
        <h3 style="margin:0 0 var(--fw-spacing-md) 0;">Scan Results</h3>
        <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:var(--fw-spacing-md);">
          <div style="text-align:center;">
            <div style="font-size:32px; font-weight:700; color:var(--accent-crm);">${data.accounts_scanned}</div>
            <div style="font-size:13px; color:var(--fw-text-muted);">Accounts Scanned</div>
          </div>
          <div style="text-align:center;">
            <div style="font-size:32px; font-weight:700; color:#f59e0b;">${data.candidates_found}</div>
            <div style="font-size:13px; color:var(--fw-text-muted);">Duplicates Found</div>
          </div>
          <div style="text-align:center;">
            <div style="font-size:32px; font-weight:700; color:#10b981;">${data.candidates_added}</div>
            <div style="font-size:13px; color:var(--fw-text-muted);">New Candidates</div>
          </div>
        </div>
        ${data.candidates_added > 0 ? `
          <div style="margin-top:var(--fw-spacing-md); text-align:center;">
            <button type="button" class="fw-crm__btn fw-crm__btn--primary" onclick="viewCandidates()">
              View Candidates
            </button>
          </div>
        ` : ''}
      </div>
    `;

    resultsDiv.innerHTML = html;
    resultsDiv.style.display = 'block';
  }

  window.viewCandidates = function() {
    document.querySelector('[data-tab="candidates"]').click();
    location.reload();
  };

  // ========== MERGE MODAL ==========
  let leftAccount = null;
  let rightAccount = null;
  let candidateId = null;
  const selectedFields = {};

  window.openMergeModal = async function(leftId, rightId, candId) {
    leftAccount = null;
    rightAccount = null;
    candidateId = candId;

    CRMModal.open('mergeModal');

    const body = document.getElementById('mergeModalBody');
    body.innerHTML = '<div class="fw-crm__loading">Loading accounts...</div>';

    try {
      const res = await fetch(`/crm/ajax/dedupe_get_accounts.php?left=${leftId}&right=${rightId}`);
      const data = await res.json();

      if (data.ok) {
        leftAccount = data.left;
        rightAccount = data.right;
        renderMergeUI();
      } else {
        body.innerHTML = '<div class="fw-crm__empty-state">Error: ' + (data.error || 'Failed to load accounts') + '</div>';
      }
    } catch (err) {
      body.innerHTML = '<div class="fw-crm__empty-state">Network error</div>';
      console.error(err);
    }
  };

  function renderMergeUI() {
    const body = document.getElementById('mergeModalBody');
    
    const fields = [
      'name', 'legal_name', 'reg_no', 'vat_no', 'email', 'phone', 
      'website', 'industry_id', 'region_id', 'status', 'notes'
    ];

    let html = `
      <p style="margin-bottom:var(--fw-spacing-lg); color:var(--fw-text-muted);">
        Click on a field to select which value to keep. The left account will be kept as the master record.
      </p>
      <div class="fw-crm__merge-grid">
        <div class="fw-crm__merge-column">
          <div class="fw-crm__merge-column-header">
            ${leftAccount.name}
            <span class="fw-crm__badge fw-crm__badge--primary" style="margin-left:8px;">Master</span>
          </div>
          <div id="leftFields"></div>
        </div>

        <div class="fw-crm__merge-arrow">→</div>

        <div class="fw-crm__merge-column">
          <div class="fw-crm__merge-column-header">${rightAccount.name}</div>
          <div id="rightFields"></div>
        </div>
      </div>
    `;

    body.innerHTML = html;

    const leftFieldsContainer = document.getElementById('leftFields');
    const rightFieldsContainer = document.getElementById('rightFields');

    fields.forEach(field => {
      const leftValue = leftAccount[field] || '';
      const rightValue = rightAccount[field] || '';
      
      // Default to left (master)
      selectedFields[field] = 'left';

      const fieldLabel = field.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());

      // Left field
      const leftFieldEl = document.createElement('div');
      leftFieldEl.className = 'fw-crm__merge-field fw-crm__merge-field--selected' + (!leftValue ? ' fw-crm__merge-field--empty' : '');
      leftFieldEl.dataset.field = field;
      leftFieldEl.dataset.side = 'left';
      leftFieldEl.innerHTML = `
        <div class="fw-crm__merge-field-label">${fieldLabel}</div>
        <div class="fw-crm__merge-field-value">${leftValue || '(empty)'}</div>
      `;
      leftFieldEl.addEventListener('click', () => selectField(field, 'left'));
      leftFieldsContainer.appendChild(leftFieldEl);

      // Right field
      const rightFieldEl = document.createElement('div');
      rightFieldEl.className = 'fw-crm__merge-field' + (!rightValue ? ' fw-crm__merge-field--empty' : '');
      rightFieldEl.dataset.field = field;
      rightFieldEl.dataset.side = 'right';
      rightFieldEl.innerHTML = `
        <div class="fw-crm__merge-field-label">${fieldLabel}</div>
        <div class="fw-crm__merge-field-value">${rightValue || '(empty)'}</div>
      `;
      rightFieldEl.addEventListener('click', () => selectField(field, 'right'));
      rightFieldsContainer.appendChild(rightFieldEl);
    });

    document.getElementById('executeMergeBtn').style.display = 'inline-block';
  }

  function selectField(field, side) {
    selectedFields[field] = side;

    // Update UI
    document.querySelectorAll(`[data-field="${field}"]`).forEach(el => {
      el.classList.remove('fw-crm__merge-field--selected');
    });

    document.querySelector(`[data-field="${field}"][data-side="${side}"]`).classList.add('fw-crm__merge-field--selected');
  }

  document.getElementById('executeMergeBtn').addEventListener('click', async () => {
    if (!confirm('Are you sure you want to merge these accounts? This cannot be undone.')) return;

    const btn = document.getElementById('executeMergeBtn');
    btn.disabled = true;
    btn.textContent = 'Merging...';

    try {
      const res = await fetch('/crm/ajax/dedupe_merge.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          left_id: leftAccount.id,
          right_id: rightAccount.id,
          candidate_id: candidateId,
          selected_fields: selectedFields
        })
      });

      const data = await res.json();

      if (data.ok) {
        alert('Accounts merged successfully!');
        location.reload();
      } else {
        alert('Merge failed: ' + (data.error || 'Unknown error'));
        btn.disabled = false;
        btn.textContent = 'Merge Accounts';
      }
    } catch (err) {
      alert('Network error');
      console.error(err);
      btn.disabled = false;
      btn.textContent = 'Merge Accounts';
    }
  });

})();