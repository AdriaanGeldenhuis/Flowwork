/*
 * overview.js
 *
 * This script powers the charts and table population on the CRM overview page.
 * It uses Chart.js v4 to render bar and line charts, provides dummy data for
 * demonstration, and renders status pills into tables. Replace the dummy
 * arrays with AJAX calls to your own API endpoints once available.
 */

document.addEventListener('DOMContentLoaded', () => {
  // Determine if we are currently in dark mode. This influences chart colour palettes.
  // The CRM sets data-theme="dark" on the root .fw-crm element.
  const root = document.querySelector('.fw-crm');
  const isDark = root && root.getAttribute('data-theme') === 'dark';
  // Handle Export button click – currently triggers browser print. Replace with
  // custom export logic (CSV/PDF) as needed.
  const exportBtn = document.getElementById('btn-export');
  if (exportBtn) {
    exportBtn.addEventListener('click', () => {
      // Placeholder: use window.print() as a simple export demonstration.
      window.print();
    });
  }

  // Example data for charts (revenue and customer satisfaction).
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const revenue = [12000, 15000, 13000, 18000, 22000, 21000, 26000, 24000, 28000, 30000, 27000, 32000];
  const csat = [72, 75, 74, 78, 81, 79, 82, 83, 80, 85, 84, 86];

  // -------------------------------------------------------------------------
  // Additional datasets for the new charts
  // Visitor Insights (monthly visitors)
  const visitors = [220, 240, 200, 250, 260, 280, 270, 300, 310, 330, 320, 340];
  // Sales analytics: orders and revenue over months
  const analyticsOrders = [400, 380, 420, 450, 430, 470, 500, 520, 510, 530, 550, 580];
  const analyticsRevenue = [12000, 15000, 13000, 18000, 22000, 21000, 26000, 24000, 28000, 30000, 27000, 32000];
  // Country mapping data (sales by country)
  const countries = ['South Africa', 'USA', 'UK', 'Germany', 'Japan'];
  const countrySales = [300, 250, 200, 180, 160];
  // Volume vs Service per quarter
  const volume = [120, 140, 160, 180];
  const service = [100, 130, 140, 150];

  // Revenue bar chart
  const ctxRev = document.getElementById('chartRevenue');
  if (ctxRev) {
    const ctxRev2D = ctxRev.getContext('2d');
    // Create a vertical gradient for the bars. Colours adapt based on the theme.
    const revenueGradient = ctxRev2D.createLinearGradient(0, 0, 0, ctxRev.height || 200);
    if (isDark) {
      // Dark mode: use CRM accent colour at full strength and a medium opacity tail
      revenueGradient.addColorStop(0, 'rgba(6,182,212,1)');
      revenueGradient.addColorStop(1, 'rgba(6,182,212,0.6)');
    } else {
      // Light mode: subtle accent gradient for the revenue bars
      revenueGradient.addColorStop(0, 'rgba(6,182,212,0.9)');
      revenueGradient.addColorStop(1, 'rgba(6,182,212,0.3)');
    }
    new Chart(ctxRev, {
      type: 'bar',
      data: {
        labels: months,
        datasets: [{
          label: 'Revenue',
          data: revenue,
          borderWidth: 0,
          borderRadius: 8,
          backgroundColor: revenueGradient,
        }],
      },
      options: {
        responsive: true,
        plugins: {
          legend: { display: false },
        },
        scales: {
          x: {
            grid: { display: false },
          },
          y: {
            grid: { color: 'rgba(0, 0, 0, 0.06)' },
            ticks: {
              callback: (value) => 'R' + value / 1000 + 'k',
            },
          },
        },
      },
    });
  }

  // Visitor Insights line chart
  const ctxVisitors = document.getElementById('chartVisitors');
  if (ctxVisitors) {
    const ctxV2D = ctxVisitors.getContext('2d');
    // Gradient for visitors line area
    const visitorsGradient = ctxV2D.createLinearGradient(0, 0, 0, ctxVisitors.height || 200);
    if (isDark) {
      visitorsGradient.addColorStop(0, 'rgba(203,213,225,0.9)'); // slate-300
      visitorsGradient.addColorStop(1, 'rgba(203,213,225,0.2)');
    } else {
      visitorsGradient.addColorStop(0, 'rgba(59,130,246,0.8)'); // blue-500
      visitorsGradient.addColorStop(1, 'rgba(59,130,246,0.15)');
    }
    new Chart(ctxVisitors, {
      type: 'line',
      data: {
        labels: months,
        datasets: [
          {
            label: 'Visitors',
            data: visitors,
            fill: true,
            backgroundColor: visitorsGradient,
            borderColor: isDark ? 'rgba(96,165,250,1)' : 'rgba(59,130,246,1)',
            tension: 0.3,
            pointRadius: 3,
            pointBackgroundColor: isDark ? 'rgba(96,165,250,1)' : 'rgba(59,130,246,1)',
            pointHoverRadius: 4,
          },
        ],
      },
      options: {
        plugins: {
          legend: { display: false },
        },
        scales: {
          x: {
            grid: { display: false },
          },
          y: {
            ticks: {
              callback: (value) => value,
            },
            grid: { color: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.05)' },
          },
        },
      },
    });
  }

  // Sales Analytics chart (dual dataset: orders & revenue)
  const ctxAnalytics = document.getElementById('chartSalesAnalytics');
  if (ctxAnalytics) {
    const ctxA2D = ctxAnalytics.getContext('2d');
    // Gradient for revenue bars
    const analyticsRevenueGradient = ctxA2D.createLinearGradient(0, 0, 0, ctxAnalytics.height || 200);
    if (isDark) {
      analyticsRevenueGradient.addColorStop(0, 'rgba(6,182,212,1)');
      analyticsRevenueGradient.addColorStop(1, 'rgba(6,182,212,0.5)');
    } else {
      analyticsRevenueGradient.addColorStop(0, 'rgba(6,182,212,0.8)');
      analyticsRevenueGradient.addColorStop(1, 'rgba(6,182,212,0.3)');
    }
    new Chart(ctxAnalytics, {
      type: 'bar',
      data: {
        labels: months,
        datasets: [
          {
            label: 'Orders',
            data: analyticsOrders,
            type: 'line',
            yAxisID: 'y1',
            borderColor: isDark ? 'rgba(16,185,129,1)' : 'rgba(16,185,129,1)',
            tension: 0.3,
            pointRadius: 3,
            pointBackgroundColor: isDark ? 'rgba(16,185,129,1)' : 'rgba(16,185,129,1)',
            fill: false,
          },
          {
            label: 'Revenue',
            data: analyticsRevenue,
            yAxisID: 'y',
            borderWidth: 0,
            borderRadius: 6,
            backgroundColor: analyticsRevenueGradient,
          },
        ],
      },
      options: {
        plugins: {
          legend: { position: 'bottom' },
        },
        scales: {
          x: {
            grid: { display: false },
          },
          y: {
            position: 'left',
            grid: { color: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.05)' },
            ticks: {
              callback: (value) => 'R' + value / 1000 + 'k',
            },
          },
          y1: {
            position: 'right',
            grid: { drawOnChartArea: false },
            ticks: {
              callback: (value) => value,
            },
          },
        },
      },
    });
  }

  // Sales Mapping by Country (horizontal bar chart)
  const ctxCountry = document.getElementById('chartCountrySales');
  if (ctxCountry) {
    const ctxC2D = ctxCountry.getContext('2d');
    // Gradient for country bars
    const countryGradient = ctxC2D.createLinearGradient(0, 0, ctxCountry.width || 200, 0);
    if (isDark) {
      countryGradient.addColorStop(0, 'rgba(16,185,129,1)');
      countryGradient.addColorStop(1, 'rgba(6,182,212,1)');
    } else {
      countryGradient.addColorStop(0, 'rgba(34,197,94,0.8)');
      countryGradient.addColorStop(1, 'rgba(59,130,246,0.8)');
    }
    new Chart(ctxCountry, {
      type: 'bar',
      data: {
        labels: countries,
        datasets: [
          {
            label: 'Sales',
            data: countrySales,
            borderWidth: 0,
            borderRadius: 8,
            backgroundColor: countryGradient,
          },
        ],
      },
      options: {
        indexAxis: 'y',
        plugins: {
          legend: { display: false },
        },
        scales: {
          x: {
            grid: { color: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.05)' },
            ticks: {
              callback: (value) => value,
            },
          },
          y: {
            grid: { display: false },
          },
        },
      },
    });
  }

  // Volume vs Service chart (grouped bar chart)
  const ctxVolumeService = document.getElementById('chartVolumeService');
  if (ctxVolumeService) {
    // Gradients for each dataset
    const ctxVS2D = ctxVolumeService.getContext('2d');
    const volumeGrad = ctxVS2D.createLinearGradient(0, 0, 0, ctxVolumeService.height || 200);
    const serviceGrad = ctxVS2D.createLinearGradient(0, 0, 0, ctxVolumeService.height || 200);
    if (isDark) {
      volumeGrad.addColorStop(0, 'rgba(59,130,246,1)');
      volumeGrad.addColorStop(1, 'rgba(59,130,246,0.5)');
      serviceGrad.addColorStop(0, 'rgba(139,92,246,1)');
      serviceGrad.addColorStop(1, 'rgba(139,92,246,0.5)');
    } else {
      volumeGrad.addColorStop(0, 'rgba(59,130,246,0.8)');
      volumeGrad.addColorStop(1, 'rgba(59,130,246,0.3)');
      serviceGrad.addColorStop(0, 'rgba(167,139,250,0.8)');
      serviceGrad.addColorStop(1, 'rgba(167,139,250,0.3)');
    }
    new Chart(ctxVolumeService, {
      type: 'bar',
      data: {
        labels: ['Q1', 'Q2', 'Q3', 'Q4'],
        datasets: [
          {
            label: 'Volume',
            data: volume,
            borderRadius: 8,
            borderWidth: 0,
            backgroundColor: volumeGrad,
          },
          {
            label: 'Service',
            data: service,
            borderRadius: 8,
            borderWidth: 0,
            backgroundColor: serviceGrad,
          },
        ],
      },
      options: {
        plugins: {
          legend: { position: 'bottom' },
        },
        scales: {
          x: {
            grid: { display: false },
          },
          y: {
            grid: { color: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.05)' },
            ticks: {
              callback: (value) => value,
            },
          },
        },
      },
    });
  }

  // Customer Satisfaction line chart
  const ctxCS = document.getElementById('chartCSAT');
  if (ctxCS) {
    const ctxCS2D = ctxCS.getContext('2d');
    // Create a gradient for the area under the line. Use more opaque colours in dark mode.
    const csatGradient = ctxCS2D.createLinearGradient(0, 0, 0, ctxCS.height || 200);
    if (isDark) {
      csatGradient.addColorStop(0, 'rgba(52,211,153,0.9)');
      csatGradient.addColorStop(1, 'rgba(52,211,153,0.3)');
    } else {
      csatGradient.addColorStop(0, 'rgba(52,211,153,0.8)');
      csatGradient.addColorStop(1, 'rgba(52,211,153,0.1)');
    }
    new Chart(ctxCS, {
      type: 'line',
      data: {
        labels: months,
        datasets: [{
          label: 'CSAT',
          data: csat,
          fill: true,
          backgroundColor: csatGradient,
          borderColor: isDark ? 'rgba(16,185,129,1)' : 'rgba(16,185,129,1)',  // same in both modes
          tension: 0.35,
          pointRadius: 3,
          pointBackgroundColor: isDark ? 'rgba(16,185,129,1)' : 'rgba(16,185,129,1)',
          pointHoverRadius: 4,
        }],
      },
      options: {
        plugins: {
          legend: { display: false },
        },
        scales: {
          x: {
            grid: { display: false },
          },
          y: {
            min: 60,
            max: 100,
            ticks: {
              callback: (value) => value + '%',
            },
          },
        },
      },
    });
  }

  // Reality vs Target bar chart
  const ctxRVT = document.getElementById('chartRVT');
  if (ctxRVT) {
    new Chart(ctxRVT, {
      type: 'bar',
      data: {
        labels: ['Q1', 'Q2', 'Q3', 'Q4'],
        datasets: [
          {
            label: 'Actual',
            data: [9, 10.5, 8.5, 9.2],
            borderRadius: 8,
            backgroundColor: isDark ? 'rgba(236,72,153,1)' : 'rgba(236,72,153,0.8)', // stronger pink in dark mode
          },
          {
            label: 'Target',
            data: [12.5, 12.5, 12.5, 12.5],
            borderRadius: 8,
            backgroundColor: isDark ? 'rgba(107,114,128,0.6)' : 'rgba(229,231,235,0.6)', // dark grey for dark mode
          },
        ],
      },
      options: {
        plugins: {
          legend: { position: 'bottom' },
        },
        scales: {
          x: {
            grid: { display: false },
          },
          y: {
            ticks: {
              callback: (value) => value + '%',
            },
            grid: { color: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0, 0, 0, 0.05)' },
          },
        },
      },
    });
    // Set gauge values. These could be updated dynamically based on data.
    const rvActual = document.getElementById('rv-actual');
    const rvTarget = document.getElementById('rv-target');
    if (rvActual) rvActual.textContent = '9.2%';
    if (rvTarget) rvTarget.textContent = '12.5%';
  }

  // Populate Recent Activity table with dummy data
  const activity = [
    { when: '10:22', type: 'Invoice', ref: '#INV-1042', account: 'Hallmart Group', amount: 'R 12,450', status: 'paid' },
    { when: '09:10', type: 'Quote', ref: '#Q-553', account: 'Concreto Civils', amount: 'R 89,000', status: 'open' },
    { when: 'Yesterday', type: 'Invoice', ref: '#INV-1041', account: 'Bodycorp', amount: 'R 5,200', status: 'late' },
  ];
  const tblActivity = document.getElementById('tbl-activity');
  if (tblActivity) {
    tblActivity.innerHTML = activity
      .map((r) => {
        return `
          <tr>
            <td>${r.when}</td>
            <td>${r.type}</td>
            <td>${r.ref}</td>
            <td>${r.account}</td>
            <td>${r.amount}</td>
            <td>${pill(r.status)}</td>
          </tr>`;
      })
      .join('');
  }

  // Populate Top Accounts table with dummy data
  const accounts = [
    { name: 'Geldenhuis Trust', orders: 14, rev: 'R 210k', last: '2d ago' },
    { name: 'Hallmart Group', orders: 8, rev: 'R 125k', last: '1d ago' },
    { name: 'Bodycorp', orders: 5, rev: 'R 48k', last: '6h ago' },
  ];
  const tblAccounts = document.getElementById('tbl-accounts');
  if (tblAccounts) {
    tblAccounts.innerHTML = accounts
      .map((r) => {
        return `
          <tr>
            <td>${r.name}</td>
            <td>${r.orders}</td>
            <td>${r.rev}</td>
            <td>${r.last}</td>
          </tr>`;
      })
      .join('');
  }
});

// Helper to render a status pill. Maps status strings to CSS classes.
function pill(status) {
  const map = {
    open: 'pill pill--open',
    paid: 'pill pill--paid',
    late: 'pill pill--late',
  };
  const cls = map[status] || 'pill';
  const label = status.charAt(0).toUpperCase() + status.slice(1);
  return `<span class="${cls}">${label}</span>`;
}