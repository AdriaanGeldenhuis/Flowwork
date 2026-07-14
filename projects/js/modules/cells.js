/**
 * Cell Editing Module - COMPLETE ALL TYPES
 */

(() => {
  'use strict';

  if (!window.BoardApp) {
    console.error('❌ BoardApp not initialized');
    return;
  }

  // ===== MAIN EDIT CELL FUNCTION =====
  window.BoardApp.editCell = function(itemId, columnId, columnType, event) {
    if (!event) return;

    // As die gebruiker op 'n anchor tag gekliek het (link/email/phone),
    // laat die browser die link volg in plaas van die edit modal open
    if (event.target.closest('a')) {
      return;
    }

    // Already editing this cell inline — let the input keep focus
    if (event.target.closest && event.target.closest('.fw-cell-inline-input')) {
      return;
    }

    if (event.stopPropagation) event.stopPropagation();

    const cellElement = event.currentTarget || (event.target.closest && event.target.closest('.fw-cell'));
    if (!cellElement) return;
    
    console.log('📝 Edit cell:', { itemId, columnId, columnType });

    // Close any existing modals (never remove server-rendered .fw-static-modal
    // overlays like #modalGuests — they must survive for their open button)
    document.querySelectorAll('.fw-modal-overlay, .fw-cell-picker-overlay:not(.fw-static-modal)').forEach(el => el.remove());
    
    switch (columnType) {
      case 'text':
        inlineEdit(itemId, columnId, cellElement, 'text');
        break;
      case 'longtext':
        inlineEdit(itemId, columnId, cellElement, 'longtext');
        break;
      case 'number':
        inlineEdit(itemId, columnId, cellElement, 'number');
        break;
      case 'status':
        editStatus(itemId, columnId, cellElement);
        break;
      case 'people':
        editPeople(itemId, columnId, cellElement);
        break;
      case 'date':
        inlineEdit(itemId, columnId, cellElement, 'date');
        break;
      case 'priority':
        editPriority(itemId, columnId, cellElement);
        break;
      case 'supplier':
        editSupplier(itemId, columnId, cellElement);
        break;
      case 'dropdown':
        editDropdown(itemId, columnId, cellElement);
        break;
      case 'timeline':
        editTimeline(itemId, columnId, cellElement);
        break;
      case 'checkbox':
        editCheckbox(itemId, columnId, cellElement);
        break;
      case 'tags':
        editTags(itemId, columnId, cellElement);
        break;
      case 'link':
        editLink(itemId, columnId, cellElement);
        break;
      case 'email':
        editEmail(itemId, columnId, cellElement);
        break;
      case 'phone':
        editPhone(itemId, columnId, cellElement);
        break;
      case 'progress':
        editProgress(itemId, columnId, cellElement);
        break;
      case 'files':
        editFiles(itemId, columnId, cellElement);
        break;
      case 'formula':
        alert('Formula columns are calculated automatically. Edit the formula in column settings.');
        break;
      default:
        console.warn('Unknown column type:', columnType);
        alert(`Editor for ${columnType} not yet implemented`);
    }
  };
  
  // ===== INLINE EDITOR (text / longtext / number / date) =====
  // Swaps an input directly into the cell — no modal. Seeded from the raw
  // value in valuesMap (never from the formatted textContent, which used to
  // feed "R 1,234.56" into number inputs). Enter commits, Escape restores,
  // Tab commits and moves to the next editable cell in the row.
  const INLINE_TYPES = ['text', 'longtext', 'number', 'date'];

  function rawCellValue(itemId, columnId, cellElement) {
    const fromMap = (window.BOARD_DATA.valuesMap[itemId] || {})[columnId];
    if (fromMap !== undefined && fromMap !== null) return String(fromMap);
    // Item-field-backed cells (e.g. Due Date fallback) carry their resolved
    // value in data-value, rendered by board.php
    return cellElement.dataset.value || '';
  }

  function inlineEdit(itemId, columnId, cellElement, kind) {
    if (cellElement.querySelector('.fw-cell-inline-input')) return;

    let currentValue = rawCellValue(itemId, columnId, cellElement);
    if (kind === 'date') currentValue = currentValue.split(' ')[0];

    const originalHtml = cellElement.innerHTML;

    const input = document.createElement(kind === 'longtext' ? 'textarea' : 'input');
    if (kind !== 'longtext') input.type = kind; // text | number | date
    if (kind === 'number') input.step = 'any';
    if (kind === 'longtext') input.rows = 3;
    input.className = 'fw-cell-inline-input';
    // "done" instead of the default "next" so mobile keyboards commit the edit
    // on Enter rather than jumping focus to the next field.
    if (kind !== 'longtext') input.enterKeyHint = 'done';
    input.value = currentValue;

    cellElement.innerHTML = '';
    cellElement.appendChild(input);
    input.focus();
    if (kind === 'text' || kind === 'number') input.select();

    let settled = false;

    const restore = () => {
      settled = true;
      cellElement.innerHTML = originalHtml;
    };

    const commit = (openNextCell) => {
      if (settled) return;
      const value = kind === 'longtext' || kind === 'text' ? input.value.trim() : input.value;
      restore();
      if (value !== currentValue) {
        window.BoardApp.saveCellValue(itemId, columnId, value);
      }
      if (openNextCell) focusNextEditableCell(cellElement, itemId);
    };

    input.addEventListener('keydown', (e) => {
      e.stopPropagation(); // keep global shortcuts (Escape/Delete/…) out of the edit
      if (e.key === 'Enter' && !(kind === 'longtext' && e.shiftKey)) {
        e.preventDefault();
        commit(false);
      } else if (e.key === 'Escape') {
        restore();
      } else if (e.key === 'Tab') {
        e.preventDefault();
        commit(true);
      }
    });

    input.addEventListener('blur', () => {
      // Give keydown commits a tick to settle first
      setTimeout(() => { if (!settled) commit(false); }, 0);
    });

    input.addEventListener('click', (e) => e.stopPropagation());
  }

  function focusNextEditableCell(cellElement, itemId) {
    let next = cellElement.nextElementSibling;
    while (next) {
      if (next.classList.contains('fw-cell')) {
        const type = next.dataset.type;
        const colId = parseInt(next.dataset.columnId, 10);
        if (INLINE_TYPES.includes(type)) {
          inlineEdit(itemId, colId, next, type);
          return;
        }
        if (type && type !== 'formula') {
          // Picker types open their modal editor
          window.BoardApp.editCell(itemId, colId, type, {
            currentTarget: next,
            target: next,
            stopPropagation() {}
          });
          return;
        }
      }
      next = next.nextElementSibling;
    }
  }

  // ===== STATUS EDITOR =====
  function editStatus(itemId, columnId, cellElement) {
    const statuses = window.BOARD_DATA.statusConfig || {
      'todo': { label: 'To Do', color: '#64748b' },
      'working': { label: 'Working', color: '#fdab3d' },
      'stuck': { label: 'Stuck', color: '#e2445c' },
      'done': { label: 'Done', color: '#00c875' }
    };
    
    // Compare against the raw stored key, not the rendered label text
    const currentValue = (cellElement.dataset.value || cellElement.textContent.trim().toLowerCase());

    const options = Object.keys(statuses).map(key => {
      const status = statuses[key];
      return `
        <button class="fw-picker-option ${currentValue === key ? 'active' : ''}" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, '${key}')">
          <span style="width:20px;height:20px;border-radius:50%;background:${status.color};flex-shrink:0;"></span>
          <span style="flex:1;font-weight:600;">${status.label}</span>
          ${currentValue === key ? '<span style="color:var(--accent-primary);">✓</span>' : ''}
        </button>
      `;
    }).join('');
    
    createModal('Select Status', `<div class="fw-picker-options">${options}</div>`);
  }

  // ===== PEOPLE EDITOR =====
  function editPeople(itemId, columnId, cellElement) {
    const users = window.BOARD_DATA.users || [];
    const currentUserId = cellElement.dataset.value || '';

    if (users.length === 0) {
      alert('No users available');
      return;
    }

    const options = `
      <button class="fw-picker-option ${!currentUserId ? 'active' : ''}" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, '')" data-name="Unassigned">
        <div class="fw-avatar-sm" style="background:#64748b;flex-shrink:0;">?</div>
        <span style="flex:1;font-weight:600;">Unassigned</span>
        ${!currentUserId ? '<span style="color:var(--accent-primary);">✓</span>' : ''}
      </button>
      ${users.map(u => `
        <button class="fw-picker-option ${u.id == currentUserId ? 'active' : ''}" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, ${u.id})" data-name="${esc(u.first_name + ' ' + u.last_name)}">
          <div class="fw-avatar-sm" style="flex-shrink:0;">${esc(String(u.first_name).charAt(0))}${esc(String(u.last_name).charAt(0))}</div>
          <span style="flex:1;font-weight:600;">${esc(u.first_name + ' ' + u.last_name)}</span>
          ${u.id == currentUserId ? '<span style="color:var(--accent-primary);">✓</span>' : ''}
        </button>
      `).join('')}
    `;
    
    createModal('Assign Person', `
      <div class="fw-picker-search">
        <input type="text" class="fw-picker-search-input" placeholder="🔍 Search..." id="peopleSearchInput" oninput="window.filterPickerOptions(this.value)" />
      </div>
      <div class="fw-picker-options" id="peopleOptionsList">${options}</div>
    `);
    
    setTimeout(() => document.getElementById('peopleSearchInput')?.focus(), 100);
  }

  // ===== PRIORITY EDITOR =====
  function editPriority(itemId, columnId, cellElement) {
    const priorities = [
      { value: 'low', label: 'Low', color: '#10b981' },
      { value: 'medium', label: 'Medium', color: '#fdab3d' },
      { value: 'high', label: 'High', color: '#f97316' },
      { value: 'critical', label: 'Critical', color: '#ef4444' }
    ];
    
    const currentValue = cellElement.querySelector('.fw-priority-pill')?.dataset.value || '';
    
    const options = priorities.map(p => `
      <button class="fw-picker-option ${currentValue === p.value ? 'active' : ''}" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, '${p.value}')">
        <span class="fw-priority-dot" style="background:${p.color};width:16px;height:16px;border-radius:50%;flex-shrink:0;"></span>
        <span style="flex:1;font-weight:600;">${p.label}</span>
        ${currentValue === p.value ? '<span style="color:var(--accent-primary);">✓</span>' : ''}
      </button>
    `).join('');
    
    createModal('Select Priority', `<div class="fw-picker-options">${options}</div>`);
  }

  // ===== SUPPLIER EDITOR =====
  function editSupplier(itemId, columnId, cellElement) {
    const suppliers = window.BOARD_DATA.suppliers || [];
    const currentId = cellElement.dataset.value || '';

    if (suppliers.length === 0) {
      alert('No suppliers available. Add suppliers in CRM first.');
      return;
    }

    const options = suppliers.map(s => `
      <button class="fw-picker-option ${s.id == currentId ? 'active' : ''}" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, ${s.id})" data-name="${esc(s.name)}">
        <div style="width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#6c5ce7,#8b5cf6);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:16px;flex-shrink:0;">
          🏢
        </div>
        <div style="flex:1;min-width:0;">
          <div style="font-weight:600;font-size:15px;margin-bottom:2px;">${esc(s.name)}</div>
          ${s.phone || s.email ? `<div style="font-size:12px;color:var(--text-muted);display:flex;gap:12px;flex-wrap:wrap;">
            ${s.phone ? `<span>📞 ${esc(s.phone)}</span>` : ''}
            ${s.email ? `<span>✉️ ${esc(s.email)}</span>` : ''}
          </div>` : ''}
        </div>
        ${s.preferred ? '<div style="font-size:24px;flex-shrink:0;">⭐</div>' : ''}
        ${s.id == currentId ? '<span style="color:var(--accent-primary);">✓</span>' : ''}
      </button>
    `).join('');

    createModal('Select Supplier', `
      <div class="fw-picker-search">
        <input type="text" class="fw-picker-search-input" placeholder="🔍 Search suppliers..." id="supplierSearchInput" oninput="window.filterPickerOptions(this.value)" />
      </div>
      <div class="fw-picker-options" id="supplierOptionsList">
        <button class="fw-picker-option ${!currentId ? 'active' : ''}" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, '')" data-name="None">
          <div style="width:40px;height:40px;border-radius:12px;background:#64748b;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:20px;flex-shrink:0;">?</div>
          <div style="flex:1;"><div style="font-weight:600;font-size:15px;">No Supplier</div></div>
          ${!currentId ? '<span style="color:var(--accent-primary);">✓</span>' : ''}
        </button>
        ${options}
      </div>
    `);
    
    setTimeout(() => document.getElementById('supplierSearchInput')?.focus(), 100);
  }

  // ===== DROPDOWN EDITOR =====
  function editDropdown(itemId, columnId, cellElement) {
    const column = window.BOARD_DATA.columns.find(c => c.column_id == columnId);
    if (!column) return;
    
    const config = safeParseConfig(column.config);
    const options = config.options || ['Option 1', 'Option 2', 'Option 3'];
    const currentValue = cellElement.dataset.value || '';

    // The option value is carried in a data-* attribute (escaped for the
    // attribute context) and read back via this.dataset in the handler, so a
    // user-defined option label can never break out into the onclick JS.
    const optionsHtml = options.map(opt => `
      <button class="fw-picker-option ${currentValue === opt ? 'active' : ''}" data-optval="${esc(opt)}" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, this.dataset.optval)">
        <span style="flex:1;font-weight:600;">${esc(opt)}</span>
        ${currentValue === opt ? '<span style="color:var(--accent-primary);">✓</span>' : ''}
      </button>
    `).join('');
    
    createModal('Select Option', `<div class="fw-picker-options">${optionsHtml}</div>`);
  }

  // ===== TIMELINE EDITOR =====
  function editTimeline(itemId, columnId, cellElement) {
    // Read the raw stored JSON ({start,end}) — NOT the formatted "Jan 5 → Jan 10"
    // pill text, which loses the year and can drop the end date.
    let startDate = '';
    let endDate = '';
    const stored = safeParseConfig(cellElement.dataset.value);
    if (stored && stored.start) startDate = String(stored.start).split('T')[0];
    if (stored && stored.end) endDate = String(stored.end).split('T')[0];

    createModal('Set Timeline', `
      <div style="display:grid;gap:16px;">
        <div>
          <label style="display:block;margin-bottom:8px;font-weight:600;color:var(--text-secondary);">Start Date</label>
          <input type="date" id="timelineStartInput" class="fw-input" value="${startDate}" style="width:100%;" />
        </div>
        <div>
          <label style="display:block;margin-bottom:8px;font-weight:600;color:var(--text-secondary);">End Date</label>
          <input type="date" id="timelineEndInput" class="fw-input" value="${endDate}" style="width:100%;" />
        </div>
      </div>
      <div class="fw-modal-footer">
        <button class="fw-btn fw-btn--secondary" onclick="this.closest('.fw-modal-overlay').remove()">Cancel</button>
        <button class="fw-btn fw-btn--primary" onclick="BoardApp.saveTimelineValue(${itemId}, ${columnId})">Save</button>
      </div>
    `);
  }

  window.BoardApp.saveTimelineValue = function(itemId, columnId) {
    const start = document.getElementById('timelineStartInput').value;
    const end = document.getElementById('timelineEndInput').value;
    
    if (!start || !end) {
      alert('Please select both start and end dates');
      return;
    }
    
    if (new Date(start) > new Date(end)) {
      alert('End date must be after start date');
      return;
    }
    
    const value = JSON.stringify({ start, end });
    window.BoardApp.saveCellValue(itemId, columnId, value);
  };

  // ===== CHECKBOX EDITOR =====
  function editCheckbox(itemId, columnId, cellElement) {
    const checked = cellElement.querySelector('.fw-cell-checkbox--checked') !== null
      || cellElement.textContent.includes('✓')
      || cellElement.textContent.includes('✅');
    const newValue = checked ? '0' : '1';
    window.BoardApp.saveCellValue(itemId, columnId, newValue);
  }

  // ===== TAGS EDITOR =====
  function editTags(itemId, columnId, cellElement) {
    // Raw comma-separated value — the pill text drops the commas between tags.
    const currentValue = cellElement.dataset.value || '';

    createModal('Edit Tags', `
      <input type="text" id="cellTagsInput" class="fw-input" value="${esc(currentValue)}" placeholder="tag1, tag2, tag3" style="width:100%;padding:12px;border:1px solid var(--input-border);border-radius:8px;background:var(--input-bg);color:var(--input-text);font-size:14px;" />
      <p style="font-size:12px;color:var(--text-muted);margin-top:8px;">Separate tags with commas</p>
      <div class="fw-modal-footer">
        <button class="fw-btn fw-btn--secondary" onclick="this.closest('.fw-modal-overlay').remove()">Cancel</button>
        <button class="fw-btn fw-btn--primary" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, document.getElementById('cellTagsInput').value)">Save</button>
      </div>
    `);
    
    setTimeout(() => document.getElementById('cellTagsInput')?.focus(), 100);
  }

  // ===== LINK EDITOR =====
  function editLink(itemId, columnId, cellElement) {
    const currentValue = cellElement.dataset.value || '';

    createModal('Edit Link', `
      <input type="url" id="cellLinkInput" class="fw-input" value="${esc(currentValue)}" placeholder="https://example.com" style="width:100%;padding:12px;border:1px solid var(--input-border);border-radius:8px;background:var(--input-bg);color:var(--input-text);font-size:14px;" />
      <div class="fw-modal-footer">
        <button class="fw-btn fw-btn--secondary" onclick="this.closest('.fw-modal-overlay').remove()">Cancel</button>
        <button class="fw-btn fw-btn--primary" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, document.getElementById('cellLinkInput').value)">Save</button>
      </div>
    `);
    
    setTimeout(() => document.getElementById('cellLinkInput')?.focus(), 100);
  }

  // ===== EMAIL EDITOR =====
  function editEmail(itemId, columnId, cellElement) {
    // Raw address — NOT the "✉️ addr" link text (which carries the emoji prefix).
    const currentValue = cellElement.dataset.value || '';

    createModal('Edit Email', `
      <input type="email" id="cellEmailInput" class="fw-input" value="${esc(currentValue)}" placeholder="email@example.com" style="width:100%;padding:12px;border:1px solid var(--input-border);border-radius:8px;background:var(--input-bg);color:var(--input-text);font-size:14px;" />
      <div class="fw-modal-footer">
        <button class="fw-btn fw-btn--secondary" onclick="this.closest('.fw-modal-overlay').remove()">Cancel</button>
        <button class="fw-btn fw-btn--primary" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, document.getElementById('cellEmailInput').value)">Save</button>
      </div>
    `);
    
    setTimeout(() => document.getElementById('cellEmailInput')?.focus(), 100);
  }

  // ===== PHONE EDITOR =====
  function editPhone(itemId, columnId, cellElement) {
    // Raw number — NOT the "📞 082 123 4567" link text (emoji + reformatting).
    const currentValue = cellElement.dataset.value || '';

    createModal('Edit Phone', `
      <input type="tel" id="cellPhoneInput" class="fw-input" value="${esc(currentValue)}" placeholder="+1 (555) 123-4567" style="width:100%;padding:12px;border:1px solid var(--input-border);border-radius:8px;background:var(--input-bg);color:var(--input-text);font-size:14px;" />
      <div class="fw-modal-footer">
        <button class="fw-btn fw-btn--secondary" onclick="this.closest('.fw-modal-overlay').remove()">Cancel</button>
        <button class="fw-btn fw-btn--primary" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, document.getElementById('cellPhoneInput').value)">Save</button>
      </div>
    `);
    
    setTimeout(() => document.getElementById('cellPhoneInput')?.focus(), 100);
  }

  // ===== PROGRESS EDITOR =====
  function editProgress(itemId, columnId, cellElement) {
    const currentValue = parseInt(cellElement.dataset.value) || 0;
    
    createModal('Set Progress', `
      <div style="padding:20px 0;">
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;">
          <div style="flex:1;height:12px;background:rgba(255,255,255,0.1);border-radius:12px;overflow:hidden;">
            <div id="progressFillPreview" style="height:100%;background:var(--accent-primary);width:${currentValue}%;transition:width 0.2s;"></div>
          </div>
          <div id="progressValueDisplay" style="font-size:20px;font-weight:700;color:var(--accent-primary);min-width:60px;text-align:right;">${currentValue}%</div>
        </div>
        <input type="range" id="progressSlider" min="0" max="100" value="${currentValue}" 
          style="width:100%;height:8px;border-radius:8px;background:rgba(255,255,255,0.1);outline:none;-webkit-appearance:none;"
          oninput="document.getElementById('progressFillPreview').style.width = this.value + '%'; document.getElementById('progressValueDisplay').textContent = this.value + '%';" />
      </div>
      <div class="fw-modal-footer">
        <button class="fw-btn fw-btn--secondary" onclick="this.closest('.fw-modal-overlay').remove()">Cancel</button>
        <button class="fw-btn fw-btn--primary" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, document.getElementById('progressSlider').value)">Save</button>
      </div>
    `);
  }

  // ===== FILES EDITOR =====
  function editFiles(itemId, columnId, cellElement) {
    createModal('Upload Files', `
      <div class="fw-upload-zone" onclick="document.getElementById('fileUploadInput').click()" style="border:2px dashed rgba(255,255,255,0.2);border-radius:12px;padding:40px;text-align:center;cursor:pointer;transition:all 0.2s;">
        <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="2" style="margin:0 auto 16px;opacity:0.5;">
          <path d="M21 15v18m0-9l-6 6m6-6l6 6"/>
          <path d="M9 24v12h30V24"/>
        </svg>
        <p style="font-weight:600;margin-bottom:8px;">Click to upload or drag files here</p>
        <p style="font-size:12px;color:var(--text-muted);">PDF, Images, Documents (Max 10MB)</p>
        <input type="file" id="fileUploadInput" multiple style="display:none;" onchange="BoardApp.handleFileUpload(${itemId}, ${columnId}, this.files)" />
      </div>
      <div id="uploadProgress" style="margin-top:16px;display:none;"></div>
    `);
  }

  // Re-render a files cell's "📎 N files" pill from the current attachment count.
  function renderFilesCell(itemId, columnId) {
    const cell = document.querySelector(`td[data-item-id="${itemId}"][data-column-id="${columnId}"]`);
    if (!cell) return;
    const list = (window.BOARD_DATA.attachments && window.BOARD_DATA.attachments[itemId]) || [];
    const n = list.length;
    if (n > 0) {
      const names = list.map(f => f.file_name || f.filename || f.name || 'file').join('\n');
      cell.innerHTML = `<div class="fw-files-pill" title="${esc(names)}"><span>📎</span><span style="font-weight:600;">${n} file${n > 1 ? 's' : ''}</span></div>`;
    } else {
      cell.innerHTML = '<button class="fw-cell-empty">+</button>';
    }
  }

  window.BoardApp.handleFileUpload = function(itemId, columnId, files) {
    if (!files || !files.length) return;

    const fd = new FormData();
    fd.append('item_id', itemId);
    for (const f of files) fd.append('files[]', f);

    const progress = document.getElementById('uploadProgress');
    if (progress) { progress.style.display = 'block'; progress.textContent = '⏳ Uploading…'; }

    const token = document.querySelector('meta[name="csrf-token"]')?.content
      || (window.BOARD_DATA && window.BOARD_DATA.csrfToken) || '';

    fetch('/projects/api/file.upload.php', {
      method: 'POST',
      headers: { 'X-CSRF-Token': token },
      body: fd
    })
      .then(r => r.json())
      .then(data => {
        if (!data.ok) throw new Error(data.error || 'Upload failed');

        // Update the local attachment cache so the cell (and re-renders) match.
        window.BOARD_DATA.attachments = window.BOARD_DATA.attachments || {};
        const list = window.BOARD_DATA.attachments[itemId] = window.BOARD_DATA.attachments[itemId] || [];
        (data.uploaded || []).forEach(name => list.push({ file_name: name }));
        renderFilesCell(itemId, columnId);

        document.querySelector('.fw-modal-overlay')?.remove();

        const okN = (data.uploaded || []).length;
        const rej = (data.rejected || []);
        if (window.BoardApp.showToast) {
          if (okN) window.BoardApp.showToast(`${okN} file${okN > 1 ? 's' : ''} uploaded`, 'success');
          if (rej.length) window.BoardApp.showToast(`${rej.length} file(s) skipped (type/size)`, 'error');
        } else if (rej.length && !okN) {
          alert('Files skipped: only documents/images up to 10MB are allowed.');
        }
      })
      .catch(err => {
        console.error('📁 Upload error:', err);
        if (progress) { progress.style.display = 'block'; progress.textContent = '⚠️ ' + err.message; }
        else alert('Upload failed: ' + err.message);
      });
  };

  // ===== SAVE CELL VALUE =====
window.BoardApp.saveCellValue = function(itemId, columnId, value) {
  console.log('💾 Saving cell:', { itemId, columnId, value });
  
  const column = window.BOARD_DATA.columns.find(c => c.column_id == columnId);
  if (!column) {
    console.error('Column not found:', columnId);
    return;
  }
  
  window.BoardApp.apiCall('/projects/api/cell/update.php', {
    item_id: itemId,
    column_id: columnId,
    value: value
  })
  .then(data => {
    console.log('✅ Cell saved:', data);
    
    // Close modal (keep server-rendered static modals in the DOM)
    document.querySelectorAll('.fw-modal-overlay, .fw-cell-picker-overlay:not(.fw-static-modal)').forEach(el => el.remove());
    
    // Update in memory
    if (!window.BOARD_DATA.valuesMap[itemId]) {
      window.BOARD_DATA.valuesMap[itemId] = {};
    }
    window.BOARD_DATA.valuesMap[itemId][columnId] = value;
    
    // Update DOM
    if (window.BoardApp.updateCellDOM) {
      window.BoardApp.updateCellDOM(itemId, columnId, value, column.type);
    } else {
      updateCellDisplay(itemId, columnId, value, column.type);
    }

    // Apply server-computed formula values (persisted on write) — this
    // replaces the old client-side recalculation guesswork
    const formulas = data && data.formulas ? data.formulas : null;
    if (formulas && Object.keys(formulas).length > 0) {
      Object.entries(formulas).forEach(([fColId, fValue]) => {
        window.BOARD_DATA.valuesMap[itemId][fColId] = fValue;
        updateCellDisplay(itemId, fColId, fValue, 'formula');
      });
    } else if (column.type === 'number' && window.BoardApp.recalculateFormulas) {
      // Fallback for older API responses
      window.BoardApp.recalculateFormulas(itemId);
    }

    // Aggregations + totals — direct sequential calls (the old setTimeout
    // chain existed only to dodge ordering that is now guaranteed)
    const row = document.querySelector(`tr.fw-item-row[data-item-id="${itemId}"]`);
    const groupId = row ? row.dataset.groupId : null;
    if (groupId && window.BoardApp.updateAggregations) {
      window.BoardApp.updateAggregations(groupId);
    }
    if (window.BoardApp.updateBoardTotals) {
      window.BoardApp.updateBoardTotals();
    }

    // Dispatch event for dependents (item panel, etc.)
    document.dispatchEvent(new CustomEvent('cellUpdated', {
      detail: { itemId, columnId, value, columnType: column.type }
    }));
  })
  .catch(err => {
    console.error('❌ Save cell error:', err);
    alert('Failed to save:\n\n' + err.message);
  });
};

  // ===== UPDATE CELL DISPLAY =====
  // Local escape helper (ui.js may not be loaded if the loader partially failed)
  function esc(text) {
    if (text === null || text === undefined) return '';
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // Tolerant column-config parse: a single malformed config must not throw and
  // break the whole cell editor / picker.
  function safeParseConfig(raw) {
    if (!raw) return {};
    if (typeof raw === 'object') return raw;
    try { return JSON.parse(raw) || {}; }
    catch (e) { console.warn('Bad column config JSON:', e); return {}; }
  }

  // Legible text color for a solid pill background (mirrors PHP fw_readable_text)
  function readableText(color) {
    const h = String(color || '#8b5cf6').replace('#', '');
    const hh = h.length === 3 ? h.split('').map(x => x + x).join('') : h;
    if (hh.length < 6) return '#ffffff';
    const lum = (0.2126 * parseInt(hh.slice(0, 2), 16) + 0.7152 * parseInt(hh.slice(2, 4), 16) + 0.0722 * parseInt(hh.slice(4, 6), 16)) / 255;
    return lum > 0.6 ? '#1a1a1a' : '#ffffff';
  }

  function renderNumberLike(cell, columnId, value, isFormula) {
    if (value !== '' && value !== null && value !== undefined) {
      const col = window.BOARD_DATA.columns.find(c => c.column_id == columnId);
      const cfg = safeParseConfig(col && col.config);
      const affix = cfg.affix || '';
      const pos = cfg.affixPosition === 'suffix' ? 'suffix' : 'prefix';
      const precision = parseInt(cfg.precision) >= 0 ? parseInt(cfg.precision) : 2;
      const num = parseFloat(value);
      let formatted = esc(value);
      if (!isNaN(num)) {
        formatted = num.toLocaleString('en-US', { minimumFractionDigits: precision, maximumFractionDigits: precision });
        if (!isFormula && cfg.format === 'percentage') formatted += '%';
      }
      const sep = '<span style="display:inline-block;width:0.25em;"></span>';
      const display = affix ? (pos === 'prefix' ? esc(affix) + sep + formatted : formatted + sep + esc(affix)) : formatted;
      const negClass = (!isNaN(num) && num < 0) ? ' fw-cell-number--negative' : '';
      cell.innerHTML = `<span class="fw-cell-number${negClass}">${display}</span>`;
    } else {
      cell.innerHTML = isFormula ? '—' : '<button class="fw-cell-empty">+</button>';
    }
  }

  function updateCellDisplay(itemId, columnId, value, columnType) {
    const cell = document.querySelector(`td[data-item-id="${itemId}"][data-column-id="${columnId}"]`);
    if (!cell) {
      console.warn('Cell not found for update:', itemId, columnId);
      return;
    }

    cell.dataset.value = value;

    switch (columnType) {
      case 'text':
        cell.innerHTML = value ? esc(value) : '<button class="fw-cell-empty">+</button>';
        break;

      case 'longtext':
        cell.innerHTML = value
          ? `<div class="fw-cell-longtext" title="${esc(value)}">${esc(value).replace(/\n/g, '<br>')}</div>`
          : '<button class="fw-cell-empty">+</button>';
        break;

      case 'number': {
        renderNumberLike(cell, columnId, value, false);
        break;
      }

      case 'formula': {
        renderNumberLike(cell, columnId, value, true);
        break;
      }
        
      case 'status':
        if (value) {
          const statusConfig = window.BOARD_DATA.statusConfig[value] || { label: value, color: '#8b5cf6' };
          cell.innerHTML = `<span class="fw-status-badge" style="background: ${esc(statusConfig.color)}; color: ${readableText(statusConfig.color)};">${esc(value.toUpperCase())}</span>`;
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
        
      case 'priority':
        if (value) {
          const priorityColors = {
            'low': '#10b981',
            'medium': '#fdab3d',
            'high': '#f97316',
            'critical': '#ef4444'
          };
          cell.innerHTML = `
            <button class="fw-priority-pill fw-priority-pill--${value}" data-value="${value}">
              <span class="fw-priority-dot" style="background:${priorityColors[value]};"></span>
              <span class="fw-priority-label">${value.charAt(0).toUpperCase() + value.slice(1)}</span>
            </button>
          `;
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
        
      case 'people':
        if (value) {
          const user = window.BOARD_DATA.users.find(u => u.id == value);
          if (user) {
            const initials = (user.first_name[0] + user.last_name[0]).toUpperCase();
            cell.innerHTML = `
              <div class="fw-user-pill">
                <div class="fw-avatar-sm">${esc(initials)}</div>
                <span class="fw-user-name">${esc(user.first_name + ' ' + user.last_name)}</span>
              </div>
            `;
            cell.dataset.userId = user.id;
          }
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
        
      case 'supplier':
        if (value) {
          const supplier = window.BOARD_DATA.suppliers.find(s => s.id == value);
          if (supplier) {
            cell.innerHTML = `
              <div class="fw-supplier-pill">
                <span class="fw-supplier-icon">🏢</span>
                <span class="fw-supplier-name">${esc(supplier.name)}</span>
                ${supplier.preferred ? '<span class="fw-supplier-badge">⭐</span>' : ''}
              </div>
            `;
          }
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
        
      case 'date': {
        if (value) {
          const date = new Date(value);
          const formatted = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
          const todayMs = new Date(new Date().toDateString()).getTime();
          const valMs = new Date(value.substring(0, 10)).getTime();
          let cls = 'fw-date-pill';
          if (valMs < todayMs) cls += ' fw-date-pill--overdue';
          else if (valMs === todayMs) cls += ' fw-date-pill--today';
          else if (valMs < todayMs + 86400000 * 3) cls += ' fw-date-pill--soon';
          cell.innerHTML = `
            <div class="${cls}">
              <svg width="14" height="14" fill="currentColor">
                <rect x="2" y="3" width="10" height="9" rx="1" stroke="currentColor" stroke-width="1.5" fill="none"/>
                <path d="M2 5h10M5 1v3M9 1v3"/>
              </svg>
              <span>${formatted}</span>
            </div>
          `;
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
      }

      case 'checkbox': {
        const checked = value === '1';
        cell.innerHTML = `<span class="fw-cell-checkbox ${checked ? 'fw-cell-checkbox--checked' : ''}">${checked ? '✓' : ''}</span>`;
        break;
      }

      case 'progress': {
        if (value !== '' && value !== null && value !== undefined) {
          const pct = Math.max(0, Math.min(100, parseInt(value)));
          let color;
          if (pct >= 100) color = '#10b981';
          else if (pct >= 70) color = '#22c55e';
          else if (pct >= 40) color = '#eab308';
          else if (pct >= 15) color = '#f97316';
          else color = '#ef4444';
          cell.innerHTML = `
            <div class="fw-progress-wrap">
              <div class="fw-progress-track">
                <div class="fw-progress-fill" style="width:${pct}%;background:${color};"></div>
              </div>
              <span class="fw-progress-label" style="color:${color};">${pct}%</span>
            </div>
          `;
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
      }

      case 'link':
        if (value) {
          const display = value.replace(/^https?:\/\//, '');
          cell.innerHTML = `
            <div style="display:flex;align-items:center;gap:8px;justify-content:space-between;">
              <a href="${esc(value)}" target="_blank" rel="noopener" style="color:var(--primary);text-decoration:underline;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">🔗 ${esc(display)}</a>
              <button type="button" class="fw-cell-edit-btn" title="Edit link" style="background:none;border:none;cursor:pointer;opacity:0.5;font-size:12px;padding:2px 4px;">✏️</button>
            </div>
          `;
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;

      case 'email':
        if (value) {
          cell.innerHTML = `
            <div style="display:flex;align-items:center;gap:8px;justify-content:space-between;min-width:0;">
              <a href="mailto:${esc(value)}" title="${esc(value)}" style="color:var(--primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;">✉️ ${esc(value)}</a>
              <button type="button" class="fw-cell-edit-btn" title="Edit email" style="background:none;border:none;cursor:pointer;opacity:0.5;font-size:12px;padding:2px 4px;flex-shrink:0;">✏️</button>
            </div>
          `;
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;

      case 'phone':
        if (value) {
          const digits = value.replace(/\D/g, '');
          let formatted = value;
          if (digits.length === 10 && digits[0] === '0') {
            formatted = digits.slice(0, 3) + ' ' + digits.slice(3, 6) + ' ' + digits.slice(6);
          } else if (digits.length === 11 && digits.startsWith('27')) {
            formatted = '+27 ' + digits.slice(2, 4) + ' ' + digits.slice(4, 7) + ' ' + digits.slice(7);
          }
          cell.innerHTML = `
            <div style="display:flex;align-items:center;gap:6px;justify-content:space-between;min-width:0;">
              <div style="display:flex;align-items:center;gap:6px;min-width:0;">
                <a href="tel:${esc(value)}" style="color:var(--primary);white-space:nowrap;">📞 ${esc(formatted)}</a>
                <a href="https://wa.me/${esc(digits)}" target="_blank" rel="noopener" title="WhatsApp" style="text-decoration:none;font-size:14px;">💬</a>
              </div>
              <button type="button" class="fw-cell-edit-btn" title="Edit phone" style="background:none;border:none;cursor:pointer;opacity:0.5;font-size:12px;padding:2px 4px;flex-shrink:0;">✏️</button>
            </div>
          `;
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;

      case 'tags': {
        if (value) {
          const palette = ['#ef4444','#f97316','#f59e0b','#eab308','#84cc16','#22c55e','#10b981','#14b8a6','#06b6d4','#0ea5e9','#3b82f6','#6366f1','#8b5cf6','#a855f7','#d946ef','#ec4899','#f43f5e'];
          const hash = (s) => { let h=0; for (let i=0;i<s.length;i++) h=(h*31+s.charCodeAt(i))>>>0; return h; };
          const tags = value.split(',').map(t => t.trim()).filter(t => t);
          cell.innerHTML = '<div style="display:flex;gap:4px;flex-wrap:wrap;">' + tags.map(tag => {
            const color = palette[hash(tag) % palette.length];
            return `<span class="fw-tag" style="background:${color};color:${readableText(color)};border:1px solid ${color};">${esc(tag)}</span>`;
          }).join('') + '</div>';
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
      }

      case 'timeline': {
        if (value) {
          try {
            const timeline = JSON.parse(value);
            const startTs = new Date(timeline.start).getTime();
            const endTs = new Date(timeline.end).getTime();
            const todayTs = new Date(new Date().toDateString()).getTime();
            const totalDays = Math.max(1, Math.round((endTs - startTs) / 86400000) + 1);
            let pct = 0;
            if (todayTs <= startTs) pct = 0;
            else if (todayTs >= endTs) pct = 100;
            else pct = Math.round(((todayTs - startTs) / Math.max(1, endTs - startTs)) * 100);
            let cls = 'fw-timeline-pill';
            if (todayTs > endTs) cls += ' fw-timeline-pill--overdue';
            else if (todayTs >= startTs) cls += ' fw-timeline-pill--active';
            const fmt = (d) => new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            cell.innerHTML = `
              <div class="${cls}">
                <div class="fw-timeline-bar"><div class="fw-timeline-bar-fill" style="width:${pct}%;"></div></div>
                <div class="fw-timeline-label">${fmt(timeline.start)} → ${fmt(timeline.end)} <span class="fw-timeline-days">${totalDays}d</span></div>
              </div>
            `;
          } catch (e) {
            cell.innerHTML = '<button class="fw-cell-empty">+</button>';
          }
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
      }

      case 'dropdown': {
        if (value) {
          const col = window.BOARD_DATA.columns.find(c => c.column_id == columnId);
          const cfg = col && col.config ? JSON.parse(col.config) : {};
          const optionColors = cfg.optionColors || {};
          const palette = ['#ef4444','#f97316','#f59e0b','#eab308','#84cc16','#22c55e','#10b981','#14b8a6','#06b6d4','#0ea5e9','#3b82f6','#6366f1','#8b5cf6','#a855f7','#d946ef','#ec4899'];
          const hash = (s) => { let h=0; for (let i=0;i<s.length;i++) h=(h*31+s.charCodeAt(i))>>>0; return h; };
          const color = optionColors[value] || palette[hash(value) % palette.length];
          cell.innerHTML = `<span class="fw-dropdown-pill" style="background:${color};color:${readableText(color)};border:1px solid ${color};">${esc(value)}</span>`;
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
      }
        
      default:
        cell.innerHTML = value ? esc(value) : '<button class="fw-cell-empty">+</button>';
    }
  }

  // ===== KEYBOARD OPERABILITY =====
  // Cells carry tabindex="0"; Enter/Space opens the editor, arrows rove focus
  // between cells (left/right within the row, up/down across rows).
  document.addEventListener('keydown', (e) => {
    const cell = e.target.closest && e.target.closest('td.fw-cell');
    if (!cell || e.target !== cell) return;

    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      const itemId = parseInt(cell.dataset.itemId, 10);
      const colId = parseInt(cell.dataset.columnId, 10);
      window.BoardApp.editCell(itemId, colId, cell.dataset.type, {
        currentTarget: cell,
        target: cell,
        stopPropagation() {}
      });
      return;
    }

    let target = null;
    if (e.key === 'ArrowRight') {
      target = cell.nextElementSibling;
      while (target && !target.classList.contains('fw-cell')) target = target.nextElementSibling;
    } else if (e.key === 'ArrowLeft') {
      target = cell.previousElementSibling;
      while (target && !target.classList.contains('fw-cell')) target = target.previousElementSibling;
    } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      const row = cell.closest('tr.fw-item-row');
      if (row) {
        let sibling = e.key === 'ArrowDown' ? row.nextElementSibling : row.previousElementSibling;
        while (sibling && !sibling.classList.contains('fw-item-row')) {
          sibling = e.key === 'ArrowDown' ? sibling.nextElementSibling : sibling.previousElementSibling;
        }
        if (sibling) target = sibling.querySelector(`td.fw-cell[data-column-id="${cell.dataset.columnId}"]`);
      }
    }

    if (target) {
      e.preventDefault();
      target.focus();
    }
  });

  // Expose the full renderer. realtime.js wraps this as BoardApp.updateCellDOM
  // (adding flash animation, cache + aggregation updates); the direct assignment
  // below is only the fallback for when realtime.js fails to load.
  window.BoardApp.renderCellFull = updateCellDisplay;
  window.BoardApp.updateCellDOM = updateCellDisplay;

  // ===== HELPER: FILTER PICKER OPTIONS =====
  window.filterPickerOptions = function(query) {
    const options = document.querySelectorAll('.fw-picker-option');
    const lowerQuery = query.toLowerCase();
    
    options.forEach(opt => {
      const name = opt.dataset.name || opt.textContent;
      opt.style.display = name.toLowerCase().includes(lowerQuery) ? 'flex' : 'none';
    });
  };

  // ===== HELPER: CREATE MODAL =====
  function createModal(title, content) {
    const modal = document.createElement('div');
    modal.className = 'fw-modal-overlay';
    modal.innerHTML = `
      <div class="fw-modal-content fw-slide-up">
        <div class="fw-modal-header">
          <h3 style="margin:0;font-size:18px;font-weight:700;">${title}</h3>
          <button onclick="this.closest('.fw-modal-overlay').remove()" style="background:none;border:none;color:var(--text-secondary);cursor:pointer;font-size:24px;padding:0;width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:6px;transition:all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.1)'" onmouseout="this.style.background='none'">×</button>
        </div>
        <div class="fw-modal-body">${content}</div>
      </div>
    `;
    
    modal.addEventListener('click', (e) => {
      if (e.target === modal) modal.remove();
    });
    
    // ✅ FIX: Append to .fw-proj instead of body
    const container = document.querySelector('.fw-proj') || document.body;
    container.appendChild(modal);
    
    // Add close on Escape
    const escHandler = (e) => {
      if (e.key === 'Escape') {
        modal.remove();
        document.removeEventListener('keydown', escHandler);
      }
    };
    document.addEventListener('keydown', escHandler);
    
    return modal;
  }

  console.log('✅ Cells module loaded - ALL column types supported');

})();