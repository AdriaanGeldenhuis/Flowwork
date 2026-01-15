(function() {
  'use strict';

  const uploadZone = document.getElementById('uploadZone');
  const fileInput = document.getElementById('fileInput');
  const browseBtn = document.getElementById('browseBtn');
  const cameraBtn = document.getElementById('cameraBtn');
  const bulkBtn = document.getElementById('bulkBtn');
  const bulkFileInput = document.getElementById('bulkFileInput');
  const bulkList = document.getElementById('bulkList');
  const bulkItems = document.getElementById('bulkItems');
  const uploadList = document.getElementById('uploadList');
  const uploadItems = document.getElementById('uploadItems');

  // Camera modal
  const cameraModal = document.getElementById('cameraModal');
  const cameraStream = document.getElementById('cameraStream');
  const cameraCanvas = document.getElementById('cameraCanvas');
  const closeCameraBtn = document.getElementById('closeCameraBtn');
  const cancelCameraBtn = document.getElementById('cancelCameraBtn');
  const captureBtn = document.getElementById('captureBtn');

  let currentStream = null;

  // ========== DRAG & DROP ==========
  uploadZone.addEventListener('click', () => fileInput.click());
  browseBtn.addEventListener('click', () => fileInput.click());

  uploadZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    uploadZone.classList.add('fw-receipts__upload-zone--dragover');
  });

  uploadZone.addEventListener('dragleave', () => {
    uploadZone.classList.remove('fw-receipts__upload-zone--dragover');
  });

  uploadZone.addEventListener('drop', (e) => {
    e.preventDefault();
    uploadZone.classList.remove('fw-receipts__upload-zone--dragover');
    handleFiles(e.dataTransfer.files);
  });

  fileInput.addEventListener('change', (e) => {
    handleFiles(e.target.files);
  });

  // Bulk import events
  if (bulkBtn && bulkFileInput) {
    bulkBtn.addEventListener('click', () => bulkFileInput.click());
    bulkFileInput.addEventListener('change', (e) => {
      const zipFile = e.target.files && e.target.files[0];
      if (zipFile) {
        handleBulkFile(zipFile);
      }
      // reset value so same file can be chosen again
      e.target.value = '';
    });
  }

  // ========== FILE UPLOAD ==========
  function handleFiles(files) {
    if (files.length === 0) return;

    uploadList.style.display = 'block';

    Array.from(files).forEach(file => {
      if (!validateFile(file)) return;
      uploadFile(file);
    });
  }

  function validateFile(file) {
    if (!ALLOWED_TYPES.includes(file.type)) {
      alert('Invalid file type: ' + file.name);
      return false;
    }
    if (file.size > MAX_SIZE_BYTES) {
      alert('File too large: ' + file.name + ' (max ' + (MAX_SIZE_BYTES / 1024 / 1024) + 'MB)');
      return false;
    }
    return true;
  }

  // Validate bulk zip file (only extension check; size checked server-side)
  function validateBulkFile(file) {
    const ext = file.name.split('.').pop().toLowerCase();
    if (ext !== 'zip') {
      alert('Invalid ZIP file: ' + file.name);
      return false;
    }
    return true;
  }

  // Handle bulk import of a ZIP archive
  function handleBulkFile(zipFile) {
    if (!validateBulkFile(zipFile)) return;
    bulkList.style.display = 'block';
    const itemId = 'bulk-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
    const itemHtml = `
      <div class="fw-receipts__upload-item" id="${itemId}">
        <div class="fw-receipts__upload-item-info">
          <strong>${escapeHtml(zipFile.name)}</strong>
          <small>${formatFileSize(zipFile.size)}</small>
        </div>
        <div class="fw-receipts__upload-item-progress">
          <div class="fw-receipts__progress-bar">
            <div class="fw-receipts__progress-fill" style="width:0%">0%</div>
          </div>
          <span class="fw-receipts__upload-status">Uploading...</span>
        </div>
      </div>
    `;
    bulkItems.insertAdjacentHTML('beforeend', itemHtml);
    const formData = new FormData();
    formData.append('file', zipFile);
    const xhr = new XMLHttpRequest();
    xhr.upload.addEventListener('progress', (e) => {
      if (e.lengthComputable) {
        const percent = Math.round((e.loaded / e.total) * 100);
        const fill = document.querySelector('#' + itemId + ' .fw-receipts__progress-fill');
        if (fill) {
          fill.style.width = percent + '%';
          fill.textContent = percent + '%';
        }
      }
    });
    xhr.addEventListener('load', () => {
      const item = document.getElementById(itemId);
      if (!item) return;
      const statusEl = item.querySelector('.fw-receipts__upload-status');
      const fill = item.querySelector('.fw-receipts__progress-fill');
      if (xhr.status === 200) {
        try {
          const response = JSON.parse(xhr.responseText);
          if (response.ok) {
            // Completed upload; set progress to 100%
            if (fill) {
              fill.style.width = '100%';
              fill.textContent = '100%';
            }
            statusEl.textContent = '✓ Imported ' + response.results.length + ' files';
            statusEl.style.color = 'var(--accent-success)';
            // For each result, create a row with link to review
            response.results.forEach(res => {
              const fileName = res.name || 'File';
              const fileId = res.file_id;
              const subItemHtml = `
                <div class="fw-receipts__upload-item">
                  <div class="fw-receipts__upload-item-info">
                    <strong>${escapeHtml(fileName)}</strong>
                  </div>
                  <div class="fw-receipts__upload-item-progress">
                    <span class="fw-receipts__upload-status">
                      ✓ <a href="review.php?id=${fileId}">Open</a>
                    </span>
                  </div>
                </div>
              `;
              bulkItems.insertAdjacentHTML('beforeend', subItemHtml);
            });
          } else {
            statusEl.textContent = '✗ Error: ' + (response.error || 'Unknown');
            statusEl.style.color = 'var(--accent-danger)';
          }
        } catch (err) {
          statusEl.textContent = '✗ Parse error';
          statusEl.style.color = 'var(--accent-danger)';
        }
      } else {
        statusEl.textContent = '✗ HTTP ' + xhr.status;
        statusEl.style.color = 'var(--accent-danger)';
      }
    });
    xhr.addEventListener('error', () => {
      const item = document.getElementById(itemId);
      if (item) {
        const statusEl = item.querySelector('.fw-receipts__upload-status');
        statusEl.textContent = '✗ Network error';
        statusEl.style.color = 'var(--accent-danger)';
      }
    });
    xhr.open('POST', 'api/bulk_import.php', true);
    xhr.send(formData);
  }

  function uploadFile(file) {
    const itemId = 'upload-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
    // Create UI element for this upload
    const itemHtml = `
      <div class="fw-receipts__upload-item" id="${itemId}">
        <div class="fw-receipts__upload-item-info">
          <strong>${escapeHtml(file.name)}</strong>
          <small>${formatFileSize(file.size)}</small>
        </div>
        <div class="fw-receipts__upload-item-progress">
          <div class="fw-receipts__progress-bar">
            <div class="fw-receipts__progress-fill" style="width:0%">0%</div>
          </div>
          <span class="fw-receipts__upload-status">Uploading...</span>
        </div>
      </div>
    `;
    uploadItems.insertAdjacentHTML('beforeend', itemHtml);

    const formData = new FormData();
    formData.append('file', file);

    // Use XMLHttpRequest for upload to track progress
    const xhr = new XMLHttpRequest();

    xhr.upload.addEventListener('progress', (e) => {
      if (e.lengthComputable) {
        const percent = Math.round((e.loaded / e.total) * 100);
        const fill = document.querySelector('#' + itemId + ' .fw-receipts__progress-fill');
        if (fill) {
          fill.style.width = percent + '%';
          fill.textContent = percent + '%';
        }
      }
    });

    xhr.addEventListener('load', () => {
      const item = document.getElementById(itemId);
      const statusEl = item.querySelector('.fw-receipts__upload-status');
      const fill = item.querySelector('.fw-receipts__progress-fill');
      if (xhr.status === 200) {
        try {
          const response = JSON.parse(xhr.responseText);
          if (response.ok) {
            // Upload finished, call OCR trigger and start polling
            statusEl.textContent = 'Processing...';
            statusEl.style.color = '';
            if (fill) {
              fill.style.width = '100%';
              fill.textContent = '100%';
            }
            triggerOCR(response.file_id, itemId);
          } else {
            statusEl.textContent = '✗ Error: ' + (response.error || 'Unknown');
            statusEl.style.color = 'var(--accent-danger)';
          }
        } catch (err) {
          statusEl.textContent = '✗ Parse error';
          statusEl.style.color = 'var(--accent-danger)';
        }
      } else {
        statusEl.textContent = '✗ HTTP ' + xhr.status;
        statusEl.style.color = 'var(--accent-danger)';
      }
    });

    xhr.addEventListener('error', () => {
      const statusEl = document.querySelector('#' + itemId + ' .fw-receipts__upload-status');
      statusEl.textContent = '✗ Network error';
      statusEl.style.color = 'var(--accent-danger)';
    });

    // Upload to new API endpoint
    xhr.open('POST', 'api/upload_start.php', true);
    xhr.send(formData);
  }

  // Trigger OCR for a given file ID and then poll until complete
  function triggerOCR(fileId, itemId) {
    const statusEl = document.querySelector('#' + itemId + ' .fw-receipts__upload-status');
    fetch('api/ocr_trigger.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ file_id: fileId })
    })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          pollOCR(fileId, itemId);
        } else {
          statusEl.textContent = '✗ OCR error: ' + (data.error || 'Unknown');
          statusEl.style.color = 'var(--accent-danger)';
        }
      })
      .catch(err => {
        console.error(err);
        statusEl.textContent = '✗ OCR network error';
        statusEl.style.color = 'var(--accent-danger)';
      });
  }

  function pollOCR(fileId, itemId) {
    const statusEl = document.querySelector('#' + itemId + ' .fw-receipts__upload-status');
    fetch('api/ocr_poll.php?file_id=' + encodeURIComponent(fileId))
      .then(res => res.json())
      .then(data => {
        if (!data.ok) {
          statusEl.textContent = '✗ OCR error';
          statusEl.style.color = 'var(--accent-danger)';
          return;
        }
        if (data.status === 'parsed') {
          statusEl.textContent = '✓ Complete';
          statusEl.style.color = 'var(--accent-success)';
          setTimeout(() => {
            window.location.href = 'review.php?id=' + fileId;
          }, 1000);
        } else if (data.status === 'failed') {
          statusEl.textContent = '✗ OCR failed';
          statusEl.style.color = 'var(--accent-danger)';
        } else {
          // Still processing, poll again
          statusEl.textContent = 'Processing...';
          setTimeout(() => pollOCR(fileId, itemId), 1500);
        }
      })
      .catch(err => {
        console.error(err);
        statusEl.textContent = '✗ OCR network error';
        statusEl.style.color = 'var(--accent-danger)';
      });
  }

  // ========== CAMERA CAPTURE ==========
  cameraBtn.addEventListener('click', async () => {
    try {
      currentStream = await navigator.mediaDevices.getUserMedia({ 
        video: { facingMode: 'environment' } 
      });
      cameraStream.srcObject = currentStream;
      cameraModal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
    } catch (err) {
      alert('Camera access denied or unavailable');
      console.error(err);
    }
  });

  closeCameraBtn.addEventListener('click', stopCamera);
  cancelCameraBtn.addEventListener('click', stopCamera);

  captureBtn.addEventListener('click', () => {
    const video = cameraStream;
    const canvas = cameraCanvas;
    const ctx = canvas.getContext('2d');

    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    ctx.drawImage(video, 0, 0);

    canvas.toBlob((blob) => {
      const file = new File([blob], 'camera-capture-' + Date.now() + '.jpg', { type: 'image/jpeg' });
      stopCamera();
      uploadFile(file);
    }, 'image/jpeg', 0.92);
  });

  function stopCamera() {
    if (currentStream) {
      currentStream.getTracks().forEach(track => track.stop());
      currentStream = null;
    }
    cameraModal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  // ========== UTILITIES ==========
  function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1024 / 1024).toFixed(1) + ' MB';
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }
})();