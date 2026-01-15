/**
 * ============================================================================
 * FLOWWORK PROJECTS — INDEX PAGE
 * Projects list, search, filters, pagination, and new project creation
 * ============================================================================
 */

(function() {
  'use strict';

  // ===== GLOBAL STATE =====
  let allProjects = [];
  let filteredProjects = [];
  let currentPage = 1;
  const itemsPerPage = 12;

  // ===== MODAL SYSTEM =====
  window.ProjModal = {
    open(modalId) {
      const modal = document.getElementById(modalId);
      if (!modal) return;
      modal.classList.add('show');
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      document.body.classList.add('fw-modal-open');
      
      // Focus first input
      setTimeout(() => {
        const firstInput = modal.querySelector('input:not([type="hidden"]), textarea, select');
        if (firstInput) firstInput.focus();
      }, 100);
    },

    close(modalId) {
      const modal = document.getElementById(modalId);
      if (!modal) return;
      modal.classList.remove('show');
      modal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      document.body.classList.remove('fw-modal-open');
      
      // Reset form
      const form = modal.querySelector('form');
      if (form) {
        form.reset();
        const msg = modal.querySelector('.fw-form-message');
        if (msg) msg.style.display = 'none';
      }
    },

    showMessage(elementId, message, type = 'success') {
      const el = document.getElementById(elementId);
      if (!el) return;
      el.textContent = message;
      el.className = `fw-form-message fw-form-message--${type}`;
      el.style.display = 'block';
    },

    hideMessage(elementId) {
      const el = document.getElementById(elementId);
      if (el) el.style.display = 'none';
    }
  };

  // ===== API HELPERS =====
  function getCSRFToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
  }

  async function apiRequest(endpoint, options = {}) {
    const defaults = {
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCSRFToken()
      }
    };

    const config = { ...defaults, ...options };
    if (config.body && typeof config.body === 'object') {
      config.body = JSON.stringify(config.body);
    }

    try {
      const response = await fetch(endpoint, config);
      const data = await response.json();
      
      if (!response.ok) {
        throw new Error(data.message || 'Request failed');
      }
      
      return data;
    } catch (error) {
      console.error('API Error:', error);
      throw error;
    }
  }

  // ===== UTILITY FUNCTIONS =====
  function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-GB', { 
      day: '2-digit', 
      month: 'short', 
      year: 'numeric' 
    });
  }

  function getProjectInitials(name) {
    return name
      .split(' ')
      .map(word => word[0])
      .join('')
      .substring(0, 2)
      .toUpperCase();
  }

  function getProjectColor(name) {
    const colors = [
      '#6c5ce7', '#00b894', '#fdcb6e', '#e17055', 
      '#74b9ff', '#a29bfe', '#fd79a8', '#00cec9'
    ];
    const index = name.charCodeAt(0) % colors.length;
    return colors[index];
  }

  function getStatusBadgeClass(status) {
    const statusMap = {
      'active': 'status-active',
      'completed': 'status-completed',
      'on_hold': 'status-hold',
      'cancelled': 'status-cancelled'
    };
    return statusMap[status] || 'status-active';
  }

  function getStatusLabel(status) {
    const labels = {
      'active': 'Active',
      'completed': 'Completed',
      'on_hold': 'On Hold',
      'cancelled': 'Cancelled'
    };
    return labels[status] || status;
  }

  // ===== RENDER FUNCTIONS =====
  function renderProjects(projects) {
    const container = document.getElementById('projectsList');
    
    if (!projects || projects.length === 0) {
      container.innerHTML = `
        <div class="fw-empty-state" style="grid-column: 1 / -1;">
          <div style="font-size: 64px; margin-bottom: 16px; opacity: 0.3;">📂</div>
          <div style="font-size: 24px; font-weight: 700; margin-bottom: 8px; color: var(--text-primary);">
            No Projects Found
          </div>
          <div style="font-size: 16px; color: var(--text-tertiary);">
            Create your first project to get started
          </div>
        </div>
      `;
      return;
    }

    container.innerHTML = projects.map(project => {
      const color = getProjectColor(project.name);
      const initials = getProjectInitials(project.name);
      
      return `
        <a href="/projects/view.php?project_id=${project.id}" class="fw-project-card">
          <div class="fw-project-card__avatar" style="background: ${color};">
            ${initials}
          </div>
          <div class="fw-project-card__content">
            <div class="fw-project-card__header">
              <h3 class="fw-project-card__title">${escapeHtml(project.name)}</h3>
              <span class="fw-status-badge ${getStatusBadgeClass(project.status)}">
                ${getStatusLabel(project.status)}
              </span>
            </div>
            <div class="fw-project-card__meta">
              <span>📅 ${formatDate(project.start_date)} - ${formatDate(project.end_date)}</span>
              <span>👤 ${escapeHtml(project.manager_name || 'No manager')}</span>
            </div>
            <div class="fw-project-card__stats">
              <div class="fw-project-stat">
                <span class="fw-project-stat__value">${project.board_count || 0}</span>
                <span class="fw-project-stat__label">Boards</span>
              </div>
              <div class="fw-project-stat">
                <span class="fw-project-stat__value">${project.task_count || 0}</span>
                <span class="fw-project-stat__label">Tasks</span>
              </div>
              <div class="fw-project-stat">
                <span class="fw-project-stat__value">${project.member_count || 0}</span>
                <span class="fw-project-stat__label">Members</span>
              </div>
            </div>
          </div>
        </a>
      `;
    }).join('');
  }

  function renderPagination(totalItems) {
    const container = document.getElementById('projectsPagination');
    const totalPages = Math.ceil(totalItems / itemsPerPage);
    
    if (totalPages <= 1) {
      container.innerHTML = '';
      return;
    }

    let html = `
      <button class="fw-proj__page-btn" ${currentPage === 1 ? 'disabled' : ''} 
              onclick="ProjIndex.goToPage(${currentPage - 1})">
        ‹ Previous
      </button>
    `;

    // Page numbers
    for (let i = 1; i <= totalPages; i++) {
      if (
        i === 1 || 
        i === totalPages || 
        (i >= currentPage - 2 && i <= currentPage + 2)
      ) {
        html += `
          <button class="fw-proj__page-btn ${i === currentPage ? 'fw-proj__page-btn--active' : ''}" 
                  onclick="ProjIndex.goToPage(${i})">
            ${i}
          </button>
        `;
      } else if (i === currentPage - 3 || i === currentPage + 3) {
        html += `<span style="padding: 0 8px; color: var(--text-muted);">...</span>`;
      }
    }

    html += `
      <button class="fw-proj__page-btn" ${currentPage === totalPages ? 'disabled' : ''} 
              onclick="ProjIndex.goToPage(${currentPage + 1})">
        Next ›
      </button>
    `;

    container.innerHTML = html;
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  // ===== FILTER & SEARCH =====
  function applyFilters() {
    const searchTerm = document.getElementById('projSearch')?.value.toLowerCase() || '';
    const statusFilter = document.getElementById('projFilterStatus')?.value || '';
    const managerFilter = document.getElementById('projFilterManager')?.value || '';

    filteredProjects = allProjects.filter(project => {
      const matchesSearch = !searchTerm || 
        project.name.toLowerCase().includes(searchTerm) ||
        (project.manager_name && project.manager_name.toLowerCase().includes(searchTerm));
      
      const matchesStatus = !statusFilter || project.status === statusFilter;
      
      const matchesManager = !managerFilter || 
        project.manager_user_id === parseInt(managerFilter);

      return matchesSearch && matchesStatus && matchesManager;
    });

    currentPage = 1;
    renderCurrentPage();
  }

  function renderCurrentPage() {
    const startIndex = (currentPage - 1) * itemsPerPage;
    const endIndex = startIndex + itemsPerPage;
    const pageProjects = filteredProjects.slice(startIndex, endIndex);
    
    renderProjects(pageProjects);
    renderPagination(filteredProjects.length);
  }

  // ===== DATA LOADING =====
  async function loadProjects() {
    try {
      const container = document.getElementById('projectsList');
      container.innerHTML = '<div class="fw-proj__loading">Loading projects...</div>';

      const data = await apiRequest('/projects/api/projects.php?action=list');
      
      if (data.success) {
        allProjects = data.projects || [];
        filteredProjects = [...allProjects];
        renderCurrentPage();
        await loadManagers();
      } else {
        throw new Error(data.message || 'Failed to load projects');
      }
    } catch (error) {
      console.error('Load projects error:', error);
      document.getElementById('projectsList').innerHTML = `
        <div class="fw-empty-state" style="grid-column: 1 / -1;">
          <div style="font-size: 48px; margin-bottom: 16px; opacity: 0.3;">⚠️</div>
          <div style="font-size: 18px; font-weight: 600; margin-bottom: 8px; color: #ef4444;">
            Error Loading Projects
          </div>
          <div style="font-size: 14px; color: var(--text-tertiary);">
            ${error.message}
          </div>
        </div>
      `;
    }
  }

  async function loadManagers() {
    try {
      const data = await apiRequest('/projects/api/projects.php?action=managers');
      
      if (data.success && data.managers) {
        const select = document.getElementById('projFilterManager');
        const modalSelect = document.getElementById('selectManager');
        
        const options = data.managers.map(user => 
          `<option value="${user.id}">${escapeHtml(user.name)}</option>`
        ).join('');
        
        if (select) select.innerHTML += options;
        if (modalSelect) modalSelect.innerHTML += options;
      }
    } catch (error) {
      console.error('Load managers error:', error);
    }
  }

  // ===== NEW PROJECT =====
  async function handleNewProject(e) {
    e.preventDefault();
    
    const form = e.target;
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);
    
    // Validation
    if (!data.name || data.name.trim().length < 3) {
      ProjModal.showMessage('newProjectMessage', 'Project name must be at least 3 characters', 'error');
      return;
    }

    try {
      const submitBtn = form.querySelector('button[type="submit"]');
      submitBtn.disabled = true;
      submitBtn.textContent = 'Creating...';

      const result = await apiRequest('/projects/api/projects.php?action=create', {
        method: 'POST',
        body: data
      });

      if (result.success) {
        ProjModal.showMessage('newProjectMessage', 'Project created successfully!', 'success');
        
        setTimeout(() => {
          ProjModal.close('modalNewProject');
          if (result.project_id) {
            window.location.href = `/projects/view.php?project_id=${result.project_id}`;
          } else {
            loadProjects();
          }
        }, 1000);
      } else {
        throw new Error(result.message || 'Failed to create project');
      }
    } catch (error) {
      console.error('Create project error:', error);
      ProjModal.showMessage('newProjectMessage', error.message, 'error');
      
      const submitBtn = form.querySelector('button[type="submit"]');
      submitBtn.disabled = false;
      submitBtn.textContent = 'Create Project';
    }
  }

  // ===== PUBLIC API =====
  window.ProjIndex = {
    goToPage(page) {
      const totalPages = Math.ceil(filteredProjects.length / itemsPerPage);
      if (page < 1 || page > totalPages) return;
      currentPage = page;
      renderCurrentPage();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  };

  // ===== EVENT LISTENERS =====
  function initEventListeners() {
    // New Project Button
    const btnNewProject = document.getElementById('btnNewProject');
    if (btnNewProject) {
      btnNewProject.addEventListener('click', () => ProjModal.open('modalNewProject'));
    }

    // New Project Form
    const formNewProject = document.getElementById('formNewProject');
    if (formNewProject) {
      formNewProject.addEventListener('submit', handleNewProject);
    }

    // Search
    const searchInput = document.getElementById('projSearch');
    if (searchInput) {
      let searchTimeout;
      searchInput.addEventListener('input', () => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(applyFilters, 300);
      });
    }

    // Filters
    const statusFilter = document.getElementById('projFilterStatus');
    const managerFilter = document.getElementById('projFilterManager');
    
    if (statusFilter) {
      statusFilter.addEventListener('change', applyFilters);
    }
    
    if (managerFilter) {
      managerFilter.addEventListener('change', applyFilters);
    }

    // Close modals on ESC
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        const openModal = document.querySelector('.fw-cell-picker-overlay.show');
        if (openModal) {
          ProjModal.close(openModal.id);
        }
      }
    });

    // Close modals on overlay click
    document.querySelectorAll('.fw-cell-picker-overlay').forEach(overlay => {
      overlay.addEventListener('click', (e) => {
        if (e.target === overlay) {
          ProjModal.close(overlay.id);
        }
      });
    });
  }

  // ===== INITIALIZATION =====
  document.addEventListener('DOMContentLoaded', () => {
    initEventListeners();
    loadProjects();
    console.log('✅ Projects Index initialized');
  });

})();