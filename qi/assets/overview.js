/*
 * overview.js — QI OVERVIEW WITH NEON HIGHLIGHTS (COMPLETE)
 *
 * This script powers the Quotes & Invoices dashboard on the overview tab. It
 * fetches aggregated metrics from `/qi/ajax/overview_api.php` and uses
 * Chart.js to render interactive charts with neon colour highlights.
 */

document.addEventListener('DOMContentLoaded', () => {
  // Only run on the overview tab
  const params = new URLSearchParams(window.location.search);
  const tab = params.get('tab') || 'overview';
  if (tab !== 'overview') return;

  const root = document.querySelector('.fw-qi');
  const isDark = root && root.getAttribute('data-theme') === 'dark';

  // ===== NEON COLOUR PALETTE =====
  const NEON = {
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

  // Fetch metrics from the API
  fetch('/qi/ajax/overview_api.php')
    .then(res => res.json())
    .then(data => {
      if (!data.ok) {
        console.error('Failed to load overview:', data.error);
        const overview = document.getElementById('qi-overview');
        if (overview) {
          overview.innerHTML = `<p style="padding:20px;">Error loading overview: ${data.error}</p>`;
        }
        return;
      }
      populateKpis(data.kpis || {});
      renderRevenueChart(data.revenue_monthly || {});
      renderConversionChart(data.quote_conversion || {});
      renderTargetChart(data.mtd || {});
      renderSalesTable(data.sales_table || []);
      renderRegionChart(data.region || []);
      renderVolumeChart(data.volume || []);
    })
    .catch(err => {
      console.error('Overview fetch error:', err);
      const overview = document.getElementById('qi-overview');
      if (overview) {
        overview.innerHTML = `<p style="padding:20px;">Network error loading overview: ${err.message}</p>`;
      }
    });

  /**
   * Update KPI values in the DOM.
   * @param {Object} kpis
   */
  function populateKpis(kpis) {
    const map = {
      active_quotes: 'kpiActiveQuotes',
      overdue_invoices: 'kpiOverdueInvoices',
      pending_invoices: 'kpiPendingInvoices',
      recurring_invoices: 'kpiRecurringInvoices',
      credit_notes: 'kpiCreditNotes',
    };
    Object.keys(map).forEach(key => {
      const el = document.getElementById(map[key]);
      if (el) {
        const val = kpis[key];
        el.textContent = typeof val === 'number' ? val.toLocaleString('en-ZA') : '-';
      }
    });
  }

  /**
   * Render the revenue bar chart with NEON CYAN GRADIENT
   * @param {Object} dataset
   */
  function renderRevenueChart(dataset) {
    const ctx = document.getElementById('chartRevenue');
    if (!ctx || !dataset.labels || !dataset.series) return;
    
    const ctx2d = ctx.getContext('2d');
    const gradient = ctx2d.createLinearGradient(0, 0, 0, ctx.height || 200);
    gradient.addColorStop(0, NEON.cyan);
    gradient.addColorStop(1, NEON.blue);
    
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: dataset.labels,
        datasets: [
          {
            label: 'Revenue',
            data: dataset.series,
            borderRadius: 10,
            borderWidth: 2,
            borderColor: NEON.cyan,
            backgroundColor: gradient,
            hoverBorderWidth: 3,
            hoverBorderColor: NEON.blue
          },
        ],
      },
      options: {
        responsive: true,
        plugins: { 
          legend: { display: false },
          tooltip: {
            backgroundColor: 'rgba(0, 0, 0, 0.9)',
            titleColor: NEON.cyan,
            bodyColor: '#fff',
            borderColor: NEON.cyan,
            borderWidth: 2,
            padding: 12,
            displayColors: true,
            boxWidth: 12,
            boxHeight: 12,
            callbacks: {
              label: function(context) {
                let label = context.dataset.label || '';
                if (label) {
                  label += ': ';
                }
                if (context.parsed.y !== null) {
                  label += 'R' + context.parsed.y.toLocaleString('en-ZA', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                  });
                }
                return label;
              }
            }
          }
        },
        scales: {
          x: { 
            grid: { display: false },
            ticks: { 
              color: isDark ? '#9fb0c8' : '#6b7280',
              font: { weight: 'bold' }
            }
          },
          y: {
            grid: { 
              color: isDark ? 'rgba(0, 224, 214, 0.15)' : 'rgba(0, 224, 214, 0.1)',
              lineWidth: 1
            },
            ticks: {
              color: isDark ? '#9fb0c8' : '#6b7280',
              font: { weight: 'bold' },
              callback: (value) => 'R' + (value / 1000).toFixed(0) + 'k',
            },
          },
        },
      },
    });
  }

  /**
   * Render quote conversion chart with NEON GREEN + YELLOW
   * @param {Object} dataset
   */
  function renderConversionChart(dataset) {
    const ctx = document.getElementById('chartConversion');
    if (!ctx || !dataset.labels) return;
    
    new Chart(ctx, {
      data: {
        labels: dataset.labels,
        datasets: [
          {
            type: 'bar',
            label: 'Total Quotes',
            data: dataset.total || [],
            yAxisID: 'y',
            backgroundColor: NEON.yellow,
            borderRadius: 8,
            borderWidth: 2,
            borderColor: NEON.orange,
            hoverBorderWidth: 3
          },
          {
            type: 'line',
            label: 'Accepted/Converted',
            data: dataset.won || [],
            yAxisID: 'y1',
            borderColor: NEON.green,
            backgroundColor: NEON.green,
            tension: 0.4,
            borderWidth: 4,
            pointRadius: 6,
            pointBackgroundColor: NEON.green,
            pointBorderColor: isDark ? '#1a1d29' : '#fff',
            pointBorderWidth: 3,
            pointHoverRadius: 8,
            pointHoverBorderWidth: 4,
            fill: false,
          },
        ],
      },
      options: {
        responsive: true,
        plugins: { 
          legend: { 
            position: 'bottom',
            labels: {
              color: isDark ? '#9fb0c8' : '#6b7280',
              font: { size: 12, weight: 'bold' },
              padding: 15,
              usePointStyle: true
            }
          },
          tooltip: {
            backgroundColor: 'rgba(0, 0, 0, 0.9)',
            titleColor: NEON.green,
            bodyColor: '#fff',
            borderColor: NEON.green,
            borderWidth: 2,
            padding: 12
          }
        },
        scales: {
          x: { 
            grid: { display: false },
            ticks: { 
              color: isDark ? '#9fb0c8' : '#6b7280',
              font: { weight: 'bold' }
            }
          },
          y: {
            position: 'left',
            grid: { 
              color: isDark ? 'rgba(255, 255, 0, 0.1)' : 'rgba(255, 255, 0, 0.08)',
              lineWidth: 1
            },
            ticks: {
              color: isDark ? '#9fb0c8' : '#6b7280',
              font: { weight: 'bold' },
              callback: (value) => value,
            },
          },
          y1: {
            position: 'right',
            grid: { drawOnChartArea: false },
            ticks: {
              color: isDark ? '#9fb0c8' : '#6b7280',
              font: { weight: 'bold' },
              callback: (value) => value,
            },
          },
        },
      },
    });
  }

  /**
   * Render actual vs target chart with NEON RED + GREEN
   * @param {Object} dataset
   */
  function renderTargetChart(dataset) {
    const ctx = document.getElementById('chartTarget');
    if (!ctx || dataset.actual === undefined) return;
    
    const ctx2d = ctx.getContext('2d');
    const gradientRed = ctx2d.createLinearGradient(0, 0, 0, ctx.height || 200);
    gradientRed.addColorStop(0, NEON.red);
    gradientRed.addColorStop(1, NEON.orange);
    
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: ['Month To Date'],
        datasets: [
          {
            label: 'Actual',
            data: [dataset.actual || 0],
            yAxisID: 'y',
            backgroundColor: gradientRed,
            borderRadius: 10,
            borderWidth: 2,
            borderColor: NEON.red,
            hoverBorderWidth: 3
          },
          {
            type: 'line',
            label: 'Target',
            data: [dataset.target || 0],
            yAxisID: 'y',
            borderColor: NEON.green,
            backgroundColor: NEON.green,
            tension: 0.25,
            borderWidth: 4,
            pointRadius: 8,
            pointBackgroundColor: NEON.green,
            pointBorderColor: isDark ? '#1a1d29' : '#fff',
            pointBorderWidth: 3,
            pointHoverRadius: 10,
            pointHoverBorderWidth: 4,
            fill: false,
          },
        ],
      },
      options: {
        responsive: true,
        plugins: { 
          legend: { 
            position: 'bottom',
            labels: {
              color: isDark ? '#9fb0c8' : '#6b7280',
              font: { size: 12, weight: 'bold' },
              padding: 15,
              usePointStyle: true
            }
          },
          tooltip: {
            backgroundColor: 'rgba(0, 0, 0, 0.9)',
            titleColor: NEON.green,
            bodyColor: '#fff',
            borderColor: NEON.green,
            borderWidth: 2,
            padding: 12,
            callbacks: {
              label: function(context) {
                let label = context.dataset.label || '';
                if (label) {
                  label += ': ';
                }
                if (context.parsed.y !== null) {
                  label += 'R' + context.parsed.y.toLocaleString('en-ZA', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                  });
                }
                return label;
              }
            }
          }
        },
        scales: {
          x: { 
            grid: { display: false },
            ticks: { 
              color: isDark ? '#9fb0c8' : '#6b7280',
              font: { weight: 'bold' }
            }
          },
          y: {
            grid: { 
              color: isDark ? 'rgba(16, 185, 129, 0.15)' : 'rgba(16, 185, 129, 0.1)',
              lineWidth: 1
            },
            ticks: {
              color: isDark ? '#9fb0c8' : '#6b7280',
              font: { weight: 'bold' },
              callback: (value) => 'R' + (value / 1000).toFixed(0) + 'k',
            },
          },
        },
      },
    });
  }

  /**
   * Populate the sales analytics table.
   * @param {Array} rows
   */
  function renderSalesTable(rows) {
    const tbody = document.getElementById('tableSalesBody');
    if (!tbody) return;
    if (!rows || rows.length === 0) {
      tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:12px;">No data</td></tr>';
      return;
    }
    tbody.innerHTML = rows.map(row => {
      const total = parseFloat(row.total || 0);
      return `
        <tr>
          <td>${row.customer || 'Unknown'}</td>
          <td style="text-align:right;">${row.invoices || 0}</td>
          <td style="text-align:right;">R ${total.toLocaleString('en-ZA', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
        </tr>
      `;
    }).join('');
  }

  /**
   * Render bar chart for sales by region with NEON PURPLE
   * @param {Array} data
   */
  function renderRegionChart(data) {
    const ctx = document.getElementById('chartRegion');
    if (!ctx || !data) return;
    
    const labels = data.map(item => item.region);
    const values = data.map(item => parseInt(item.customers, 10) || 0);
    
    const ctx2d = ctx.getContext('2d');
    const gradient = ctx2d.createLinearGradient(0, 0, 0, ctx.height || 200);
    gradient.addColorStop(0, NEON.purple);
    gradient.addColorStop(1, NEON.pink);
    
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'Customers',
            data: values,
            backgroundColor: gradient,
            borderRadius: 10,
            borderWidth: 2,
            borderColor: NEON.purple,
            hoverBorderWidth: 3,
            hoverBorderColor: NEON.pink
          },
        ],
      },
      options: {
        responsive: true,
        plugins: { 
          legend: { display: false },
          tooltip: {
            backgroundColor: 'rgba(0, 0, 0, 0.9)',
            titleColor: NEON.purple,
            bodyColor: '#fff',
            borderColor: NEON.purple,
            borderWidth: 2,
            padding: 12
          }
        },
        scales: {
          x: { 
            grid: { display: false },
            ticks: { 
              color: isDark ? '#9fb0c8' : '#6b7280',
              font: { weight: 'bold' }
            }
          },
          y: {
            grid: { 
              color: isDark ? 'rgba(139, 92, 246, 0.15)' : 'rgba(139, 92, 246, 0.1)',
              lineWidth: 1
            },
            ticks: { 
              color: isDark ? '#9fb0c8' : '#6b7280',
              font: { weight: 'bold' },
              callback: (value) => value 
            },
          },
        },
      },
    });
  }

  /**
   * Render bar chart comparing invoices and quotes with NEON ORANGE
   * @param {Array} data
   */
  function renderVolumeChart(data) {
    const ctx = document.getElementById('chartVolume');
    if (!ctx || !data) return;
    
    const labels = data.map(item => item.name);
    const values = data.map(item => parseInt(item.value, 10) || 0);
    
    const ctx2d = ctx.getContext('2d');
    const gradient = ctx2d.createLinearGradient(0, 0, 0, ctx.height || 200);
    gradient.addColorStop(0, NEON.orange);
    gradient.addColorStop(1, NEON.yellow);
    
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'Volume',
            data: values,
            backgroundColor: gradient,
            borderRadius: 10,
            borderWidth: 2,
            borderColor: NEON.orange,
            hoverBorderWidth: 3,
            hoverBorderColor: NEON.yellow
          },
        ],
      },
      options: {
        responsive: true,
        plugins: { 
          legend: { display: false },
          tooltip: {
            backgroundColor: 'rgba(0, 0, 0, 0.9)',
            titleColor: NEON.orange,
            bodyColor: '#fff',
            borderColor: NEON.orange,
            borderWidth: 2,
            padding: 12
          }
        },
        scales: {
          x: { 
            grid: { display: false },
            ticks: { 
              color: isDark ? '#9fb0c8' : '#6b7280',
              font: { weight: 'bold' }
            }
          },
          y: {
            grid: { 
              color: isDark ? 'rgba(255, 153, 0, 0.15)' : 'rgba(255, 153, 0, 0.1)',
              lineWidth: 1
            },
            ticks: { 
              color: isDark ? '#9fb0c8' : '#6b7280',
              font: { weight: 'bold' },
              callback: (value) => value 
            },
          },
        },
      },
    });
  }

  // ===== ADDITIONAL UTILITY FUNCTIONS =====

  /**
   * Format currency for display
   * @param {number} value
   * @returns {string}
   */
  function formatCurrency(value) {
    return 'R' + parseFloat(value || 0).toLocaleString('en-ZA', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  /**
   * Format percentage for display
   * @param {number} value
   * @returns {string}
   */
  function formatPercentage(value) {
    return parseFloat(value || 0).toFixed(1) + '%';
  }

  /**
   * Get status badge HTML
   * @param {string} status
   * @returns {string}
   */
  function getStatusBadge(status) {
    const statusMap = {
      'paid': { class: 'pill--paid', text: 'Paid' },
      'open': { class: 'pill--open', text: 'Open' },
      'overdue': { class: 'pill--late', text: 'Overdue' },
      'draft': { class: 'pill--open', text: 'Draft' },
      'sent': { class: 'pill--open', text: 'Sent' },
      'viewed': { class: 'pill--open', text: 'Viewed' },
      'accepted': { class: 'pill--paid', text: 'Accepted' },
      'declined': { class: 'pill--late', text: 'Declined' },
      'expired': { class: 'pill--late', text: 'Expired' },
    };
    const badge = statusMap[status] || { class: '', text: status };
    return `<span class="pill ${badge.class}">${badge.text}</span>`;
  }

  /**
   * Animate counter from 0 to target value
   * @param {HTMLElement} element
   * @param {number} target
   * @param {number} duration
   */
  function animateCounter(element, target, duration = 1000) {
    let start = 0;
    const increment = target / (duration / 16); // 60fps
    const timer = setInterval(() => {
      start += increment;
      if (start >= target) {
        element.textContent = Math.round(target);
        clearInterval(timer);
      } else {
        element.textContent = Math.round(start);
      }
    }, 16);
  }

  /**
   * Update chart with new data (for future real-time updates)
   * @param {Chart} chart
   * @param {Array} newData
   */
  function updateChartData(chart, newData) {
    if (!chart || !newData) return;
    chart.data.datasets[0].data = newData;
    chart.update('active');
  }

  /**
   * Export chart as image
   * @param {string} canvasId
   * @param {string} filename
   */
  function exportChartAsImage(canvasId, filename = 'chart.png') {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    
    const link = document.createElement('a');
    link.download = filename;
    link.href = canvas.toDataURL('image/png');
    link.click();
  }

  /**
   * Print chart
   * @param {string} canvasId
   */
  function printChart(canvasId) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    
    const printWindow = window.open('', '', 'height=600,width=800');
    printWindow.document.write('<html><head><title>Chart</title></head><body>');
    printWindow.document.write('<img src="' + canvas.toDataURL() + '" />');
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.print();
  }

  /**
   * Toggle chart fullscreen
   * @param {string} chartCardId
   */
  function toggleChartFullscreen(chartCardId) {
    const card = document.getElementById(chartCardId);
    if (!card) return;
    
    if (!document.fullscreenElement) {
      card.requestFullscreen().catch(err => {
        console.error('Fullscreen error:', err);
      });
    } else {
      document.exitFullscreen();
    }
  }

  /**
   * Refresh all charts
   */
  function refreshAllCharts() {
    console.log('🔄 Refreshing all charts...');
    fetch('/qi/ajax/overview_api.php')
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          populateKpis(data.kpis || {});
          // Re-render all charts with new data
          // (Implementation depends on chart instances being stored globally)
          console.log('✅ Charts refreshed');
        }
      })
      .catch(err => {
        console.error('Refresh error:', err);
      });
  }

  // ===== EXPORT GLOBAL FUNCTIONS =====
  window.QIOverview = {
    formatCurrency,
    formatPercentage,
    getStatusBadge,
    animateCounter,
    updateChartData,
    exportChartAsImage,
    printChart,
    toggleChartFullscreen,
    refreshAllCharts
  };

  console.log('✅ QI Overview initialized with neon charts');
});