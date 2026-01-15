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

  // ========== THEME TOGGLE ==========
  function initTheme() {
    const toggle = document.getElementById('themeToggle');
    const indicator = document.getElementById('themeIndicator');
    const body = document.querySelector('.fw-qi');
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

  // ========== OVERVIEW DASHBOARD ==========
  function initOverview() {
    const listContainer = document.getElementById('qiList');
    if (!listContainer) {
        console.error('qiList container not found');
        return;
    }

    const urlParams = new URLSearchParams(window.location.search);
    const activeTab = urlParams.get('tab') || 'overview';
    
    if (activeTab !== 'overview') {
        console.log('Not overview tab, skipping...');
        return;
    }

    console.log('Loading overview...');

    listContainer.innerHTML = `
        <div class="fw-qi__loading">
            <div class="fw-qi__spinner"></div>
            <p>Loading overview...</p>
        </div>
    `;

    fetch('/qi/ajax/load_overview.php')
        .then(res => {
            console.log('Response status:', res.status);
            return res.json();
        })
        .then(data => {
            console.log('Response data:', data);
            if (data.ok) {
                listContainer.innerHTML = renderOverview(data);
            } else {
                listContainer.innerHTML = '<div class="fw-qi__loading">Error: ' + (data.error || 'Unknown error') + '</div>';
            }
        })
        .catch(err => {
            console.error('Fetch error:', err);
            listContainer.innerHTML = '<div class="fw-qi__loading">Network error: ' + err.message + '</div>';
        });
}

function renderOverview(data) {
    const stats = data.stats || {};
    const overdueInvoices = data.overdue_invoices || [];
    const pendingInvoices = data.pending_invoices || [];
    const activeQuotes = data.active_quotes || [];
    const recentPayments = data.recent_payments || [];
    const revenueChart = data.revenue_chart || [];
    const topCustomers = data.top_customers || [];

    const avgPaymentDays = Math.round(stats.avg_payment_days || 0);

    let html = `
        <div class="fw-qi__overview">
            
            <!-- Hero Stats Row -->
            <div class="fw-qi__hero-stats">
                <div class="fw-qi__hero-stat fw-qi__hero-stat--primary">
                    <div class="fw-qi__hero-stat-icon">💰</div>
                    <div class="fw-qi__hero-stat-content">
                        <div class="fw-qi__hero-stat-value">R ${parseFloat(stats.outstanding_amount || 0).toLocaleString('en-ZA', {minimumFractionDigits: 0, maximumFractionDigits: 0})}</div>
                        <div class="fw-qi__hero-stat-label">Outstanding</div>
                    </div>
                </div>

                <div class="fw-qi__hero-stat fw-qi__hero-stat--success">
                    <div class="fw-qi__hero-stat-icon">✅</div>
                    <div class="fw-qi__hero-stat-content">
                        <div class="fw-qi__hero-stat-value">R ${parseFloat(stats.paid_this_month || 0).toLocaleString('en-ZA', {minimumFractionDigits: 0, maximumFractionDigits: 0})}</div>
                        <div class="fw-qi__hero-stat-label">Paid This Month</div>
                    </div>
                </div>

                <div class="fw-qi__hero-stat fw-qi__hero-stat--info">
                    <div class="fw-qi__hero-stat-icon">📊</div>
                    <div class="fw-qi__hero-stat-content">
                        <div class="fw-qi__hero-stat-value">R ${parseFloat(stats.paid_this_year || 0).toLocaleString('en-ZA', {minimumFractionDigits: 0, maximumFractionDigits: 0})}</div>
                        <div class="fw-qi__hero-stat-label">YTD Revenue</div>
                    </div>
                </div>

                <div class="fw-qi__hero-stat fw-qi__hero-stat--accent">
                    <div class="fw-qi__hero-stat-icon">⏱️</div>
                    <div class="fw-qi__hero-stat-content">
                        <div class="fw-qi__hero-stat-value">${avgPaymentDays} days</div>
                        <div class="fw-qi__hero-stat-label">Avg. Payment Time</div>
                    </div>
                </div>
            </div>

            <!-- Quick Stats Grid -->
            <div class="fw-qi__stats-grid">
                <div class="fw-qi__stat-card fw-qi__stat-card--danger" onclick="location.href='?tab=invoices&status=overdue'">
                    <div class="fw-qi__stat-icon">⚠️</div>
                    <div class="fw-qi__stat-value">${stats.overdue_invoices || 0}</div>
                    <div class="fw-qi__stat-label">Overdue Invoices</div>
                </div>

                <div class="fw-qi__stat-card fw-qi__stat-card--warning" onclick="location.href='?tab=invoices&status=sent'">
                    <div class="fw-qi__stat-icon">📋</div>
                    <div class="fw-qi__stat-value">${stats.pending_invoices || 0}</div>
                    <div class="fw-qi__stat-label">Pending Invoices</div>
                </div>

                <div class="fw-qi__stat-card fw-qi__stat-card--info" onclick="location.href='?tab=quotes'">
                    <div class="fw-qi__stat-icon">📄</div>
                    <div class="fw-qi__stat-value">${stats.active_quotes || 0}</div>
                    <div class="fw-qi__stat-label">Active Quotes</div>
                </div>
            </div>

            <!-- Main Content Grid -->
            <div class="fw-qi__dashboard-grid">
                
                <!-- LEFT COLUMN -->
                <div class="fw-qi__dashboard-left">
                    
                    <!-- OVERDUE INVOICES (Critical) -->
                    ${overdueInvoices.length > 0 ? `
                        <div class="fw-qi__dashboard-section fw-qi__dashboard-section--danger">
                            <div class="fw-qi__section-header">
                                <h3>⚠️ Overdue Invoices (Urgent)</h3>
                                <a href="?tab=invoices&status=overdue" class="fw-qi__view-all">View All →</a>
                            </div>
                            <div class="fw-qi__table-wrapper">
                                <table class="fw-qi__mini-table">
                                    <thead>
                                        <tr>
                                            <th>Invoice</th>
                                            <th>Customer</th>
                                            <th>Due Date</th>
                                            <th class="fw-qi__table-align-right">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${overdueInvoices.map(inv => `
                                            <tr onclick="location.href='/qi/invoice_view.php?id=${inv.id}'" class="fw-qi__clickable-row">
                                                <td><strong>${inv.invoice_number}</strong></td>
                                                <td>${inv.customer_name}</td>
                                                <td>
                                                    <span class="fw-qi__overdue-badge-sm">${inv.days_overdue}d overdue</span>
                                                </td>
                                                <td class="fw-qi__table-align-right"><strong>R ${parseFloat(inv.balance_due).toFixed(2)}</strong></td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    ` : ''}

                    <!-- PENDING INVOICES -->
                    ${pendingInvoices.length > 0 ? `
                        <div class="fw-qi__dashboard-section">
                            <div class="fw-qi__section-header">
                                <h3>📋 Pending Invoices</h3>
                                <a href="?tab=invoices&status=sent" class="fw-qi__view-all">View All →</a>
                            </div>
                            <div class="fw-qi__table-wrapper">
                                <table class="fw-qi__mini-table">
                                    <thead>
                                        <tr>
                                            <th>Invoice</th>
                                            <th>Customer</th>
                                            <th>Due</th>
                                            <th class="fw-qi__table-align-right">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${pendingInvoices.map(inv => `
                                            <tr onclick="location.href='/qi/invoice_view.php?id=${inv.id}'" class="fw-qi__clickable-row">
                                                <td><strong>${inv.invoice_number}</strong></td>
                                                <td>${inv.customer_name}</td>
                                                <td>
                                                    ${inv.days_until_due <= 7 ? 
                                                        `<span class="fw-qi__due-soon-badge">${inv.days_until_due}d</span>` : 
                                                        new Date(inv.due_date).toLocaleDateString()
                                                    }
                                                </td>
                                                <td class="fw-qi__table-align-right"><strong>R ${parseFloat(inv.balance_due).toFixed(2)}</strong></td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    ` : ''}

                    <!-- ACTIVE QUOTES -->
                    ${activeQuotes.length > 0 ? `
                        <div class="fw-qi__dashboard-section">
                            <div class="fw-qi__section-header">
                                <h3>📄 Active Quotes</h3>
                                <a href="?tab=quotes" class="fw-qi__view-all">View All →</a>
                            </div>
                            <div class="fw-qi__table-wrapper">
                                <table class="fw-qi__mini-table">
                                    <thead>
                                        <tr>
                                            <th>Quote</th>
                                            <th>Customer</th>
                                            <th>Expires</th>
                                            <th class="fw-qi__table-align-right">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${activeQuotes.map(q => `
                                            <tr onclick="location.href='/qi/quote_view.php?id=${q.id}'" class="fw-qi__clickable-row">
                                                <td><strong>${q.quote_number}</strong></td>
                                                <td>${q.customer_name}</td>
                                                <td>
                                                    ${q.days_until_expiry <= 3 ? 
                                                        `<span class="fw-qi__expiring-badge">${q.days_until_expiry}d left</span>` : 
                                                        new Date(q.expiry_date).toLocaleDateString()
                                                    }
                                                </td>
                                                <td class="fw-qi__table-align-right"><strong>R ${parseFloat(q.total).toFixed(2)}</strong></td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    ` : ''}

                </div>

                <!-- RIGHT COLUMN -->
                <div class="fw-qi__dashboard-right">
                    
                    <!-- REVENUE CHART -->
                    ${revenueChart.length > 0 ? `
                        <div class="fw-qi__dashboard-section">
                            <div class="fw-qi__section-header">
                                <h3>📈 Revenue Trend (6 Months)</h3>
                            </div>
                            <div class="fw-qi__chart-container">
                                ${renderMiniChart(revenueChart)}
                            </div>
                        </div>
                    ` : ''}

                    <!-- RECENT PAYMENTS -->
                    ${recentPayments.length > 0 ? `
                        <div class="fw-qi__dashboard-section">
                            <div class="fw-qi__section-header">
                                <h3>💳 Recent Payments</h3>
                            </div>
                            <div class="fw-qi__payment-list">
                                ${recentPayments.map(pmt => `
                                    <div class="fw-qi__payment-item">
                                        <div class="fw-qi__payment-icon">✅</div>
                                        <div class="fw-qi__payment-details">
                                            <div class="fw-qi__payment-customer">${pmt.customer_name}</div>
                                            <div class="fw-qi__payment-invoice">${pmt.invoice_number} • ${new Date(pmt.payment_date).toLocaleDateString()}</div>
                                        </div>
                                        <div class="fw-qi__payment-amount">R ${parseFloat(pmt.amount).toFixed(2)}</div>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    ` : ''}

                    <!-- TOP CUSTOMERS -->
                    ${topCustomers.length > 0 ? `
                        <div class="fw-qi__dashboard-section">
                            <div class="fw-qi__section-header">
                                <h3>🏆 Top Customers (YTD)</h3>
                            </div>
                            <div class="fw-qi__top-customers">
                                ${topCustomers.map((cust, idx) => `
                                    <div class="fw-qi__top-customer-item">
                                        <div class="fw-qi__top-customer-rank">${idx + 1}</div>
                                        <div class="fw-qi__top-customer-info">
                                            <div class="fw-qi__top-customer-name">${cust.name}</div>
                                            <div class="fw-qi__top-customer-meta">${cust.invoice_count} invoices</div>
                                        </div>
                                        <div class="fw-qi__top-customer-revenue">R ${parseFloat(cust.total_revenue).toLocaleString('en-ZA', {minimumFractionDigits: 0})}</div>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    ` : ''}

                </div>

            </div>

        </div>
    `;

    return html;
}

function renderMiniChart(data) {
    if (data.length === 0) return '<p class="fw-qi__no-data">No data available</p>';

    const maxRevenue = Math.max(...data.map(d => parseFloat(d.revenue)));
    const chartHeight = 120;

    let html = '<div class="fw-qi__mini-chart">';
    
    data.forEach((item, idx) => {
        const height = (parseFloat(item.revenue) / maxRevenue) * chartHeight;
        const monthLabel = new Date(item.month + '-01').toLocaleDateString('en-ZA', { month: 'short' });
        
        html += `
            <div class="fw-qi__chart-bar-wrapper">
                <div class="fw-qi__chart-bar" style="height: ${height}px;" title="R ${parseFloat(item.revenue).toLocaleString('en-ZA')}">
                    <div class="fw-qi__chart-bar-fill"></div>
                </div>
                <div class="fw-qi__chart-label">${monthLabel}</div>
                <div class="fw-qi__chart-value">R ${(parseFloat(item.revenue) / 1000).toFixed(0)}k</div>
            </div>
        `;
    });

    html += '</div>';
    return html;
}

  // ========== LIST VIEW (Quotes, Invoices, etc.) ==========
  function initList() {
    const listContainer = document.getElementById('qiList');
    const searchInput = document.getElementById('searchInput');
    const filterStatus = document.getElementById('filterStatus');
    const filterDateFrom = document.getElementById('filterDateFrom');
    const filterDateTo = document.getElementById('filterDateTo');

    if (!listContainer) return;

    const urlParams = new URLSearchParams(window.location.search);
    const activeTab = urlParams.get('tab') || 'overview';

    if (activeTab === 'overview') return; // Skip if overview tab

    // Pagination state
    let currentPage = 1;
    const PAGE_SIZE = 20;
    let totalPages = 1;

    function getTypeFromTab(tab) {
        switch (tab) {
            case 'quotes': return 'quote';
            case 'invoices': return 'invoice';
            case 'recurring': return 'recurring';
            case 'credit_notes': return 'credit';
            default: return 'quote';
        }
    }

    // Pre-populate filters based on query string
    const urlParamsList = new URLSearchParams(window.location.search);
    const initialStatus = urlParamsList.get('status');
    if (filterStatus && initialStatus) {
        filterStatus.value = initialStatus;
    }

    function loadList() {
        const search = searchInput ? searchInput.value : '';
        const status = filterStatus ? filterStatus.value : '';
        const dateFrom = filterDateFrom ? filterDateFrom.value : '';
        const dateTo = filterDateTo ? filterDateTo.value : '';

        const type = getTypeFromTab(activeTab);
        const params = new URLSearchParams({
            type: type,
            q: search,
            status: status,
            page: currentPage,
            page_size: PAGE_SIZE
        });

        // Add date filters if present (not used server-side yet but retained)
        if (dateFrom) params.append('date_from', dateFrom);
        if (dateTo) params.append('date_to', dateTo);

        listContainer.innerHTML = `
            <div class="fw-qi__loading">
                <div class="fw-qi__spinner"></div>
                <p>Loading ${activeTab}...</p>
            </div>
        `;

        fetch('/qi/ajax/search.php?' + params.toString())
            .then(res => res.json())
            .then(data => {
                if (data.ok) {
                    const rows = data.data && Array.isArray(data.data.rows) ? data.data.rows : [];
                    const total = data.data && typeof data.data.total !== 'undefined' ? data.data.total : 0;
                    totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
                    if (rows.length > 0) {
                        // Render table and pagination
                        listContainer.innerHTML = renderList(rows, activeTab) + renderPagination();
                        attachPaginationEvents();
                    } else {
                        listContainer.innerHTML = renderEmptyState(activeTab);
                    }
                } else {
                    listContainer.innerHTML = '<div class="fw-qi__loading">Error: ' + (data.error || 'Unknown error') + '</div>';
                }
            })
            .catch(err => {
                listContainer.innerHTML = '<div class="fw-qi__loading">Network error</div>';
                console.error(err);
            });
    }

    function renderPagination() {
        if (totalPages <= 1) return '';
        const prevDisabled = currentPage <= 1 ? 'disabled' : '';
        const nextDisabled = currentPage >= totalPages ? 'disabled' : '';
        return `
            <div class="fw-qi__pagination" style="margin-top: var(--fw-spacing-md); display: flex; justify-content: space-between; align-items: center;">
                <button class="fw-qi__btn fw-qi__btn--secondary" data-action="prev" ${prevDisabled}>Prev</button>
                <span>Page ${currentPage} of ${totalPages}</span>
                <button class="fw-qi__btn fw-qi__btn--secondary" data-action="next" ${nextDisabled}>Next</button>
            </div>
        `;
    }

    function attachPaginationEvents() {
        const pag = listContainer.querySelector('.fw-qi__pagination');
        if (!pag) return;
        pag.addEventListener('click', (e) => {
            const action = e.target.getAttribute('data-action');
            if (!action) return;
            if (action === 'prev' && currentPage > 1) {
                currentPage--;
                loadList();
            } else if (action === 'next' && currentPage < totalPages) {
                currentPage++;
                loadList();
            }
        });
    }

    function renderList(items, tab) {
        const headers = getHeaders(tab);
        
        let html = '<table class="fw-qi__table"><thead><tr>';
        headers.forEach(h => {
            html += `<th class="${h.align || ''}">${h.label}</th>`;
        });
        html += '</tr></thead><tbody>';

        items.forEach(item => {
            html += '<tr onclick="QIView.openItem(\'' + tab + '\', ' + item.id + ')">';
            headers.forEach(h => {
                let value = item[h.key] || '';
                if (h.key === 'status') {
                    // For recurring type, display status without badge; others use badge styling
                    if (tab === 'recurring') {
                        value = item.status_label || item.status;
                    } else {
                        value = `<span class="fw-qi__badge fw-qi__badge--${item.status}">${item.status_label || item.status}</span>`;
                    }
                } else if (h.key === 'total' || h.key === 'balance_due') {
                    value = 'R ' + parseFloat(value || 0).toFixed(2);
                }
                html += `<td class="${h.align || ''}">${value}</td>`;
            });
            html += '</tr>';
        });

        html += '</tbody></table>';
        return html;
    }

    function getHeaders(tab) {
        const headers = {
            'quotes': [
                { key: 'quote_number', label: '#', align: '' },
                { key: 'customer_name', label: 'Customer', align: '' },
                { key: 'issue_date', label: 'Date', align: '' },
                { key: 'expiry_date', label: 'Expires', align: '' },
                { key: 'total', label: 'Amount', align: 'fw-qi__table-align-right' },
                { key: 'status', label: 'Status', align: 'fw-qi__table-align-center' }
            ],
            'invoices': [
                { key: 'invoice_number', label: '#', align: '' },
                { key: 'customer_name', label: 'Customer', align: '' },
                { key: 'issue_date', label: 'Date', align: '' },
                { key: 'due_date', label: 'Due', align: '' },
                { key: 'total', label: 'Total', align: 'fw-qi__table-align-right' },
                { key: 'balance_due', label: 'Balance', align: 'fw-qi__table-align-right' },
                { key: 'status', label: 'Status', align: 'fw-qi__table-align-center' }
            ],
            'recurring': [
                { key: 'template_name', label: 'Name', align: '' },
                { key: 'customer_name', label: 'Customer', align: '' },
                { key: 'frequency_label', label: 'Frequency', align: '' },
                { key: 'next_run_date', label: 'Next Run', align: '' },
                { key: 'status', label: 'Status', align: 'fw-qi__table-align-center' }
            ],
            'credit_notes': [
                { key: 'credit_note_number', label: '#', align: '' },
                { key: 'customer_name', label: 'Customer', align: '' },
                { key: 'issue_date', label: 'Date', align: '' },
                { key: 'total', label: 'Amount', align: 'fw-qi__table-align-right' },
                { key: 'status', label: 'Status', align: 'fw-qi__table-align-center' }
            ]
        };
        return headers[tab] || headers['quotes'];
    }

    function renderEmptyState(tab) {
        const config = {
            'quotes': {
                icon: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
                title: 'No Quotes Yet',
                text: 'Create your first quote to get started',
                button: 'Create First Quote',
                link: '/qi/quote_new.php'
            },
            'invoices': {
                icon: '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>',
                title: 'No Invoices Yet',
                text: 'Create your first invoice to start billing',
                button: 'Create First Invoice',
                link: '/qi/invoice_new.php'
            },
            'recurring': {
                icon: '<path d="M23 6l-9.5 9.5-5-5L1 18"/><polyline points="17 6 23 6 23 12"/>',
                title: 'No Recurring Invoices',
                text: 'Set up automatic billing for regular customers',
                button: 'Create Recurring Invoice',
                link: '/qi/recurring.php'
            },
            'credit_notes': {
                icon: '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
                title: 'No Credit Notes',
                text: 'Issue refunds or corrections when needed',
                button: 'Create Credit Note',
                link: '/qi/credit_note_new.php'
            }
        };

        const cfg = config[tab] || config['quotes'];

        return `
            <div class="fw-qi__empty-state">
                <div class="fw-qi__empty-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="64" height="64">
                        ${cfg.icon}
                    </svg>
                </div>
                <h3>${cfg.title}</h3>
                <p>${cfg.text}</p>
                <a href="${cfg.link}" class="fw-qi__btn fw-qi__btn--primary">
                    ${cfg.button}
                </a>
            </div>
        `;
    }

    // Initial load
    loadList();

    // Event listeners
    if (searchInput) {
        searchInput.addEventListener('input', debounce(() => {
            currentPage = 1;
            loadList();
        }, 300));
    }
    if (filterStatus) {
        filterStatus.addEventListener('change', () => {
            currentPage = 1;
            loadList();
        });
    }
    if (filterDateFrom) {
        filterDateFrom.addEventListener('change', () => {
            currentPage = 1;
            loadList();
        });
    }
    if (filterDateTo) {
        filterDateTo.addEventListener('change', () => {
            currentPage = 1;
            loadList();
        });
    }
  }

  // ========== GLOBAL HELPER ==========
  window.QIView = {
    openItem: function(tab, id) {
        const routes = {
            'quotes': '/qi/quote_view.php?id=',
            'invoices': '/qi/invoice_view.php?id=',
            'recurring': '/qi/recurring.php?id=',
            'credit_notes': '/qi/credit_note_view.php?id='
        };
        window.location.href = routes[tab] + id;
    }
  };

  // ========== INIT ==========
  function init() {
    initTheme();
    initKebabMenu();
    
    const urlParams = new URLSearchParams(window.location.search);
    const activeTab = urlParams.get('tab') || 'overview';
    
    if (activeTab === 'overview') {
        initOverview();
    } else {
        initList();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();