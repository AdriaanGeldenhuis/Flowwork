// /suppliers_ai/suppliers_ai.js
(function() {
  'use strict';

  const THEME_COOKIE = 'fw_theme';
  const THEME_DARK = 'dark';
  const THEME_LIGHT = 'light';

  // State
  let currentQuery = null;
  let currentQueryText = '';
  let shortlist = new Set();
  let searchInProgress = false;
  let allCandidates = [];

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

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `fw-suppliers-ai__toast fw-suppliers-ai__toast--${type}`;
    toast.textContent = message;
    toast.style.cssText = `
      position: fixed;
      top: 20px;
      right: 20px;
      padding: 16px 24px;
      background: var(--fw-panel-bg);
      border: 1px solid var(--fw-panel-border);
      border-radius: 12px;
      box-shadow: var(--fw-shadow-lg);
      backdrop-filter: blur(12px);
      z-index: 10000;
      animation: slideIn 0.3s ease;
      max-width: 400px;
      font-weight: 600;
      color: ${type === 'error' ? '#ef4444' : type === 'success' ? '#10b981' : 'var(--fw-text-primary)'};
    `;
    document.body.appendChild(toast);
    setTimeout(() => {
      toast.style.animation = 'slideOut 0.3s ease';
      setTimeout(() => toast.remove(), 300);
    }, 3000);
  }

  // ========== THEME TOGGLE ==========
  function initTheme() {
    const toggle = document.getElementById('themeToggle');
    const indicator = document.getElementById('themeIndicator');
    const body = document.querySelector('.fw-suppliers-ai');
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
      if (indicator) {
        indicator.textContent = 'Theme: ' + (t === THEME_DARK ? 'Dark' : 'Light');
      }
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

  // ========== SEARCH FUNCTIONALITY ==========
  function initSearch() {
    const searchBtn = document.getElementById('searchBtn');
    const nlInput = document.getElementById('nlSearchInput');
    const resultsArea = document.getElementById('resultsArea');
    const resultsList = document.getElementById('resultsList');
    const resultsCount = document.getElementById('resultsCount');
    const clearResultsBtn = document.getElementById('clearResultsBtn');

    if (!searchBtn || !nlInput) return;

    searchBtn.addEventListener('click', performSearch);
    
    nlInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && e.ctrlKey) {
        performSearch();
      }
    });

    if (clearResultsBtn) {
      clearResultsBtn.addEventListener('click', () => {
        resultsArea.style.display = 'none';
        resultsList.innerHTML = '';
        resultsCount.textContent = '0';
        currentQuery = null;
        currentQueryText = '';
        allCandidates = [];
      });
    }

    async function performSearch() {
      const query = nlInput.value.trim();
      if (!query) {
        showToast('Please enter a search query', 'warning');
        return;
      }

      if (searchInProgress) {
        showToast('Search already in progress', 'info');
        return;
      }

      searchInProgress = true;
      searchBtn.disabled = true;
      searchBtn.innerHTML = `
        <span class="fw-suppliers-ai__spinner"></span>
        Searching...
      `;

      currentQueryText = query;

      const filters = {
        radius: document.getElementById('filterRadius')?.value || 25,
        minScore: document.getElementById('filterMinScore')?.value || 0.55,
        compliance: document.getElementById('filterCompliance')?.value || '',
        source: document.getElementById('filterSource')?.value || ''
      };

      try {
        const response = await fetch('/suppliers_ai/ajax/search.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            query: query,
            filters: filters
          })
        });

        const data = await response.json();

        if (data.ok) {
          currentQuery = data.query_id;
          allCandidates = data.candidates;
          renderResults(data.candidates);
          resultsArea.style.display = 'block';
          resultsCount.textContent = data.candidates.length;
          showToast(`Found ${data.candidates.length} suppliers in ${data.took_ms}ms 🤖`, 'success');
        } else {
          showToast(data.error || 'Search failed', 'error');
        }
      } catch (err) {
        console.error('Search error:', err);
        showToast('Network error. Please try again.', 'error');
      } finally {
        searchInProgress = false;
        searchBtn.disabled = false;
        searchBtn.innerHTML = `
          <svg viewBox="0 0 24 24" fill="none">
            <circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2"/>
            <path d="m21 21-4.35-4.35" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
          Search
        `;
      }
    }
  }

  // ========== RENDER RESULTS ==========
  function renderResults(candidates) {
    const resultsList = document.getElementById('resultsList');
    if (!resultsList) return;

    if (candidates.length === 0) {
      resultsList.innerHTML = '<div class="fw-suppliers-ai__empty-state">No suppliers found matching your criteria</div>';
      return;
    }

    const html = candidates.map(c => {
      const initials = (c.name || '?').substring(0, 2).toUpperCase();
      const isSelected = shortlist.has(c.id);
      
      const categories = Array.isArray(c.categories) ? c.categories : (c.categories ? JSON.parse(c.categories) : []);
      const categoryTags = categories.map(cat => 
        `<span class="fw-suppliers-ai__tag">${escapeHtml(cat)}</span>`
      ).join('');

      const complianceBadge = getComplianceBadge(c.compliance_state);
      const sourceBadge = c.account_id ? '<span class="fw-suppliers-ai__badge fw-suppliers-ai__badge--crm">In CRM</span>' : '';

      const performance = c.performance ? JSON.parse(c.performance) : {};
      
      return `
        <div class="fw-suppliers-ai__supplier-card ${isSelected ? 'fw-suppliers-ai__supplier-card--selected' : ''}" data-candidate-id="${c.id}">
          <div class="fw-suppliers-ai__card-header">
            <div class="fw-suppliers-ai__card-avatar">${initials}</div>
            <div class="fw-suppliers-ai__card-info">
              <h3 class="fw-suppliers-ai__card-title">${escapeHtml(c.name)}</h3>
              <div class="fw-suppliers-ai__card-meta">
                ${c.distance_km ? `<span>📍 ${parseFloat(c.distance_km).toFixed(1)} km away</span>` : ''}
                ${c.phone ? `<span>📞 ${escapeHtml(c.phone)}</span>` : ''}
                ${c.email ? `<span>✉️ ${escapeHtml(c.email)}</span>` : ''}
              </div>
              <div class="fw-suppliers-ai__card-tags">
                ${categoryTags}
                ${complianceBadge}
                ${sourceBadge}
              </div>
            </div>
            <div class="fw-suppliers-ai__card-score">
              <span class="fw-suppliers-ai__score-value">${Math.round(parseFloat(c.score_final) * 100)}</span>
            </div>
          </div>

          <div class="fw-suppliers-ai__card-body">
            ${c.explanation ? `
              <div class="fw-suppliers-ai__card-explanation">
                <strong>🤖 AI Analysis:</strong> ${escapeHtml(c.explanation)}
              </div>
            ` : ''}

            ${performance.on_time_pct || performance.response_hours ? `
              <div class="fw-suppliers-ai__card-metrics">
                ${performance.on_time_pct ? `
                  <div class="fw-suppliers-ai__metric">
                    <div class="fw-suppliers-ai__metric-label">On-Time Delivery</div>
                    <div class="fw-suppliers-ai__metric-value">${parseFloat(performance.on_time_pct).toFixed(0)}%</div>
                  </div>
                ` : ''}
                ${performance.response_hours ? `
                  <div class="fw-suppliers-ai__metric">
                    <div class="fw-suppliers-ai__metric-label">Avg Response</div>
                    <div class="fw-suppliers-ai__metric-value">${parseFloat(performance.response_hours).toFixed(1)}h</div>
                  </div>
                ` : ''}
                ${performance.defect_rate ? `
                  <div class="fw-suppliers-ai__metric">
                    <div class="fw-suppliers-ai__metric-label">Defect Rate</div>
                    <div class="fw-suppliers-ai__metric-value">${parseFloat(performance.defect_rate).toFixed(1)}%</div>
                  </div>
                ` : ''}
              </div>
            ` : ''}
          </div>

          <div class="fw-suppliers-ai__card-footer">
            ${c.phone ? `
              <a href="tel:${c.phone}" class="fw-suppliers-ai__action-btn" data-action="call" data-candidate-id="${c.id}">
                <svg viewBox="0 0 24 24" fill="none"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" stroke="currentColor" stroke-width="2"/></svg>
                Call
              </a>
            ` : ''}
            ${c.phone ? `
              <a href="https://wa.me/${c.phone.replace(/[^0-9]/g, '')}" target="_blank" class="fw-suppliers-ai__action-btn" data-action="whatsapp" data-candidate-id="${c.id}">
                <svg viewBox="0 0 24 24" fill="none"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" stroke="currentColor" stroke-width="2"/></svg>
                WhatsApp
              </a>
            ` : ''}
            ${c.email ? `
              <button class="fw-suppliers-ai__action-btn" data-action="email" data-candidate-id="${c.id}" data-candidate-name="${escapeHtml(c.name)}" data-candidate-email="${escapeHtml(c.email)}">
                <svg viewBox="0 0 24 24" fill="none"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" stroke="currentColor" stroke-width="2"/><polyline points="22,6 12,13 2,6" stroke="currentColor" stroke-width="2"/></svg>
                Send RFQ
              </button>
            ` : ''}
            ${!c.account_id ? `
              <button class="fw-suppliers-ai__action-btn" data-action="add-to-crm" data-candidate-id="${c.id}">
                <svg viewBox="0 0 24 24" fill="none"><line x1="12" y1="5" x2="12" y2="19" stroke="currentColor" stroke-width="2"/><line x1="5" y1="12" x2="19" y2="12" stroke="currentColor" stroke-width="2"/></svg>
                Add to CRM
              </button>
            ` : `
              <a href="/crm/account_view.php?id=${c.account_id}" class="fw-suppliers-ai__action-btn">
                <svg viewBox="0 0 24 24" fill="none"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/></svg>
                View in CRM
              </a>
            `}
            <button class="fw-suppliers-ai__action-btn fw-suppliers-ai__action-btn--primary" data-action="shortlist-toggle" data-candidate-id="${c.id}">
              ${isSelected ? '✓ Remove from Shortlist' : '+ Add to Shortlist'}
            </button>
          </div>
        </div>
      `;
    }).join('');

    resultsList.innerHTML = html;
    attachCardListeners();
  }

  function getComplianceBadge(state) {
    const badges = {
      'valid': '<span class="fw-suppliers-ai__badge fw-suppliers-ai__badge--valid">✓ Compliant</span>',
      'expiring': '<span class="fw-suppliers-ai__badge fw-suppliers-ai__badge--expiring">⚠ Expiring Soon</span>',
      'expired': '<span class="fw-suppliers-ai__badge fw-suppliers-ai__badge--expired">✗ Expired</span>',
      'missing': '<span class="fw-suppliers-ai__badge fw-suppliers-ai__badge--missing">? No Docs</span>'
    };
    return badges[state] || '';
  }

  // ========== CARD ACTIONS ==========
  function attachCardListeners() {
    const resultsList = document.getElementById('resultsList');
    if (!resultsList) return;

    resultsList.addEventListener('click', async (e) => {
      const btn = e.target.closest('[data-action]');
      if (!btn) return;

      const action = btn.dataset.action;
      const candidateId = btn.dataset.candidateId;

      switch (action) {
        case 'call':
        case 'whatsapp':
          logAction(candidateId, action);
          break;
        
        case 'email':
          await openEmailModal(candidateId, btn.dataset.candidateName, btn.dataset.candidateEmail);
          break;
        
        case 'add-to-crm':
          await addToCRM(candidateId, btn);
          break;
        
        case 'shortlist-toggle':
          toggleShortlist(candidateId);
          break;
      }
    });
  }

  async function logAction(candidateId, action) {
    try {
      await fetch('/suppliers_ai/ajax/log_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          query_id: currentQuery,
          candidate_id: candidateId,
          action: action
        })
      });
    } catch (err) {
      console.error('Failed to log action:', err);
    }
  }

