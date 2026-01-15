/*
 * overview.js for Payroll Module
 *
 * This script fetches metrics from `/payroll/ajax/overview_api.php` and
 * renders interactive charts and tables on the payroll dashboard using
 * Chart.js. It adapts the Quotes & Invoices dashboard logic for
 * payroll-specific data: employee counts, pay runs, monthly costs,
 * headcount trends, MTD payroll vs target, top earners, employment
 * distribution and run volumes. Colours are pulled from the payroll
 * theme (defined in payroll.css) to ensure a coherent look in both
 * light and dark modes.
 */

document.addEventListener('DOMContentLoaded', () => {
  // Identify the root payroll element to detect theme and access CSS vars
  const root = document.querySelector('.fw-payroll');
  if (!root) return;
  const isDark = root.getAttribute('data-theme') === 'dark';

  // Fetch aggregated metrics from the API
  fetch('/payroll/ajax/overview_api.php')
    .then(res => res.json())
    .then(data => {
      if (!data.ok) {
        console.error('Payroll overview error:', data.error);
        const container = document.getElementById('payroll-overview');
        if (container) {
          container.innerHTML = `<p style="padding:20px;">Error loading overview: ${data.error}</p>`;
        }
        return;
      }
      populateKpis(data.kpis || {});
      renderCostChart(data.cost_monthly || {});
      renderHeadcountChart(data.headcount_monthly || {});
      renderMtdChart(data.mtd || {});
      renderTopEmployeesTable(data.top_employees || []);
      renderEmploymentChart(data.employment_types || []);
      renderRunsVolumeChart(data.runs_volume || []);
    })
    .catch(err => {
      console.error('Payroll overview network error:', err);
      const container = document.getElementById('payroll-overview');
      if (container) {
        container.innerHTML = `<p style="padding:20px;">Network error loading overview: ${err.message}</p>`;
      }
    });

  /**
   * Update KPI numbers on the page.
   * @param {Object} kpis
   */
  function populateKpis(kpis) {
    const map = {
      employees: 'kpiEmployees',
      open_runs: 'kpiOpenRuns',
      last_net_payroll: 'kpiLastPayroll',
      new_employees: 'kpiNewEmployees',
    };
    Object.keys(map).forEach(key => {
      const el = document.getElementById(map[key]);
      if (el) {
        const val = kpis[key];
        if (key === 'last_net_payroll' && typeof val === 'number') {
          el.textContent = 'R ' + val.toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        } else {
          el.textContent = typeof val === 'number' ? val.toLocaleString('en-ZA') : '-';
        }
      }
    });
  }

  /**
   * Render the monthly payroll cost chart.
   * @param {Object} dataset
   */
  function renderCostChart(dataset) {
    const ctx = document.getElementById('chartCost');
    if (!ctx || !dataset.labels || !dataset.series) return;
    const ctx2d = ctx.getContext('2d');
    // Create a vertical gradient based on payroll accent and hover accent
    const accent = getComputedStyle(root).getPropertyValue('--accent-payroll').trim() || '#f97316';
    const hover = getComputedStyle(root).getPropertyValue('--accent-payroll-hover').trim() || '#ea580c';
    const gradient = ctx2d.createLinearGradient(0, 0, 0, ctx.height || 200);
    if (isDark) {
      gradient.addColorStop(0, hover);
      gradient.addColorStop(1, accent + '55');
    } else {
      gradient.addColorStop(0, accent);
      gradient.addColorStop(1, hover + '55');
    }
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: dataset.labels,
        datasets: [
          {
            label: 'Payroll Cost',
            data: dataset.series,
            borderRadius: 6,
            borderWidth: 0,
            backgroundColor: gradient,
          },
        ],
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
          x: { grid: { display: false } },
          y: {
            grid: { color: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.05)' },
            ticks: {
              callback: (value) => 'R' + (value / 1000).toFixed(0) + 'k',
            },
          },
        },
      },
    });
  }

  /**
   * Render the monthly headcount line chart.
   * @param {Object} dataset
   */
  function renderHeadcountChart(dataset) {
    const ctx = document.getElementById('chartHeadcount');
    if (!ctx || !dataset.labels || !dataset.series) return;
    const primary = getComputedStyle(root).getPropertyValue('--accent-success').trim() || '#10b981';
    new Chart(ctx, {
      type: 'line',
      data: {
        labels: dataset.labels,
        datasets: [
          {
            label: 'Headcount',
            data: dataset.series,
            borderColor: primary,
            backgroundColor: primary + '22',
            tension: 0.3,
            fill: true,
            pointRadius: 3,
          },
        ],
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
          x: { grid: { display: false } },
          y: {
            grid: { color: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.05)' },
            ticks: { callback: (value) => value },
          },
        },
      },
    });
  }

  /**
   * Render the Month-To-Date payroll chart (actual vs target).
   * @param {Object} dataset
   */
  function renderMtdChart(dataset) {
    const ctx = document.getElementById('chartMtd');
    if (!ctx || dataset.actual === undefined) return;
    const barColour = getComputedStyle(root).getPropertyValue('--accent-danger').trim() || '#ef4444';
    const lineColour = getComputedStyle(root).getPropertyValue('--accent-success').trim() || '#10b981';
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: ['Month To Date'],
        datasets: [
          {
            label: 'Actual',
            data: [dataset.actual || 0],
            yAxisID: 'y',
            backgroundColor: barColour,
            borderRadius: 6,
            borderWidth: 0,
          },
          {
            type: 'line',
            label: 'Target',
            data: [dataset.target || 0],
            yAxisID: 'y',
            borderColor: lineColour,
            backgroundColor: lineColour,
            tension: 0.25,
            pointRadius: 4,
            fill: false,
          },
        ],
      },
      options: {
        responsive: true,
        plugins: { legend: { position: 'bottom' } },
        scales: {
          x: { grid: { display: false } },
          y: {
            grid: { color: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.05)' },
            ticks: {
              callback: (value) => 'R' + (value / 1000).toFixed(0) + 'k',
            },
          },
        },
      },
    });
  }

  /**
   * Populate the top employees table.
   * @param {Array} rows
   */
  function renderTopEmployeesTable(rows) {
    const tbody = document.getElementById('tableTopEmployees');
    if (!tbody) return;
    if (!rows || rows.length === 0) {
      tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:12px;">No data</td></tr>';
      return;
    }
    tbody.innerHTML = rows.map(row => {
      const total = parseFloat(row.total || 0);
      return `
        <tr>
          <td>${row.employee}</td>
          <td style="text-align:right;">${row.runs}</td>
          <td style="text-align:right;">R ${total.toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
        </tr>
      `;
    }).join('');
  }

  /**
   * Render a bar chart showing the distribution of employment types.
   * @param {Array} data
   */
  function renderEmploymentChart(data) {
    const ctx = document.getElementById('chartEmploymentType');
    if (!ctx || !data) return;
    const labels = data.map(item => item.type);
    const values = data.map(item => parseInt(item.count, 10) || 0);
    // Build a palette cycling through payroll accent variants
    const paletteVars = ['--accent-success', '--accent-warning', '--accent-danger', '--accent-secondary'];
    const colours = values.map((_, idx) => {
      const varName = paletteVars[idx % paletteVars.length];
      return getComputedStyle(root).getPropertyValue(varName).trim() || '#6b7280';
    });
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'Employees',
            data: values,
            backgroundColor: colours,
            borderRadius: 6,
            borderWidth: 0,
          },
        ],
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
          x: { grid: { display: false } },
          y: {
            grid: { color: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.05)' },
            ticks: { callback: (value) => value },
          },
        },
      },
    });
  }

  /**
   * Render a bar chart comparing the number of posted vs open runs.
   * @param {Array} data
   */
  function renderRunsVolumeChart(data) {
    const ctx = document.getElementById('chartRunsVolume');
    if (!ctx || !data) return;
    const labels = data.map(item => item.name);
    const values = data.map(item => parseInt(item.value, 10) || 0);
    const barColour = getComputedStyle(root).getPropertyValue('--accent-warning').trim() || '#f59e0b';
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          {
            label: '',
            data: values,
            backgroundColor: barColour,
            borderRadius: 8,
            borderWidth: 0,
          },
        ],
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
          x: { grid: { display: false } },
          y: {
            grid: { color: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.05)' },
            ticks: { callback: (value) => value },
          },
        },
      },
    });
  }
});