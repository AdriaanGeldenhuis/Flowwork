/*
 * Review page script – stepper logic
 * Handles multi-step review wizard for receipts. Relies on API endpoints to suggest lines, check policy, save bill, and post to GL.
 */
(function() {
  'use strict';

  // Viewer controls (retain existing functionality)
  const viewerContent = document.getElementById('viewerContent');
  const receiptImage = document.getElementById('receiptImage');
  const zoomInBtn = document.getElementById('zoomInBtn');
  const zoomOutBtn = document.getElementById('zoomOutBtn');
  const rotateBtn = document.getElementById('rotateBtn');
  const zoomLevel = document.getElementById('zoomLevel');

  let currentZoom = 100;
  let currentRotation = 0;

  if (receiptImage) {
    zoomInBtn.addEventListener('click', () => {
      currentZoom = Math.min(currentZoom + 25, 300);
      applyTransform();
    });

    zoomOutBtn.addEventListener('click', () => {
      currentZoom = Math.max(currentZoom - 25, 50);
      applyTransform();
    });

    rotateBtn.addEventListener('click', () => {
      currentRotation = (currentRotation + 90) % 360;
      applyTransform();
    });

    function applyTransform() {
      receiptImage.style.transform = `scale(${currentZoom / 100}) rotate(${currentRotation}deg)`;
      receiptImage.style.transformOrigin = 'center center';
      receiptImage.style.transition = 'transform 0.3s ease';
      zoomLevel.textContent = currentZoom + '%';
    }
  }

  // Global state
  let currentStep = 1;
  let headerData = {};
  let linesData = [];
  let billId = null;

  // Arrays to hold GL accounts and project items for dropdowns in step 2.
  // These will be populated when the user progresses from step 1 to step 2.
  let glAccounts = [];
  let projectItems = [];

  /**
   * Fetches available expense GL accounts for the current company.  Populates
   * the global glAccounts array.  Returns a promise that resolves when data
   * has been loaded.
   */
  function loadGlAccounts() {
    return fetch('api/gl_accounts.php')
      .then(res => res.json())
      .then(data => {
        if (data.ok && Array.isArray(data.accounts)) {
          glAccounts = data.accounts;
        } else {
          glAccounts = [];
        }
      })
      .catch(() => {
        glAccounts = [];
      });
  }

  /**
   * Fetches project (board) items for the given board id.  Populates
   * the global projectItems array.  Returns a promise that resolves when
   * the data has been loaded.  Passing a falsy boardId clears the array.
   * @param {number|string} boardId
   */
  function loadProjectItems(boardId) {
    const bId = parseInt(boardId, 10);
    if (!bId) {
      projectItems = [];
      return Promise.resolve();
    }
    return fetch('api/project_items.php?board_id=' + encodeURIComponent(bId))
      .then(res => res.json())
      .then(data => {
        if (data.ok && Array.isArray(data.items)) {
          projectItems = data.items;
        } else {
          projectItems = [];
        }
      })
      .catch(() => {
        projectItems = [];
      });
  }

  // Elements
  const stepperNav = document.querySelectorAll('.fw-receipts__stepper-step');
  const stepSections = document.querySelectorAll('.fw-step');
  const messageBox = document.getElementById('formMessage');
  const lineContainer = document.getElementById('lineItemsContainer2');
  const addLineBtn2 = document.getElementById('addLineBtn2');

  // Step navigation buttons
  const step1Next = document.getElementById('step1Next');
  const step2Back = document.getElementById('step2Back');
  const step2Next = document.getElementById('step2Next');
  const step3Back = document.getElementById('step3Back');
  const step3Next = document.getElementById('step3Next');
  const step4Back = document.getElementById('step4Back');
  const approveBtn = document.getElementById('approveBtn');
  const step5Back = document.getElementById('step5Back');
  const postBtn = document.getElementById('postBtn');
  const bankRuleBtn = document.getElementById('bankRuleBtn');

  // Summary containers
  const approveSummary = document.getElementById('approveSummary');
  const postSummary = document.getElementById('postSummary');
  const matchSuggestions = document.getElementById('matchSuggestions');

  // Initial lines from PHP
  linesData = Array.isArray(INITIAL_LINES) && INITIAL_LINES.length ? INITIAL_LINES.map(l => ({
    description: l.description || '',
    qty: l.qty || 1,
    unit: l.unit || 'ea',
    unit_price: l.unit_price || 0,
    gl_account_id: null,
    project_board_id: null,
    project_item_id: null,
    flag_spike: false
  })) : [ {
    description: '', qty: 1, unit: 'ea', unit_price: 0, gl_account_id: null, project_board_id: null, project_item_id: null, flag_spike: false
  } ];

  // Render lines into Step2 container
  function renderLines() {
    lineContainer.innerHTML = '';
    linesData.forEach((line, idx) => {
      const div = document.createElement('div');
      div.className = 'fw-receipts__line-item';
      div.dataset.lineIndex = idx;
      // Build inner HTML for line item including spike flag, GL and project selectors
      let html = '';
      html += `<input type="text" class="fw-receipts__input line-desc" placeholder="Description" style="flex:2;" value="${escapeHtml(line.description)}">`;
      html += `<input type="number" step="0.001" class="fw-receipts__input line-qty" style="width:80px;" value="${line.qty}">`;
      html += `<input type="text" class="fw-receipts__input line-unit" style="width:80px;" value="${escapeHtml(line.unit)}">`;
      html += `<input type="number" step="0.01" class="fw-receipts__input line-price" style="width:100px;" value="${line.unit_price}">`;
      // GL account dropdown
      html += `<select class="fw-receipts__input line-gl" style="max-width:160px;">
        <option value="">GL Account</option>`;
      glAccounts.forEach(acc => {
        const selected = line.gl_account_id && Number(line.gl_account_id) === Number(acc.id) ? ' selected' : '';
        const label = `${escapeHtml(acc.code)} – ${escapeHtml(acc.name)}`;
        html += `<option value="${acc.id}"${selected}>${label}</option>`;
      });
      html += `</select>`;
      // Project item dropdown
      html += `<select class="fw-receipts__input line-project" style="max-width:160px;">
        <option value="">Task Item</option>`;
      projectItems.forEach(item => {
        const selected = line.project_item_id && Number(line.project_item_id) === Number(item.id) ? ' selected' : '';
        html += `<option value="${item.id}"${selected}>${escapeHtml(item.title)}</option>`;
      });
      html += `</select>`;
      // Remove button
      html += `<button type="button" class="fw-receipts__btn fw-receipts__btn--danger fw-receipts__btn--small remove-line-btn">✕</button>`;
      // Append flag if this line is marked as a price spike
      if (line.flag_spike) {
        html += `<span class="fw-receipts__flag-spike" title="Price spike">⚠</span>`;
      }
      div.innerHTML = html;
      lineContainer.appendChild(div);
    });
    attachLineHandlers();
  }

  // Add line handler
  addLineBtn2.addEventListener('click', () => {
    linesData.push({ description: '', qty: 1, unit: 'ea', unit_price: 0, gl_account_id: null, project_board_id: null, project_item_id: null, flag_spike: false });
    renderLines();
  });

  // Attach remove/cell handlers
  function attachLineHandlers() {
    const removeBtns = lineContainer.querySelectorAll('.remove-line-btn');
    removeBtns.forEach((btn, idx) => {
      btn.addEventListener('click', () => {
        if (linesData.length > 1) {
          linesData.splice(idx, 1);
          renderLines();
        } else {
          alert('At least one line item is required');
        }
      });
    });
    // Attach GL account change handlers
    const glSelects = lineContainer.querySelectorAll('.line-gl');
    glSelects.forEach((sel, idx) => {
      sel.addEventListener('change', () => {
        const val = sel.value ? parseInt(sel.value, 10) : null;
        if (linesData[idx]) {
          linesData[idx].gl_account_id = val;
        }
      });
    });
    // Attach project item change handlers
    const projectSelects = lineContainer.querySelectorAll('.line-project');
    projectSelects.forEach((sel, idx) => {
      sel.addEventListener('change', () => {
        const val = sel.value ? parseInt(sel.value, 10) : null;
        if (linesData[idx]) {
          linesData[idx].project_item_id = val;
        }
      });
    });
  }

  // Helper: escape HTML
  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  // Message helper
  function showMessage(type, text) {
    if (!messageBox) return;
    messageBox.style.display = 'block';
    messageBox.className = 'fw-receipts__form-message fw-receipts__form-message--' + type;
    messageBox.textContent = text;
    if (type === 'success' || type === 'info') {
      setTimeout(() => {
        messageBox.style.display = 'none';
      }, 5000);
    }
  }

  // Show/hide steps
  function showStep(step) {
    currentStep = step;
    stepSections.forEach(sec => {
      if (parseInt(sec.dataset.step) === step) {
        sec.style.display = '';
      } else {
        sec.style.display = 'none';
      }
    });
    stepperNav.forEach(nav => {
      if (parseInt(nav.dataset.step) === step) {
        nav.classList.add('active');
      } else {
        nav.classList.remove('active');
      }
    });
  }

  // Step1 Next
  step1Next.addEventListener('click', () => {
    // Gather header fields
    const supplierId = document.getElementById('supplierSelect').value;
    const projectId = document.getElementById('projectSelect').value;
    const invNumber = document.getElementById('invoiceNumber').value.trim();
    const invDate = document.getElementById('invoiceDate').value;
    const currency = document.getElementById('currency').value;
    const subtotal = parseFloat(document.getElementById('subtotal').value) || 0;
    const tax = parseFloat(document.getElementById('tax').value) || 0;
    const total = parseFloat(document.getElementById('total').value) || 0;
    if (!supplierId || !projectId || !invNumber || !invDate || !total) {
      showMessage('error', 'Please fill all required fields');
      return;
    }
    headerData = {
      supplier_id: supplierId,
      project_board_id: projectId,
      invoice_number: invNumber,
      invoice_date: invDate,
      currency: currency,
      subtotal: subtotal,
      tax: tax,
      total: total,
      file_id: FILE_ID
    };
    showMessage('info', 'Fetching line suggestions...');
    // Prepare promises: fetch suggested lines, load GL accounts, load project items
    const suggestPromise = fetch('api/suggest_lines.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ file_id: FILE_ID, supplier_id: supplierId })
    }).then(res => res.json()).then(data => {
      if (data.ok && Array.isArray(data.lines)) {
        // Map to internal structure; default project_board_id to selected project
        linesData = data.lines.map(l => ({
          description: l.description || '',
          qty: l.qty || 1,
          unit: l.unit || 'ea',
          unit_price: l.unit_price || 0,
          gl_account_id: l.gl_account_id || null,
          project_board_id: projectId,
          project_item_id: null,
          flag_spike: !!l.flag_spike
        }));
      }
    }).catch(err => {
      console.error(err);
      // leave linesData as-is
    });
    const glPromise = loadGlAccounts();
    const projPromise = loadProjectItems(projectId);
    Promise.all([suggestPromise, glPromise, projPromise]).then(() => {
      // Ensure linesData has at least one line if empty
      if (!linesData || linesData.length === 0) {
        linesData = [ { description: '', qty: 1, unit: 'ea', unit_price: 0, gl_account_id: null, project_board_id: projectId, project_item_id: null, flag_spike: false } ];
      }
      renderLines();
      showMessage('success', 'Lines loaded');
      showStep(2);
    }).catch(err => {
      console.error(err);
      // Even if some promises fail, proceed with current state
      renderLines();
      showMessage('warning', 'Some data could not be loaded');
      showStep(2);
    });
  });

  // Step2 Back
  step2Back.addEventListener('click', () => {
    showStep(1);
  });

  // Step2 Next
  step2Next.addEventListener('click', () => {
    // Gather lines
    const lineDivs = lineContainer.querySelectorAll('.fw-receipts__line-item');
    const newLines = [];
    let valid = true;
    lineDivs.forEach((div, idx) => {
      const desc = div.querySelector('.line-desc').value.trim();
      const qty = parseFloat(div.querySelector('.line-qty').value) || 0;
      const unit = div.querySelector('.line-unit').value.trim() || 'ea';
      const price = parseFloat(div.querySelector('.line-price').value) || 0;
      const glVal = div.querySelector('.line-gl') ? div.querySelector('.line-gl').value : '';
      const projVal = div.querySelector('.line-project') ? div.querySelector('.line-project').value : '';
      if (!desc) {
        valid = false;
      }
      // Preserve spike flag from existing linesData if available
      const existingFlag = (linesData[idx] && linesData[idx].flag_spike) ? true : false;
      newLines.push({
        description: desc,
        qty: qty,
        unit: unit,
        unit_price: price,
        gl_account_id: glVal ? parseInt(glVal, 10) : null,
        project_board_id: headerData.project_board_id,
        project_item_id: projVal ? parseInt(projVal, 10) : null,
        flag_spike: existingFlag
      });
    });
    if (!valid) {
      showMessage('error', 'All line items require a description');
      return;
    }
    linesData = newLines;
    showMessage('info', 'Checking policy and matching...');
    // Call policy check and match targets endpoints
    const payload = { header: headerData, lines: linesData };
    // Policy check
    fetch('api/policy_check.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(policyData => {
      // Match targets
      fetch('api/match_targets.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      })
      .then(res2 => res2.json())
      .then(matchData => {
        // Render policy/match results
        renderPolicyMatch(policyData, matchData);
        showMessage('success', 'Policy & match results ready');
        showStep(3);
      })
      .catch(err2 => {
        console.error(err2);
        renderPolicyMatch(policyData, null);
        showMessage('warning', 'Match check failed');
        showStep(3);
      });
    })
    .catch(err => {
      console.error(err);
      renderPolicyMatch(null, null);
      showMessage('warning', 'Policy check failed');
      showStep(3);
    });
  });

  function renderPolicyMatch(policyData, matchData) {
    // Clear existing
    matchSuggestions.innerHTML = '';
    // Policy blocks and warnings
    if (policyData && policyData.blocks && policyData.blocks.length) {
      const alert = document.createElement('div');
      alert.className = 'fw-receipts__alert fw-receipts__alert--error';
      alert.innerHTML = '<strong>Policy Blocks:</strong><ul>' + policyData.blocks.map(b => '<li>' + escapeHtml(b) + '</li>').join('') + '</ul>';
      matchSuggestions.appendChild(alert);
    } else if (policyData && policyData.warnings && policyData.warnings.length) {
      const alert = document.createElement('div');
      alert.className = 'fw-receipts__alert fw-receipts__alert--warn';
      alert.innerHTML = '<strong>Warnings:</strong><ul>' + policyData.warnings.map(w => '<li>' + escapeHtml(w) + '</li>').join('') + '</ul>';
      matchSuggestions.appendChild(alert);
    } else {
      const okMsg = document.createElement('div');
      okMsg.className = 'fw-receipts__alert fw-receipts__alert--success';
      okMsg.textContent = 'No policy blocks detected.';
      matchSuggestions.appendChild(okMsg);
    }
    // Match suggestions
    if (matchData && (matchData.po || matchData.grn || matchData.board_item)) {
      const mList = document.createElement('div');
      mList.className = 'fw-receipts__alert fw-receipts__alert--info';
      mList.innerHTML = '<strong>Match Suggestions:</strong><ul>';
      if (matchData.po) {
        mList.innerHTML += '<li>PO: ' + escapeHtml(matchData.po.id + ' (' + matchData.po.variance + '% variance)') + '</li>';
      }
      if (matchData.grn) {
        mList.innerHTML += '<li>GRN: ' + escapeHtml(matchData.grn.id + ' (' + matchData.grn.variance + '% variance)') + '</li>';
      }
      if (matchData.board_item) {
        mList.innerHTML += '<li>Project Item: ' + escapeHtml(matchData.board_item.id) + '</li>';
      }
      mList.innerHTML += '</ul>';
      matchSuggestions.appendChild(mList);
    }
    // Save blocks to decide if next button should be disabled
    if (policyData && policyData.blocks && policyData.blocks.length) {
      step3Next.disabled = true;
    } else {
      step3Next.disabled = false;
    }
  }

  // Step3 Back
  step3Back.addEventListener('click', () => {
    showStep(2);
  });

  // Step3 Next
  step3Next.addEventListener('click', () => {
    // Prepare summary for approve step
    buildApproveSummary();
    showStep(4);
  });

  function buildApproveSummary() {
    approveSummary.innerHTML = '';
    const headerList = document.createElement('div');
    headerList.innerHTML = `
      <p><strong>Supplier:</strong> ${escapeHtml(document.getElementById('supplierSelect').selectedOptions[0].text)}</p>
      <p><strong>Invoice #:</strong> ${escapeHtml(headerData.invoice_number)}</p>
      <p><strong>Date:</strong> ${escapeHtml(headerData.invoice_date)}</p>
      <p><strong>Total:</strong> ${headerData.total.toFixed(2)}</p>
    `;
    approveSummary.appendChild(headerList);
    const table = document.createElement('table');
    table.className = 'fw-receipts__table';
    table.innerHTML = '<thead><tr><th>Description</th><th>Qty</th><th>Unit</th><th>Price</th><th>Net</th></tr></thead><tbody></tbody>';
    linesData.forEach(line => {
      const row = document.createElement('tr');
      const net = (line.qty * line.unit_price);
      row.innerHTML = `<td>${escapeHtml(line.description)}</td><td>${line.qty}</td><td>${escapeHtml(line.unit)}</td><td>${line.unit_price.toFixed(2)}</td><td>${net.toFixed(2)}</td>`;
      table.querySelector('tbody').appendChild(row);
    });
    approveSummary.appendChild(table);
  }

  // Step4 Back
  step4Back.addEventListener('click', () => {
    showStep(3);
  });

  // Approve button
  approveBtn.addEventListener('click', () => {
    showMessage('info', 'Saving bill...');
    const payload = {
      header: headerData,
      lines: linesData
    };
    fetch('api/save_bill.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
      if (data.ok) {
        billId = data.bill_id;
        showMessage('success', 'Bill saved');
        buildPostSummary();
        showStep(5);
      } else {
        // Handle error messages, including structured error objects
        let err = data.error;
        let errMsg;
        if (err && typeof err === 'object') {
          // If it's a duplicate, message accordingly
          if (err.code === 'DUPLICATE') {
            errMsg = err.message || 'This bill looks like a duplicate.';
          } else {
            errMsg = err.message || JSON.stringify(err);
          }
        } else {
          errMsg = err || 'Failed to save bill';
        }
        showMessage('error', errMsg);
      }
    })
    .catch(err => {
      console.error(err);
      showMessage('error', 'Network error');
    });
  });

  function buildPostSummary() {
    postSummary.innerHTML = '';
    postSummary.innerHTML = `<p>Bill ID: ${escapeHtml(String(billId))}</p>`;
  }

  // Step5 Back
  step5Back.addEventListener('click', () => {
    showStep(4);
  });

  // Post button
  postBtn.addEventListener('click', () => {
    if (!billId) {
      showMessage('error', 'Bill not saved');
      return;
    }
    showMessage('info', 'Posting to GL...');
    fetch('api/post_to_gl.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ bill_id: billId })
    })
    .then(res => res.json())
    .then(data => {
      if (data.ok) {
        showMessage('success', 'Posted to GL');
      } else {
        showMessage('error', data.error || 'Failed to post');
      }
    })
    .catch(err => {
      console.error(err);
      showMessage('error', 'Network error');
    });
  });

  // Bank rule button
  bankRuleBtn.addEventListener('click', () => {
    if (!billId) {
      showMessage('error', 'Bill not saved');
      return;
    }
    showMessage('info', 'Creating bank rule...');
    fetch('api/bank_link_rule.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ supplier_id: headerData.supplier_id })
    })
    .then(res => res.json())
    .then(data => {
      if (data.ok) {
        showMessage('success', 'Bank rule saved');
      } else {
        showMessage('error', data.error || 'Failed to save bank rule');
      }
    })
    .catch(err => {
      console.error(err);
      showMessage('error', 'Network error');
    });
  });

  // Initially show step 1 and render lines
  renderLines();
  showStep(1);

  // Attempt to auto-select supplier based on OCR vendor name
  (function autoSelectSupplier() {
    // Only run if supplier select is present and no supplier chosen
    const supplierSelect = document.getElementById('supplierSelect');
    const complianceEl = document.getElementById('supplierComplianceMsg');
    if (!supplierSelect) return;
    if (supplierSelect.value) return; // Already selected
    fetch('api/suggest_vendor.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ file_id: FILE_ID })
    })
      .then(res => res.json())
      .then(data => {
        if (data.ok && data.supplier && data.supplier.id) {
          // Set value if exists in dropdown
          const opt = supplierSelect.querySelector(`option[value="${data.supplier.id}"]`);
          if (opt) {
            supplierSelect.value = String(data.supplier.id);
          }
        }
        // Update compliance message if element exists
        if (complianceEl && data.ok && data.compliance) {
          if (data.compliance.ok) {
            complianceEl.innerHTML = '<div class="fw-receipts__alert fw-receipts__alert--success">Supplier compliance OK</div>';
          } else {
            const missing = Array.isArray(data.compliance.missing) ? data.compliance.missing.join(', ') : '';
            complianceEl.innerHTML = '<div class="fw-receipts__alert fw-receipts__alert--warn">Missing compliance: ' + escapeHtml(missing) + '</div>';
          }
        }
      })
      .catch(() => {
        // ignore errors silently
      });
  })();

})();