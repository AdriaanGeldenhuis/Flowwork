(function() {
  'use strict';

  const THEME_COOKIE = 'fw_theme';
  const THEME_DARK = 'dark';
  const THEME_LIGHT = 'light';

  // ========== UTILITIES ==========
  function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
    return match ? match[2] : null;
  }

  function setCookie(name, value, days = 365) {
    const date = new Date();
    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
    document.cookie = name + '=' + value + ';expires=' + date.toUTCString() + ';path=/;SameSite=Lax';
  }

  function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      clearTimeout(timeout);
      timeout = setTimeout(() => func(...args), wait);
    };
  }

  function showMessage(elementId, message, isError = false) {
    const el = document.getElementById(elementId);
    if (!el) return;
    el.textContent = message;
    el.className = 'fw-mail__form-message ' + (isError ? 'fw-mail__form-message--error' : 'fw-mail__form-message--success');
    el.style.display = 'block';
    setTimeout(() => { el.style.display = 'none'; }, 5000);
  }

  window.showMessage = showMessage;

  // ========== THEME TOGGLE ==========
  function initTheme() {
    const toggle = document.getElementById('themeToggle');
    const body = document.querySelector('.fw-mail');
    if (!toggle || !body) return;

    let theme = getCookie(THEME_COOKIE) || THEME_LIGHT;
    applyTheme(theme);

    toggle.addEventListener('click', () => {
      theme = theme === THEME_DARK ? THEME_LIGHT : THEME_DARK;
      applyTheme(theme);
      setCookie(THEME_COOKIE, theme);
    });

    function applyTheme(t) {
      body.setAttribute('data-theme', t);
    }
  }

  // ========== KEBAB MENU ==========
  function initKebabMenu() {
    const toggle = document.getElementById('kebabToggle');
    const menu = document.getElementById('kebabMenu');
    if (!toggle || !menu) return;

    toggle.addEventListener('click', (e) => {
      e.stopPropagation();
      const isOpen = menu.getAttribute('aria-hidden') === 'false';
      setMenuState(!isOpen);
    });

    document.addEventListener('click', (e) => {
      if (!menu.contains(e.target) && !toggle.contains(e.target)) {
        setMenuState(false);
      }
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && menu.getAttribute('aria-hidden') === 'false') {
        setMenuState(false);
        toggle.focus();
      }
    });

    function setMenuState(isOpen) {
      menu.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
      toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }
  }

  // ========== TABS ==========
  function initTabs() {
    const tabs = document.querySelectorAll('.fw-mail__tab');
    if (!tabs.length) return;

    tabs.forEach(tab => {
      tab.addEventListener('click', () => {
        const targetPanel = tab.getAttribute('data-tab');
        
        document.querySelectorAll('.fw-mail__tab').forEach(t => t.classList.remove('fw-mail__tab--active'));
        document.querySelectorAll('.fw-mail__tab-panel').forEach(p => p.classList.remove('fw-mail__tab-panel--active'));
        
        tab.classList.add('fw-mail__tab--active');
        const panel = document.querySelector(`.fw-mail__tab-panel[data-panel="${targetPanel}"]`);
        if (panel) {
          panel.classList.add('fw-mail__tab-panel--active');
        }
      });
    });
  }

  // ========== MAIL APP ==========
  const MailApp = {
    currentFolder: 'INBOX',
    currentThreadId: null,

    init: function() {
      this.loadFolders();
      this.initSearch();
    },

    loadFolders: function() {
      const container = document.getElementById('folderList');
      if (!container) return;

      container.innerHTML = '<div class="fw-mail__loading">Loading folders...</div>';

      fetch('/mail/ajax/folder_list.php')
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            this.renderFolders(data.folders);
            if (data.folders.length > 0) {
              this.loadThreads(data.folders[0].name);
            }
          } else {
            container.innerHTML = '<div class="fw-mail__loading">Error loading folders</div>';
          }
        })
        .catch(err => {
          console.error(err);
          container.innerHTML = '<div class="fw-mail__loading">Network error</div>';
        });
    },

    renderFolders: function(folders) {
      const container = document.getElementById('folderList');
      if (!container) return;

      const html = folders.map(folder => {
        const isActive = folder.name === this.currentFolder ? 'fw-mail__folder-item--active' : '';
        const unreadBadge = folder.unread > 0 ? `<span class="fw-mail__folder-badge">${folder.unread}</span>` : '';
        return `
          <a href="#" class="fw-mail__folder-item ${isActive}" data-folder="${folder.name}">
            <span>${folder.name}</span>
            ${unreadBadge}
          </a>
        `;
      }).join('');

      container.innerHTML = html;

      container.querySelectorAll('.fw-mail__folder-item').forEach(item => {
        item.addEventListener('click', (e) => {
          e.preventDefault();
          const folder = item.getAttribute('data-folder');
          this.loadThreads(folder);
        });
      });
    },

    loadThreads: function(folder) {
      this.currentFolder = folder;
      const container = document.getElementById('threads');
      const title = document.getElementById('threadListTitle');
      if (!container) return;

      if (title) title.textContent = folder;

      container.innerHTML = '<div class="fw-mail__loading">Loading threads...</div>';

      const params = new URLSearchParams({
        folder: folder,
        search: document.getElementById('threadSearch')?.value || '',
        filter: document.getElementById('filterRead')?.value || ''
      });

      fetch('/mail/ajax/thread_list.php?' + params.toString())
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            this.renderThreads(data.threads);
          } else {
            container.innerHTML = '<div class="fw-mail__loading">Error: ' + (data.error || 'Unknown error') + '</div>';
          }
        })
        .catch(err => {
          console.error(err);
          container.innerHTML = '<div class="fw-mail__loading">Network error</div>';
        });

      document.querySelectorAll('.fw-mail__folder-item').forEach(item => {
        item.classList.toggle('fw-mail__folder-item--active', item.getAttribute('data-folder') === folder);
      });
    },

    renderThreads: function(threads) {
      const container = document.getElementById('threads');
      if (!container) return;

      if (threads.length === 0) {
        container.innerHTML = '<div class="fw-mail__empty-state"><p>No messages</p></div>';
        return;
      }

      const html = threads.map(thread => {
        const unreadClass = thread.is_read ? '' : 'fw-mail__thread-card--unread';
        const selectedClass = thread.thread_id === this.currentThreadId ? 'fw-mail__thread-card--selected' : '';
        const initials = (thread.sender || '?').substring(0, 2).toUpperCase();
        const attachBadge = thread.has_attachments ? '<div class="fw-mail__thread-badge fw-mail__thread-badge--attachment">📎</div>' : '';
        const starBadge = thread.is_starred ? '<div class="fw-mail__thread-badge fw-mail__thread-badge--starred">★</div>' : '';
        
        return `
          <div class="fw-mail__thread-card ${unreadClass} ${selectedClass}" data-thread-id="${thread.thread_id}">
            <div class="fw-mail__thread-avatar">${initials}</div>
            <div class="fw-mail__thread-info">
              <div class="fw-mail__thread-sender">${thread.sender || 'Unknown'}</div>
              <div class="fw-mail__thread-subject">${thread.subject || '(No subject)'}</div>
              <div class="fw-mail__thread-preview">${thread.preview || ''}</div>
            </div>
            <div class="fw-mail__thread-meta">
              <div class="fw-mail__thread-time">${thread.time}</div>
              <div class="fw-mail__thread-badges">${attachBadge}${starBadge}</div>
            </div>
          </div>
        `;
      }).join('');

      container.innerHTML = html;

      container.querySelectorAll('.fw-mail__thread-card').forEach(card => {
        card.addEventListener('click', () => {
          const threadId = parseInt(card.getAttribute('data-thread-id'));
          this.loadMessage(threadId);
        });
      });
    },

    loadMessage: function(threadId) {
      this.currentThreadId = threadId;
      const container = document.getElementById('messagePreview');
      if (!container) return;

      container.innerHTML = '<div class="fw-mail__loading">Loading message...</div>';

      fetch('/mail/ajax/thread_get.php?thread_id=' + threadId)
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            this.renderMessage(data.message);
            this.markAsRead(threadId);
          } else {
            container.innerHTML = '<div class="fw-mail__loading">Error loading message</div>';
          }
        })
        .catch(err => {
          console.error(err);
          container.innerHTML = '<div class="fw-mail__loading">Network error</div>';
        });

      document.querySelectorAll('.fw-mail__thread-card').forEach(card => {
        card.classList.toggle('fw-mail__thread-card--selected', parseInt(card.getAttribute('data-thread-id')) === threadId);
      });
    },

    renderMessage: function(msg) {
      const container = document.getElementById('messagePreview');
      if (!container) return;

      const initials = (msg.sender || '?').substring(0, 2).toUpperCase();
      
      const attachmentsHtml = msg.attachments && msg.attachments.length > 0 ? `
        <div class="fw-mail__attachments">
          <div class="fw-mail__attachments-title">Attachments</div>
          <div class="fw-mail__attachment-list">
            ${msg.attachments.map(att => `
              <a href="/mail/ajax/download_attachment.php?id=${att.attachment_id}" class="fw-mail__attachment-item" target="_blank">
                <svg class="fw-mail__attachment-icon" viewBox="0 0 24 24" fill="none"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                ${att.file_name}
              </a>
            `).join('')}
          </div>
        </div>
      ` : '';

      const linksHtml = msg.links && msg.links.length > 0 ? `
        <div class="fw-mail__preview-links">
          <div class="fw-mail__preview-links-title">Linked to</div>
          <div class="fw-mail__link-chips">
            ${msg.links.map(link => `
              <a href="${link.url}" class="fw-mail__link-chip">${link.type}: ${link.name}</a>
            `).join('')}
          </div>
        </div>
      ` : '';

      const html = `
        <div class="fw-mail__preview-header">
          <h2 class="fw-mail__preview-subject">${msg.subject || '(No subject)'}</h2>
          <div class="fw-mail__preview-from">
            <div class="fw-mail__preview-avatar">${initials}</div>
            <div class="fw-mail__preview-sender-info">
              <div class="fw-mail__preview-sender-name">${msg.sender_name || msg.sender}</div>
              <div class="fw-mail__preview-sender-email">${msg.sender}</div>
            </div>
            <div class="fw-mail__preview-date">${msg.sent_at}</div>
          </div>
          <div class="fw-mail__preview-actions">
            <button class="fw-mail__btn fw-mail__btn--secondary fw-mail__btn--small" onclick="location.href='/mail/compose.php?mode=reply&email_id=${msg.email_id}'">Reply</button>
            <button class="fw-mail__btn fw-mail__btn--secondary fw-mail__btn--small" onclick="location.href='/mail/compose.php?mode=reply_all&email_id=${msg.email_id}'">Reply All</button>
            <button class="fw-mail__btn fw-mail__btn--secondary fw-mail__btn--small" onclick="location.href='/mail/compose.php?mode=forward&email_id=${msg.email_id}'">Forward</button>
            <button class="fw-mail__btn fw-mail__btn--secondary fw-mail__btn--small" onclick="window.MailApp.showLinkModal(${msg.email_id})">Link...</button>
          </div>
        </div>
        <div class="fw-mail__preview-body">${msg.body_html || msg.body_text || '(No content)'}</div>
        ${attachmentsHtml}
        ${linksHtml}
      `;

      container.innerHTML = html;
    },

    markAsRead: function(threadId) {
      fetch('/mail/ajax/message_mark_read.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ thread_id: threadId })
      }).catch(err => console.error(err));
    },

    initSearch: function() {
      const searchInput = document.getElementById('threadSearch');
      const filterRead = document.getElementById('filterRead');
      
      if (searchInput) {
        searchInput.addEventListener('input', debounce(() => {
          this.loadThreads(this.currentFolder);
        }, 300));
      }

      if (filterRead) {
        filterRead.addEventListener('change', () => {
          this.loadThreads(this.currentFolder);
        });
      }
    },

    syncAll: function() {
      const btn = event.target;
      btn.disabled = true;
      btn.textContent = 'Syncing...';

      fetch('/mail/ajax/sync_run.php', { method: 'POST' })
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            alert('Sync completed: ' + data.message);
            this.loadFolders();
          } else {
            alert('Sync failed: ' + (data.error || 'Unknown error'));
          }
        })
        .catch(err => {
          console.error(err);
          alert('Sync error');
        })
        .finally(() => {
          btn.disabled = false;
          btn.textContent = 'Sync All';
        });
    },

    showLinkModal: function(emailId) {
      alert('Link modal for email ' + emailId + ' - Coming soon');
    }
  };

  // ========== MAIL SETTINGS ==========
  const MailSettings = {
    init: function() {
      console.log('MailSettings.init()');
      this.loadSignatures();
      this.loadRules();
      this.initPrefsForm();
      this.initAccountForm();
      this.initSignatureForm();
      this.initRuleForm();
      this.bindButtons();
    },

    bindButtons: function() {
      console.log('Binding buttons...');
      
      document.querySelectorAll('[data-action="add-account"]').forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          console.log('Add account clicked');
          this.showAccountModal(null);
        });
      });

      document.querySelectorAll('[data-action="edit-account"]').forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          const id = parseInt(btn.getAttribute('data-id'));
          console.log('Edit account clicked:', id);
          this.editAccount(id);
        });
      });

      document.querySelectorAll('[data-action="add-signature"]').forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          console.log('Add signature clicked');
          this.showSignatureModal(null);
        });
      });

      document.querySelectorAll('[data-action="add-rule"]').forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          console.log('Add rule clicked');
          this.showRuleModal(null);
        });
      });

      document.querySelectorAll('[data-action="close-modal"]').forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          const modalId = btn.getAttribute('data-modal');
          console.log('Close modal:', modalId);
          this.closeModal(modalId);
        });
      });
    },

    showAccountModal: function(accountId) {
      console.log('showAccountModal:', accountId);
      const modal = document.getElementById('accountModal');
      const form = document.getElementById('accountForm');
      const title = document.getElementById('accountModalTitle');
      
      if (!modal || !form) {
        console.error('Account modal not found');
        alert('ERROR: Modal not found');
        return;
      }
      
      if (accountId) {
        title.textContent = 'Edit Account';
        this.loadAccount(accountId);
      } else {
        title.textContent = 'Add Email Account';
        form.reset();
        document.getElementById('accountId').value = '';
      }

      modal.classList.add('fw-mail__modal-overlay--active');
      document.body.style.overflow = 'hidden';
    },

    closeModal: function(modalId) {
      console.log('closeModal:', modalId);
      const modal = document.getElementById(modalId);
      if (modal) {
        modal.classList.remove('fw-mail__modal-overlay--active');
        document.body.style.overflow = '';
      }
    },

    loadAccount: function(accountId) {
      fetch('/mail/ajax/account_get.php?id=' + accountId)
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            const form = document.getElementById('accountForm');
            Object.keys(data.account).forEach(key => {
              const input = form.elements[key];
              if (input) {
                if (input.type === 'checkbox') {
                  input.checked = data.account[key] == 1;
                } else if (key !== 'password_encrypted') {
                  input.value = data.account[key] || '';
                }
              }
            });
          }
        })
        .catch(err => console.error('Load account error:', err));
    },

    initAccountForm: function() {
      const form = document.getElementById('accountForm');
      if (!form) return;

      form.addEventListener('submit', (e) => {
        e.preventDefault();
        
        const formData = new FormData(form);
        const data = {};
        for (const [key, value] of formData.entries()) {
          data[key] = value;
        }

        if (data.account_id && !data.password) {
          delete data.password;
        }

        const btn = form.querySelector('button[type="submit"]');
        const originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Saving...';

        fetch('/mail/ajax/account_save.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(data)
        })
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            showMessage('accountMessage', 'Account saved successfully');
            setTimeout(() => location.reload(), 1500);
          } else {
            showMessage('accountMessage', 'Error: ' + (data.error || 'Unknown error'), true);
            btn.disabled = false;
            btn.textContent = originalText;
          }
        })
        .catch(err => {
          console.error('Save error:', err);
          showMessage('accountMessage', 'Network error', true);
          btn.disabled = false;
          btn.textContent = originalText;
        });
      });
    },

    editAccount: function(accountId) {
      this.showAccountModal(accountId);
    },

    testAccount: function(accountId) {
      const btn = event.target;
      btn.disabled = true;
      btn.textContent = 'Testing...';

      fetch('/mail/ajax/account_test.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ account_id: accountId })
      })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          alert('✓ Connection successful!\n\nIMAP: ' + data.imap + '\nSMTP: ' + data.smtp);
        } else {
          alert('✗ Connection failed:\n\n' + (data.error || 'Unknown error'));
        }
      })
      .catch(err => {
        alert('✗ Network error');
        console.error(err);
      })
      .finally(() => {
        btn.disabled = false;
        btn.textContent = 'Test';
      });
    },

    deleteAccount: function(accountId) {
      if (!confirm('Delete this account? All synced emails will be removed.')) return;

      fetch('/mail/ajax/account_delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ account_id: accountId })
      })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          alert('Account deleted');
          location.reload();
        } else {
          alert('Error: ' + (data.error || 'Unknown error'));
        }
      })
      .catch(err => {
        alert('Network error');
        console.error(err);
      });
    },

    loadSignatures: function() {
      const container = document.getElementById('signaturesList');
      if (!container) return;

      container.innerHTML = '<div class="fw-mail__loading">Loading signatures...</div>';

      fetch('/mail/ajax/signature_list.php')
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            if (data.signatures.length === 0) {
              container.innerHTML = '<div class="fw-mail__empty-state"><p>No signatures yet.</p></div>';
            } else {
              const html = data.signatures.map(sig => `
                <div class="fw-mail__account-card">
                  <div class="fw-mail__account-card-header">
                    <h3>${sig.name}</h3>
                    ${sig.is_default ? '<span class="fw-mail__badge fw-mail__badge--active">Default</span>' : ''}
                  </div>
                  <div class="fw-mail__account-card-body">
                    <div style="max-height:100px;overflow:auto;padding:8px;background:var(--fw-highlight);border-radius:8px;">${sig.content_html || '(Empty)'}</div>
                  </div>
                  <div class="fw-mail__card-actions">
                    <button class="fw-mail__btn fw-mail__btn--small" onclick="window.MailSettings.editSignature(${sig.signature_id})">Edit</button>
                    <button class="fw-mail__btn fw-mail__btn--small fw-mail__btn--danger" onclick="window.MailSettings.deleteSignature(${sig.signature_id})">Delete</button>
                  </div>
                </div>
              `).join('');
              container.innerHTML = html;
            }
          } else {
            container.innerHTML = '<div class="fw-mail__loading">Error loading signatures</div>';
          }
        })
        .catch(err => {
          console.error(err);
          container.innerHTML = '<div class="fw-mail__loading">Network error</div>';
        });
    },

    showSignatureModal: function(signatureId) {
      console.log('showSignatureModal:', signatureId);
      const modal = document.getElementById('signatureModal');
      const form = document.getElementById('signatureForm');
      const title = document.getElementById('signatureModalTitle');
      
      if (!modal || !form) {
        console.error('Signature modal not found');
        alert('ERROR: Signature modal not found');
        return;
      }
      
      if (signatureId) {
        title.textContent = 'Edit Signature';
        this.loadSignature(signatureId);
      } else {
        title.textContent = 'New Signature';
        form.reset();
        document.getElementById('signatureId').value = '';
      }

      modal.classList.add('fw-mail__modal-overlay--active');
      document.body.style.overflow = 'hidden';
    },

    loadSignature: function(signatureId) {
      fetch('/mail/ajax/signature_get.php?id=' + signatureId)
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            const form = document.getElementById('signatureForm');
            Object.keys(data.signature).forEach(key => {
              const input = form.elements[key];
              if (input) {
                if (input.type === 'checkbox') {
                  input.checked = data.signature[key] == 1;
                } else {
                  input.value = data.signature[key] || '';
                }
              }
            });
          }
        });
    },

    initSignatureForm: function() {
      const form = document.getElementById('signatureForm');
      if (!form) return;

      form.addEventListener('submit', (e) => {
        e.preventDefault();
        const formData = new FormData(form);
        const data = {};
        for (const [key, value] of formData.entries()) {
          data[key] = value;
        }

        const btn = form.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.textContent = 'Saving...';

        fetch('/mail/ajax/signature_save.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(data)
        })
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            showMessage('signatureMessage', 'Signature saved successfully');
            setTimeout(() => {
              MailSettings.closeModal('signatureModal');
              MailSettings.loadSignatures();
            }, 1000);
          } else {
            showMessage('signatureMessage', 'Error: ' + (data.error || 'Unknown error'), true);
            btn.disabled = false;
            btn.textContent = 'Save Signature';
          }
        })
        .catch(err => {
          console.error(err);
          showMessage('signatureMessage', 'Network error', true);
          btn.disabled = false;
          btn.textContent = 'Save Signature';
        });
      });
    },

    editSignature: function(id) {
      this.showSignatureModal(id);
    },

    deleteSignature: function(id) {
      if (!confirm('Delete this signature?')) return;

      fetch('/mail/ajax/signature_delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ signature_id: id })
      })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          this.loadSignatures();
        } else {
          alert('Error: ' + (data.error || 'Unknown error'));
        }
      })
      .catch(err => {
        alert('Network error');
        console.error(err);
      });
    },

    loadRules: function() {
      const container = document.getElementById('rulesList');
      if (!container) return;

      container.innerHTML = '<div class="fw-mail__loading">Loading rules...</div>';

      fetch('/mail/ajax/rule_list.php')
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            if (data.rules.length === 0) {
              container.innerHTML = '<div class="fw-mail__empty-state"><p>No rules yet.</p></div>';
            } else {
              const html = data.rules.map(rule => `
                <div class="fw-mail__account-card">
                  <div class="fw-mail__account-card-header">
                    <h3>${rule.name}</h3>
                    <span class="fw-mail__badge ${rule.is_active ? 'fw-mail__badge--active' : 'fw-mail__badge--inactive'}">${rule.is_active ? 'Active' : 'Inactive'}</span>
                  </div>
                  <div class="fw-mail__account-card-body">
                    <p><strong>Priority:</strong> ${rule.priority}</p>
                  </div>
                  <div class="fw-mail__card-actions">
                    <button class="fw-mail__btn fw-mail__btn--small" onclick="window.MailSettings.editRule(${rule.rule_id})">Edit</button>
                    <button class="fw-mail__btn fw-mail__btn--small fw-mail__btn--danger" onclick="window.MailSettings.deleteRule(${rule.rule_id})">Delete</button>
                  </div>
                </div>
              `).join('');
              container.innerHTML = html;
            }
          } else {
            container.innerHTML = '<div class="fw-mail__loading">Error loading rules</div>';
          }
        })
        .catch(err => {
          console.error(err);
          container.innerHTML = '<div class="fw-mail__loading">Network error</div>';
        });
    },

    showRuleModal: function(ruleId) {
      console.log('showRuleModal:', ruleId);
      const modal = document.getElementById('ruleModal');
      const form = document.getElementById('ruleForm');
      const title = document.getElementById('ruleModalTitle');
      
      if (!modal || !form) {
        console.error('Rule modal not found');
        alert('ERROR: Rule modal not found');
        return;
      }
      
      if (ruleId) {
        title.textContent = 'Edit Rule';
        this.loadRule(ruleId);
      } else {
        title.textContent = 'New Rule';
        form.reset();
        document.getElementById('ruleId').value = '';
        document.getElementById('conditionsContainer').innerHTML = this.renderCondition(null);
        document.getElementById('actionsContainer').innerHTML = this.renderAction(null);
      }

      modal.classList.add('fw-mail__modal-overlay--active');
      document.body.style.overflow = 'hidden';
    },

    loadRule: function(ruleId) {
      fetch('/mail/ajax/rule_get.php?id=' + ruleId)
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            const form = document.getElementById('ruleForm');
            form.elements['rule_id'].value = data.rule.rule_id;
            form.elements['name'].value = data.rule.name;
            form.elements['priority'].value = data.rule.priority;
            form.elements['is_active'].checked = data.rule.is_active == 1;
            form.elements['stop_processing'].checked = data.rule.stop_processing == 1;

            const conditions = data.rule.conditions_json ? JSON.parse(data.rule.conditions_json) : [];
            const actions = data.rule.actions_json ? JSON.parse(data.rule.actions_json) : [];

            document.getElementById('conditionsContainer').innerHTML = conditions.map(c => 
              this.renderCondition(c)
            ).join('');

            document.getElementById('actionsContainer').innerHTML = actions.map(a => 
              this.renderAction(a)
            ).join('');
          }
        });
    },

    renderCondition: function(condition) {
      return `
        <div class="fw-mail__rule-item">
          <select name="condition_field[]" class="fw-mail__input">
            <option value="from" ${condition?.field === 'from' ? 'selected' : ''}>From</option>
            <option value="to" ${condition?.field === 'to' ? 'selected' : ''}>To</option>
            <option value="subject" ${condition?.field === 'subject' ? 'selected' : ''}>Subject</option>
            <option value="body" ${condition?.field === 'body' ? 'selected' : ''}>Body</option>
          </select>
          <select name="condition_operator[]" class="fw-mail__input">
            <option value="contains" ${condition?.operator === 'contains' ? 'selected' : ''}>Contains</option>
            <option value="equals" ${condition?.operator === 'equals' ? 'selected' : ''}>Equals</option>
            <option value="starts_with" ${condition?.operator === 'starts_with' ? 'selected' : ''}>Starts with</option>
            <option value="ends_with" ${condition?.operator === 'ends_with' ? 'selected' : ''}>Ends with</option>
          </select>
          <input type="text" name="condition_value[]" class="fw-mail__input" placeholder="Value" value="${condition?.value || ''}" required>
          <button type="button" onclick="this.parentElement.remove()">Remove</button>
        </div>
      `;
    },

    renderAction: function(action) {
      return `
        <div class="fw-mail__rule-item">
          <select name="action_type[]" class="fw-mail__input">
            <option value="tag" ${action?.type === 'tag' ? 'selected' : ''}>Add Tag</option>
            <option value="move" ${action?.type === 'move' ? 'selected' : ''}>Move to Folder</option>
            <option value="mark_read" ${action?.type === 'mark_read' ? 'selected' : ''}>Mark as Read</option>
            <option value="star" ${action?.type === 'star' ? 'selected' : ''}>Star</option>
          </select>
          <input type="text" name="action_value[]" class="fw-mail__input" placeholder="Value (if needed)" value="${action?.value || ''}">
          <button type="button" onclick="this.parentElement.remove()">Remove</button>
        </div>
      `;
    },

    addCondition: function() {
      document.getElementById('conditionsContainer').insertAdjacentHTML('beforeend', this.renderCondition(null));
    },

    addAction: function() {
      document.getElementById('actionsContainer').insertAdjacentHTML('beforeend', this.renderAction(null));
    },

    initRuleForm: function() {
      const form = document.getElementById('ruleForm');
      if (!form) return;

      form.addEventListener('submit', (e) => {
        e.preventDefault();
        const formData = new FormData(form);
        
        const conditions = [];
        const fields = formData.getAll('condition_field[]');
        const operators = formData.getAll('condition_operator[]');
        const values = formData.getAll('condition_value[]');
        
        for (let i = 0; i < fields.length; i++) {
          conditions.push({
            field: fields[i],
            operator: operators[i],
            value: values[i]
          });
        }

        const actions = [];
        const actionTypes = formData.getAll('action_type[]');
        const actionValues = formData.getAll('action_value[]');
        
        for (let i = 0; i < actionTypes.length; i++) {
          actions.push({
            type: actionTypes[i],
            value: actionValues[i] || null
          });
        }

        const data = {
          rule_id: formData.get('rule_id') || null,
          name: formData.get('name'),
          priority: parseInt(formData.get('priority')),
          is_active: formData.get('is_active') ? 1 : 0,
          stop_processing: formData.get('stop_processing') ? 1 : 0,
          conditions_json: JSON.stringify(conditions),
          actions_json: JSON.stringify(actions)
        };

        const btn = form.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.textContent = 'Saving...';

        fetch('/mail/ajax/rule_save.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(data)
        })
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            showMessage('ruleMessage', 'Rule saved successfully');
            setTimeout(() => {
              MailSettings.closeModal('ruleModal');
              MailSettings.loadRules();
            }, 1000);
          } else {
            showMessage('ruleMessage', 'Error: ' + (data.error || 'Unknown error'), true);
            btn.disabled = false;
            btn.textContent = 'Save Rule';
          }
        })
        .catch(err => {
          console.error(err);
          showMessage('ruleMessage', 'Network error', true);
          btn.disabled = false;
          btn.textContent = 'Save Rule';
        });
      });
    },

    editRule: function(id) {
      this.showRuleModal(id);
    },

    deleteRule: function(id) {
      if (!confirm('Delete this rule?')) return;

      fetch('/mail/ajax/rule_delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ rule_id: id })
      })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          this.loadRules();
        } else {
          alert('Error: ' + (data.error || 'Unknown error'));
        }
      })
      .catch(err => {
        alert('Network error');
        console.error(err);
      });
    },

    initPrefsForm: function() {
      const form = document.getElementById('prefsForm');
      if (!form) return;

      form.addEventListener('submit', (e) => {
        e.preventDefault();
        const formData = new FormData(form);
        const data = {};
        for (const [key, value] of formData.entries()) {
          data[key] = value;
        }

        fetch('/mail/ajax/prefs_save.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(data)
        })
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            showMessage('prefsMessage', 'Preferences saved successfully');
          } else {
            showMessage('prefsMessage', 'Error: ' + (data.error || 'Unknown error'), true);
          }
        })
        .catch(err => {
          console.error(err);
          showMessage('prefsMessage', 'Network error', true);
        });
      });
    }
  };

  // ========== MAIL TEMPLATES ==========
  const MailTemplates = {
    init: function() {
      // Templates are rendered server-side
    },

    showModal: function(templateId) {
      const modal = document.getElementById('templateModal');
      const form = document.getElementById('templateForm');
      const title = document.getElementById('templateModalTitle');
      
      if (!modal || !form) {
        console.error('Template modal not found');
        return;
      }
      
      if (templateId) {
        title.textContent = 'Edit Template';
        this.loadTemplate(templateId);
      } else {
        title.textContent = 'New Template';
        form.reset();
        document.getElementById('templateId').value = '';
      }

      modal.classList.add('fw-mail__modal-overlay--active');
      document.body.style.overflow = 'hidden';
    },

    closeModal: function() {
      const modal = document.getElementById('templateModal');
      if (modal) {
        modal.classList.remove('fw-mail__modal-overlay--active');
        document.body.style.overflow = '';
      }
    },

    loadTemplate: function(templateId) {
      fetch('/mail/ajax/template_get.php?id=' + templateId)
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            const form = document.getElementById('templateForm');
            Object.keys(data.template).forEach(key => {
              const input = form.elements[key];
              if (input) {
                input.value = data.template[key] || '';
              }
            });
          }
        });
    },

    edit: function(templateId) {
      this.showModal(templateId);
    },

    use: function(templateId) {
      location.href = '/mail/compose.php?template_id=' + templateId;
    },

    delete: function(templateId) {
      if (!confirm('Delete this template?')) return;

      fetch('/mail/ajax/template_delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ template_id: templateId })
      })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          location.reload();
        } else {
          alert('Error: ' + (data.error || 'Unknown error'));
        }
      });
    }
  };

  // ========== COMPOSE ==========
  const MailCompose = {
    init: function() {
      this.initTemplateSelect();
      this.initSignatureSelect();
      this.initAttachments();
      this.initForm();
    },

    initTemplateSelect: function() {
      const select = document.getElementById('templateSelect');
      if (!select) return;

      select.addEventListener('change', () => {
        const templateId = select.value;
        if (!templateId) return;

        fetch('/mail/ajax/template_get.php?id=' + templateId)
          .then(res => res.json())
          .then(data => {
            if (data.ok) {
              const form = document.getElementById('composeForm');
              if (data.template.subject) {
                form.elements['subject'].value = data.template.subject;
              }
              if (data.template.body_html) {
                document.getElementById('composeBody').value = data.template.body_html;
              }
            }
          });
      });
    },

    initSignatureSelect: function() {
      const select = document.getElementById('signatureSelect');
      if (!select) return;

      select.addEventListener('change', () => {
        const signatureId = select.value;
        if (!signatureId) return;

        fetch('/mail/ajax/signature_get.php?id=' + signatureId)
          .then(res => res.json())
          .then(data => {
            if (data.ok && data.signature.content_html) {
              const body = document.getElementById('composeBody');
              body.value += '\n\n' + data.signature.content_html;
            }
          });
      });
    },

    initAttachments: function() {
      const input = document.getElementById('attachmentInput');
      const list = document.getElementById('attachmentList');
      if (!input || !list) return;

      input.addEventListener('change', () => {
        const files = Array.from(input.files);
        list.innerHTML = files.map(f => `<div class="fw-mail__attachment-item">${f.name} (${(f.size / 1024).toFixed(1)} KB)</div>`).join('');
      });
    },

    initForm: function() {
      const form = document.getElementById('composeForm');
      if (!form) return;

      form.addEventListener('submit', (e) => {
        e.preventDefault();
        
        const formData = new FormData(form);
        
        const btn = form.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.textContent = 'Sending...';

        fetch('/mail/ajax/compose_send.php', {
          method: 'POST',
          body: formData
        })
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            showMessage('composeMessage', 'Message sent successfully!');
            setTimeout(() => location.href = '/mail/', 2000);
          } else {
            showMessage('composeMessage', 'Error: ' + (data.error || 'Unknown error'), true);
            btn.disabled = false;
            btn.textContent = 'Send';
          }
        })
        .catch(err => {
          console.error(err);
          showMessage('composeMessage', 'Network error', true);
          btn.disabled = false;
          btn.textContent = 'Send';
        });
      });
    }
  };

  // EXPOSE TO WINDOW
  window.MailApp = MailApp;
  window.MailSettings = MailSettings;
  window.MailTemplates = MailTemplates;
  window.MailCompose = MailCompose;

  // ========== INIT ==========
  function init() {
    console.log('🚀 Mail app init...');
    initTheme();
    initKebabMenu();
    initTabs();

    if (document.getElementById('folderList')) {
      MailApp.init();
    }
    if (document.getElementById('signaturesList')) {
      MailSettings.init();
    }
    if (document.getElementById('composeForm')) {
      MailCompose.init();
    }

    console.log('✅ MailSettings exposed:', typeof window.MailSettings);
    console.log('✅ MailApp exposed:', typeof window.MailApp);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();