async function openEmailModal(candidateId, candidateName, candidateEmail) {
  const modal = document.getElementById('emailModal');
  if (!modal) return;

  // Lock body scroll
  document.body.classList.add('fw-suppliers-ai__modal-open');

  // Store current supplier
  window.currentEmailSupplier = {
    id: candidateId,
    name: candidateName,
    email: candidateEmail
  };

  // Show loading state
  document.getElementById('emailTo').value = candidateEmail;
  document.getElementById('emailSubject').value = 'Generating...';
  document.getElementById('emailBody').value = 'Generating professional RFQ email with AI... 🤖';
  document.getElementById('emailScope').value = '';

  modal.setAttribute('aria-hidden', 'false');

  // Generate email
  await generateEmail(candidateName, candidateEmail);
}

window.SupplierAI = {
  closeEmailModal: function() {
    const modal = document.getElementById('emailModal');
    if (modal) {
      modal.setAttribute('aria-hidden', 'true');
      // Unlock body scroll
      document.body.classList.remove('fw-suppliers-ai__modal-open');
      window.currentEmailSupplier = null;
    }
  },

  sendEmail: async function() {
    const to = document.getElementById('emailTo').value;
    const subject = document.getElementById('emailSubject').value;
    const body = document.getElementById('emailBody').value;

    if (!to || !subject || !body) {
      showToast('Please fill in all fields', 'warning');
      return;
    }

    // Open mailto
    const mailtoLink = `mailto:${encodeURIComponent(to)}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
    window.location.href = mailtoLink;

    // Log action
    if (window.currentEmailSupplier) {
      await logAction(window.currentEmailSupplier.id, 'email');
    }

    showToast('Email client opened 📧', 'success');
    SupplierAI.closeEmailModal();
  },

  regenerateEmail: async function() {
    if (!window.currentEmailSupplier) return;

    document.getElementById('emailBody').value = 'Regenerating with AI... 🤖';
    await generateEmail(window.currentEmailSupplier.name, window.currentEmailSupplier.email);
  }
};

// Close modal on overlay click
document.addEventListener('click', (e) => {
  if (e.target.classList.contains('fw-suppliers-ai__modal-overlay')) {
    SupplierAI.closeEmailModal();
  }
});

// Close modal on Escape key
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    const modal = document.getElementById('emailModal');
    if (modal && modal.getAttribute('aria-hidden') === 'false') {
      SupplierAI.closeEmailModal();
    }
  }
});

  // Email modal buttons
  document.addEventListener('DOMContentLoaded', () => {
    const sendBtn = document.getElementById('sendEmailBtn');
    const regenBtn = document.getElementById('regenerateEmailBtn');

    if (sendBtn) {
      sendBtn.addEventListener('click', SupplierAI.sendEmail);
    }

    if (regenBtn) {
      regenBtn.addEventListener('click', SupplierAI.regenerateEmail);
    }
  });

  async function addToCRM(candidateId, btn) {
    if (!confirm('Add this supplier to your CRM?')) return;

    btn.disabled = true;
    btn.innerHTML = '<span class="fw-suppliers-ai__spinner"></span> Adding...';

    try {
      const response = await fetch('/suppliers_ai/ajax/add_to_crm.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          query_id: currentQuery,
          candidate_id: candidateId
        })
      });

      const data = await response.json();

      if (data.ok) {
        showToast('Supplier added to CRM ✓', 'success');
        btn.outerHTML = `
          <a href="/crm/account_view.php?id=${data.account_id}" class="fw-suppliers-ai__action-btn">
            <svg viewBox="0 0 24 24" fill="none"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/></svg>
            View in CRM
          </a>
        `;
      } else {
        showToast(data.error || 'Failed to add supplier', 'error');
        btn.disabled = false;
        btn.textContent = 'Add to CRM';
      }
    } catch (err) {
      console.error('Add to CRM error:', err);
      showToast('Network error', 'error');
      btn.disabled = false;
      btn.textContent = 'Add to CRM';
    }
  }

  // ========== SHORTLIST MANAGEMENT ==========
  function toggleShortlist(candidateId) {
    if (shortlist.has(candidateId)) {
      shortlist.delete(candidateId);
    } else {
      shortlist.add(candidateId);
    }
    updateShortlistUI();
    renderResults(allCandidates);
  }

  function updateShortlistUI() {
    const shortlistCount = document.getElementById('shortlistCount');
    const shortlistBtn = document.getElementById('shortlistBtn');
    const generateRfqBtn = document.getElementById('generateRfqBtn');
    const shortlistBody = document.getElementById('shortlistBody');

    if (shortlistCount) shortlistCount.textContent = shortlist.size;
    if (shortlistBtn) shortlistBtn.disabled = shortlist.size === 0;
    if (generateRfqBtn) generateRfqBtn.disabled = shortlist.size === 0;

    if (shortlistBody) {
      if (shortlist.size === 0) {
        shortlistBody.innerHTML = '<p class="fw-suppliers-ai__empty-state">No suppliers shortlisted yet</p>';
      } else {
        const candidates = allCandidates.filter(c => shortlist.has(c.id));
        const html = candidates.map(c => `
          <div class="fw-suppliers-ai__shortlist-item">
            <div class="fw-suppliers-ai__shortlist-item-info">
              <div class="fw-suppliers-ai__shortlist-item-name">${escapeHtml(c.name)}</div>
              <div class="fw-suppliers-ai__shortlist-item-meta">Score: ${Math.round(parseFloat(c.score_final) * 100)}</div>
            </div>
            <button class="fw-suppliers-ai__shortlist-item-remove" data-candidate-id="${c.id}">
              <svg viewBox="0 0 24 24" fill="none">
                <line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2"/>
                <line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2"/>
              </svg>
            </button>
          </div>
        `).join('');
        shortlistBody.innerHTML = html;

        shortlistBody.querySelectorAll('[data-candidate-id]').forEach(btn => {
          btn.addEventListener('click', () => {
            toggleShortlist(btn.dataset.candidateId);
          });
        });
      }
    }
  }

  function initShortlist() {
    const shortlistBtn = document.getElementById('shortlistBtn');
    const shortlistPanel = document.getElementById('shortlistPanel');
    const shortlistCloseBtn = document.getElementById('shortlistCloseBtn');
    const clearShortlistBtn = document.getElementById('clearShortlistBtn');
    const generateRfqBtn = document.getElementById('generateRfqBtn');

    if (shortlistBtn && shortlistPanel) {
      shortlistBtn.addEventListener('click', () => {
        shortlistPanel.setAttribute('aria-hidden', 'false');
      });
    }

    if (shortlistCloseBtn && shortlistPanel) {
      shortlistCloseBtn.addEventListener('click', () => {
        shortlistPanel.setAttribute('aria-hidden', 'true');
      });
    }

    if (clearShortlistBtn) {
      clearShortlistBtn.addEventListener('click', () => {
        if (confirm('Clear entire shortlist?')) {
          shortlist.clear();
          updateShortlistUI();
          renderResults(allCandidates);
          showToast('Shortlist cleared', 'info');
        }
      });
    }

    if (generateRfqBtn) {
      generateRfqBtn.addEventListener('click', generateRFQ);
    }
  }

  async function generateRFQ() {
    if (shortlist.size === 0) {
      showToast('No suppliers selected', 'warning');
      return;
    }

    if (!confirm(`Generate RFQ for ${shortlist.size} suppliers?`)) return;

    try {
      // Use the bulk RFQ endpoint which generates separate RFQs for each supplier
      const response = await fetch('/suppliers_ai/ajax/generate_rfq_bulk.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          query_id: currentQuery,
          candidate_ids: Array.from(shortlist)
        })
      });
      const data = await response.json();
      if (data.ok) {
        showToast('RFQ(s) created successfully', 'success');
        // If only one RFQ created, redirect to its view
        if (Array.isArray(data.rfq_ids) && data.rfq_ids.length === 1) {
          setTimeout(() => {
            window.location.href = `/procurement/rfq_view.php?id=${data.rfq_ids[0]}`;
          }, 1500);
        }
        // Otherwise, remain on page; user can navigate via procurement module
      } else {
        showToast(data.error || 'Failed to create RFQs', 'error');
      }
    } catch (err) {
      console.error('RFQ generation error:', err);
      showToast('Network error', 'error');
    }
  }

  // ========== INIT ==========
  function init() {
    initTheme();
    initKebabMenu();
    initSearch();
    initShortlist();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();