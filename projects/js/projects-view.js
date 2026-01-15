/**
 * ============================================================================
 * FLOWWORK PROJECTS VIEW — COMPLETE CONTROLLER (NEON CHARTS + BOARD DROPDOWNS)
 * Features: Overview Charts with Neon Highlights, Boards List with Dropdowns, Settings, Files, 3D Tilt
 * ============================================================================
 */

(() => {
  'use strict';

  function getCSRF() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
  }

  // ===== NEON COLOUR PALETTE =====
  const NEON_COLORS = {
    cyan: '#00e0d6',
    yellow: '#ffff00',
    green: '#10b981',
    orange: '#ff9900',
    purple: '#8b5cf6',
    pink: '#ff1dce',
    magenta: '#ff00ff',
    red: '#ff0000',
    aqua: '#00ffb5',
    blue: '#00f0ff'
  };

  // ===== MODAL SYSTEM =====
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
    document.querySelectorAll('.fw-board-dropdown').forEach(dd => {
      dd.classList.remove('show');
    });
    currentDropdown = null;
  }

  function showDropdown(dropdown, button) {
    closeAllDropdowns();
    dropdown.classList.add('show');
    currentDropdown = dropdown;
    
    // Position dropdown
    const rect = button.getBoundingClientRect();
    dropdown.style.top = (rect.bottom + 4) + 'px';
    dropdown.style.left = (rect.left - dropdown.offsetWidth + button.offsetWidth) + 'px';
  }

  // Close dropdowns when clicking outside
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.fw-board-dropdown') && !e.target.closest('.fw-board-menu-btn')) {
      closeAllDropdowns();
    }
  });

  // Close on escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      closeAllDropdowns();
    }
  });

  // ===== BOARD ACTIONS =====
  window.BoardActions = {
    rename: function(boardId, currentTitle) {
      closeAllDropdowns();
      console.log('✏️ Rename board:', boardId);
      
      document.getElementById('renameBoardId').value = boardId;
      document.getElementById('renameBoardTitle').value = currentTitle;
      
      ProjModal.open('modalRenameBoard');
    },

    duplicate: function(boardId) {
      closeAllDropdowns();
      
      if (!confirm('Duplicate this board?\n\nThis will create a copy with all columns, groups, and items.')) {
        return;
      }
      
      console.log('📋 Duplicate board:', boardId);
      
      const formData = new FormData();
      formData.append('board_id', boardId);
      formData.append('csrf', getCSRF());
      
      fetch('/projects/api/board.duplicate.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          alert('✅ Board duplicated successfully!');
          window.location.reload();
        } else {
          alert('Failed to duplicate board: ' + (data.error || 'Unknown error'));
        }
      })
      .catch(err => {
        console.error('Duplicate error:', err);
        alert('Network error');
      });
    },

    archive: function(boardId) {
      closeAllDropdowns();
      
      if (!confirm('Archive this board?\n\nYou can restore it later from the archive.')) {
        return;
      }
      
      console.log('📦 Archive board:', boardId);
      
      const formData = new FormData();
      formData.append('board_id', boardId);
      formData.append('csrf', getCSRF());
      
      fetch('/projects/api/board.archive.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          alert('✅ Board archived successfully!');
          window.location.reload();
        } else {
          alert('Failed to archive board: ' + (data.error || 'Unknown error'));
        }
      })
      .catch(err => {
        console.error('Archive error:', err);
        alert('Network error');
      });
    },

    delete: function(boardId, boardTitle) {
      closeAllDropdowns();
      
      if (!confirm(`⚠️ DELETE BOARD?\n\nThis will permanently delete "${boardTitle}" and ALL its data:\n\n• All items\n• All columns\n• All groups\n• All attachments\n\nTHIS CANNOT BE UNDONE!`)) {
        return;
      }
      
      const confirmText = prompt('Type "DELETE" to confirm:');
      if (confirmText !== 'DELETE') {
        alert('Confirmation text does not match. Deletion cancelled.');
        return;
      }
      
      console.log('🗑️ Delete board:', boardId);
      
      const formData = new FormData();
      formData.append('board_id', boardId);
      formData.append('csrf', getCSRF());
      
      fetch('/projects/api/board.delete.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          alert('🗑️ Board deleted successfully');
          window.location.reload();
        } else {
          alert('Failed to delete board: ' + (data.error || 'Unknown error'));
        }
      })
      .catch(err => {
        console.error('Delete error:', err);
        alert('Network error');
      });
    }
  };

  // ===== PLAYGROUND (OVERVIEW CHARTS) =====
  let projectData = null;
  const chartInstances = {};
  let isLocked = false;
  let draggedCard = null;

  function buildPlayground() {
    const board = document.getElementById('playgroundBoard');
    if (!board || !projectData) {
      console.error('❌ Board element or project data missing');
      return;
    }
    
    console.log('✅ Building playground with data:', projectData);
    
    board.innerHTML = '';
    
    const charts = [
      { id: 'chart1', title: '💰 Budget Usage', type: 'doughnut' },
      { id: 'chart2', title: '📊 Progress Tracking', type: 'bar' },
      { id: 'chart3', title: '📋 Boards Overview', type: 'bar' },
      { id: 'chart4', title: '📅 Timeline Progress', type: 'line' },
      { id: 'chart5', title: '👥 Team Composition', type: 'doughnut' },
      { id: 'chart6', title: '⚡ Recent Activity', type: 'line' },
      { id: 'chart7', title: '💸 Budget Trend', type: 'line' },
      { id: 'chart8', title: '✅ Task Status', type: 'bar' }
    ];
    
    charts.forEach((chart, index) => {
      const card = document.createElement('div');
      card.className = 'proj-playground-chart-card';
      card.draggable = !isLocked;
      card.dataset.chartId = chart.id;
      
      card.innerHTML = `
        <div class="proj-playground-chart-header">
          <div class="proj-playground-chart-title">${chart.title}</div>
          <div class="proj-playground-chart-controls">
            <div class="proj-chart-view-dropdown">
              <button class="proj-chart-view-btn chart-view-toggle" title="Change View">📊</button>
              <div class="proj-chart-view-menu">
                <div class="proj-chart-view-option ${chart.type === 'bar' ? 'active' : ''}" data-type="bar">📊 Bar</div>
                <div class="proj-chart-view-option ${chart.type === 'line' ? 'active' : ''}" data-type="line">📈 Line</div>
                <div class="proj-chart-view-option ${chart.type === 'doughnut' ? 'active' : ''}" data-type="doughnut">🍩 Donut</div>
                <div class="proj-chart-view-option ${chart.type === 'pie' ? 'active' : ''}" data-type="pie">🥧 Pie</div>
              </div>
            </div>
            <button class="proj-chart-btn refresh-chart" title="Refresh">🔄</button>
          </div>
        </div>
        <div class="proj-playground-chart-body">
          <canvas id="${chart.id}"></canvas>
        </div>
      `;
      
      board.appendChild(card);
      
      // Add drag event listeners
      if (!isLocked) {
        card.addEventListener('dragstart', handleDragStart);
        card.addEventListener('dragend', handleDragEnd);
        card.addEventListener('dragover', handleDragOver);
        card.addEventListener('drop', handleDrop);
      }
      
      // View type dropdown
      const viewToggle = card.querySelector('.chart-view-toggle');
      const viewMenu = card.querySelector('.proj-chart-view-menu');
      
      viewToggle.addEventListener('click', (e) => {
        e.stopPropagation();
        
        // Close other dropdowns
        document.querySelectorAll('.proj-chart-view-menu').forEach(menu => {
          if (menu !== viewMenu) menu.classList.remove('show');
        });
        
        viewMenu.classList.toggle('show');
      });
      
      card.querySelectorAll('.proj-chart-view-option').forEach(option => {
        option.addEventListener('click', () => {
          const newType = option.dataset.type;
          chart.type = newType;
          
          // Update active state
          card.querySelectorAll('.proj-chart-view-option').forEach(o => o.classList.remove('active'));
          option.classList.add('active');
          
          // Re-render chart
          renderChart(chart.id, newType, index);
          
          // Close menu
          viewMenu.classList.remove('show');
          
          console.log(`📊 Chart ${chart.id} changed to ${newType}`);
        });
      });
      
      // Refresh button
      const refreshBtn = card.querySelector('.refresh-chart');
      refreshBtn.addEventListener('click', () => {
        console.log('🔄 Refreshing chart:', chart.id);
        renderChart(chart.id, chart.type, index);
      });
      
      // Render chart
      setTimeout(() => {
        renderChart(chart.id, chart.type, index);
      }, 100);
    });
  }

  // Close all dropdowns when clicking outside
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.proj-chart-view-dropdown')) {
      document.querySelectorAll('.proj-chart-view-menu').forEach(menu => {
        menu.classList.remove('show');
      });
    }
  });

  // ===== DRAG AND DROP HANDLERS =====
  function handleDragStart(e) {
    if (isLocked) return;
    draggedCard = this;
    this.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/html', this.innerHTML);
    console.log('🎯 Drag started:', this.dataset.chartId);
  }

  function handleDragEnd(e) {
    this.classList.remove('dragging');
    document.querySelectorAll('.proj-playground-chart-card').forEach(card => {
      card.classList.remove('over');
    });
    console.log('✅ Drag ended');
  }

  function handleDragOver(e) {
    if (isLocked) return;
    if (e.preventDefault) {
      e.preventDefault();
    }
    e.dataTransfer.dropEffect = 'move';
    return false;
  }

  function handleDrop(e) {
    if (isLocked) return;
    if (e.stopPropagation) {
      e.stopPropagation();
    }

    if (draggedCard !== this) {
      const board = document.getElementById('playgroundBoard');
      const allCards = Array.from(board.querySelectorAll('.proj-playground-chart-card'));
      const draggedIndex = allCards.indexOf(draggedCard);
      const targetIndex = allCards.indexOf(this);

      if (draggedIndex < targetIndex) {
        this.parentNode.insertBefore(draggedCard, this.nextSibling);
      } else {
        this.parentNode.insertBefore(draggedCard, this);
      }
      
      console.log('🔄 Cards reordered');
    }

    return false;
  }

  // ===== RENDER CHART WITH NEON HIGHLIGHTS =====
  function renderChart(canvasId, type, index) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) {
      console.error('❌ Canvas not found:', canvasId);
      return;
    }
    
    const ctx = canvas.getContext('2d');
    
    if (chartInstances[canvasId]) {
      chartInstances[canvasId].destroy();
    }
    
    let data, options;
    
    switch(index) {
      case 0: // Budget Usage (Neon Cyan + Green)
        data = {
          labels: ['Used', 'Remaining'],
          datasets: [{
            data: [6500, 3500],
            backgroundColor: [NEON_COLORS.cyan, NEON_COLORS.green],
            borderWidth: 3,
            borderColor: '#1a1d29',
            hoverBorderColor: '#fff',
            hoverBorderWidth: 4
          }]
        };
        options = {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { 
              position: 'bottom',
              labels: { 
                color: '#9fb0c8', 
                font: { size: 12, weight: 'bold' },
                padding: 15,
                usePointStyle: true,
                pointStyle: 'circle'
              }
            },
            tooltip: {
              backgroundColor: 'rgba(0, 0, 0, 0.9)',
              titleColor: NEON_COLORS.cyan,
              bodyColor: '#fff',
              borderColor: NEON_COLORS.cyan,
              borderWidth: 2,
              padding: 12,
              displayColors: true,
              boxWidth: 12,
              boxHeight: 12,
              boxPadding: 6
            }
          }
        };
        break;
        
      case 1: // Progress Tracking (Neon Cyan Gradient)
        const gradientBar1 = ctx.createLinearGradient(0, 0, 0, canvas.height);
        gradientBar1.addColorStop(0, NEON_COLORS.cyan);
        gradientBar1.addColorStop(1, NEON_COLORS.blue);
        
        data = {
          labels: ['Complete'],
          datasets: [{
            label: 'Progress %',
            data: [65],
            backgroundColor: gradientBar1,
            borderRadius: 12,
            borderWidth: 2,
            borderColor: NEON_COLORS.cyan,
            hoverBackgroundColor: NEON_COLORS.blue,
            hoverBorderWidth: 3
          }]
        };
        options = {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            y: { 
              beginAtZero: true,
              max: 100,
              grid: { 
                color: 'rgba(0, 224, 214, 0.15)',
                lineWidth: 1
              },
              ticks: { 
                color: '#7a8aa3',
                font: { weight: 'bold' }
              }
            },
            x: {
              grid: { display: false },
              ticks: { 
                color: '#7a8aa3',
                font: { weight: 'bold' }
              }
            }
          },
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: 'rgba(0, 0, 0, 0.9)',
              titleColor: NEON_COLORS.cyan,
              bodyColor: '#fff',
              borderColor: NEON_COLORS.cyan,
              borderWidth: 2,
              padding: 12
            }
          }
        };
        break;
        
      case 2: // Boards Overview (Neon Green)
        const gradientBar2 = ctx.createLinearGradient(0, 0, 0, canvas.height);
        gradientBar2.addColorStop(0, NEON_COLORS.green);
        gradientBar2.addColorStop(1, NEON_COLORS.aqua);
        
        data = {
          labels: ['Cost', 'Schedule', 'Quality', 'Safety', 'Planning'],
          datasets: [{
            label: 'Items',
            data: [34, 12, 8, 15, 22],
            backgroundColor: gradientBar2,
            borderRadius: 10,
            borderWidth: 2,
            borderColor: NEON_COLORS.green,
            hoverBorderWidth: 3
          }]
        };
        options = {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            y: { 
              beginAtZero: true,
              grid: { 
                color: 'rgba(16, 185, 129, 0.15)',
                lineWidth: 1
              },
              ticks: { 
                color: '#7a8aa3',
                font: { weight: 'bold' }
              }
            },
            x: {
              grid: { display: false },
              ticks: { 
                color: '#7a8aa3',
                font: { weight: 'bold' }
              }
            }
          },
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: 'rgba(0, 0, 0, 0.9)',
              titleColor: NEON_COLORS.green,
              bodyColor: '#fff',
              borderColor: NEON_COLORS.green,
              borderWidth: 2,
              padding: 12
            }
          }
        };
        break;
        
      case 3: // Timeline Progress (Neon Yellow Line)
        data = {
          labels: ['Start', 'Q1', 'Q2', 'Q3', 'Q4', 'End'],
          datasets: [{
            label: 'Timeline',
            data: [0, 20, 45, 65, 85, 100],
            borderColor: NEON_COLORS.yellow,
            backgroundColor: 'rgba(255, 255, 0, 0.15)',
            tension: 0.4,
            borderWidth: 4,
            fill: true,
            pointRadius: 6,
            pointBackgroundColor: NEON_COLORS.yellow,
            pointBorderColor: '#1a1d29',
            pointBorderWidth: 3,
            pointHoverRadius: 8,
            pointHoverBorderWidth: 4
          }]
        };
        options = {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            y: { 
              beginAtZero: true,
              max: 100,
              grid: { 
                color: 'rgba(255, 255, 0, 0.1)',
                lineWidth: 1
              },
              ticks: { 
                color: '#7a8aa3',
                font: { weight: 'bold' }
              }
            },
            x: {
              grid: { display: false },
              ticks: { 
                color: '#7a8aa3',
                font: { weight: 'bold' }
              }
            }
          },
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: 'rgba(0, 0, 0, 0.9)',
              titleColor: NEON_COLORS.yellow,
              bodyColor: '#fff',
              borderColor: NEON_COLORS.yellow,
              borderWidth: 2,
              padding: 12
            }
          }
        };
        break;
        
      case 4: // Team Composition (Neon Purple + Pink)
        data = {
          labels: ['Owner', 'Member'],
          datasets: [{
            data: [1, 4],
            backgroundColor: [NEON_COLORS.purple, NEON_COLORS.pink],
            borderWidth: 3,
            borderColor: '#1a1d29',
            hoverBorderColor: '#fff',
            hoverBorderWidth: 4
          }]
        };
        options = {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { 
              position: 'bottom',
              labels: { 
                color: '#9fb0c8', 
                font: { size: 12, weight: 'bold' },
                padding: 15,
                usePointStyle: true,
                pointStyle: 'circle'
              }
            },
            tooltip: {
              backgroundColor: 'rgba(0, 0, 0, 0.9)',
              titleColor: NEON_COLORS.purple,
              bodyColor: '#fff',
              borderColor: NEON_COLORS.purple,
              borderWidth: 2,
              padding: 12
            }
          }
        };
        break;
        
      case 5: // Recent Activity (Neon Purple Line)
        data = {
          labels: ['6d', '5d', '4d', '3d', '2d', '1d', 'Today'],
          datasets: [{
            label: 'Events',
            data: [2, 5, 3, 8, 4, 6, 9],
            borderColor: NEON_COLORS.purple,
            backgroundColor: 'rgba(139, 92, 246, 0.15)',
            tension: 0.4,
            borderWidth: 4,
            fill: true,
            pointRadius: 6,
            pointBackgroundColor: NEON_COLORS.purple,
            pointBorderColor: '#1a1d29',
            pointBorderWidth: 3,
            pointHoverRadius: 8,
            pointHoverBorderWidth: 4
          }]
        };
        options = {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            y: { 
              beginAtZero: true,
              grid: { 
                color: 'rgba(139, 92, 246, 0.1)',
                lineWidth: 1
              },
              ticks: { 
                color: '#7a8aa3',
                font: { weight: 'bold' }
              }
            },
            x: {
              grid: { display: false },
              ticks: { 
                color: '#7a8aa3',
                font: { weight: 'bold' }
              }
            }
          },
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: 'rgba(0, 0, 0, 0.9)',
              titleColor: NEON_COLORS.purple,
              bodyColor: '#fff',
              borderColor: NEON_COLORS.purple,
              borderWidth: 2,
              padding: 12
            }
          }
        };
        break;
        
      case 6: // Budget Trend (Neon Green Line)
        data = {
          labels: ['Start', 'M1', 'M2', 'M3', 'M4', 'M5', 'M6'],
          datasets: [{
            label: 'Budget Remaining',
            data: [10000, 9200, 8100, 7200, 6500, 5800, 5000],
            borderColor: NEON_COLORS.green,
            backgroundColor: 'rgba(16, 185, 129, 0.15)',
            tension: 0.4,
            borderWidth: 4,
            fill: true,
            pointRadius: 6,
            pointBackgroundColor: NEON_COLORS.green,
            pointBorderColor: '#1a1d29',
            pointBorderWidth: 3,
            pointHoverRadius: 8,
            pointHoverBorderWidth: 4
          }]
        };
        options = {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            y: { 
              beginAtZero: true,
              grid: { 
                color: 'rgba(16, 185, 129, 0.1)',
                lineWidth: 1
              },
              ticks: { 
                color: '#7a8aa3',
                font: { weight: 'bold' }
              }
            },
            x: {
              grid: { display: false },
              ticks: { 
                color: '#7a8aa3',
                font: { weight: 'bold' }
              }
            }
          },
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: 'rgba(0, 0, 0, 0.9)',
              titleColor: NEON_COLORS.green,
              bodyColor: '#fff',
              borderColor: NEON_COLORS.green,
              borderWidth: 2,
              padding: 12
            }
          }
        };
        break;
        
      case 7: // Task Status (Neon Red + Yellow + Green)
        const gradientRed = ctx.createLinearGradient(0, 0, 0, canvas.height);
        gradientRed.addColorStop(0, NEON_COLORS.red);
        gradientRed.addColorStop(1, NEON_COLORS.orange);
        
        const gradientYellow = ctx.createLinearGradient(0, 0, 0, canvas.height);
        gradientYellow.addColorStop(0, NEON_COLORS.yellow);
        gradientYellow.addColorStop(1, NEON_COLORS.orange);
        
        const gradientGreen = ctx.createLinearGradient(0, 0, 0, canvas.height);
        gradientGreen.addColorStop(0, NEON_COLORS.green);
        gradientGreen.addColorStop(1, NEON_COLORS.aqua);
        
        data = {
          labels: ['To Do', 'In Progress', 'Completed'],
          datasets: [{
            label: 'Tasks',
            data: [15, 23, 45],
            backgroundColor: [gradientRed, gradientYellow, gradientGreen],
            borderRadius: 10,
            borderWidth: 2,
            borderColor: [NEON_COLORS.red, NEON_COLORS.yellow, NEON_COLORS.green],
            hoverBorderWidth: 3
          }]
        };
        options = {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            y: { 
              beginAtZero: true,
              grid: { 
                color: 'rgba(255, 255, 255, 0.05)',
                lineWidth: 1
              },
              ticks: { 
                color: '#7a8aa3',
                font: { weight: 'bold' }
              }
            },
            x: {
              grid: { display: false },
              ticks: { 
                color: '#7a8aa3',
                font: { weight: 'bold' }
              }
            }
          },
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: 'rgba(0, 0, 0, 0.9)',
              titleColor: NEON_COLORS.green,
              bodyColor: '#fff',
              borderColor: NEON_COLORS.green,
              borderWidth: 2,
              padding: 12
            }
          }
        };
        break;
    }
    
    try {
      chartInstances[canvasId] = new Chart(ctx, {
        type: type,
        data: data,
        options: options
      });
      console.log('✅ Chart rendered:', canvasId, 'as', type);
    } catch (error) {
      console.error('❌ Chart render error:', canvasId, error);
    }
  }

  function loadProjectData() {
    console.log('🔄 Loading project data for ID:', PROJECT_ID);
    
    fetch('/projects/api/project.view.php?project_id=' + PROJECT_ID)
      .then(res => res.json())
      .then(data => {
        console.log('📦 API Response:', data);
        
        if (data.ok) {
          projectData = data.data;
          buildPlayground();
        } else {
          document.getElementById('playgroundBoard').innerHTML = `
            <div style="grid-column: 1 / -1; text-align: center; padding: 60px; color: #ef4444;">
              <div style="font-size: 48px; margin-bottom: 16px;">⚠️</div>
              <div style="font-size: 18px; font-weight: 600; margin-bottom: 8px;">Failed to load project</div>
              <div style="font-size: 14px; opacity: 0.7;">${data.error || 'Unknown error'}</div>
            </div>
          `;
        }
      })
      .catch(err => {
        console.error('❌ Load project error:', err);
        document.getElementById('playgroundBoard').innerHTML = `
          <div style="grid-column: 1 / -1; text-align: center; padding: 60px; color: #ef4444;">
            <div style="font-size: 48px; margin-bottom: 16px;">⚠️</div>
            <div style="font-size: 18px; font-weight: 600; margin-bottom: 8px;">Network Error</div>
            <div style="font-size: 14px; opacity: 0.7;">Check console for details</div>
          </div>
        `;
      });
  }

  // Playground controls
  const btnReset = document.getElementById('playgroundReset');
  const btnLock = document.getElementById('playgroundLock');
  const btnExport = document.getElementById('playgroundExport');

  if (btnReset) {
    btnReset.addEventListener('click', function() {
      if (confirm('Reset chart layout to default?')) {
        Object.values(chartInstances).forEach(chart => chart.destroy());
        buildPlayground();
        console.log('✅ Playground reset');
      }
    });
  }

  if (btnLock) {
    btnLock.addEventListener('click', function() {
      isLocked = !isLocked;
      this.textContent = isLocked ? '🔒 Locked' : '🔓 Unlock';
      this.classList.toggle('locked', isLocked);
      
      document.querySelectorAll('.proj-playground-chart-card').forEach(card => {
        card.draggable = !isLocked;
        card.style.cursor = isLocked ? 'default' : 'grab';
      });
      
      console.log('🔒 Playground', isLocked ? 'locked' : 'unlocked');
    });
  }

  if (btnExport) {
    btnExport.addEventListener('click', function() {
      console.log('📤 Exporting overview data...');
      
      const exportData = {
        project_id: PROJECT_ID,
        project_name: projectData?.project?.name || 'Unknown',
        exported_at: new Date().toISOString(),
        charts: Object.keys(chartInstances).map(chartId => ({
          id: chartId,
          type: chartInstances[chartId].config.type,
          data: chartInstances[chartId].data
        }))
      };
      
      const blob = new Blob([JSON.stringify(exportData, null, 2)], { type: 'application/json' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `project-${PROJECT_ID}-overview-${Date.now()}.json`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
      
      console.log('✅ Export completed');
    });
  }

  // ===== BOARDS TAB =====
  function loadBoardsList() {
    const container = document.getElementById('boardsListView');
    if (!container) return;

    container.innerHTML = '<div class="fw-proj__loading">Loading boards...</div>';

    fetch('/projects/api/project.view.php?project_id=' + PROJECT_ID)
      .then(res => res.json())
      .then(data => {
        if (data.ok && data.data.boards) {
          renderBoardsList(data.data.boards);
        } else {
          container.innerHTML = '<div class="fw-empty-state"><div style="font-size: 48px; margin-bottom: 16px; opacity: 0.3;">📋</div><div>No boards found</div></div>';
        }
      })
      .catch(err => {
        console.error('Load boards error:', err);
        container.innerHTML = '<div class="fw-empty-state">Error loading boards</div>';
      });
  }

  function renderBoardsList(boards) {
    const container = document.getElementById('boardsListView');
    
    let html = `
      <div class="fw-boards-header">
        <h2 class="fw-section-title">Project Boards</h2>
        <button class="fw-btn fw-btn--primary fw-btn--glossy" onclick="ProjModal.open('modalNewBoard')">
          <svg width="16" height="16" fill="currentColor" style="margin-right: 8px;">
            <path d="M8 2v12M2 8h12" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
          </svg>
          New Board
        </button>
      </div>
    `;

    if (boards.length === 0) {
      html += '<div class="fw-empty-state"><div style="font-size: 48px; opacity: 0.3;">📋</div><div>No boards yet</div></div>';
    } else {
      html += '<div class="fw-boards-grid">';
      boards.forEach(board => {
        html += `
          <div class="fw-board-card">
            <a href="/projects/board.php?board_id=${board.board_id}" class="fw-board-card__link">
              <div class="fw-board-card__icon">📋</div>
              <div class="fw-board-card__content">
                <h3 class="fw-board-card__title">${board.title}</h3>
                <div class="fw-board-card__meta">
                  <span>${board.item_count || 0} items</span>
                  <span>•</span>
                  <span>${board.default_view} view</span>
                </div>
              </div>
              <svg width="20" height="20" fill="currentColor" class="fw-board-card__arrow">
                <path d="M8 4l6 6-6 6" stroke="currentColor" stroke-width="2" fill="none"/>
              </svg>
            </a>
            <button class="fw-board-menu-btn" data-board-id="${board.board_id}" data-board-title="${board.title}" title="More actions">
              <svg viewBox="0 0 24 24" fill="currentColor">
                <circle cx="12" cy="5" r="2"/>
                <circle cx="12" cy="12" r="2"/>
                <circle cx="12" cy="19" r="2"/>
              </svg>
            </button>
            <div class="fw-board-dropdown">
              <button class="fw-board-dropdown__item" onclick="BoardActions.rename(${board.board_id}, '${board.title.replace(/'/g, "\\'")}')">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                  <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                </svg>
                Rename Board
              </button>
              <button class="fw-board-dropdown__item" onclick="BoardActions.duplicate(${board.board_id})">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                  <rect x="9" y="9" width="13" height="13" rx="2"/>
                  <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
                </svg>
                Duplicate
              </button>
              <button class="fw-board-dropdown__item" onclick="BoardActions.archive(${board.board_id})">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M21 8v13H3V8M1 3h22v5H1zM10 12h4"/>
                </svg>
                Archive
              </button>
              <div class="fw-board-dropdown__divider"></div>
              <button class="fw-board-dropdown__item fw-board-dropdown__item--danger" onclick="BoardActions.delete(${board.board_id}, '${board.title.replace(/'/g, "\\'")}')">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                </svg>
                Delete Board
              </button>
            </div>
          </div>
        `;
      });
      html += '</div>';
    }

    container.innerHTML = html;
    
    // Attach dropdown listeners
    document.querySelectorAll('.fw-board-menu-btn').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        const dropdown = btn.nextElementSibling;
        if (dropdown && dropdown.classList.contains('fw-board-dropdown')) {
          if (dropdown.classList.contains('show')) {
            closeAllDropdowns();
          } else {
            showDropdown(dropdown, btn);
          }
        }
      });
    });
  }

  // ===== RENAME BOARD MODAL =====
  function initRenameBoardModal() {
    const form = document.getElementById('formRenameBoard');
    const message = document.getElementById('renameBoardMessage');

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

      fetch('/projects/api/board.update.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          if (message) {
            message.className = 'fw-form-message fw-form-message--success';
            message.textContent = '✅ Board renamed successfully!';
            message.style.display = 'block';
          }
          setTimeout(() => {
            window.location.reload();
          }, 1000);
        } else {
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = '💾 Save';
          }
          if (message) {
            message.className = 'fw-form-message fw-form-message--error';
            message.textContent = data.error || 'Failed to rename board';
            message.style.display = 'block';
          }
        }
      })
      .catch(err => {
        console.error('Rename board error:', err);
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = '💾 Save';
        }
        if (message) {
          message.className = 'fw-form-message fw-form-message--error';
          message.textContent = 'Network error';
          message.style.display = 'block';
        }
      });
    });
  }

  // ===== SETTINGS TAB =====
  function loadProjectSettings() {
    const container = document.getElementById('projectSettingsView');
    if (!container) return;

    console.log('🔄 Loading settings for project:', PROJECT_ID);
    container.innerHTML = '<div class="fw-proj__loading">Loading settings...</div>';

    fetch('/projects/api/project.view.php?project_id=' + PROJECT_ID)
      .then(res => res.json())
      .then(data => {
        console.log('📦 Settings API Response:', data);
        if (data.ok) {
          renderProjectSettings(data.data.project);
        } else {
          container.innerHTML = '<div class="fw-empty-state"><div style="font-size: 48px; margin-bottom: 16px; opacity: 0.3;">⚠️</div><div style="font-size: 16px; font-weight: 600;">Error loading settings</div></div>';
        }
      })
      .catch(err => {
        console.error('❌ Load settings error:', err);
        container.innerHTML = '<div class="fw-empty-state"><div style="font-size: 48px; margin-bottom: 16px; opacity: 0.3;">⚠️</div><div style="font-size: 16px; font-weight: 600;">Network error</div></div>';
      });
  }

  function renderProjectSettings(project) {
    const container = document.getElementById('projectSettingsView');
    
    const statusActive = project.status === 'active';
    const statusCompleted = project.status === 'completed';
    const statusOnHold = project.status === 'on_hold';
    
    container.innerHTML = `
      <div class="fw-settings-container">
        
        <!-- PROJECT DETAILS -->
        <div class="fw-settings-section">
          <div class="fw-settings-section__header">
            <span class="fw-settings-section__icon">📝</span>
            <div>
              <h2 class="fw-settings-section__title">Project Details</h2>
              <p class="fw-settings-section__desc">Update project information</p>
            </div>
          </div>
          
          <form id="formProjectDetails">
            <input type="hidden" name="project_id" value="${project.project_id}">
            
            <div class="fw-form-group">
              <label class="fw-label">Project Name</label>
              <input type="text" name="name" class="fw-input" value="${project.name}" required>
            </div>
            
            <div class="fw-form-row">
              <div class="fw-form-group">
                <label class="fw-label">Start Date</label>
                <input type="date" name="start_date" class="fw-input" value="${project.start_date || ''}">
              </div>
              <div class="fw-form-group">
                <label class="fw-label">End Date</label>
                <input type="date" name="end_date" class="fw-input" value="${project.end_date || ''}">
              </div>
            </div>
            
            <div class="fw-form-row">
              <div class="fw-form-group">
                <label class="fw-label">Budget (R)</label>
                <input type="number" name="budget" class="fw-input" value="${project.budget || ''}" min="0" step="100">
              </div>
              <div class="fw-form-group">
                <label class="fw-label">Manager</label>
                <select name="manager_user_id" id="settingsSelectManager" class="fw-input">
                  <option value="">Select manager...</option>
                </select>
              </div>
            </div>
            
            <div class="fw-form-message" id="detailsMessage" style="display:none;"></div>
            
            <button type="submit" class="fw-btn fw-btn--primary" style="margin-top: 16px;">
              💾 Save Changes
            </button>
          </form>
        </div>
        
        <!-- STATUS MANAGEMENT -->
        <div class="fw-settings-section">
          <div class="fw-settings-section__header">
            <span class="fw-settings-section__icon">🎯</span>
            <div>
              <h2 class="fw-settings-section__title">Status Management</h2>
              <p class="fw-settings-section__desc">Control project lifecycle</p>
            </div>
          </div>
          
          <div class="fw-settings-row">
            <div class="fw-settings-row__label">
              <div class="fw-settings-row__title">Active Status</div>
              <div class="fw-settings-row__desc">Project is actively being worked on</div>
            </div>
            <div class="fw-settings-row__control">
              <div class="fw-toggle ${statusActive ? 'active' : ''}" onclick="updateProjectStatus('active')"></div>
            </div>
          </div>
          
          <div class="fw-settings-row">
            <div class="fw-settings-row__label">
              <div class="fw-settings-row__title">Completed</div>
              <div class="fw-settings-row__desc">Mark project as finished</div>
            </div>
            <div class="fw-settings-row__control">
              <div class="fw-toggle ${statusCompleted ? 'active' : ''}" onclick="updateProjectStatus('completed')"></div>
            </div>
          </div>
          
          <div class="fw-settings-row">
            <div class="fw-settings-row__label">
              <div class="fw-settings-row__title">On Hold</div>
              <div class="fw-settings-row__desc">Temporarily pause the project</div>
            </div>
            <div class="fw-settings-row__control">
              <div class="fw-toggle ${statusOnHold ? 'active' : ''}" onclick="updateProjectStatus('on_hold')"></div>
            </div>
          </div>
        </div>
        
        <!-- DANGER ZONE -->
        <div class="fw-settings-section fw-settings-section--danger">
          <div class="fw-settings-section__header">
            <span class="fw-settings-section__icon">⚠️</span>
            <div>
              <h2 class="fw-settings-section__title">Danger Zone</h2>
              <p class="fw-settings-section__desc">Irreversible actions</p>
            </div>
          </div>
          
          <div class="fw-settings-row">
            <div class="fw-settings-row__label">
              <div class="fw-settings-row__title">Archive Project</div>
              <div class="fw-settings-row__desc">Hide from active projects list</div>
            </div>
            <div class="fw-settings-row__control">
              <button class="fw-btn fw-btn--secondary" onclick="archiveProject()">
                📦 Archive
              </button>
            </div>
          </div>
          
          <div class="fw-settings-row">
            <div class="fw-settings-row__label">
              <div class="fw-settings-row__title">Delete Project</div>
              <div class="fw-settings-row__desc">Permanently delete this project and all its data</div>
            </div>
            <div class="fw-settings-row__control">
              <button class="fw-btn fw-btn--danger" onclick="deleteProject()">
                🗑️ Delete Forever
              </button>
            </div>
          </div>
        </div>
        
      </div>
    `;
    
    loadManagersForSettings(project.project_manager_id);
  }

  function loadManagersForSettings(currentManagerId) {
    fetch('/projects/api/users.list.php')
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          const select = document.getElementById('settingsSelectManager');
          if (select) {
            data.data.users.forEach(user => {
              const option = document.createElement('option');
              option.value = user.id;
              option.textContent = `${user.first_name} ${user.last_name}`;
              if (user.id == currentManagerId) option.selected = true;
              select.appendChild(option);
            });
          }
        }
      });
  }

  // Update Project Details
  document.addEventListener('submit', function(e) {
    if (e.target.id === 'formProjectDetails') {
      e.preventDefault();
      
      const formData = new FormData(e.target);
      formData.append('csrf', getCSRF());
      
      const message = document.getElementById('detailsMessage');
      if (message) message.style.display = 'none';
      
      const submitBtn = e.target.querySelector('button[type="submit"]');
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
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = '💾 Save Changes';
        }
        
        if (data.ok) {
          if (message) {
            message.className = 'fw-form-message fw-form-message--success';
            message.textContent = '✅ Project updated successfully!';
            message.style.display = 'block';
          }
          setTimeout(() => {
            window.location.reload();
          }, 1500);
        } else {
          if (message) {
            message.className = 'fw-form-message fw-form-message--error';
            message.textContent = data.error || 'Failed to update project';
            message.style.display = 'block';
          }
        }
      })
      .catch(err => {
        console.error('Update error:', err);
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
    }
  });

  // Update Project Status
  window.updateProjectStatus = function(newStatus) {
    const formData = new FormData();
    formData.append('project_id', PROJECT_ID);
    formData.append('status', newStatus);
    formData.append('csrf', getCSRF());
    
    fetch('/projects/api/project.status.php', {
      method: 'POST',
      body: formData
    })
    .then(res => res.json())
    .then(data => {
      if (data.ok) {
        console.log('✅ Status updated to:', newStatus);
        window.location.reload();
      } else {
        alert('Failed to update status: ' + (data.error || 'Unknown error'));
      }
    })
    .catch(err => {
      console.error('Status update error:', err);
      alert('Network error');
    });
  };

  // Archive Project
  window.archiveProject = function() {
    if (!confirm('Archive this project?\n\nYou can restore it later from the archive.')) return;
    
    const formData = new FormData();
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf', getCSRF());
    
    fetch('/projects/api/project.archive.php', {
      method: 'POST',
      body: formData
    })
    .then(res => res.json())
    .then(data => {
      if (data.ok) {
        alert('✅ Project archived successfully!');
        window.location.href = '/projects/index.php';
      } else {
        alert('Failed to archive: ' + (data.error || 'Unknown error'));
      }
    })
    .catch(err => {
      console.error('Archive error:', err);
      alert('Network error');
    });
  };

  // Delete Project
  window.deleteProject = function() {
    const projectName = document.querySelector('input[name="name"]')?.value || 'this project';
    
    if (!confirm(`⚠️ DELETE PROJECT?\n\nThis will permanently delete "${projectName}" and ALL its data:\n\n• All boards\n• All items\n• All files\n• All history\n\nTHIS CANNOT BE UNDONE!\n\nType the project name to confirm.`)) return;
    
    const confirmName = prompt('Type the project name to confirm deletion:');
    if (confirmName !== projectName) {
      alert('Project name does not match. Deletion cancelled.');
      return;
    }
    
    const formData = new FormData();
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf', getCSRF());
    
    fetch('/projects/api/project.delete.php', {
      method: 'POST',
      body: formData
    })
    .then(res => res.json())
    .then(data => {
      if (data.ok) {
        alert('🗑️ Project deleted successfully');
        window.location.href = '/projects/index.php';
      } else {
        alert('Failed to delete: ' + (data.error || 'Unknown error'));
      }
    })
    .catch(err => {
      console.error('Delete error:', err);
      alert('Network error');
    });
  };

  // ===== FILES TAB =====
  function loadFilesList() {
    const container = document.getElementById('filesListView');
    if (!container) return;
    
    container.innerHTML = `
      <div class="fw-empty-state">
        <div style="font-size: 64px; margin-bottom: 20px; opacity: 0.3;">📎</div>
        <div style="font-size: 20px; font-weight: 700; margin-bottom: 8px; color: var(--fw-text-primary);">
          No Files Yet
        </div>
        <div style="font-size: 14px; color: var(--fw-text-tertiary); margin-bottom: 24px;">
          Upload project documents, images, and files
        </div>
        <button class="fw-btn fw-btn--primary" onclick="alert('File upload coming soon!')">
          📤 Upload Files
        </button>
      </div>
    `;
  }

  // ===== 3D TILT FOR BOARD CARDS =====
  function init3DTiltBoards() {
    const REDUCE_MOTION = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const IS_MOBILE = window.innerWidth <= 768;

    if (REDUCE_MOTION || IS_MOBILE) {
      console.log('⏸️ 3D tilt disabled for boards (reduced motion or mobile)');
      return;
    }

    function applyTilt(card) {
      card.addEventListener('mousemove', function(e) {
        const rect = card.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;
        const centerX = rect.width / 2;
        const centerY = rect.height / 2;

        const rotateX = ((y - centerY) / centerY) * -8;
        const rotateY = ((x - centerX) / centerX) * 8;

        card.style.transform = `
          perspective(1000px) 
          rotateX(${rotateX}deg) 
          rotateY(${rotateY}deg) 
          translateY(-4px)
          scale3d(1.02, 1.02, 1.02)
        `;
      });

      card.addEventListener('mouseleave', function() {
        card.style.transform = '';
      });
    }

    function initExistingBoards() {
      document.querySelectorAll('.fw-board-card').forEach(applyTilt);
    }

    const observer = new MutationObserver(function(mutations) {
      mutations.forEach(function(mutation) {
        mutation.addedNodes.forEach(function(node) {
          if (node.nodeType === 1) {
            if (node.classList && node.classList.contains('fw-board-card')) {
              applyTilt(node);
            }
            node.querySelectorAll && node.querySelectorAll('.fw-board-card').forEach(applyTilt);
          }
        });
      });
    });

    const container = document.getElementById('boardsListView');
    if (container) {
      observer.observe(container, { childList: true, subtree: true });
    }

    initExistingBoards();
    console.log('✅ 3D tilt initialized for board cards');
  }

  // ===== INIT =====
  function init() {
    console.log('🚀 Flowwork Projects View initialized');
    console.log('📌 Project ID:', PROJECT_ID);
    console.log('🔖 Active Tab:', ACTIVE_TAB);
    console.log('📊 Chart.js loaded:', typeof Chart !== 'undefined');
    
    if (ACTIVE_TAB === 'overview') {
      loadProjectData();
    } else if (ACTIVE_TAB === 'boards') {
      loadBoardsList();
      initRenameBoardModal();
      setTimeout(init3DTiltBoards, 500);
    } else if (ACTIVE_TAB === 'files') {
      loadFilesList();
    } else if (ACTIVE_TAB === 'settings') {
      loadProjectSettings();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();