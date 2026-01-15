(function() {
  'use strict';

  let uploadedFile = null;
  let parsedData = null;
  let columnMapping = {};
  let importType = 'accounts';

  // ========== STEP NAVIGATION ==========
  window.goToStep = function(step) {
    // Hide all panels
    for (let i = 1; i <= 4; i++) {
      document.getElementById('step' + i).style.display = 'none';
      const stepEl = document.querySelector(`[data-step="${i}"]`);
      stepEl.classList.remove('fw-crm__import-step--active', 'fw-crm__import-step--completed');
    }

    // Show target panel
    document.getElementById('step' + step).style.display = 'block';
    document.querySelector(`[data-step="${step}"]`).classList.add('fw-crm__import-step--active');

    // Mark previous steps as completed
    for (let i = 1; i < step; i++) {
      document.querySelector(`[data-step="${i}"]`).classList.add('fw-crm__import-step--completed');
    }
  };

  // ========== TAB SWITCHING ==========
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

  // ========== FILE UPLOAD ==========
  const fileInput = document.getElementById('fileInput');
  const fileUploadZone = document.getElementById('fileUploadZone');
  const fileInfo = document.getElementById('fileInfo');
  const importTypeSelect = document.getElementById('importType');
  const nextToStep2Btn = document.getElementById('nextToStep2');

  fileUploadZone.addEventListener('click', () => fileInput.click());

  fileUploadZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    fileUploadZone.style.borderColor = 'var(--accent-crm)';
  });

  fileUploadZone.addEventListener('dragleave', () => {
    fileUploadZone.style.borderColor = 'var(--fw-border)';
  });

  fileUploadZone.addEventListener('drop', (e) => {
    e.preventDefault();
    fileUploadZone.style.borderColor = 'var(--fw-border)';
    const file = e.dataTransfer.files[0];
    handleFile(file);
  });

  fileInput.addEventListener('change', (e) => {
    const file = e.target.files[0];
    handleFile(file);
  });

  importTypeSelect.addEventListener('change', (e) => {
    importType = e.target.value;
  });

  function handleFile(file) {
    if (!file) return;

    const validTypes = ['text/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    const validExtensions = ['.csv', '.xlsx'];
    const fileExtension = '.' + file.name.split('.').pop().toLowerCase();

    if (!validTypes.includes(file.type) && !validExtensions.includes(fileExtension)) {
      alert('Please upload a CSV or XLSX file');
      return;
    }

    if (file.size > 10 * 1024 * 1024) {
      alert('File size must be less than 10MB');
      return;
    }

    uploadedFile = file;
    importType = importTypeSelect.value;

    fileInfo.innerHTML = `
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <div>
          <strong>${file.name}</strong><br>
          <small>${(file.size / 1024).toFixed(2)} KB • ${importType}</small>
        </div>
        <button type="button" class="fw-crm__btn fw-crm__btn--small fw-crm__btn--danger" onclick="clearFile()">
          Remove
        </button>
      </div>
    `;
    fileInfo.style.display = 'block';

    // Parse file
    parseFile(file);
  }

  window.clearFile = function() {
    uploadedFile = null;
    parsedData = null;
    fileInput.value = '';
    fileInfo.style.display = 'none';
    nextToStep2Btn.disabled = true;
  };

  function parseFile(file) {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('type', importType);

    fetch('/crm/ajax/import_parse.php', {
      method: 'POST',
      body: formData
    })
    .then(res => res.json())
    .then(data => {
      if (data.ok) {
        parsedData = data;
        nextToStep2Btn.disabled = false;
      } else {
        alert('Error parsing file: ' + (data.error || 'Unknown error'));
        clearFile();
      }
    })
    .catch(err => {
      alert('Network error');
      console.error(err);
      clearFile();
    });
  }

  // ========== STEP 2: COLUMN MAPPING ==========
  nextToStep2Btn.addEventListener('click', () => {
    if (!parsedData) return;
    buildColumnMapping();
    goToStep(2);
  });

  function buildColumnMapping() {
    const container = document.getElementById('columnMappingContainer');
    const fileColumns = parsedData.headers;
    
    const fieldMap = {
      accounts: [
        { value: 'name', label: 'Name *', required: true },
        { value: 'legal_name', label: 'Legal Name' },
        { value: 'reg_no', label: 'Registration Number' },
        { value: 'vat_no', label: 'VAT Number' },
        { value: 'email', label: 'Email' },
        { value: 'phone', label: 'Phone' },
        { value: 'website', label: 'Website' },
        { value: 'status', label: 'Status (active/inactive/prospect)' },
        { value: 'industry', label: 'Industry' },
        { value: 'region', label: 'Region' },
        { value: 'notes', label: 'Notes' }
      ],
      contacts: [
        { value: 'account_name', label: 'Account Name *', required: true },
        { value: 'first_name', label: 'First Name' },
        { value: 'last_name', label: 'Last Name' },
        { value: 'role_title', label: 'Role/Title' },
        { value: 'email', label: 'Email' },
        { value: 'phone', label: 'Phone' },
        { value: 'is_primary', label: 'Is Primary (1/0)' }
      ],
      addresses: [
        { value: 'account_name', label: 'Account Name *', required: true },
        { value: 'type', label: 'Type (billing/shipping/site/head_office)' },
        { value: 'line1', label: 'Address Line 1 *', required: true },
        { value: 'line2', label: 'Address Line 2' },
        { value: 'city', label: 'City' },
        { value: 'region', label: 'Province' },
        { value: 'postal_code', label: 'Postal Code' },
        { value: 'country', label: 'Country Code (ZA)' }
      ]
    };

    const fields = fieldMap[importType] || fieldMap.accounts;

    let html = '';
    fileColumns.forEach((col, index) => {
      // Try to auto-match
      const normalized = col.toLowerCase().replace(/[^a-z0-9]/g, '');
      let suggestedField = '';
      
      fields.forEach(field => {
        const fieldNormalized = field.value.toLowerCase().replace(/[^a-z0-9]/g, '');
        if (normalized === fieldNormalized || normalized.includes(fieldNormalized) || fieldNormalized.includes(normalized)) {
          suggestedField = field.value;
        }
      });

      html += `
        <div class="fw-crm__mapping-row">
          <div>
            <strong>${col}</strong><br>
            <small>${parsedData.sample_data[0][index] || 'No data'}</small>
          </div>
          <div class="fw-crm__mapping-arrow">→</div>
          <div>
            <select class="fw-crm__input" data-file-column="${col}">
              <option value="">Skip this column</option>
              ${fields.map(f => `
                <option value="${f.value}" ${suggestedField === f.value ? 'selected' : ''}>
                  ${f.label}
                </option>
              `).join('')}
            </select>
          </div>
        </div>
      `;
    });

    container.innerHTML = html;

    // Store initial mapping
    document.querySelectorAll('#columnMappingContainer select').forEach(select => {
      columnMapping[select.dataset.fileColumn] = select.value;
      select.addEventListener('change', (e) => {
        columnMapping[e.target.dataset.fileColumn] = e.target.value;
      });
    });
  }

  // ========== STEP 3: PREVIEW ==========
  document.getElementById('nextToStep3').addEventListener('click', () => {
    // Validate required fields are mapped
    const requiredFields = importType === 'accounts' ? ['name'] : ['account_name'];
    const mappedFields = Object.values(columnMapping).filter(v => v);
    
    const missingRequired = requiredFields.filter(req => !mappedFields.includes(req));
    if (missingRequired.length > 0) {
      alert('Please map required fields: ' + missingRequired.join(', '));
      return;
    }

    buildPreview();
    goToStep(3);
  });

  function buildPreview() {
    const container = document.getElementById('previewContainer');
    
    // Get mapped fields only
    const mappedColumns = Object.entries(columnMapping).filter(([k, v]) => v);
    
    if (mappedColumns.length === 0) {
      container.innerHTML = '<div class="fw-crm__empty-state">No columns mapped</div>';
      return;
    }

    let html = '<div class="fw-crm__preview-table"><table><thead><tr>';
    
    mappedColumns.forEach(([fileCol, crmField]) => {
      html += `<th>${crmField}</th>`;
    });
    html += '</tr></thead><tbody>';

    // Show first 10 rows
    const previewRows = parsedData.sample_data.slice(0, 10);
    const headers = parsedData.headers;

    previewRows.forEach(row => {
      html += '<tr>';
      mappedColumns.forEach(([fileCol, crmField]) => {
        const colIndex = headers.indexOf(fileCol);
        const value = row[colIndex] || '';
        html += `<td>${value}</td>`;
      });
      html += '</tr>';
    });

    html += '</tbody></table></div>';
    html += `<p class="fw-crm__help-text" style="margin-top:var(--fw-spacing-md);">
      Showing first 10 rows of ${parsedData.total_rows} total rows
    </p>`;

    container.innerHTML = html;
  }

  // ========== STEP 4: IMPORT ==========
  document.getElementById('nextToStep4').addEventListener('click', () => {
    goToStep(4);
  });

  document.getElementById('startImportBtn').addEventListener('click', () => {
    startImport();
  });

  async function startImport() {
    const dryRun = document.getElementById('dryRun').checked;
    const skipDuplicates = document.getElementById('skipDuplicates').checked;
    const startBtn = document.getElementById('startImportBtn');
    const backBtn = document.getElementById('backToStep3');
    const progressDiv = document.getElementById('importProgress');
    const resultsDiv = document.getElementById('importResults');

    startBtn.disabled = true;
    backBtn.disabled = true;
    progressDiv.style.display = 'block';
    resultsDiv.style.display = 'none';

    const formData = new FormData();
    formData.append('file', uploadedFile);
    formData.append('type', importType);
    formData.append('mapping', JSON.stringify(columnMapping));
    formData.append('dry_run', dryRun ? '1' : '0');
    formData.append('skip_duplicates', skipDuplicates ? '1' : '0');

    try {
      const response = await fetch('/crm/ajax/import_execute.php', {
        method: 'POST',
        body: formData
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
              updateProgress(data.progress, data.message);
            }

            if (data.complete) {
              showResults(data);
              startBtn.style.display = 'none';
              document.getElementById('startOverBtn').style.display = 'inline-block';
            }
          } catch (e) {
            console.error('Parse error:', e);
          }
        });
      }

    } catch (err) {
      alert('Import failed: ' + err.message);
      console.error(err);
      startBtn.disabled = false;
      backBtn.disabled = false;
    }
  }

  function updateProgress(percent, message) {
    const fill = document.getElementById('progressFill');
    const text = document.getElementById('progressText');

    fill.style.width = percent + '%';
    fill.textContent = Math.round(percent) + '%';
    text.textContent = message;
  }

  function showResults(data) {
    const resultsDiv = document.getElementById('importResults');
    
    const html = `
      <div class="fw-crm__results-summary">
        <div class="fw-crm__result-stat">
          <span class="fw-crm__result-stat-value">${data.total}</span>
          <span class="fw-crm__result-stat-label">Total Rows</span>
        </div>
        <div class="fw-crm__result-stat fw-crm__result-stat--success">
          <span class="fw-crm__result-stat-value">${data.successful}</span>
          <span class="fw-crm__result-stat-label">Successful</span>
        </div>
        <div class="fw-crm__result-stat fw-crm__result-stat--error">
          <span class="fw-crm__result-stat-value">${data.failed}</span>
          <span class="fw-crm__result-stat-label">Failed</span>
        </div>
      </div>
      ${data.dry_run ? '<div class="fw-crm__badge fw-crm__badge--pending" style="display:inline-block; margin-bottom:var(--fw-spacing-md);">DRY RUN - No data was actually imported</div>' : ''}
      ${data.errors && data.errors.length > 0 ? `
        <details style="margin-top:var(--fw-spacing-md);">
          <summary style="cursor:pointer; font-weight:600; color:var(--accent-danger);">
            View ${data.errors.length} Error(s)
          </summary>
          <pre style="margin-top:var(--fw-spacing-sm); padding:var(--fw-spacing-sm); background:var(--fw-bg-base); border-radius:var(--fw-radius-sm); overflow:auto; max-height:200px; font-size:12px;">${data.errors.join('\n')}</pre>
        </details>
      ` : ''}
    `;

    resultsDiv.innerHTML = html;
    resultsDiv.style.display = 'block';
  }

  // ========== DOWNLOAD TEMPLATE ==========
  window.downloadTemplate = function() {
    const type = importTypeSelect.value;
    window.location.href = '/crm/ajax/import_template.php?type=' + type;
  };

  // ========== EXPORT ==========
  window.startExport = function() {
    const type = document.getElementById('exportType').value;
    const format = document.getElementById('exportFormat').value;
    const includeInactive = document.getElementById('includeInactive').checked ? '1' : '0';

    const url = `/crm/ajax/export.php?type=${type}&format=${format}&include_inactive=${includeInactive}`;
    window.location.href = url;
  };

})();