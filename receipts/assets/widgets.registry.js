// Registry + renderers (cards, tables, chart). Exposes window.ReceiptsWidgets
(function(){
  'use strict';

  function $el(tag, cls, txt){ const n=document.createElement(tag); if(cls) n.className=cls; if(txt!==undefined) n.textContent=txt; return n; }

  function renderCard(root, value) {
    root.innerHTML = '';
    const wrap = $el('div','fw-wcard');
    wrap.append($el('div','fw-wcard-value', String(value ?? 0)));
    root.append(wrap);
  }

  function renderTable(root, cols, rows) {
    root.innerHTML = '';
    const t = $el('div','fw-wtable');
    const head = $el('div','fw-wtable-head');
    cols.forEach(c=>{
      const th = $el('div','fw-wcell fw-wcell--head'+(c.align?(' fw-wcell--'+c.align):''), c.label);
      if (c.grow) th.style.flexGrow = c.grow;
      head.append(th);
    });
    t.append(head);

    const body = $el('div','fw-wtable-body');
    if (!rows || rows.length===0) {
      body.append($el('div','fw-wempty','No data'));
    } else {
      rows.forEach(r=>{
        const row = $el('div','fw-wrow');
        cols.forEach(c=>{
          const raw = r[c.key];
          const val = c.format ? c.format(raw, r) : (raw ?? '');
          const td = $el('div','fw-wcell'+(c.align?(' fw-wcell--'+c.align):''), String(val));
          if (c.grow) td.style.flexGrow = c.grow;
          row.append(td);
        });
        body.append(row);
      });
    }
    t.append(body);
    root.append(t);
  }

  // Responsive bar chart; shows placeholder if empty
  function renderBars(root, points) {
    root.innerHTML = '';
    if (!Array.isArray(points) || points.length === 0) {
      root.append($el('div','fw-wempty','No data'));
      return;
    }

    // Dimensions
    const pad = 16;
    const targetH = 160;
    const width = Math.max(300, root.clientWidth || 480);
    const height = targetH;

    const maxVal = Math.max(1, ...points.map(p => Number(p.total ?? p.t ?? 0)));
    const barW = Math.max(6, Math.floor((width - pad*2) / points.length));

    const svgNS = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(svgNS,'svg');
    svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
    svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
    svg.classList.add('fw-chart');

    // Draw bars
    points.forEach((p, i) => {
      const v = Number(p.total ?? p.t ?? 0);
      const bh = Math.round((v / maxVal) * (height - pad*2));
      const rect = document.createElementNS(svgNS,'rect');
      rect.setAttribute('x', pad + i*barW);
      rect.setAttribute('y', height - pad - bh);
      rect.setAttribute('width', barW - 4);
      rect.setAttribute('height', bh);
      rect.setAttribute('rx', '3');
      rect.classList.add('fw-bar');
      svg.appendChild(rect);
    });

    root.append(svg);
  }

  window.ReceiptsWidgets = {
    unreviewed_uploads: {
      title: 'Unreviewed uploads', icon: '📥', defaultSize: 'sm',
      render: (root, data)=>renderCard(root, data?.unreviewed_uploads)
    },
    bank_matches_pending: {
      title: 'Bank matches pending', icon: '🏦', defaultSize: 'sm',
      render: (root, data)=>renderCard(root, data?.bank_matches_pending)
    },
    top_suppliers_90d: {
      title: 'Top suppliers (90d)', icon: '🏷️', defaultSize: 'md',
      columns: [
        { key:'name',  label:'Supplier', grow:2 },
        { key:'total', label:'Total', align:'right', format:v=>'R'+Number(v||0).toFixed(2) }
      ],
      render: (root, data)=>renderTable(root, window.ReceiptsWidgets.top_suppliers_90d.columns, data?.top_suppliers_90d || [])
    },
    cost_by_project: {
      title: 'Cost by project', icon: '📁', defaultSize: 'md',
      columns: [
        { key:'name',  label:'Project', grow:2 },
        { key:'total', label:'Total', align:'right', format:v=>'R'+Number(v||0).toFixed(2) }
      ],
      render: (root, data)=>renderTable(root, window.ReceiptsWidgets.cost_by_project.columns, data?.cost_by_project || [])
    },
    recent_receipts: {
      title: 'Recent receipts', icon: '🧾', defaultSize: 'lg',
      columns: [
        { key:'vendor_name',   label:'Vendor',  grow:2 },
        { key:'invoice_number',label:'Invoice #' },
        { key:'uploaded_at',   label:'Uploaded' },
        { key:'ocr_status',    label:'Status' }
      ],
      render: (root, data)=>renderTable(root, window.ReceiptsWidgets.recent_receipts.columns, data?.recent_receipts || [])
    },
    this_month_spend: {
      title: 'This month spend', icon:'📊', defaultSize:'lg',
      render: (root, data)=>renderBars(root, data?.this_month_spend || [])
    }
  };
})();
