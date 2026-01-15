/**
 * Flowwork Projects Index — Main Controller with Dropdown Menus
 * NO INLINE STYLES - All styling via CSS classes
 */

(() => {
  'use strict';

  function getCSRF() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
  }

  function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      clearTimeout(timeout);
      timeout = setTimeout(() => func(...args), wait);
    };
  }

  // ===== MODAL SYSTEM (NO INLINE STYLES) =====
  window.ProjModal = {
    open: function(modalId) {
      const modal = document.getElementById(modalId);
      if (modal) {
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        document.body.classList.add('fw-modal-open');
      }
    },
    close: function(modalId) {
      const modal = document.getElementById(modalId);
      if (modal) {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        document.body.classList.remove('fw-modal-open');
      }
    }
  };

  // Close modals on overlay click
  document.addEventListener('click', (e) => {
    if (e.target.classList.contains('fw-cell-picker-overlay')) {
      ProjModal.close(e.target.id);
    }
  });

  // ===== DROPDOWN MENU SYSTEM =====
  let currentDropdown = null;

  function closeAllDropdowns() {
    document.querySelectorAll('.fw-project-dropdown').forEach(dd => {
      dd.classList.remove('show');
    });
    currentDropdown = null;
  }

  function showDropdown(dropdown, button) {
    closeAllDropdowns();
    
    // Get button position
    const rect = button.getBoundingClientRect();
    
    // Position dropdown
    dropdown.style.position = 'fixed';
    dropdown.style.top = (rect.bottom + 8) + 'px';
    dropdown.style.left = (rect.right - 200) + 'px'; // Align to right edge of button
    
    // Show dropdown
    dropdown.classList.add('show');
    currentDropdown = dropdown;
    
    // Adjust if dropdown goes off screen
    setTimeout(() => {
      const dropdownRect = dropdown.getBoundingClientRect();
      
      // Check right edge
      if (dropdownRect.right > window.innerWidth - 10) {
        dropdown.style.left = (window.innerWidth - dropdownRect.width - 10) + 'px';
      }
      
      // Check left edge
      if (dropdownRect.left < 10) {
        dropdown.style.left = '10px';
      }
      
      // Check bottom edge
      if (dropdownRect.bottom > window.innerHeight - 10) {
        dropdown.style.top = (rect.top - dropdownRect.height - 8) + 'px';
      }
    }, 0);
  }

  // Close dropdowns when clicking outside
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.fw-project-dropdown') && !e.target.closest('.fw-project-menu-btn')) {
      closeAllDropdowns();
    }
  });

  // Close on escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      closeAllDropdowns();
    }
  });

  // ===== PROJECT ACTIONS =====
  window.ProjectActions = {
    edit: function(projectId) {
      closeAllDropdowns();
      console.log('✏️ Edit project:', projectId);
      
      // Load project data
      fetch('/projects/api/project.view.php?project_id=' + projectId)
        .then(res => res.json())
        .then(data => {
          if (data.ok && data.data.project) {
            const proj = data.data.project;
            
            // Populate edit form
            document.getElementById('editProjectId').value = proj.project_id;
            document.getElementById('editProjectName').value = proj.name || '';
            document.getElementById('editProjectStartDate').value = proj.start_date || '';
            document.getElementById('editProjectEndDate').value = proj.end_date || '';
            document.getElementById('editProjectBudget').value = proj.budget || '';
            
            // Load managers if not already loaded
            const managerSelect = document.getElementById('editProjectManager');
            if (managerSelect.options.length === 1) {
              fetch('/projects/api/users.list.php')
                .then(res => res.json())
                .then(data => {
                  if (data.ok) {
                    data.data.users.forEach(user => {
                      const option = document.createElement('option');
                      option.value = user.id;
                      option.textContent = `${user.first_name} ${user.last_name}`;
                      if (user.id == proj.project_manager_id) option.selected = true;
                      managerSelect.appendChild(option);
                    });
                  }
                });
            } else {
              managerSelect.value = proj.project_manager_id || '';
            }
            
            ProjModal.open('modalEditProject');
          } else {
            alert('Failed to load project details');
          }
        })
        .catch(err => {
          console.error('Load project error:', err);
          alert('Network error');
        });
    },

    duplicate: function(projectId) {
      closeAllDropdowns();
      
      if (!confirm('Duplicate this project?\n\nThis will create a copy with all boards and columns (but not items).')) {
        return;
      }
      
      console.log('📋 Duplicate project:', projectId);
      
      const formData = new FormData();
      formData.append('project_id', projectId);
      formData.append('csrf', getCSRF());
      
      fetch('/projects/api/project.duplicate.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          alert('✅ Project duplicated successfully!');
          window.location.reload();
        } else {
          alert('Failed to duplicate project: ' + (data.error || 'Unknown error'));
        }
      })
      .catch(err => {
        console.error('Duplicate error:', err);
        alert('Network error');
      });
    },

    archive: function(projectId) {
      closeAllDropdowns();
      
      if (!confirm('Archive this project?\n\nYou can restore it later from the archive.')) {
        return;
      }
      
      console.log('📦 Archive project:', projectId);
      
      const formData = new FormData();
      formData.append('project_id', projectId);
      formData.append('csrf', getCSRF());
      
      fetch('/projects/api/project.archive.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          alert('✅ Project archived successfully!');
          window.location.reload();
        } else {
          alert('Failed to archive project: ' + (data.error || 'Unknown error'));
        }
      })
      .catch(err => {
        console.error('Archive error:', err);
        alert('Network error');
      });
    },

    delete: function(projectId, projectName) {
      closeAllDropdowns();
      
      if (!confirm(`⚠️ DELETE PROJECT?\n\nThis will permanently delete "${projectName}" and ALL its data:\n\n• All boards\n• All items\n• All files\n• All history\n\nTHIS CANNOT BE UNDONE!`)) {
        return;
      }
      
      const confirmName = prompt(`Type the project name to confirm deletion:\n\n"${projectName}"`);
      if (confirmName !== projectName) {
        alert('Project name does not match. Deletion cancelled.');
        return;
      }
      
      console.log('🗑️ Delete project:', projectId);
      
      const formData = new FormData();
      formData.append('project_id', projectId);
      formData.append('csrf', getCSRF());
      
      fetch('/projects/api/project.delete.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          alert('🗑️ Project deleted successfully');
          window.location.reload();
        } else {
          alert('Failed to delete project: ' + (data.error || 'Unknown error'));
        }
      })
      .catch(err => {
        console.error('Delete error:', err);
        alert('Network error');
      });
    }
  };

  // ===== PROJECT LIST =====
  function initProjectList() {
    const listContainer = document.getElementById('projectsList');
    const searchInput = document.getElementById('projSearch');
    const filterStatus = document.getElementById('projFilterStatus');
    const filterManager = document.getElementById('projFilterManager');
    const paginationContainer = document.getElementById('projectsPagination');

    if (!listContainer) return;

    let currentPage = 1;
    const perPage = 50;

    function loadProjects() {
      const search = searchInput ? searchInput.value : '';
      const status = filterStatus ? filterStatus.value : '';
      const manager = filterManager ? filterManager.value : '';

      const params = new URLSearchParams({
        q: search,
        status: status,
        page: currentPage,
        per: perPage
      });

      listContainer.innerHTML = '<div class="fw-proj__loading">Loading projects...</div>';

      fetch('/projects/api/project.list.php?' + params.toString())
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            renderProjects(data.data.projects);
            renderPagination(data.data.pagination);
          } else {
            listContainer.innerHTML = '<div class="fw-proj__loading">Error: ' + (data.error || 'Unknown error') + '</div>';
          }
        })
        .catch(err => {
          listContainer.innerHTML = '<div class="fw-proj__loading">Network error</div>';
          console.error(err);
        });
    }

    function renderProjects(projects) {
      if (projects.length === 0) {
        listContainer.innerHTML = `
          <div class="fw-empty-state" style="grid-column: 1 / -1;">
            <div style="font-size: 64px; margin-bottom: 16px; opacity: 0.3;">📂</div>
            <div style="font-size: 24px; font-weight: 700; margin-bottom: 8px; color: var(--fw-text-primary);">
              No Projects Found
            </div>
            <div style="font-size: 16px; color: var(--fw-text-tertiary);">
              Create your first project to get started
            </div>
          </div>
        `;
        return;
      }

      const html = projects.map(proj => {
        const initials = (proj.name || '?').substring(0, 2).toUpperCase();
        const statusColors = {
          active: '#00c875',
          completed: '#0073ea',
          on_hold: '#fdab3d',
          cancelled: '#64748b'
        };
        const statusColor = statusColors[proj.status] || '#64748b';
        const managerName = proj.manager_first ? `${proj.manager_first} ${proj.manager_last}` : 'No manager';
        
        // Escape project name for JavaScript
        const escapedName = proj.name.replace(/'/g, "\\'").replace(/"/g, '&quot;');
        
        return `
          <div class="fw-project-card" data-project-id="${proj.project_id}">
            <a href="/projects/view.php?project_id=${proj.project_id}" class="fw-project-card__link">
              <div class="fw-project-card__avatar" style="background: linear-gradient(135deg, #6c5ce7, #8b5cf6);">
                ${initials}
              </div>
              <div class="fw-project-card__content">
                <div class="fw-project-card__header">
                  <h3 class="fw-project-card__title">${proj.name}</h3>
                  <span class="fw-status-badge" style="background: ${statusColor};">
                    ${proj.status.toUpperCase()}
                  </span>
                </div>
                <div class="fw-project-card__meta">
                  <span>👤 ${managerName}</span>
                  ${proj.start_date ? `<span>📅 ${proj.start_date}</span>` : ''}
                </div>
                <div class="fw-project-card__stats">
                  <div class="fw-project-stat">
                    <span class="fw-project-stat__value">${proj.board_count || 0}</span>
                    <span class="fw-project-stat__label">Boards</span>
                  </div>
                  <div class="fw-project-stat">
                    <span class="fw-project-stat__value">${proj.item_count || 0}</span>
                    <span class="fw-project-stat__label">Items</span>
                  </div>
                  <div class="fw-project-stat">
                    <span class="fw-project-stat__value">0</span>
                    <span class="fw-project-stat__label">Members</span>
                  </div>
                </div>
              </div>
            </a>
            <button class="fw-project-menu-btn" data-project-id="${proj.project_id}" data-project-name="${escapedName}" title="More actions">
              <svg viewBox="0 0 24 24" fill="currentColor">
                <circle cx="12" cy="5" r="2"/>
                <circle cx="12" cy="12" r="2"/>
                <circle cx="12" cy="19" r="2"/>
              </svg>
            </button>
          </div>
        `;
      }).join('');

      listContainer.innerHTML = html;
      
      // Create single dropdown container at body level
      let dropdownContainer = document.getElementById('projectDropdownContainer');
      if (!dropdownContainer) {
        dropdownContainer = document.createElement('div');
        dropdownContainer.id = 'projectDropdownContainer';
        dropdownContainer.className = 'fw-project-dropdown';
        dropdownContainer.innerHTML = `
          <button class="fw-project-dropdown__item" data-action="edit">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
              <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
            </svg>
            Edit Project
          </button>
          <button class="fw-project-dropdown__item" data-action="duplicate">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="9" y="9" width="13" height="13" rx="2"/>
              <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
            </svg>
            Duplicate
          </button>
          <button class="fw-project-dropdown__item" data-action="archive">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 8v13H3V8M1 3h22v5H1zM10 12h4"/>
            </svg>
            Archive
          </button>
          <div class="fw-project-dropdown__divider"></div>
          <button class="fw-project-dropdown__item fw-project-dropdown__item--danger" data-action="delete">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
            </svg>
            Delete Project
          </button>
        `;
        document.body.appendChild(dropdownContainer);
        
        // Attach action listeners to dropdown items
        dropdownContainer.querySelectorAll('[data-action]').forEach(item => {
          item.addEventListener('click', (e) => {
            e.stopPropagation();
            const action = item.dataset.action;
            const projectId = parseInt(dropdownContainer.dataset.projectId);
            const projectName = dropdownContainer.dataset.projectName;
            
            if (action === 'edit') {
              ProjectActions.edit(projectId);
            } else if (action === 'duplicate') {
              ProjectActions.duplicate(projectId);
            } else if (action === 'archive') {
              ProjectActions.archive(projectId);
            } else if (action === 'delete') {
              ProjectActions.delete(projectId, projectName);
            }
          });
        });
      }
      
      // Attach menu button listeners
      document.querySelectorAll('.fw-project-menu-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          
          const projectId = btn.dataset.projectId;
          const projectName = btn.dataset.projectName;
          
          // Store project info in dropdown
          dropdownContainer.dataset.projectId = projectId;
          dropdownContainer.dataset.projectName = projectName;
          
          if (dropdownContainer.classList.contains('show')) {
            closeAllDropdowns();
          } else {
            showDropdown(dropdownContainer, btn);
          }
        });
      });
    }

    function renderPagination(pagination) {
      if (!paginationContainer || pagination.pages <= 1) {
        if (paginationContainer) paginationContainer.innerHTML = '';
        return;
      }

      let html = '';
      
      html += `<button class="fw-proj__page-btn" ${currentPage === 1 ? 'disabled' : ''} data-page="${currentPage - 1}">‹ Previous</button>`;
      
      for (let i = 1; i <= pagination.pages; i++) {
        if (i === 1 || i === pagination.pages || (i >= currentPage - 2 && i <= currentPage + 2)) {
          const active = i === currentPage ? 'fw-proj__page-btn--active' : '';
          html += `<button class="fw-proj__page-btn ${active}" data-page="${i}">${i}</button>`;
        } else if (i === currentPage - 3 || i === currentPage + 3) {
          html += '<span style="padding: 0 8px; color: var(--fw-text-muted);">...</span>';
        }
      }
      
      html += `<button class="fw-proj__page-btn" ${currentPage === pagination.pages ? 'disabled' : ''} data-page="${currentPage + 1}">Next ›</button>`;
      
      paginationContainer.innerHTML = html;
      
      paginationContainer.querySelectorAll('.fw-proj__page-btn[data-page]').forEach(btn => {
        btn.addEventListener('click', () => {
          currentPage = parseInt(btn.dataset.page);
          loadProjects();
          window.scrollTo({ top: 0, behavior: 'smooth' });
        });
      });
    }

    loadProjects();

    if (searchInput) {
      searchInput.addEventListener('input', debounce(() => {
        currentPage = 1;
        loadProjects();
      }, 300));
    }
    
    if (filterStatus) {
      filterStatus.addEventListener('change', () => {
        currentPage = 1;
        loadProjects();
      });
    }
    
    if (filterManager) {
      filterManager.addEventListener('change', () => {
        currentPage = 1;
        loadProjects();
      });
    }
  }

  // ===== NEW PROJECT MODAL =====
  function initNewProjectModal() {
    const btn = document.getElementById('btnNewProject');
    const form = document.getElementById('formNewProject');
    const selectManager = document.getElementById('selectManager');
    const message = document.getElementById('newProjectMessage');

    if (!btn || !form) return;

    // Load managers once
    if (selectManager && selectManager.options.length === 1) {
      fetch('/projects/api/users.list.php')
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            data.data.users.forEach(user => {
              const option = document.createElement('option');
              option.value = user.id;
              option.textContent = `${user.first_name} ${user.last_name}`;
              selectManager.appendChild(option);
            });
          }
        })
        .catch(err => console.error('Failed to load users:', err));
    }

    // Open modal on button click
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      ProjModal.open('modalNewProject');
      form.reset();
      if (message) message.style.display = 'none';
    });

    // Handle form submission
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      e.stopPropagation();
      
      const formData = new FormData(form);
      formData.append('csrf', getCSRF());

      if (message) {
        message.style.display = 'none';
      }

      const submitBtn = form.querySelector('button[type="submit"]');
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Creating...';
      }

      fetch('/projects/api/project.create.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          if (message) {
            message.className = 'fw-form-message fw-form-message--success';
            message.textContent = 'Project created successfully! Redirecting...';
            message.style.display = 'block';
          }
          setTimeout(() => {
            window.location.href = '/projects/view.php?project_id=' + data.data.project_id;
          }, 1000);
        } else {
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = '✨ Create Project';
          }
          if (message) {
            message.className = 'fw-form-message fw-form-message--error';
            message.textContent = data.error || 'Failed to create project';
            message.style.display = 'block';
          }
        }
      })
      .catch(err => {
        console.error('Create project error:', err);
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = '✨ Create Project';
        }
        if (message) {
          message.className = 'fw-form-message fw-form-message--error';
          message.textContent = 'Network error';
          message.style.display = 'block';
        }
      });
    });
  }

  // ===== EDIT PROJECT MODAL =====
  function initEditProjectModal() {
    const form = document.getElementById('formEditProject');
    const message = document.getElementById('editProjectMessage');

    if (!form) return;

    form.addEventListener('submit', (e) => {
      e.preventDefault();
      e.stopPropagation();
      
      const formData = new FormData(form);
      formData.append('csrf', getCSRF());

      if (message) {
        message.style.display = 'none';
      }

      const submitBtn = form.querySelector('button[type="submit"]');
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = '💾 Saving...';
      }

      fetch('/projects/api/project.update.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          if (message) {
            message.className = 'fw-form-message fw-form-message--success';
            message.textContent = '✅ Project updated successfully!';
            message.style.display = 'block';
          }
          setTimeout(() => {
            window.location.reload();
          }, 1000);
        } else {
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = '💾 Save Changes';
          }
          if (message) {
            message.className = 'fw-form-message fw-form-message--error';
            message.textContent = data.error || 'Failed to update project';
            message.style.display = 'block';
          }
        }
      })
      .catch(err => {
        console.error('Update project error:', err);
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = '💾 Save Changes';
        }
        if (message) {
          message.className = 'fw-form-message fw-form-message--error';
          message.textContent = 'Network error';
          message.style.display = 'block';
        }
      });
    });
  }

  // Global Settings
  const btnGlobalSettings = document.getElementById('btnGlobalSettings');
  if (btnGlobalSettings) {
    btnGlobalSettings.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      ProjModal.open('modalGlobalSettings');
    });
  }

  // ===== INIT =====
  function init() {
    initProjectList();
    initNewProjectModal();
    initEditProjectModal();
    console.log('✅ Projects Index initialized with dropdown menus');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();