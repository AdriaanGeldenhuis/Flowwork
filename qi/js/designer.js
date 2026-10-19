// /qi/js/designer.js — the document designer.
// Holds a working copy of the company's design, renders the controls, and asks
// the server (/qi/api/design.php preview) to render the paper with the same
// code that produces the PDF, so the preview is exact. Clicking a part of the
// document opens its controls.
(function () {
  'use strict';
  const { $, $$, esc, icon, api, toast, toastError, dialog, debounce } = QI;

  const S = {
    design: null, saved: '', defaults: null, presets: [], fonts: [], labels: {}, counts: {}, canEdit: false, isAdmin: false,
    company: {}, type: 'quote', source: 'sample', open: 'templates', zoom: 0, previewSeq: 0, canUndo: false,
  };
  const el = { sections: $('#dsSections'), paper: $('#paper'), wrap: $('#paperWrap'), scroll: $('#stageScroll'), dirty: $('#dsDirty'), save: $('#dsSave'), undo: $('#dsUndo') };

  // ------------------------------------------------------------ helpers
  const get = (path) => path.split('.').reduce((o, k) => (o == null ? o : o[k]), S.design);
  function set(path, value, opts = {}) {
    const keys = path.split('.');
    let o = S.design;
    keys.slice(0, -1).forEach((k) => { if (typeof o[k] !== 'object' || o[k] === null) o[k] = {}; o = o[k]; });
    o[keys[keys.length - 1]] = value;
    changed(opts);
  }
  function changed(opts = {}) {
    updateDirty();
    if (opts.rerender) renderSections();
    preview();
  }
  const isDirty = () => JSON.stringify(S.design) !== S.saved;
  function updateDirty() {
    const d = isDirty();
    el.dirty.classList.toggle('is-dirty', d);
    el.dirty.textContent = d ? 'Unsaved changes' : (S.savedAt ? 'Saved' : 'Using your current branding');
    el.save.disabled = !d || !S.canEdit;
    el.undo.disabled = !S.canUndo || !S.canEdit;
  }
  const clone = (o) => JSON.parse(JSON.stringify(o));

  /** Same overlay as QiTheme::applyPreset (fonts merge, colours replace, layout merges). */
  function applyPreset(key) {
    const p = S.presets.find((x) => x.key === key);
    if (!p) return;
    S.design.preset = key;
    S.design.fonts = Object.assign({}, S.design.fonts, p.fonts);
    S.design.colors = Object.assign({}, p.colors);
    S.design.header = Object.assign({}, S.design.header, { layout: p.layout, ornament: p.design.header.ornament, accent_bar: p.accent_bar });
    S.design.table = Object.assign({}, S.design.table, { style: p.table.style, head: p.table.head, heading_row: p.table.heading_row, radius: p.table.radius }, p.table.density ? { density: p.table.density } : {});
    S.design.totals = Object.assign({}, S.design.totals, { style: p.totals });
    changed({ rerender: true });
  }

  // ------------------------------------------------------------ colour math
  function lum(hex) {
    const h = (hex || '#000000').replace('#', '');
    const c = [0, 2, 4].map((i) => parseInt(h.substr(i, 2), 16) / 255).map((v) => (v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4)));
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  }
  const contrast = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };

  // ------------------------------------------------------------- glyphs
  // Little schematic drawings for the layout choices (52 × 36).
  const G = {
    split: '<rect x="4" y="5" width="12" height="5" rx="1" class="g-acc"/><line x1="4" y1="14" x2="20" y2="14" class="g-line"/><line x1="4" y1="18" x2="16" y2="18" class="g-line"/><line x1="33" y1="7" x2="48" y2="7" class="g-line" style="stroke-width:3"/><line x1="38" y1="13" x2="48" y2="13" class="g-line"/><line x1="4" y1="25" x2="48" y2="25" class="g-rule"/><line x1="4" y1="30" x2="48" y2="30" class="g-rule"/>',
    stacked: '<rect x="4" y="4" width="12" height="5" rx="1" class="g-acc"/><line x1="4" y1="14" x2="30" y2="14" class="g-line" style="stroke-width:3.4"/><line x1="4" y1="20" x2="18" y2="20" class="g-line"/><line x1="36" y1="20" x2="48" y2="20" class="g-line"/><line x1="4" y1="27" x2="48" y2="27" class="g-rule"/><line x1="4" y1="31" x2="48" y2="31" class="g-rule"/>',
    centered: '<rect x="20" y="4" width="12" height="5" rx="1" class="g-acc"/><line x1="16" y1="13" x2="36" y2="13" class="g-line"/><line x1="12" y1="19" x2="40" y2="19" class="g-line" style="stroke-width:3"/><line x1="4" y1="26" x2="48" y2="26" class="g-rule"/><line x1="4" y1="31" x2="48" y2="31" class="g-rule"/>',
    banner: '<rect x="0" y="0" width="52" height="15" class="g-acc"/><rect x="4" y="4" width="10" height="6" rx="1" fill="#fff"/><line x1="34" y1="7" x2="48" y2="7" stroke="#fff" stroke-width="3" stroke-linecap="round"/><line x1="4" y1="21" x2="18" y2="21" class="g-line"/><line x1="4" y1="27" x2="48" y2="27" class="g-rule"/><line x1="4" y1="31" x2="48" y2="31" class="g-rule"/>',
    band: '<rect x="0" y="0" width="52" height="15" class="g-soft"/><rect x="4" y="5" width="10" height="5" rx="1" class="g-acc"/><line x1="34" y1="7" x2="48" y2="7" class="g-line" style="stroke-width:3"/><line x1="4" y1="21" x2="18" y2="21" class="g-line"/><line x1="4" y1="27" x2="48" y2="27" class="g-rule"/><line x1="4" y1="31" x2="48" y2="31" class="g-rule"/>',
    lines: '<line x1="4" y1="8" x2="48" y2="8" class="g-line"/><line x1="4" y1="15" x2="48" y2="15" class="g-rule"/><line x1="4" y1="22" x2="48" y2="22" class="g-rule"/><line x1="4" y1="29" x2="48" y2="29" class="g-rule"/>',
    striped: '<line x1="4" y1="8" x2="48" y2="8" class="g-line"/><rect x="4" y="13" width="44" height="6" class="g-soft"/><rect x="4" y="25" width="44" height="6" class="g-soft"/>',
    boxed: '<rect x="4" y="4" width="44" height="28" rx="2" fill="none" class="g-rule"/><line x1="4" y1="13" x2="48" y2="13" class="g-rule"/><line x1="4" y1="22" x2="48" y2="22" class="g-rule"/><line x1="30" y1="4" x2="30" y2="32" class="g-rule"/>',
    minimal: '<line x1="4" y1="8" x2="26" y2="8" class="g-line"/><line x1="4" y1="17" x2="34" y2="17" class="g-line" style="opacity:.5"/><line x1="4" y1="26" x2="30" y2="26" class="g-line" style="opacity:.5"/>',
    filled: '<rect x="4" y="6" width="44" height="8" rx="2" class="g-acc"/><line x1="4" y1="21" x2="48" y2="21" class="g-rule"/><line x1="4" y1="28" x2="48" y2="28" class="g-rule"/>',
    soft: '<rect x="4" y="6" width="44" height="8" rx="4" class="g-soft"/><line x1="8" y1="10" x2="20" y2="10" class="g-line" style="stroke:var(--accent)"/><line x1="4" y1="21" x2="48" y2="21" class="g-rule"/><line x1="4" y1="28" x2="48" y2="28" class="g-rule"/>',
    underline: '<line x1="6" y1="9" x2="22" y2="9" class="g-line"/><line x1="4" y1="14" x2="48" y2="14" class="g-gold"/><line x1="4" y1="21" x2="48" y2="21" class="g-rule"/><line x1="4" y1="28" x2="48" y2="28" class="g-rule"/>',
    plain: '<line x1="6" y1="9" x2="18" y2="9" class="g-line" style="opacity:.55"/><line x1="4" y1="14" x2="48" y2="14" class="g-rule"/><line x1="4" y1="21" x2="48" y2="21" class="g-rule"/><line x1="4" y1="28" x2="48" y2="28" class="g-rule"/>',
    stack: '<line x1="26" y1="8" x2="48" y2="8" class="g-rule"/><line x1="26" y1="14" x2="48" y2="14" class="g-rule"/><line x1="26" y1="20" x2="48" y2="20" class="g-line"/><line x1="34" y1="28" x2="48" y2="28" class="g-line" style="stroke:var(--accent);stroke-width:3.4"/>',
    box: '<rect x="22" y="4" width="28" height="28" rx="4" class="g-soft"/><line x1="26" y1="11" x2="46" y2="11" class="g-rule"/><line x1="26" y1="17" x2="46" y2="17" class="g-rule"/><line x1="32" y1="26" x2="46" y2="26" class="g-line" style="stroke:var(--accent);stroke-width:3.2"/>',
    band2: '<line x1="26" y1="8" x2="48" y2="8" class="g-rule"/><line x1="26" y1="14" x2="48" y2="14" class="g-rule"/><rect x="22" y="20" width="28" height="11" rx="3" class="g-acc"/><line x1="34" y1="25.5" x2="46" y2="25.5" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/>',
    none: '<rect x="6" y="4" width="40" height="28" rx="2" fill="none" class="g-rule"/>',
    top: '<rect x="6" y="4" width="40" height="28" rx="2" fill="none" class="g-rule"/><rect x="6" y="4" width="40" height="3" class="g-acc"/>',
    left: '<rect x="6" y="4" width="40" height="28" rx="2" fill="none" class="g-rule"/><rect x="6" y="4" width="3" height="28" class="g-acc"/>',
    bottom: '<rect x="6" y="4" width="40" height="28" rx="2" fill="none" class="g-rule"/><rect x="6" y="29" width="40" height="3" class="g-acc"/>',
    'h-band': '<rect x="4" y="10" width="44" height="8" rx="3" class="g-soft"/><line x1="8" y1="14" x2="22" y2="14" class="g-line" style="stroke:var(--accent)"/><line x1="4" y1="25" x2="48" y2="25" class="g-rule"/>',
    'h-underline': '<line x1="6" y1="13" x2="22" y2="13" class="g-line" style="stroke:var(--accent)"/><line x1="6" y1="17" x2="22" y2="17" class="g-gold"/><line x1="4" y1="25" x2="48" y2="25" class="g-rule"/>',
    'h-plain': '<line x1="6" y1="14" x2="22" y2="14" class="g-line"/><line x1="4" y1="25" x2="48" y2="25" class="g-rule"/>',
  };
  const glyph = (k) => `<svg class="fwq-glyph" viewBox="0 0 52 36" aria-hidden="true">${G[k] || ''}</svg>`;

  // ----------------------------------------------------------- controls
  const opts = (path, items, withGlyph = true) => `<div class="fwq-opts" role="radiogroup">${items.map(([v, label, g]) =>
    `<button type="button" class="fwq-opt${get(path) === v ? ' is-on' : ''}" role="radio" aria-checked="${get(path) === v}" data-set="${path}" data-val="${esc(v)}">${withGlyph ? glyph(g || v) : ''}<span>${esc(label)}</span></button>`).join('')}</div>`;
  const seg = (path, items) => `<div class="seg" role="radiogroup">${items.map(([v, label]) =>
    `<button type="button" class="${get(path) === v ? 'is-on' : ''}" role="radio" aria-checked="${get(path) === v}" data-set="${path}" data-val="${esc(v)}">${esc(label)}</button>`).join('')}</div>`;
  const sw = (path, label, hint = '') => `<label class="switch"><input type="checkbox" data-bool="${path}" ${get(path) ? 'checked' : ''}><span class="switch__track"></span><span class="switch__text"><span class="switch__title">${esc(label)}</span>${hint ? `<span class="hint">${esc(hint)}</span>` : ''}</span></label>`;
  const range = (path, min, max, step, unit) => `<div class="fwq-range"><input type="range" min="${min}" max="${max}" step="${step}" value="${esc(get(path))}" data-range="${path}" aria-label="${esc(path)}"><output>${esc(get(path))}${unit}</output></div>`;
  const ctl = (label, body, extra = '') => `<div class="fwq-ctl"><div class="label">${esc(label)}${extra}</div>${body}</div>`;

  const COLORS = [
    ['accent', 'Accent'], ['accent2', 'Highlight'], ['heading', 'Headings'], ['text', 'Body text'],
    ['muted', 'Captions'], ['paper', 'Paper'], ['rule', 'Lines'], ['stripe', 'Row stripe'], ['head_bg', 'Table head'], ['head_text', 'Table head text'],
  ];
  const SWATCHES = ['#7a1f30', '#1e3a5f', '#0b3954', '#2f7457', '#0f766e', '#6d28d9', '#e11d48', '#c2410c', '#9a7136', '#1f2937', '#111111'];
  const LABEL_GROUPS = [
    ['Titles', ['quote', 'invoice', 'tax_invoice', 'credit_note']],
    ['Header', ['number', 'date', 'valid_until', 'due_date', 'reference', 'bill_to']],
    ['Line table', ['description', 'qty', 'unit', 'price', 'discount', 'vat', 'amount', 'section_total']],
    ['Totals', ['subtotal', 'total', 'paid', 'balance_due']],
    ['Sections', ['notes', 'terms', 'payment', 'schedule', 'payments', 'accept', 'view_online', 'pay_online']],
  ];

  const SECTIONS = [
    { key: 'templates', icon: 'iq-layout', title: 'Templates', zone: [], body: () => `
      <div class="fwq-presets">${S.presets.map((p) => presetCard(p)).join('')}</div>
      <p class="hint" style="margin-top:12px">A template sets the fonts, colours and layout. Your wording, logo and what you show or hide stay as they are.</p>` },
    { key: 'brand', icon: 'image', title: 'Logo & name', zone: ['logo'], body: () => `
      <div class="fwq-logo" id="logoBox">
        <div class="fwq-logo__img">${S.company.logo_url ? `<img src="${esc(S.company.logo_url)}" alt="Logo">` : '<span>No logo</span>'}</div>
        <div class="fwq-logo__actions">
          <button class="btn btn--sm" type="button" data-act="logo-upload" ${S.canEdit ? '' : 'disabled'}>${icon('upload')}${S.company.logo_url ? 'Replace logo' : 'Upload logo'}</button>
          ${S.company.logo_url ? `<button class="btn btn--sm btn--ghost btn--danger" type="button" data-act="logo-remove" ${S.canEdit ? '' : 'disabled'}>Remove</button>` : ''}
          <span class="hint">PNG, JPG or WebP · drop a file here</span>
        </div>
      </div>
      ${ctl('Logo size', range('header.logo_size', 60, 320, 4, ' px'))}
      ${ctl('Logo position', opts('header.logo_position', [['left', 'Left'], ['center', 'Centre'], ['right', 'Right']], false))}
      <div class="fwq-ctl">${sw('header.show_name', 'Show the company name', 'Also when a logo is shown')}</div>` },
    { key: 'colors', icon: 'palette', title: 'Colours', zone: [], body: () => `
      ${ctl('Quick accent', `<div style="display:flex;gap:6px;flex-wrap:wrap">${SWATCHES.map((c) => `<button type="button" data-act="swatch" data-color="${c}" aria-label="Accent ${c}" style="width:26px;height:26px;border-radius:50%;border:0;cursor:pointer;background:${c};box-shadow:${get('colors.accent') === c ? '0 0 0 2px var(--surface),0 0 0 4px var(--gold)' : 'inset 0 0 0 1px rgba(0,0,0,.15)'}"></button>`).join('')}</div>`)}
      <div class="fwq-ctl"><div class="fwq-colors">${COLORS.map(([k, label]) => `
        <label class="fwq-color" data-color-row="${k}"><input type="color" value="${esc(get('colors.' + k))}" data-color="${k}" aria-label="${esc(label)}">
          <span class="fwq-color__text"><span>${esc(label)}</span><input type="text" value="${esc(get('colors.' + k))}" data-hex="${k}" maxlength="7" spellcheck="false" aria-label="${esc(label)} hex"></span></label>`).join('')}</div>
        <div id="contrastWarn"></div></div>` },
    { key: 'type', icon: 'iq-type', title: 'Typography', zone: ['title'], body: () => `
      ${ctl('Headings', fontPick('fonts.heading'))}
      ${ctl('Body text', fontPick('fonts.body'))}
      ${ctl('Text size', range('fonts.scale', 85, 120, 1, '%'))}
      ${ctl('Title size', seg('header.title_size', [['sm', 'S'], ['md', 'M'], ['lg', 'L'], ['xl', 'XL']]))}` },
    { key: 'layout', icon: 'iq-overview', title: 'Layout', zone: ['company', 'customer', 'meta'], body: () => `
      ${ctl('Letterhead', opts('header.layout', [['split', 'Split'], ['stacked', 'Stacked'], ['centered', 'Centred'], ['banner', 'Banner'], ['band', 'Soft band']]))}
      ${ctl('Accent bar', opts('header.accent_bar', [['none', 'None'], ['top', 'Top'], ['left', 'Side'], ['bottom', 'Bottom']]))}
      <div class="fwq-ctl">${sw('header.ornament', 'Ornament under the title', 'A fine rule with a lozenge')}</div>
      ${ctl('Paper', seg('page.size', [['A4', 'A4'], ['Letter', 'US Letter']]))}
      ${ctl('Margins', seg('page.margins', [['compact', 'Compact'], ['normal', 'Normal'], ['roomy', 'Roomy']]))}` },
    { key: 'table', icon: 'table', title: 'Line items', zone: ['table'], body: () => `
      ${ctl('Rows', opts('table.style', [['lines', 'Lines'], ['striped', 'Striped'], ['boxed', 'Boxed'], ['minimal', 'Minimal']]))}
      ${ctl('Table head', opts('table.head', [['filled', 'Filled'], ['soft', 'Soft'], ['underline', 'Underline'], ['plain', 'Plain']]))}
      ${ctl('Section headings', opts('table.heading_row', [['band', 'Band', 'h-band'], ['underline', 'Underline', 'h-underline'], ['plain', 'Plain', 'h-plain']]))}
      ${ctl('Row spacing', seg('table.density', [['compact', 'Compact'], ['normal', 'Normal'], ['airy', 'Airy']]))}
      ${ctl('Corner radius', range('table.radius', 0, 24, 1, ' pt'))}
      <div class="fwq-ctl"><div class="label">Columns</div><div class="fwq-toggles">
        ${sw('table.cols.num', 'Line numbers')}${sw('table.cols.qty', 'Quantity')}${sw('table.cols.unit', 'Unit column')}${sw('table.cols.price', 'Unit price')}
      </div></div>
      ${ctl('Discount column', seg('table.cols.discount', [['auto', 'When used'], ['on', 'Always'], ['off', 'Never']]))}
      ${ctl('VAT column', seg('table.cols.vat', [['auto', 'Mixed rates'], ['on', 'Always'], ['off', 'Never']]))}
      <div class="fwq-ctl">${sw('table.section_totals', 'Totals under each section')}</div>` },
    { key: 'totals', icon: 'coins', title: 'Totals', zone: ['totals'], body: () => `
      ${ctl('Style', opts('totals.style', [['stack', 'Stacked'], ['box', 'Boxed', 'box'], ['band', 'Banded', 'band2']]))}
      <div class="fwq-ctl">${sw('totals.show_zero_discount', 'Show the discount line when it is zero')}</div>` },
    { key: 'show', icon: 'eye', title: 'Show & hide', zone: ['notes', 'terms', 'payment', 'link', 'signature'], body: () => `
      <div class="fwq-ctl"><div class="label">Your company</div><div class="fwq-toggles">
        ${sw('company.address', 'Address')}${sw('company.phone', 'Phone')}${sw('company.email', 'Email')}${sw('company.website', 'Website')}${sw('company.vat', 'VAT number')}${sw('company.tax', 'Tax number')}${sw('company.reg', 'Registration no.')}
      </div></div>
      <div class="fwq-ctl"><div class="label">The customer</div><div class="fwq-toggles">
        ${sw('customer.contact', 'Contact person')}${sw('customer.address', 'Address')}${sw('customer.phone', 'Phone')}${sw('customer.email', 'Email')}${sw('customer.vat', 'VAT number')}${sw('customer.reg', 'Registration no.')}
      </div>${S.company.vat_registered ? '<p class="hint" style="margin-top:6px">Tax invoices and credit notes always show both VAT numbers and addresses (SARS s20).</p>' : ''}</div>
      <div class="fwq-ctl"><div class="label">Sections</div><div class="fwq-toggles">
        ${sw('sections.notes', 'Notes')}${sw('sections.terms', 'Terms')}${sw('sections.payment', 'Banking details')}${sw('sections.milestones', 'Payment schedule')}
        ${sw('sections.payments', 'Payments received')}${sw('sections.online_link', 'Accept / pay online')}${sw('sections.signature', 'Signature lines (quotes)')}${sw('stamp.status', 'Status stamp')}${sw('footer.page_numbers', 'Page numbers')}
      </div></div>` },
    { key: 'wording', icon: 'text', title: 'Wording', zone: [], body: () => LABEL_GROUPS.map(([g, keys]) => `
      <div class="fwq-ctl"><div class="label">${esc(g)}</div><div class="fwq-labels">${keys.map((k) => `
        <div class="field"><label class="label" for="lbl_${k}">${esc(S.labels[k])}</label><input class="input" id="lbl_${k}" maxlength="40" value="${esc(get('labels.' + k))}" data-label="${k}" placeholder="${esc(S.labels[k])}"></div>`).join('')}</div></div>`).join('') },
    { key: 'footer', icon: 'iq-stamp', title: 'Footers', zone: ['footer'], body: () => `
      ${['quote', 'invoice', 'credit_note'].map((t) => `<div class="fwq-ctl"><div class="label">${esc(QI.TYPE_NAMES[t])} footer</div><textarea class="textarea" rows="3" maxlength="1200" data-text="footer.${t}" placeholder="E.g. Thank you for your business · Company registration and director details">${esc(get('footer.' + t))}</textarea></div>`).join('')}` },
    { key: 'advanced', icon: 'settings', title: 'Advanced', zone: [], body: () => `
      <div class="fwq-ctl"><div class="label">Custom CSS ${S.isAdmin ? '' : '<small>Admins only</small>'}</div>
        <textarea class="textarea" rows="7" spellcheck="false" style="font-family:ui-monospace,Menlo,monospace;font-size:12px" data-text="custom_css" ${S.isAdmin ? '' : 'disabled'} placeholder=".qd-title { letter-spacing: 0.04em; }">${esc(get('custom_css'))}</textarea>
        <p class="hint" style="margin-top:6px">Applies on screen, on the printed page and on the public link (not inside the PDF). Classes start with <code>.qd</code>.</p></div>
      <div class="fwq-ctl"><button class="btn btn--sm btn--ghost" type="button" data-act="reset">${icon('refresh')}Reset everything to the ${esc((S.presets.find((p) => p.key === get('preset')) || {}).name || 'template')} defaults</button></div>` },
  ];

  function presetCard(p) {
    const c = p.colors;
    const art = [];
    const L = p.layout;
    if (L === 'banner') art.push(`<i style="left:0;right:0;top:0;height:24px;background:${c.accent}"></i><i style="left:7px;top:8px;width:16px;height:8px;background:#fff;border-radius:2px"></i><i style="right:8px;top:9px;width:26px;height:5px;background:#fff"></i>`);
    else if (L === 'band') art.push(`<i style="left:0;right:0;top:0;height:24px;background:${mixHex(c.accent, c.paper, 0.9)}"></i><i style="left:7px;top:8px;width:16px;height:7px;background:${c.accent}"></i><i style="right:8px;top:9px;width:26px;height:5px;background:${c.heading}"></i>`);
    else if (L === 'centered') art.push(`<i style="left:50%;margin-left:-9px;top:7px;width:18px;height:7px;background:${c.accent}"></i><i style="left:50%;margin-left:-16px;top:18px;width:32px;height:4px;background:${c.heading}"></i>`);
    else if (L === 'stacked') art.push(`<i style="left:7px;top:7px;width:16px;height:7px;background:${c.accent}"></i><i style="left:7px;top:18px;width:38px;height:5px;background:${c.heading}"></i>`);
    else art.push(`<i style="left:7px;top:8px;width:18px;height:7px;background:${c.accent}"></i><i style="right:8px;top:8px;width:28px;height:6px;background:${c.heading}"></i>`);
    if (p.accent_bar === 'top') art.push(`<i style="left:0;right:0;top:0;height:3px;background:${c.accent}"></i>`);
    if (p.accent_bar === 'left') art.push(`<i style="left:0;top:0;bottom:0;width:4px;background:${c.accent}"></i>`);
    const headY = 36;
    if (p.table.head === 'filled') art.push(`<i style="left:7px;right:7px;top:${headY}px;height:7px;background:${c.head_bg};border-radius:${Math.min(4, p.table.radius / 3)}px"></i>`);
    else if (p.table.head === 'soft') art.push(`<i style="left:7px;right:7px;top:${headY}px;height:7px;background:${mixHex(c.accent, c.paper, 0.88)};border-radius:4px"></i>`);
    else art.push(`<i style="left:7px;right:7px;top:${headY + 6}px;height:1.5px;background:${p.table.head === 'underline' ? c.accent2 : c.rule}"></i>`);
    for (let r = 0; r < 3; r++) {
      const y = headY + 12 + r * 9;
      if (p.table.style === 'striped' && r % 2 === 1) art.push(`<i style="left:7px;right:7px;top:${y - 3}px;height:8px;background:${c.stripe}"></i>`);
      art.push(`<i style="left:9px;top:${y}px;width:${34 - r * 6}px;height:2.5px;background:${c.muted};opacity:.55"></i><i style="right:9px;top:${y}px;width:12px;height:2.5px;background:${c.text};opacity:.6"></i>`);
      if (p.table.style !== 'minimal' && p.table.style !== 'striped') art.push(`<i style="left:7px;right:7px;top:${y + 5}px;height:1px;background:${c.rule}"></i>`);
    }
    art.push(p.totals === 'band' ? `<i style="right:7px;bottom:6px;width:36px;height:8px;background:${c.accent};border-radius:3px"></i>` : `<i style="right:9px;bottom:8px;width:24px;height:4px;background:${c.accent}"></i>`);
    return `<button type="button" class="fwq-preset${get('preset') === p.key ? ' is-on' : ''}" data-preset="${esc(p.key)}" style="--p:${c.paper}">
      <span class="fwq-preset__art" style="font-family:${esc(fontStack(p.fonts.heading))}">${art.join('')}</span>
      <span class="fwq-preset__name" style="font-family:${esc(fontStack(p.fonts.heading))};font-size:14px">${esc(p.name)}</span>
      <span class="fwq-preset__blurb">${esc(p.blurb)}</span></button>`;
  }
  function mixHex(a, b, t) {
    const pa = a.replace('#', ''), pb = b.replace('#', '');
    let out = '#';
    for (let i = 0; i < 3; i++) {
      const va = parseInt(pa.substr(i * 2, 2), 16), vb = parseInt(pb.substr(i * 2, 2), 16);
      out += Math.round(va + (vb - va) * t).toString(16).padStart(2, '0');
    }
    return out;
  }
  const fontStack = (key) => (S.fonts.find((f) => f.key === key) || { stack: 'inherit' }).stack;
  const fontPick = (path) => `<div class="fwq-fontpick" role="radiogroup">${['sans', 'serif'].map((kind) => S.fonts.filter((f) => f.kind === kind).map((f) => `
    <button type="button" class="${get(path) === f.key ? 'is-on' : ''}" role="radio" aria-checked="${get(path) === f.key}" data-set="${path}" data-val="${esc(f.key)}">
      <span style="font-family:${esc(f.stack)}">${esc(f.name)}</span><small>${esc(f.kind)}</small></button>`).join('')).join('')}</div>`;

  function renderSections() {
    const scrollTop = el.sections.scrollTop;
    el.sections.innerHTML = SECTIONS.map((s) => `
      <section class="fwq-sec${S.open === s.key ? ' is-open' : ''}" data-sec="${s.key}">
        <button class="fwq-sec__head" type="button" aria-expanded="${S.open === s.key}">${icon(s.icon)}<span>${esc(s.title)}</span>${icon('chev-down', 'chev')}</button>
        <div class="fwq-sec__body">${S.open === s.key ? s.body() : ''}</div>
      </section>`).join('');
    el.sections.scrollTop = scrollTop;
    checkContrast();
    if (!S.canEdit) $$('input, textarea, select, .fwq-opt, .fwq-preset, .seg button, .fwq-fontpick button', el.sections).forEach((x) => { x.disabled = true; });
    wireLogoDrop();
  }

  function openSection(key, flash) {
    S.open = key;
    renderSections();
    const sec = $(`.fwq-sec[data-sec="${key}"]`, el.sections);
    if (sec) {
      sec.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      if (flash) { sec.classList.remove('is-flash'); void sec.offsetWidth; sec.classList.add('is-flash'); }
    }
  }

  function checkContrast() {
    const box = $('#contrastWarn');
    const c = S.design.colors;
    const warns = [];
    if (contrast(c.text, c.paper) < 4.5) warns.push('Body text is hard to read on the paper colour.');
    if (contrast(c.heading, c.paper) < 4.5) warns.push('Headings are hard to read on the paper colour.');
    if (contrast(c.head_text, c.head_bg) < 3) warns.push('Table head text is low contrast — the document will use black or white instead.');
    $$('.fwq-color', el.sections).forEach((row) => row.classList.remove('is-warn'));
    if (contrast(c.text, c.paper) < 4.5) markColor('text');
    if (contrast(c.heading, c.paper) < 4.5) markColor('heading');
    if (contrast(c.head_text, c.head_bg) < 3) markColor('head_text');
    if (box) box.innerHTML = warns.map((w) => `<div class="fwq-warn">${icon('alert', 'i--sm')}<span>${esc(w)}</span></div>`).join('');
  }
  const markColor = (k) => { const r = $(`[data-color-row="${k}"]`, el.sections); if (r) r.classList.add('is-warn'); };

  // ------------------------------------------------------------- events
  el.sections.addEventListener('click', (e) => {
    const head = e.target.closest('.fwq-sec__head');
    if (head) { const key = head.closest('.fwq-sec').dataset.sec; S.open = S.open === key ? '' : key; renderSections(); return; }
    const preset = e.target.closest('[data-preset]');
    if (preset && S.canEdit) { applyPreset(preset.dataset.preset); return; }
    const opt = e.target.closest('[data-set]');
    if (opt && S.canEdit) {
      set(opt.dataset.set, opt.dataset.val);
      const group = opt.parentElement;
      $$('[data-set]', group).forEach((b) => { const on = b === opt; b.classList.toggle('is-on', on); b.setAttribute('aria-checked', String(on)); });
      return;
    }
    const act = e.target.closest('[data-act]');
    if (!act || !S.canEdit) return;
    if (act.dataset.act === 'swatch') {
      const c = act.dataset.color;
      S.design.colors.accent = c;
      S.design.colors.head_bg = c;
      S.design.colors.head_text = contrast('#ffffff', c) >= contrast('#111111', c) ? '#ffffff' : '#111111';
      changed({ rerender: true });
    }
    if (act.dataset.act === 'logo-upload') $('#logoFile').click();
    if (act.dataset.act === 'logo-remove') removeLogo();
    if (act.dataset.act === 'reset') resetAll();
  });
  el.sections.addEventListener('change', (e) => {
    const t = e.target;
    if (t.dataset.bool) set(t.dataset.bool, t.checked);
  });
  el.sections.addEventListener('input', (e) => {
    const t = e.target;
    if (t.dataset.range) {
      t.nextElementSibling.textContent = t.value + (t.dataset.range === 'fonts.scale' ? '%' : t.dataset.range === 'header.logo_size' ? ' px' : ' pt');
      set(t.dataset.range, Number(t.value));
    } else if (t.dataset.color) {
      S.design.colors[t.dataset.color] = t.value;
      const hex = $(`[data-hex="${t.dataset.color}"]`, el.sections);
      if (hex) hex.value = t.value;
      checkContrast();
      changed();
    } else if (t.dataset.hex) {
      const v = t.value.trim().toLowerCase();
      if (/^#[0-9a-f]{6}$/.test(v)) {
        S.design.colors[t.dataset.hex] = v;
        const picker = $(`[data-color="${t.dataset.hex}"]`, el.sections);
        if (picker) picker.value = v;
        checkContrast();
        changed();
      }
    } else if (t.dataset.label) {
      S.design.labels[t.dataset.label] = t.value;
      changedText();
    } else if (t.dataset.text) {
      set(t.dataset.text, t.value);
    }
  });
  const changedText = debounce(() => changed(), 250);

  // Click on the paper: open the matching controls
  el.paper.addEventListener('click', (e) => {
    const z = e.target.closest('[data-zone]');
    if (!z) return;
    e.preventDefault();
    const zone = z.dataset.zone;
    const sec = SECTIONS.find((s) => s.zone.includes(zone));
    if (sec) openSection(sec.key, true);
    z.classList.remove('is-flash'); void z.offsetWidth; z.classList.add('is-flash');
    if (QI.narrow()) $('#designer').classList.remove('is-preview');
  });

  // -------------------------------------------------------------- preview
  let previewTimer = null;
  function preview() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(runPreview, 110);
  }
  async function runPreview() {
    const seq = ++S.previewSeq;
    el.paper.classList.add('is-busy');
    try {
      const d = await api.post('design.php', { action: 'preview', design: S.design, type: S.type, source: S.source });
      if (seq !== S.previewSeq) return;
      ensureFonts(d.fonts);
      $('#dsCustomCss').textContent = d.css || '';
      el.paper.innerHTML = d.html;
      const qd = $('.qd', el.paper);
      if (qd) qd.classList.add('is-editable');
      fit();
    } catch (e) {
      if (seq === S.previewSeq) toastError(e);
    } finally {
      if (seq === S.previewSeq) el.paper.classList.remove('is-busy');
    }
  }
  const loadedFonts = new Set();
  function ensureFonts(html) {
    if (!html) return;
    const tmp = document.createElement('div');
    tmp.innerHTML = html;
    $$('link[rel=stylesheet]', tmp).forEach((l) => {
      if (loadedFonts.has(l.href)) return;
      loadedFonts.add(l.href);
      const n = document.createElement('link');
      n.rel = 'stylesheet';
      n.href = l.href;
      document.head.appendChild(n);
    });
  }
  function fit() {
    const qd = $('.qd', el.paper);
    if (!qd) return;
    const w = qd.offsetWidth;
    const avail = el.scroll.clientWidth - (QI.narrow() ? 20 : 48);
    const auto = Math.min(1, avail / w);
    const z = S.zoom ? Math.max(0.3, Math.min(1.6, S.zoom)) : auto;
    el.paper.style.transform = `scale(${z})`;
    el.paper.style.width = w + 'px';
    el.wrap.style.height = (qd.offsetHeight * z + (QI.narrow() ? 44 : 88)) + 'px';
    $('#dsZoomFit').textContent = Math.round(z * 100) + '%';
  }
  window.addEventListener('resize', debounce(fit, 120));
  $('#dsZoomIn').addEventListener('click', () => { S.zoom = Math.min(1.6, (S.zoom || currentZoom()) + 0.1); fit(); });
  $('#dsZoomOut').addEventListener('click', () => { S.zoom = Math.max(0.3, (S.zoom || currentZoom()) - 0.1); fit(); });
  $('#dsZoomFit').addEventListener('click', () => { S.zoom = 0; fit(); });
  const currentZoom = () => { const m = /scale\(([\d.]+)\)/.exec(el.paper.style.transform || ''); return m ? Number(m[1]) : 1; };

  $('#dsType').addEventListener('click', (e) => {
    const b = e.target.closest('[data-type]');
    if (!b) return;
    S.type = b.dataset.type;
    $$('#dsType button').forEach((x) => x.classList.toggle('is-on', x === b));
    updateLatest();
    preview();
  });
  $('#dsSource').addEventListener('click', (e) => {
    const b = e.target.closest('[data-source]');
    if (!b || b.disabled) return;
    S.source = b.dataset.source;
    $$('#dsSource button').forEach((x) => x.classList.toggle('is-on', x === b));
    preview();
  });
  function updateLatest() {
    const btn = $('#dsLatest');
    const has = (S.counts[S.type] || 0) > 0;
    btn.disabled = !has;
    btn.title = has ? 'Preview your most recent ' + QI.TYPE_NAMES[S.type].toLowerCase() : 'You have no ' + QI.TYPE_NAMES[S.type].toLowerCase() + 's yet';
    if (!has && S.source === 'latest') { S.source = 'sample'; $$('#dsSource button').forEach((x) => x.classList.toggle('is-on', x.dataset.source === 'sample')); }
  }
  $('#dsPdf').addEventListener('click', () => {
    $('#pdfDesign').value = JSON.stringify(S.design);
    $('#pdfType').value = S.type;
    $('#pdfSource').value = S.source;
    $('#pdfForm').submit();
  });
  $('#dsTogglePreview').addEventListener('click', () => { $('#designer').classList.toggle('is-preview'); setTimeout(fit, 50); });

  // ---------------------------------------------------------- save / undo
  async function save() {
    if (!isDirty() || !S.canEdit) return;
    el.save.disabled = true;
    try {
      const d = await api.post('design.php', { action: 'save', design: S.design });
      S.design = d.design;
      S.saved = JSON.stringify(S.design);
      S.savedAt = d.saved_at;
      S.canUndo = true;
      updateDirty();
      renderSections();
      toast('Design saved — every quote, invoice and PDF now uses it.');
    } catch (e) {
      toastError(e);
      updateDirty();
    }
  }
  el.save.addEventListener('click', save);
  el.undo.addEventListener('click', async () => {
    const ok = await dialog({ title: 'Go back to the previous design?', body: 'The design saved before your last save is restored and saved.', confirm: 'Restore it' });
    if (!ok) return;
    try {
      const d = await api.post('design.php', { action: 'undo' });
      S.design = d.design;
      S.saved = JSON.stringify(S.design);
      S.savedAt = d.saved_at;
      updateDirty();
      renderSections();
      preview();
      toast('Previous design restored.');
    } catch (e) { toastError(e); }
  });
  async function resetAll() {
    const ok = await dialog({ title: 'Reset the design?', body: 'Colours, fonts, layout, wording and what is shown go back to the template defaults. Your logo stays. Nothing is saved until you press Save.', confirm: 'Reset' });
    if (!ok) return;
    const p = S.presets.find((x) => x.key === get('preset'));
    const base = clone(p ? p.design : S.defaults);
    ['labels', 'company', 'customer', 'sections', 'stamp', 'page'].forEach((k) => { base[k] = clone(S.defaults[k]); });
    base.table.cols = clone(S.defaults.table.cols);
    base.fonts.scale = S.defaults.fonts.scale;
    base.header.logo_size = S.defaults.header.logo_size;
    base.header.logo_position = S.defaults.header.logo_position;
    base.footer = clone(S.design.footer);
    base.custom_css = S.design.custom_css;
    S.design = base;
    changed({ rerender: true });
  }
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save(); }
  });
  window.addEventListener('beforeunload', (e) => { if (isDirty()) { e.preventDefault(); e.returnValue = ''; } });

  // ------------------------------------------------------------------ logo
  $('#logoFile').addEventListener('change', (e) => { const f = e.target.files[0]; e.target.value = ''; if (f) uploadLogo(f); });
  async function uploadLogo(file) {
    const fd = new FormData();
    fd.append('action', 'logo');
    fd.append('logo', file);
    try {
      const d = await api.upload('design.php', fd);
      S.company.logo_url = d.logo_url;
      renderSections();
      preview();
      toast('Logo updated.');
    } catch (e) { toastError(e); }
  }
  async function removeLogo() {
    const ok = await dialog({ title: 'Remove the logo?', body: 'Documents will show your company name instead.', confirm: 'Remove', danger: true });
    if (!ok) return;
    try {
      await api.post('design.php', { action: 'logo_remove' });
      S.company.logo_url = '';
      renderSections();
      preview();
    } catch (e) { toastError(e); }
  }
  function wireLogoDrop() {
    const box = $('#logoBox');
    if (!box || !S.canEdit) return;
    ['dragenter', 'dragover'].forEach((t) => box.addEventListener(t, (e) => { e.preventDefault(); box.classList.add('is-drop'); }));
    ['dragleave', 'drop'].forEach((t) => box.addEventListener(t, (e) => { e.preventDefault(); box.classList.remove('is-drop'); }));
    box.addEventListener('drop', (e) => { const f = e.dataTransfer.files && e.dataTransfer.files[0]; if (f) uploadLogo(f); });
  }

  // ------------------------------------------------------------------ boot
  async function boot() {
    QI.initChrome();
    try {
      const d = await api.get('design.php');
      Object.assign(S, { design: d.design, defaults: d.defaults, presets: d.presets, fonts: d.fonts, labels: d.labels, counts: d.counts,
        canEdit: d.can_edit, isAdmin: d.is_admin, company: d.company, savedAt: d.saved_at, canUndo: d.can_undo });
      S.saved = JSON.stringify(S.design);
      const typeParam = new URLSearchParams(location.search).get('type');
      if (['quote', 'invoice', 'credit_note'].includes(typeParam)) { S.type = typeParam; $$('#dsType button').forEach((x) => x.classList.toggle('is-on', x.dataset.type === S.type)); }
      updateLatest();
      if ((S.counts[S.type] || 0) > 0) { S.source = 'latest'; $$('#dsSource button').forEach((x) => x.classList.toggle('is-on', x.dataset.source === 'latest')); }
      renderSections();
      updateDirty();
      if (!S.canEdit) toast('You have read-only access — you can look, but not save.', { timeout: 6000 });
      runPreview();
    } catch (e) {
      el.sections.innerHTML = `<div class="fwq-empty"><h3>Could not load the designer</h3><p>${esc(e.message)}</p></div>`;
    }
  }
  boot();
})();
