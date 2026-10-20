// /receipts/js/review.js — one receipt: the slip and its bill.
//
// The slip: zoom (wheel with Ctrl, + / −, buttons), drag to move, turn (r),
// fit (0). PDFs show in the browser's own viewer.
// The bill: the supplier (search, the one the reader suggests, or a new one
// added to the CRM on the spot), number and dates, the lines as printed on
// the slip (VAT included or not), accounts and the project's tasks, a
// purchase order, "paid on the spot", notes. Totals and the checks update
// as you type; checks offer a fix where one exists, admins can override a
// block with a reason. Ctrl S saves the draft, Ctrl Enter posts, j / k move
// through the receipts waiting.
(function () {
  'use strict';
  const { $, $$, esc, icon, api, toast, toastError, dialog, modal, openModal, closeModal, popover, closePop, busy, money, moneyC, toCents, toNum, fmtDay, ago, hue, initials, debounce, typingIn, boot } = window.FWR;
  const ID = boot.id;
  const caps = boot.caps || {};
  const formEl = $('#form');
  let D = null;          // the review payload
  let F = null;          // the form being edited
  let saved = '';        // the form as last saved (JSON) for "unsaved changes"
  let items = [];        // tasks of the chosen board
  let checkSeq = 0;
  let lastChecks = null;
  /** A line's quantity as the server reads it: empty is 1, not a number is 0 (the server refuses it), three decimals. */
  const qtyOf = (v) => { if (String(v == null ? '' : v).trim() === '') return 1; const n = toNum(v); return n == null ? 0 : Math.round(n * 1000) / 1000; };
  const SOURCE = { word: 'Learned', supplier: 'Usual', history: 'Last time', ai: 'AI pick', default: 'Default' };

  // ================================================================ viewer
  const viewer = (() => {
    const stage = $('#stage');
    let doc = $('#slipImg') || $('#slipDoc');
    let natW = 0, natH = 0;
    let scale = 1, fit = 1, rot = 0, x = 0, y = 0, mode = 'fit';
    const PDFJS = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/';
    function size() { return doc && doc.tagName === 'IMG' ? [doc.naturalWidth, doc.naturalHeight] : [natW, natH]; }
    function measure() {
      const [nw, nh] = size();
      if (!doc || !nw) return;
      const w = stage.clientWidth - 40, h = stage.clientHeight - 90;
      const iw = rot % 180 ? nh : nw;
      const ih = rot % 180 ? nw : nh;
      // A long PDF fits its width; a slip fits the frame.
      fit = Math.max(0.05, Math.min(w / iw, doc.tagName === 'IMG' || ih / iw < 1.6 ? h / ih : 9, 2));
      if (mode === 'fit') { scale = fit; x = 0; y = doc.tagName === 'IMG' || ih * scale <= h ? -25 : (ih * scale - h) / 2 - 10; }
      apply();
    }
    function apply() {
      if (!doc) return;
      doc.style.transform = `translate(calc(-50% + ${x}px), calc(-50% + ${y}px)) rotate(${rot}deg) scale(${scale})`;
      $('#zoomLabel').textContent = mode === 'fit' ? 'Fit' : Math.round(scale * 100) + '%';
    }
    function zoom(dir, at) {
      if (!doc || !size()[0]) return;
      const old = scale;
      scale = Math.max(0.1, Math.min(6, scale * (dir > 0 ? 1.25 : 0.8)));
      mode = 'free';
      if (at) { const r = stage.getBoundingClientRect(); const cx = at.x - r.left - r.width / 2 - x, cy = at.y - r.top - r.height / 2 - y; x -= cx * (scale / old - 1); y -= cy * (scale / old - 1); }
      apply();
    }
    const fitNow = () => { mode = 'fit'; measure(); };
    const turn = () => { rot = (rot + 90) % 360; fitNow(); };
    function loadScript(src) {
      return new Promise((res, rej) => { const s = document.createElement('script'); s.src = src; s.onload = res; s.onerror = rej; document.head.appendChild(s); });
    }
    /** PDFs are drawn page by page (PDF.js), so they zoom and turn like photos — phones' web views cannot show a PDF in a frame. */
    async function renderPdf() {
      try {
        if (!window.pdfjsLib) await loadScript(PDFJS + 'pdf.min.js');
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS + 'pdf.worker.min.js';
        // isEvalSupported:false — PDF.js 3.x can be made to run a crafted font's code (CVE-2024-4367); this closes that path.
        const pdf = await window.pdfjsLib.getDocument({ url: doc.dataset.pdf, withCredentials: true, isEvalSupported: false }).promise;
        const n = Math.min(pdf.numPages, 10);
        const ratio = Math.min(2, window.devicePixelRatio || 1) * 1.5;
        doc.innerHTML = '';
        let w = 0, h = 0;
        for (let i = 1; i <= n; i++) {
          const page = await pdf.getPage(i);
          const vp = page.getViewport({ scale: ratio });
          const c = document.createElement('canvas');
          c.width = Math.floor(vp.width);
          c.height = Math.floor(vp.height);
          c.style.width = (vp.width / ratio) + 'px';
          c.style.height = (vp.height / ratio) + 'px';
          await page.render({ canvasContext: c.getContext('2d'), viewport: vp }).promise;
          doc.appendChild(c);
          w = Math.max(w, vp.width / ratio);
          h += vp.height / ratio + (i > 1 ? 14 : 0);
        }
        if (pdf.numPages > n) { doc.insertAdjacentHTML('beforeend', `<p class="fwr-viewer__more">${pdf.numPages - n} more pages — open the PDF to see them</p>`); h += 40; }
        natW = w; natH = h;
        doc.style.width = w + 'px';
        doc.classList.add('is-ready');
        measure();
      } catch (e) {
        // No PDF.js (offline, blocked): the browser's own viewer in a frame.
        const url = doc.dataset.pdf;
        stage.insertAdjacentHTML('afterbegin', `<iframe src="${esc(url)}#view=FitH" title="The slip (PDF)"></iframe>`);
        doc.remove();
        doc = null;
        $$('[data-zoom], [data-fit], [data-rotate]').forEach((b) => { b.disabled = true; });
      }
    }
    if (doc) {
      if (doc.tagName === 'IMG') {
        if (doc.complete && doc.naturalWidth) measure(); else doc.addEventListener('load', measure);
        doc.addEventListener('error', () => { stage.insertAdjacentHTML('afterbegin', `<div class="fwr-viewer__missing">${icon('i-image')}<b>The slip could not be shown</b><small>It may have been removed from the server.</small></div>`); doc.remove(); doc = null; });
      } else {
        renderPdf();
      }
      window.addEventListener('resize', debounce(measure, 120));
      let drag = null;
      stage.addEventListener('pointerdown', (e) => { if (e.button !== 0 || !doc) return; drag = { px: e.clientX, py: e.clientY, x, y }; stage.setPointerCapture(e.pointerId); stage.classList.add('is-dragging'); });
      stage.addEventListener('pointermove', (e) => { if (!drag) return; x = drag.x + e.clientX - drag.px; y = drag.y + e.clientY - drag.py; mode = 'free'; apply(); });
      const end = () => { drag = null; stage.classList.remove('is-dragging'); };
      stage.addEventListener('pointerup', end);
      stage.addEventListener('pointercancel', end);
      stage.addEventListener('wheel', (e) => {
        if (!doc) return;
        if (e.ctrlKey || e.metaKey) { e.preventDefault(); zoom(e.deltaY < 0 ? 1 : -1, { x: e.clientX, y: e.clientY }); return; }
        // Plain wheel scrolls a long PDF
        if (doc.tagName !== 'IMG') { e.preventDefault(); y -= e.deltaY; mode = 'free'; apply(); }
      }, { passive: false });
      stage.addEventListener('dblclick', (e) => { if (mode === 'fit') zoom(1, { x: e.clientX, y: e.clientY }); else fitNow(); });
    }
    $$('[data-zoom]').forEach((b) => b.addEventListener('click', () => zoom(+b.dataset.zoom)));
    $$('[data-fit]').forEach((b) => b.addEventListener('click', fitNow));
    $$('[data-rotate]').forEach((b) => b.addEventListener('click', turn));
    return { zoom, fit: fitNow, turn, scanning: (on) => { const s = $('#scan'); if (s) s.hidden = !on; } };
  })();

  // ================================================================ data
  async function load(quiet) {
    try {
      D = await api.get('bill.php', { op: 'load', id: ID });
      setForm(D);
      if (D.receipt.state === 'reading' && !quiet && (caps.capture || D.receipt.uploaded_by === boot.user)) readNow();
    } catch (e) {
      formEl.innerHTML = `<div class="fwr-empty"><b>Could not open this receipt</b>${esc(e.message)}<br><button class="btn" type="button" onclick="location.reload()">Try again</button></div>`;
    }
  }
  function setForm(data) {
    D = data;
    F = JSON.parse(JSON.stringify(D.form));
    F.lines = (F.lines || []).map((l) => ({ ...l, price: l.price != null ? Number(l.price).toFixed(2) : '' }));
    if (!F.lines.length) F.lines = [blankLine()];
    if (F.slip_total != null) F.slip_total = Number(F.slip_total).toFixed(2);
    items = D.items || [];
    lastChecks = D.checks;
    saved = JSON.stringify(payload());
    render();
    head();
  }
  function blankLine() {
    const rate = (D && D.lookups && D.lookups.standard_rate) || 15;
    const prev = F && F.lines && F.lines[F.lines.length - 1];
    return { description: '', qty: 1, price: '', rate: prev ? prev.rate : rate, account_id: prev ? prev.account_id : null, account_source: '', item_id: null };
  }
  function payload() {
    return {
      supplier_id: F.supplier_id || null, number: (F.number || '').trim(), issued: F.issued || '', due: F.due || '', vendor_vat: (F.vendor_vat || '').trim(), notes: F.notes || '',
      board_id: F.board_id || null, po_id: F.po_id || null, incl: !!F.incl, slip_total: F.slip_total || '', currency: F.currency || 'ZAR',
      paid: { method: F.paid.method || '', date: F.paid.date || '', bank_account_id: F.paid.bank_account_id || null },
      lines: F.lines.map((l) => ({ description: l.description, qty: l.qty, price: l.price, rate: l.rate, account_id: l.account_id || null, item_id: l.item_id || null })),
    };
  }
  const dirty = () => D && D.editable && JSON.stringify(payload()) !== saved;

  // ================================================================ head bar
  function head() {
    const r = D.receipt;
    const sup = D.supplier;
    $('#rvTitle').textContent = sup ? sup.name : (D.reading.vendor || r.name);
    document.title = $('#rvTitle').textContent + ' · ' + document.title.split(' · ').slice(1).join(' · ');
    const st = $('#rvState');
    st.dataset.tone = window.FWR.STATE_TONE[r.state] || 'muted';
    st.textContent = r.state_label;
    const q = D.queue;
    const inQueue = ['reading', 'review', 'draft'].includes(r.state);
    $('#rvQueue').hidden = !(q && q.count > 1) && !(q && q.next);
    if (q) {
      $('#queuePos').textContent = inQueue && q.pos ? `${q.pos} of ${q.count}` : `${q.count} waiting`;
      $('#prevBtn').disabled = !q.prev;
      $('#nextBtn').disabled = !q.next;
    }
    viewer.scanning(r.state === 'reading');
  }
  function go(id) {
    if (!id) return;
    if (dirty()) {
      dialog({ title: 'Leave without saving?', body: 'Your changes to this bill are not saved.', confirm: 'Save and go on', cancel: 'Stay' }).then(async (ok) => {
        if (!ok) return;
        if (await save()) location.href = '/receipts/review.php?id=' + id;
      });
      return;
    }
    location.href = '/receipts/review.php?id=' + id;
  }
  $('#prevBtn').addEventListener('click', () => go(D && D.queue.prev));
  $('#nextBtn').addEventListener('click', () => go(D && D.queue.next));
  $('#moreBtn').addEventListener('click', (e) => menu(e.currentTarget));

  function menu(anchor) {
    if (!D) return;
    const r = D.receipt;
    const own = r.uploaded_by === boot.user;
    const canRead = !r.bill_id && r.state !== 'aside' && (caps.capture || own);
    const canDelete = !['posted', 'paid'].includes(r.state) && (caps.capture || (own && !r.bill_id));
    const html = `
      <a class="fwm-pop__item" href="${esc(r.url)}" target="_blank" rel="noopener">${icon('external')}Open the slip</a>
      <a class="fwm-pop__item" href="${esc(r.url)}&download=1">${icon('download')}Download</a>
      ${r.bill_url ? `<a class="fwm-pop__item" href="${esc(r.bill_url)}">${icon('if-ledger')}Open the bill in Finance</a>` : ''}
      ${canRead ? `<button class="fwm-pop__item" type="button" data-m="read">${icon('ir-scan')}Read it again</button>` : ''}
      ${caps.upload ? `<button class="fwm-pop__item" type="button" data-m="note">${icon('pen')}${r.note ? 'Change the note' : 'Add a note'}</button>` : ''}
      <div class="fwm-pop__sep"></div>
      ${caps.capture && r.state !== 'aside' && !['posted', 'paid'].includes(r.state) ? `<button class="fwm-pop__item" type="button" data-m="aside">${icon('archive')}Set aside…</button>` : ''}
      ${caps.capture && r.state === 'aside' ? `<button class="fwm-pop__item" type="button" data-m="restore">${icon('undo')}Bring it back</button>` : ''}
      ${caps.capture && r.state === 'draft' ? `<button class="fwm-pop__item is-danger" type="button" data-m="undraft">${icon('trash')}Delete the draft bill</button>` : ''}
      ${canDelete ? `<button class="fwm-pop__item is-danger" type="button" data-m="delete">${icon('trash')}Delete the receipt</button>` : ''}`;
    const el = popover(anchor, html, { align: 'end' });
    el.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-m]');
      if (!b) return;
      closePop();
      try {
        if (b.dataset.m === 'read') await readNow(true);
        if (b.dataset.m === 'note') await editNote();
        if (b.dataset.m === 'aside') await setAside();
        if (b.dataset.m === 'restore') { await api.post('receipts.php', { op: 'restore', ids: [ID] }); toast('Brought back'); load(true); }
        if (b.dataset.m === 'undraft') {
          if (!await dialog({ title: 'Delete the draft bill?', body: 'The bill is removed from Finance. The receipt stays here, ready to review again.', confirm: 'Delete the bill', danger: true })) return;
          const res = await api.post('bill.php', { op: 'delete_draft', id: ID });
          toast('Draft bill deleted');
          setForm(res.review);
        }
        if (b.dataset.m === 'delete') {
          if (!await dialog({ title: 'Delete this receipt?', body: 'The slip is removed for good' + (r.bill_id ? ', with its draft bill.' : '.'), confirm: 'Delete', danger: true })) return;
          await api.post('receipts.php', { op: 'delete', ids: [ID] });
          saved = JSON.stringify(payload());
          toast('Receipt deleted');
          location.href = D.queue.next ? '/receipts/review.php?id=' + D.queue.next : '/receipts/inbox.php';
        }
      } catch (x) { toastError(x); }
    });
  }
  async function readNow(force) {
    viewer.scanning(true);
    const st = $('#readState');
    if (st) st.innerHTML = '<span class="fwr-spin"></span> Reading the slip…';
    try {
      const res = await api.post('read.php', { id: ID, force: force ? 1 : 0 });
      if (force) toast(res.read.ok ? 'Read again' : (res.read.error || 'Could not be read'), res.read.ok ? {} : { type: 'error' });
    } catch (e) { toastError(e); }
    viewer.scanning(false);
    await load(true);
  }
  async function editNote() {
    const v = await dialog({ title: 'Note on this receipt', input: { label: 'What was it for?', value: D.receipt.note, multiline: true, max: 500, placeholder: 'Cement for the Smith job' }, confirm: 'Save note' });
    if (v === null) return;
    await api.post('receipts.php', { op: 'note', id: ID, note: v });
    D.receipt.note = v;
    toast('Note saved');
    render();
  }
  async function setAside(reason) {
    const res = reason ? { choice: reason, note: '' } : await dialog({ title: 'Set this receipt aside?', body: 'It leaves the to-do list' + (D.receipt.bill_id ? ' and its draft bill is deleted' : '') + '. You can bring it back.',
      choices: Object.entries(boot.aside).map(([value, label]) => ({ value, label })), note: { label: 'Note (optional)', placeholder: 'Anything the next person should know' }, confirm: 'Set aside' });
    if (!res) return;
    await api.post('receipts.php', { op: 'aside', ids: [ID], reason: res.choice, note: res.note });
    saved = JSON.stringify(payload());
    toast('Set aside', D.queue.next ? { action: { label: 'Next receipt', run: () => go(D.queue.next) } } : {});
    load(true);
  }

  // ================================================================ render
  function render() {
    const ro = !D.editable;
    formEl.innerHTML = `<div class="fwr-form__body">${banners()}${ro ? readonly() : editor()}</div>${ro ? actbarReadonly() : actbar()}`;
    if (!ro) wire();
    else wireReadonly();
    if (!ro) renderChecks(lastChecks);
  }

  function banners() {
    const r = D.receipt, rd = D.reading, out = [];
    if (r.aside) out.push(`<div class="fwr-banner"><svg class="i"><use href="#i-archive"/></svg><div class="fwr-banner__body"><b>Set aside</b> by ${esc(r.aside.by || 'someone')} on ${esc(fmtDay(r.aside.at))}: ${esc(r.aside.reason)}</div>${caps.capture ? '<button class="fwr-mini" type="button" data-restore>Bring it back</button>' : ''}</div>`);
    if (r.state === 'reading') out.push(`<div class="fwr-banner fwr-banner--ai"><svg class="i"><use href="#ir-scan"/></svg><div class="fwr-banner__body" id="readState"><span class="fwr-spin"></span> Reading the slip…</div></div>`);
    else if (D.editable && D.form.from === 'reading') {
      if (rd.state === 'parsed') {
        const pct = Math.round((rd.confidence || 0) * 100);
        const how = { ai: 'Read by AI', 'pdf-text': 'Read from the PDF', tesseract: 'Read with text recognition' }[rd.engine] || 'Read';
        out.push(`<div class="fwr-banner fwr-banner--ai"><svg class="i"><use href="#${rd.engine === 'ai' ? 'ir-ai' : 'ir-scan'}"/></svg><div class="fwr-banner__body"><b>${esc(how)}</b>${pct ? ` · ${pct}% sure` : ''} — check the fields marked ${icon('ir-ai', 'i--xs')} against the slip.</div></div>`);
      } else if (rd.state === 'failed') {
        out.push(`<div class="fwr-banner fwr-banner--warn"><svg class="i"><use href="#i-alert"/></svg><div class="fwr-banner__body"><b>${rd.engine === 'manual' ? 'Type the details in' : 'The slip could not be read'}</b>${rd.error ? ' — ' + esc(rd.error) : ''}</div>${rd.engine !== 'manual' ? '<button class="fwr-mini" type="button" data-reread>Try again</button>' : ''}</div>`);
      }
    }
    if (D.form.changed_in_finance) out.push(`<div class="fwr-banner"><svg class="i"><use href="#i-info"/></svg><div class="fwr-banner__body">This bill was changed in Finance since it was saved here, so it shows as Finance has it (prices excluding VAT).</div></div>`);
    if (r.legacy_invoice) out.push(`<div class="fwr-banner fwr-banner--warn"><svg class="i"><use href="#i-alert"/></svg><div class="fwr-banner__body">The old Receipts screen linked this slip to a sales invoice. Check that this purchase is not already in the books before posting it.</div></div>`);
    if (r.note) out.push(`<div class="fwr-banner"><svg class="i"><use href="#i-message"/></svg><div class="fwr-banner__body"><b>“${esc(r.note)}”</b><br><small class="fwr-muted">${esc(r.uploader || '')} · ${esc(ago(r.uploaded_at))}</small></div></div>`);
    return out.join('');
  }

  // ---------------------------------------------------------- the editor
  const field = (label, html, opts = {}) => `<div class="field${opts.cls ? ' ' + opts.cls : ''}" ${opts.id ? `id="${opts.id}"` : ''}><label class="label" ${opts.for ? `for="${opts.for}"` : ''}>${esc(label)}${opts.read ? ` <span class="fwr-read" title="Read from the slip">${icon('ir-ai')}read</span>` : ''}</label>${html}${opts.hint ? `<span class="hint">${opts.hint}</span>` : ''}</div>`;
  const isRead = (k) => D.form.from === 'reading' && D.reading.state === 'parsed' && ({ number: D.reading.number, issued: D.reading.date, due: D.reading.due, vendor_vat: D.reading.vat, slip_total: D.reading.total })[k] && String(F[k] || '') === String(({ number: D.reading.number, issued: D.reading.date, due: D.reading.due, vendor_vat: D.reading.vat, slip_total: D.reading.total != null ? Number(D.reading.total).toFixed(2) : '' })[k] || '');

  function editor() {
    const L = D.lookups;
    return `
      <section class="fwr-block"><div class="fwr-block__head"><h2>Supplier</h2></div>
        ${supplierBox()}
        <div id="supInfo">${supplierInfo()}</div>
      </section>
      <section class="fwr-block"><div class="fwr-block__head"><h2>The slip</h2></div>
        <div class="fwr-grid2">
          ${field('Invoice or slip number', `<input class="input${isRead('number') ? ' is-read' : ''}" id="fNumber" name="number" value="${esc(F.number)}" maxlength="190" autocomplete="off" placeholder="INV-1234">`, { for: 'fNumber', read: isRead('number') })}
          ${field('Date on the slip', `<input class="input${isRead('issued') ? ' is-read' : ''}" type="date" id="fIssued" name="issued" value="${esc(F.issued || '')}">`, { for: 'fIssued', read: isRead('issued') })}
          ${field('Due', `<input class="input${isRead('due') ? ' is-read' : ''}" type="date" id="fDue" name="due" value="${esc(F.due || '')}">`, { for: 'fDue', read: isRead('due'), hint: `<span class="fwr-dues"><button type="button" class="fwr-link" data-due="0">on the day</button> · <button type="button" class="fwr-link" data-due="30">30 days</button> · <button type="button" class="fwr-link" data-due="end">month end</button></span>` })}
          ${field('VAT number on the slip', `<input class="input${isRead('vendor_vat') ? ' is-read' : ''}" id="fVat" name="vendor_vat" value="${esc(F.vendor_vat)}" inputmode="numeric" maxlength="14" placeholder="4…" autocomplete="off">`, { for: 'fVat', read: isRead('vendor_vat') })}
          ${field('Total on the slip', `<div class="fwr-affix"><span>${esc(F.currency === 'ZAR' || !F.currency ? 'R' : F.currency)}</span><input class="input${isRead('slip_total') ? ' is-read' : ''}" id="fSlip" name="slip_total" value="${esc(F.slip_total || '')}" inputmode="decimal" placeholder="0.00" autocomplete="off"></div>`, { for: 'fSlip', read: isRead('slip_total'), hint: 'Checked against the bill below.' })}
          ${field('Currency', `<select class="select" id="fCur" name="currency">${['ZAR', 'USD', 'EUR', 'GBP'].concat(F.currency && !['ZAR', 'USD', 'EUR', 'GBP'].includes(F.currency) ? [F.currency] : []).map((c) => `<option${c === F.currency ? ' selected' : ''}>${c}</option>`).join('')}</select>`, { for: 'fCur' })}
        </div>
      </section>
      <section class="fwr-block"><div class="fwr-block__head"><h2>What was bought</h2>
          <div class="seg" role="radiogroup" aria-label="The amounts are"><button type="button" data-incl="1" class="${F.incl ? 'is-on' : ''}" role="radio" aria-checked="${F.incl}">Incl. VAT</button><button type="button" data-incl="0" class="${F.incl ? '' : 'is-on'}" role="radio" aria-checked="${!F.incl}">Excl. VAT</button></div></div>
        <div class="fwr-lines" id="lines">${F.lines.map(lineHtml).join('')}</div>
        <div class="fwr-lines__acts">
          <button class="fwr-mini" type="button" data-addline>${icon('plus')}Add a line</button>
          ${F.lines.length > 1 ? `<button class="fwr-mini" type="button" data-oneline>${icon('minus')}Make it one line</button>` : `<button class="fwr-mini" type="button" data-split>${icon('ir-split')}Split</button>`}
          ${F.lines.length > 1 ? `<button class="fwr-mini" type="button" data-sameacc>${icon('copy')}Same account for all</button>` : ''}
        </div>
        <dl class="fwr-totals" id="totals"></dl>
      </section>
      <section class="fwr-block"><div class="fwr-block__head"><h2>Project</h2></div>
        <div class="fwr-grid2">
          ${field('Project board', `<select class="select" id="fBoard" name="board_id"><option value="">No project</option>${groupBoards(L.boards)}</select>`, { for: 'fBoard', hint: F.board_id ? 'Choose the task per line; costs go to the board when posted.' : '' })}
          <div id="poField">${poField()}</div>
        </div>
      </section>
      <section class="fwr-block fwr-paid"><div class="fwr-block__head"><h2>Payment</h2></div>
        <div class="seg" role="radiogroup" aria-label="How it was paid">
          <button type="button" data-paid="" class="${!F.paid.method ? 'is-on' : ''}" role="radio" aria-checked="${!F.paid.method}">${icon('i-clock')}On account</button>
          ${Object.entries(L.paid_methods).map(([k, v]) => `<button type="button" data-paid="${k}" class="${F.paid.method === k ? 'is-on' : ''}" role="radio" aria-checked="${F.paid.method === k}">${icon(k === 'card' ? 'ir-card' : (k === 'cash' ? 'if-cash' : (k === 'eft' ? 'if-bank' : 'i-more-h')))}${esc(v)}</button>`).join('')}
        </div>
        ${F.paid.method ? `<div class="fwr-grid2">
          ${field('Paid on', `<input class="input" type="date" id="fPaidDate" value="${esc(F.paid.date || F.issued || '')}">`, { for: 'fPaidDate' })}
          ${field('From', `<select class="select" id="fPaidBank"><option value="">${F.paid.method === 'cash' ? 'Petty cash / default' : 'Default bank account'}</option>${L.banks.map((b) => `<option value="${b.id}"${+F.paid.bank_account_id === b.id ? ' selected' : ''}>${esc(b.name)}</option>`).join('')}</select>`, { for: 'fPaidBank' })}
        </div><span class="hint">Finance records the payment when the bill is posted, so the bank line matches it.</span>` : '<span class="hint">The bill stays open in Finance until it is paid.</span>'}
      </section>
      <section class="fwr-block"><div class="fwr-block__head"><h2>Note on the bill</h2></div>
        <textarea class="textarea" id="fNotes" rows="2" maxlength="2000" placeholder="Anything Finance should know">${esc(F.notes || '')}</textarea>
      </section>
      <section class="fwr-block"><div class="fwr-block__head"><h2>Checks</h2><span id="checkSpin"></span></div>
        <div class="fwr-checks" id="checks"></div>
      </section>`;
  }

  function groupBoards(boards) {
    const by = {};
    boards.forEach((b) => { (by[b.project || 'Other'] = by[b.project || 'Other'] || []).push(b); });
    return Object.entries(by).map(([p, list]) => `<optgroup label="${esc(p)}">${list.map((b) => `<option value="${b.id}"${+F.board_id === b.id ? ' selected' : ''}>${esc(b.label.replace(p + ' · ', ''))}</option>`).join('')}</optgroup>`).join('');
  }
  function poField() {
    const pos = (D.supplier && D.supplier.pos) || [];
    const needs = (lastChecks && lastChecks.checks || []).some((c) => c.code === 'po');
    if (!pos.length && !needs && !F.po_id) return '';
    return field('Purchase order', `<select class="select" id="fPo"><option value="">${pos.length ? 'Not for an order' : 'No open orders for this supplier'}</option>${pos.map((p) => `<option value="${p.id}"${+F.po_id === p.id ? ' selected' : ''}>${esc(p.number)} · ${esc(money(p.total))} · ${esc(fmtDay(p.date))}</option>`).join('')}</select>`, { for: 'fPo' });
  }
  function accountOptions(sel) {
    const L = D.lookups;
    const type = { expense: 'Expenses', asset: 'Assets', liability: 'Liabilities', equity: 'Equity' };
    const groups = {};
    L.accounts.forEach((a) => { const g = (type[a.type] || a.type) + (a.group && a.type === 'expense' ? ' · ' + a.group : ''); (groups[g] = groups[g] || []).push(a); });
    return `<option value="">Choose an account…</option>` + Object.entries(groups).map(([g, list]) => `<optgroup label="${esc(g)}">${list.map((a) => `<option value="${a.id}"${+sel === a.id ? ' selected' : ''}>${esc(a.code)} · ${esc(a.name)}</option>`).join('')}</optgroup>`).join('');
  }
  function lineHtml(l, i) {
    const rates = D.lookups.rates;
    const q = qtyOf(l.qty);
    const p = toCents(l.price);
    const tot = p != null && q > 0 ? moneyC(Math.round(q * p)) : '';
    return `<div class="fwr-line" data-i="${i}">
      ${l.account_source && SOURCE[l.account_source] ? `<span class="fwr-line__src" title="Where the account came from">${esc(SOURCE[l.account_source])}</span>` : ''}
      <div class="fwr-line__r1"><input class="input" data-k="description" value="${esc(l.description)}" maxlength="255" placeholder="${i === 0 ? 'What was bought — e.g. Diesel, Cement 50 kg' : 'Description'}" aria-label="Description, line ${i + 1}">
        <button class="icon-btn icon-btn--sm" type="button" data-remove aria-label="Remove line ${i + 1}"${F.lines.length < 2 ? ' disabled' : ''}>${icon('x', 'i--sm')}</button></div>
      <div class="fwr-line__r2">
        <input class="input" data-k="qty" value="${esc(l.qty)}" inputmode="decimal" aria-label="Quantity, line ${i + 1}" title="Quantity">
        <div class="fwr-affix"><span>R</span><input class="input" data-k="price" value="${esc(l.price)}" inputmode="decimal" placeholder="${F.incl ? 'Price incl. VAT' : 'Price excl. VAT'}" aria-label="Price ${F.incl ? 'including' : 'excluding'} VAT, line ${i + 1}"></div>
        <select class="select" data-k="rate" aria-label="VAT, line ${i + 1}">${rates.map((r) => `<option value="${r.rate}"${+l.rate === +r.rate ? ' selected' : ''}>${esc(r.label)}</option>`).join('')}${rates.some((r) => +r.rate === +l.rate) ? '' : `<option value="${l.rate}" selected>${esc(l.rate)}% VAT</option>`}</select>
        <span class="fwr-line__total" data-total>${tot}</span>
      </div>
      <div class="fwr-line__r3"><select class="select" data-k="account_id" aria-label="Account, line ${i + 1}">${accountOptions(l.account_id)}</select></div>
      ${F.board_id ? `<div class="fwr-line__r3"><select class="select" data-k="item_id" aria-label="Task, line ${i + 1}"><option value="">No task (project only)</option>${items.map((t) => `<option value="${t.id}"${+l.item_id === t.id ? ' selected' : ''}>${esc((t.group ? t.group + ' · ' : '') + t.title)}</option>`).join('')}</select></div>` : ''}
    </div>`;
  }

  // ------------------------------------------------------- supplier box
  function supplierBox() {
    const s = D.supplier;
    const sg = D.suggestion;
    const name = s ? s.name : '';
    return `<div class="fwr-sup" id="sup">
      <label class="fwr-sup__box" for="supInput"><span class="fwr-sup__ava${s ? '' : ' is-empty'}" style="--h:${hue(name)}">${s ? esc(initials(name)) : icon('i-building', 'i--sm')}</span>
        <input id="supInput" value="${esc(name)}" placeholder="${D.reading.vendor ? 'The slip says “' + esc(D.reading.vendor) + '”' : 'Search your suppliers'}" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="supList" aria-label="Supplier">
        ${s ? `<a class="icon-btn icon-btn--sm" href="${esc(s.url)}" target="_blank" rel="noopener" aria-label="Open ${esc(s.name)} in the CRM" data-tip="CRM">${icon('external', 'i--sm')}</a>` : ''}
      </label>
      <div class="fwr-sup__list" id="supList" role="listbox" hidden></div>
    </div>
    ${!s && D.reading.vendor && caps.capture ? `<div class="fwr-suggest">${sg ? `Is it <button type="button" data-pick="${sg.id}">${icon('check', 'i--xs')}${esc(sg.name)}</button>` : ''}${(sg && sg.alternatives || []).map((a) => `<button type="button" data-pick="${a.id}">${esc(a.name)}</button>`).join('')}
      <button type="button" data-newsup>${icon('plus', 'i--xs')}Add “${esc(D.reading.vendor)}”</button></div>` : ''}
    ${s && sg && sg.id === s.id && D.form.from === 'reading' ? `<div class="fwr-suggest">${icon('ir-ai', 'i--xs')} Matched ${esc({ vat: 'by the VAT number on the slip', learned: 'from earlier slips', name: 'by name', similar: 'by a similar name — check it' }[sg.source] || '')}</div>` : ''}`;
  }
  function supplierInfo() {
    const s = D.supplier;
    if (!s) return '';
    const bits = [];
    bits.push(s.vat_no ? `VAT <b>${esc(s.vat_no)}</b>` : '<span class="fwr-flag">No VAT number</span>');
    bits.push(s.compliance.ok ? `<span class="fwr-flag fwr-flag--ok">${icon('check', 'i--xs')}Compliant</span>` : `<a class="fwr-flag fwr-flag--danger" href="${esc(s.url)}#compliance" target="_blank" rel="noopener">${icon('alert', 'i--xs')}${s.compliance.missing.length} document${s.compliance.missing.length === 1 ? '' : 's'} missing</a>`);
    if (s.year.bills) bits.push(`${s.year.bills} bill${s.year.bills === 1 ? '' : 's'} · <b>${esc(money(s.year.total))}</b> this year`);
    if (s.status !== 'active') bits.push(`<span class="fwr-flag">${esc(s.status)}</span>`);
    return `<div class="fwr-supinfo">${bits.join('<span aria-hidden="true">·</span>')}</div>`;
  }
  function wireSupplier() {
    const input = $('#supInput');
    const list = $('#supList');
    let rows = [], active = -1, seq = 0;
    const show = (on) => { list.hidden = !on; input.setAttribute('aria-expanded', String(on)); };
    function draw() {
      const q = input.value.trim();
      list.innerHTML = rows.map((r, i) => `<div class="fwr-sup__opt" role="option" data-i="${i}" aria-selected="${i === active}"><span class="fwr-sup__ava" style="--h:${hue(r.name)};width:28px;height:28px;font-size:10.5px;border-radius:9px">${esc(initials(r.name))}</span><b>${esc(r.name)}</b><small>${esc(r.vat_no || (r.last ? 'last ' + fmtDay(r.last) : ''))}</small></div>`).join('')
        + (caps.capture ? `<div class="fwr-sup__opt fwr-sup__opt--new" role="option" data-new aria-selected="${active === rows.length}">${icon('plus', 'i--sm')}<b>${q ? 'Add “' + esc(q) + '” as a new supplier' : 'Add a new supplier'}</b></div>` : '');
      show(true);
    }
    const search = debounce(async () => {
      const n = ++seq;
      try {
        const res = await api.get('bill.php', { op: 'suppliers', q: input.value.trim() });
        if (n !== seq) return;
        rows = res.suppliers;
        active = rows.length ? 0 : (caps.capture ? 0 : -1);
        draw();
      } catch (e) { /* keep the old list */ }
    }, 180);
    input.addEventListener('focus', () => { input.select(); search(); });
    input.addEventListener('input', search);
    input.addEventListener('blur', () => setTimeout(() => { show(false); if (D.supplier) input.value = D.supplier.name; else if (!F.supplier_id) input.value = ''; }, 160));
    input.addEventListener('keydown', (e) => {
      const max = rows.length + (caps.capture ? 1 : 0);
      if (e.key === 'ArrowDown') { e.preventDefault(); active = (active + 1) % max; draw(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); active = (active - 1 + max) % max; draw(); }
      else if (e.key === 'Enter') { e.preventDefault(); if (active === rows.length) newSupplier(input.value.trim()); else if (rows[active]) pickSupplier(rows[active].id); }
      else if (e.key === 'Escape') { show(false); }
    });
    list.addEventListener('pointerdown', (e) => e.preventDefault());
    list.addEventListener('click', (e) => {
      const o = e.target.closest('.fwr-sup__opt');
      if (!o) return;
      if (o.hasAttribute('data-new')) newSupplier(input.value.trim());
      else pickSupplier(rows[+o.dataset.i].id);
    });
  }
  async function pickSupplier(id) {
    try {
      const { supplier } = await api.get('bill.php', { op: 'supplier', id });
      F.supplier_id = supplier.id;
      D.supplier = supplier;
      // What was learned from their earlier bills fills empty choices.
      const L = supplier.learned || {};
      if (L.account_id) F.lines.forEach((l) => { if (!l.account_id || ['default', ''].includes(l.account_source)) { l.account_id = L.account_id; l.account_source = L.source || 'supplier'; } });
      if (L.board_id && !F.board_id && D.lookups.boards.some((b) => b.id === L.board_id)) { F.board_id = L.board_id; await loadItems(); }
      if (supplier.vat_no === '' && !F.vendor_vat) F.lines.forEach((l) => { if (D.form.from === 'reading' && !D.reading.tax) l.rate = 0; });
      render();
      check();
      const n = $('#fNumber');
      if (n && !n.value) n.focus();
    } catch (e) { toastError(e); }
  }
  async function newSupplier(name, confirmDup) {
    const m = modal(`<div class="fwm-dialog__head"><h2>New supplier</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body" novalidate><p class="fwm-dialog__lead">Added to the CRM as a supplier — with the details from the slip.</p>
        <div class="fwr-grid2">
          <div class="field" style="grid-column:1/-1"><label class="label" for="nsName">Name</label><input class="input" id="nsName" maxlength="255" value="${esc(name || D.reading.vendor || '')}" autofocus></div>
          <div class="field"><label class="label" for="nsVat">VAT number</label><input class="input" id="nsVat" inputmode="numeric" maxlength="14" value="${esc(F.vendor_vat || D.reading.vat || '')}" placeholder="4…"></div>
          <div class="field"><label class="label" for="nsPhone">Phone</label><input class="input" id="nsPhone" maxlength="40"></div>
          <div class="field" style="grid-column:1/-1"><label class="label" for="nsEmail">Email</label><input class="input" id="nsEmail" type="email" maxlength="190" placeholder="accounts@…"></div>
        </div><div id="nsDup"></div></form>
      <div class="fwm-dialog__foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" id="nsGo">${icon('plus')}Add supplier</button></div>`, { width: 520 });
    openModal(m);
    let confirm = !!confirmDup;
    const go = async () => {
      const btn = $('#nsGo', m);
      busy(btn, true);
      try {
        const res = await api.post('bill.php', { op: 'supplier_new', name: $('#nsName', m).value.trim(), vat_no: $('#nsVat', m).value.trim(), phone: $('#nsPhone', m).value.trim(), email: $('#nsEmail', m).value.trim(), confirm_duplicate: confirm ? 1 : 0 });
        if (!res.created) {
          $('#nsDup', m).innerHTML = `<div class="fwr-banner fwr-banner--warn" style="margin:14px 0 0"><svg class="i"><use href="#i-alert"/></svg><div class="fwr-banner__body"><b>This looks like a supplier you have:</b> ${res.duplicates.map((d) => `<button type="button" class="fwr-link" data-use="${d.id}">${esc(d.name)}</button>`).join(', ')}. Choose one, or add it anyway.</div></div>`;
          $$('[data-use]', m).forEach((b) => b.addEventListener('click', () => { closeModal(m); pickSupplier(+b.dataset.use); }));
          $('#nsGo', m).innerHTML = icon('plus') + 'Add it anyway';
          confirm = true;
          return;
        }
        closeModal(m);
        toast(res.supplier.name + ' added to your suppliers');
        pickSupplier(res.supplier.id);
      } catch (e) { toastError(e); } finally { busy(btn, false); }
    };
    $('#nsGo', m).addEventListener('click', go);
    $('form', m).addEventListener('submit', (e) => { e.preventDefault(); go(); });
  }

  // --------------------------------------------------------- wiring
  function wire() {
    wireSupplier();
    const on = (sel, ev, fn) => { const el = $(sel, formEl); if (el) el.addEventListener(ev, fn); };
    on('#fNumber', 'input', (e) => { F.number = e.target.value; changed(); });
    on('#fIssued', 'change', (e) => { F.issued = e.target.value; if (F.paid.method && !F.paid.date) F.paid.date = F.issued; changed(); });
    on('#fDue', 'change', (e) => { F.due = e.target.value; changed(); });
    on('#fVat', 'input', (e) => { F.vendor_vat = e.target.value; changed(); });
    on('#fSlip', 'input', (e) => { F.slip_total = e.target.value; changed(); });
    on('#fCur', 'change', (e) => { F.currency = e.target.value; render(); changed(); });
    on('#fNotes', 'input', (e) => { F.notes = e.target.value; changed(true); });
    on('#fBoard', 'change', async (e) => { F.board_id = +e.target.value || null; F.lines.forEach((l) => { l.item_id = null; }); await loadItems(); render(); changed(); });
    on('#fPo', 'change', (e) => { F.po_id = +e.target.value || null; changed(); });
    on('#fPaidDate', 'change', (e) => { F.paid.date = e.target.value; changed(); });
    on('#fPaidBank', 'change', (e) => { F.paid.bank_account_id = +e.target.value || null; changed(); });
    $$('[data-due]', formEl).forEach((b) => b.addEventListener('click', () => {
      if (!F.issued) return;
      const d = new Date(F.issued + 'T00:00:00');
      if (b.dataset.due === 'end') d.setMonth(d.getMonth() + 1, 0); else d.setDate(d.getDate() + +b.dataset.due);
      F.due = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
      $('#fDue').value = F.due;
      changed();
    }));
    $$('[data-incl]', formEl).forEach((b) => b.addEventListener('click', () => { F.incl = b.dataset.incl === '1'; render(); changed(); }));
    $$('[data-paid]', formEl).forEach((b) => b.addEventListener('click', () => { F.paid.method = b.dataset.paid; if (F.paid.method && !F.paid.date) F.paid.date = F.issued; render(); changed(); }));
    $$('[data-pick]', formEl).forEach((b) => b.addEventListener('click', () => pickSupplier(+b.dataset.pick)));
    $$('[data-newsup]', formEl).forEach((b) => b.addEventListener('click', () => newSupplier(D.reading.vendor)));
    $$('[data-reread]', formEl).forEach((b) => b.addEventListener('click', () => readNow(true)));
    $$('[data-restore]', formEl).forEach((b) => b.addEventListener('click', async () => { try { await api.post('receipts.php', { op: 'restore', ids: [ID] }); toast('Brought back'); load(true); } catch (e) { toastError(e); } }));
    on('[data-addline]', 'click', () => { F.lines.push(blankLine()); render(); changed(); const last = $$('.fwr-line', formEl).pop(); if (last) $('[data-k="description"]', last).focus(); });
    on('[data-oneline]', 'click', () => {
      const t = totals();
      const first = F.lines[0];
      F.lines = [{ ...first, description: F.lines.map((l) => l.description).filter(Boolean).slice(0, 3).join(', ').slice(0, 255), qty: 1, price: ((F.incl ? t.total : t.net) / 100).toFixed(2) }];
      render(); changed();
    });
    on('[data-split]', 'click', () => {
      const l = F.lines[0];
      const c = toCents(l.price) || 0;
      const half = Math.floor(c / 2);
      F.lines = [{ ...l, price: (half / 100).toFixed(2) }, { ...l, description: '', price: ((c - half) / 100).toFixed(2), account_source: '' }];
      render(); changed();
    });
    on('[data-sameacc]', 'click', () => { const a = F.lines[0].account_id; F.lines.forEach((l) => { l.account_id = a; l.account_source = ''; }); render(); changed(); });
    const lines = $('#lines', formEl);
    if (lines) {
      lines.addEventListener('input', (e) => {
        const box = e.target.closest('.fwr-line');
        const k = e.target.dataset.k;
        if (!box || !k) return;
        const l = F.lines[+box.dataset.i];
        l[k] = e.target.value;
        if (k === 'qty' || k === 'price') {
          const q = qtyOf(l.qty), p = toCents(l.price);
          $('[data-total]', box).textContent = p != null && q > 0 ? moneyC(Math.round(q * p)) : '';
        }
        changed(k === 'description');
      });
      lines.addEventListener('change', (e) => {
        const box = e.target.closest('.fwr-line');
        const k = e.target.dataset.k;
        if (!box || !k || e.target.tagName !== 'SELECT') return;
        const l = F.lines[+box.dataset.i];
        l[k] = k === 'rate' ? +e.target.value : (+e.target.value || null);
        if (k === 'account_id') { l.account_source = ''; const src = $('.fwr-line__src', box); if (src) src.remove(); }
        changed();
      });
      lines.addEventListener('click', (e) => {
        const b = e.target.closest('[data-remove]');
        if (!b) return;
        F.lines.splice(+b.closest('.fwr-line').dataset.i, 1);
        render(); changed();
      });
      lines.addEventListener('keydown', (e) => {
        // Enter in the last price adds a line
        if (e.key === 'Enter' && e.target.dataset.k === 'price' && +e.target.closest('.fwr-line').dataset.i === F.lines.length - 1 && !e.ctrlKey && !e.metaKey) {
          e.preventDefault();
          F.lines.push(blankLine()); render(); changed();
          const last = $$('.fwr-line', formEl).pop(); if (last) $('[data-k="description"]', last).focus();
        }
      });
    }
    on('#saveBtn', 'click', () => save());
    on('#postBtn', 'click', () => post());
    drawTotals();
  }
  async function loadItems() {
    items = [];
    if (!F.board_id) return;
    try { items = (await api.get('bill.php', { op: 'items', board: F.board_id })).items; } catch (e) { items = []; }
  }

  // --------------------------------------------------------- totals
  function totals() {
    let net = 0, vat = 0;
    F.lines.forEach((l) => {
      const p = toCents(l.price);
      if (p == null) return;
      const q = qtyOf(l.qty);
      const rate = +l.rate || 0;
      const g = Math.round(q * p);
      let n = F.incl ? Math.round(g * 100 / (100 + rate)) : g;
      if (F.incl) {
        let off = Math.abs(n + Math.round(n * rate / 100) - g);
        [n - 1, n + 1].forEach((t) => { const d = Math.abs(t + Math.round(t * rate / 100) - g); if (t >= 0 && d < off) { n = t; off = d; } });
      }
      net += n;
      vat += Math.round(n * rate / 100);
    });
    return { net, vat, total: net + vat };
  }
  function drawTotals(server) {
    const el = $('#totals', formEl);
    if (!el) return;
    const t = server || totals();
    const slip = toCents(F.slip_total);
    let match = '';
    if (slip != null && slip > 0 && t.total > 0) {
      const d = t.total - slip;
      match = Math.abs(d) <= 2 ? `<div class="fwr-match is-ok">${icon('check')}Matches the slip${d ? ' (VAT rounding ' + moneyC(Math.abs(d)) + ')' : ''}</div>`
                               : `<div class="fwr-match is-off">${icon('alert')}${moneyC(Math.abs(d))} ${d > 0 ? 'more' : 'less'} than the slip’s ${moneyC(slip)}</div>`;
    }
    el.innerHTML = `<dt>Excluding VAT</dt><dd>${moneyC(t.net)}</dd><dt>VAT</dt><dd>${moneyC(t.vat)}</dd><dt class="is-total">Bill total</dt><dd class="is-total">${moneyC(t.total)}</dd>${match}`;
    const pb = $('#postTotal');
    if (pb) pb.textContent = moneyC(t.total);
  }

  // --------------------------------------------------------- checks
  function changed(textOnly) {
    drawTotals();
    if (!textOnly) check(); else checkSoon();
    const s = $('#saveState');
    if (s) s.innerHTML = dirty() ? `${icon('pen', 'i--xs')}Not saved yet` : (D.receipt.bill_id ? `${icon('check', 'i--xs')}Draft saved` : '');
  }
  const checkSoon = debounce(() => check(), 700);
  const check = debounce(async () => {
    const n = ++checkSeq;
    const spin = $('#checkSpin');
    if (spin) spin.innerHTML = '<span class="fwr-spin"></span>';
    try {
      const res = await api.post('bill.php', { op: 'check', id: ID, form: payload() });
      if (n !== checkSeq) return;
      lastChecks = res;
      renderChecks(res);
      drawTotals(res.totals);
      const po = $('#poField');
      if (po && !$('#fPo') && res.checks.some((c) => c.code === 'po')) { po.innerHTML = poField(); const s = $('#fPo'); if (s) s.addEventListener('change', (e) => { F.po_id = +e.target.value || null; changed(); }); }
    } catch (e) { /* keep the last checks */ } finally {
      if (n === checkSeq && spin) spin.innerHTML = '';
    }
  }, 350);
  function renderChecks(res) {
    const box = $('#checks', formEl);
    if (!box || !res) return;
    const list = res.checks || [];
    const ICON = { block: 'i-alert', warn: 'i-alert', info: 'i-info' };
    box.innerHTML = list.length ? list.map((c, i) => `<div class="fwr-check" data-level="${c.level}">
        <span class="fwr-check__icon">${icon(ICON[c.level])}</span>
        <span class="fwr-check__text"><b>${esc(c.title)}</b>${c.detail ? `<small>${esc(c.detail)}</small>` : ''}
          ${c.overridden ? `<em>${icon('i-shield', 'i--xs')} Overridden by ${esc(c.overridden.by)}: “${esc(c.overridden.reason)}”</em>` : ''}
          ${c.fix || c.link || c.can_override ? `<span class="fwr-check__acts">${c.fix ? `<button class="fwr-mini" type="button" data-fix="${i}">${icon('zap')}${esc(c.fix.label)}</button>` : ''}${c.link ? `<a class="fwr-mini" href="${esc(c.link.href)}" ${c.link.href.startsWith('/receipts/') ? '' : 'target="_blank" rel="noopener"'}>${icon('external')}${esc(c.link.label)}</a>` : ''}${c.can_override ? `<button class="fwr-mini" type="button" data-override="${esc(c.code)}">${icon('i-shield')}Override…</button>` : ''}</span>` : ''}
        </span></div>`).join('')
      : `<div class="fwr-allgood">${icon('check')}Everything checks out — ready for the books.</div>`;
    $$('[data-fix]', box).forEach((b) => b.addEventListener('click', () => fix(list[+b.dataset.fix].fix)));
    $$('[data-override]', box).forEach((b) => b.addEventListener('click', () => override(b.dataset.override)));
    const post = $('#postBtn');
    if (post) post.title = res.blocked ? 'Sort out the checks marked in red first' : '';
    const badge = $('#blockCount');
    const blocks = list.filter((c) => c.level === 'block').length;
    if (badge) { badge.hidden = !blocks; badge.textContent = blocks + ' to sort out'; }
  }
  async function fix(f) {
    try {
      if (f.op === 'match_total' && F.lines.length === 1) {
        const slip = toCents(F.slip_total);
        const l = F.lines[0];
        const q = qtyOf(l.qty) || 1;
        if (F.incl) l.price = (slip / q / 100).toFixed(2);
        else l.price = (Math.round(slip * 100 / (100 + (+l.rate || 0))) / q / 100).toFixed(2);
        render(); changed();
      } else if (f.op === 'no_vat') {
        F.lines.forEach((l) => { l.rate = 0; });
        render(); changed();
        toast(F.incl ? 'No VAT claimed — the full amount is the cost' : 'No VAT claimed on these lines');
      } else if (f.op === 'zar') {
        F.currency = 'ZAR'; render(); changed();
      } else if (f.op === 'save_vat') {
        const res = await api.post('bill.php', { op: 'supplier_vat', supplier_id: F.supplier_id, vat: F.vendor_vat });
        D.supplier = res.supplier;
        toast('VAT number saved on ' + res.supplier.name);
        render(); check();
      } else if (f.op === 'aside_duplicate') {
        await setAside('duplicate');
      }
    } catch (e) { toastError(e); }
  }
  async function override(code) {
    const reason = await dialog({ title: 'Override this check?', body: 'The bill can then be posted. Your reason is kept with the receipt and in the audit trail.', input: { label: 'Why', multiline: true, max: 500, placeholder: 'e.g. Tax clearance renewed, certificate on its way' }, confirm: 'Override' });
    if (!reason) return;
    try {
      const res = await api.post('bill.php', { op: 'override', id: ID, code, reason, form: payload() });
      lastChecks = res;
      renderChecks(res);
      toast('Overridden — on record');
    } catch (e) { toastError(e); }
  }

  // --------------------------------------------------------- save & post
  async function save(quiet) {
    if (!D || !D.editable) return false;
    const btn = $('#saveBtn');
    busy(btn, true);
    try {
      const res = await api.post('bill.php', { op: 'save', id: ID, form: payload() });
      setForm(res.review);
      if (!quiet) toast('Draft bill saved', { action: D.queue.next ? { label: 'Next receipt', run: () => go(D.queue.next) } : null });
      return true;
    } catch (e) {
      toastError(e);
      return false;
    } finally {
      busy($('#saveBtn'), false);
    }
  }
  async function post() {
    if (!D || !D.editable) return;
    const btn = $('#postBtn');
    if (lastChecks && lastChecks.blocked) {
      const first = $('.fwr-check[data-level="block"]', formEl);
      if (first) { first.scrollIntoView({ block: 'center', behavior: 'smooth' }); first.style.animation = 'none'; void first.offsetWidth; first.style.animation = ''; }
      toast('Sort out the checks marked in red first.', { type: 'error' });
      return;
    }
    busy(btn, true);
    try {
      const res = await api.post('bill.php', { op: 'post', id: ID, form: payload() });
      D = res.review;
      saved = JSON.stringify(payload());
      done(res.posted);
      head();
    } catch (e) {
      if (e.data && e.data.review) { setForm(e.data.review); }
      toastError(e);
    } finally {
      busy($('#postBtn'), false);
    }
  }
  function done(p) {
    const noted = (p.project_costs || []).filter((c) => c.status === 'noted').length;
    const written = (p.project_costs || []).filter((c) => c.status === 'written').length;
    formEl.innerHTML = `<div class="fwr-form__body"><div class="fwr-done">
        <span class="fwr-zero__seal" aria-hidden="true"><svg class="i"><use href="#i-check"/></svg></span>
        <p class="fwr-eyebrow">${p.payment_id ? 'Posted and paid' : 'Posted'}</p>
        <h2>In the books</h2>
        <p>${esc(D.supplier ? D.supplier.name : 'The bill')} · ${esc(moneyC(totals().total))}${p.payment_id ? ' — the payment is recorded, ready to match on the bank statement.' : ' — it stays open in Payables until it is paid.'}
          ${written ? `<br>${written} project cost${written === 1 ? '' : 's'} added to the board.` : ''}${noted ? `<br>${noted} project cost${noted === 1 ? ' was' : 's were'} noted in the task’s activity (the board has no cost column you can edit).` : ''}</p>
        <div class="fwr-done__acts">
          ${D.queue.next ? `<button class="btn btn--primary btn--lg" type="button" id="nextAfter" autofocus>${icon('chev-right')}Next receipt<kbd>j</kbd></button>` : `<a class="btn btn--primary btn--lg" href="/receipts/">${icon('check')}All done — back to the overview</a>`}
          <a class="btn" href="/finances/#payables?bill=${p.bill_id}">${icon('if-ledger')}Open in Finance</a>
        </div>
      </div>${readonly(true)}</div>`;
    const nb = $('#nextAfter');
    if (nb) { nb.addEventListener('click', () => go(D.queue.next)); nb.focus(); }
    toast('Posted to the books');
  }

  function actbar() {
    return `<div class="fwr-actbar">
      <span class="fwr-actbar__state" id="saveState">${D.receipt.bill_id ? icon('check', 'i--xs') + 'Draft saved' : ''}</span>
      <span class="fwr-flag fwr-flag--danger" id="blockCount" hidden></span>
      ${caps.capture ? `<button class="btn" type="button" id="saveBtn" title="Ctrl S">${icon('pen')}Save draft</button>
      <button class="btn btn--primary" type="button" id="postBtn">${icon('if-post')}Post <span id="postTotal" class="fwr-num"></span><kbd>Ctrl ↵</kbd></button>` : ''}
    </div>`;
  }

  // --------------------------------------------------------- read-only
  function journey() {
    const st = D.receipt.state;
    const order = ['sent', 'read', 'draft', 'books'];
    const now = { reading: 'read', review: 'draft', draft: 'books', posted: 'done', paid: 'done', aside: 'none' }[st];
    const idx = now === 'done' ? 4 : order.indexOf(now);
    const steps = [['Sent in', fmtDay(D.receipt.uploaded_at)], ['Read', D.reading.state === 'parsed' ? 'Read' : (D.reading.state === 'failed' ? 'Typed in' : '')], ['Bill made', D.receipt.bill_id ? 'Draft in Finance' : ''], ['In the books', st === 'paid' ? 'Paid' : (st === 'posted' ? 'Posted' : '')]];
    if (st === 'aside') return '';
    return `<div class="fwr-journey" role="list">${steps.map(([t, s], i) => `<div class="fwr-journey__step ${i < idx ? 'is-done' : (i === idx ? 'is-now' : '')}" role="listitem"><span class="fwr-journey__dot">${i < idx ? icon('check') : i + 1}</span><b>${esc(t)}</b><small>${esc(s)}</small></div>`).join('')}</div>`;
  }
  function readonly(afterPost) {
    const r = D.receipt;
    const L = D.lookups;
    const acc = (id) => { const a = L.accounts.find((x) => x.id === +id); return a ? a.code + ' · ' + a.name : 'Default expense account'; };
    const t = totals();
    let html = afterPost ? '' : `<section class="fwr-block"><div class="fwr-block__head"><h2>Where it is</h2></div>${journey()}</section>`;
    if (r.bill_id || D.form.from === 'bill') {
      html += `<section class="fwr-block"><div class="fwr-block__head"><h2>The bill</h2>${r.bill_url && caps.all ? `<a class="fwr-mini" href="${esc(r.bill_url)}">${icon('if-ledger')}Finance</a>` : ''}</div>
        <dl class="fwr-kv"><dt>Supplier</dt><dd>${esc(D.supplier ? D.supplier.name : '—')}</dd><dt>Number</dt><dd>${esc(F.number || '—')}</dd><dt>Date</dt><dd>${esc(fmtDay(F.issued, true))}${F.due ? ' · due ' + esc(fmtDay(F.due, true)) : ''}</dd>
          ${F.paid.method ? `<dt>Paid</dt><dd>${esc(L.paid_methods[F.paid.method] || F.paid.method)} · ${esc(fmtDay(F.paid.date, true))}</dd>` : ''}</dl>
        <table class="fwr-summary">${F.lines.map((l) => `<tr><td>${esc(l.description || 'Item')}<small>${esc(acc(l.account_id))}${+l.rate ? ' · ' + esc(l.rate) + '% VAT' : ' · no VAT'}</small></td><td>${esc(moneyC(Math.round(qtyOf(l.qty) * (toCents(l.price) || 0))))}</td></tr>`).join('')}
          <tr><td><b>Total</b> <small>${esc(moneyC(t.net))} + VAT ${esc(moneyC(t.vat))}</small></td><td><b>${esc(moneyC(t.total))}</b></td></tr></table></section>`;
    } else {
      const rd = D.reading;
      html += `<section class="fwr-block"><div class="fwr-block__head"><h2>What was read</h2></div>
        ${rd.state === 'parsed' ? `<dl class="fwr-kv"><dt>From</dt><dd>${esc(rd.vendor || '—')}</dd><dt>Number</dt><dd>${esc(rd.number || '—')}</dd><dt>Date</dt><dd>${esc(rd.date ? fmtDay(rd.date, true) : '—')}</dd><dt>Total</dt><dd>${esc(rd.total != null ? money(rd.total) : '—')}</dd></dl>`
          : `<p class="fwr-muted">${rd.state === 'pending' ? 'Being read…' : 'Nothing could be read automatically; your bookkeeper types it in.'}</p>`}</section>`;
    }
    return html;
  }
  function actbarReadonly() {
    const r = D.receipt;
    const own = r.uploaded_by === boot.user;
    const bits = [];
    if (own && caps.upload && !['posted', 'paid'].includes(r.state)) bits.push(`<button class="btn" type="button" data-ro="note">${icon('pen')}${r.note ? 'Change the note' : 'Add a note'}</button>`);
    if (own && !r.bill_id && !['posted', 'paid'].includes(r.state)) bits.push(`<button class="btn btn--danger" type="button" data-ro="delete">${icon('trash')}Delete</button>`);
    if (D.queue && D.queue.next && caps.capture) bits.push(`<button class="btn btn--primary" type="button" data-ro="next">${icon('chev-right')}Next receipt</button>`);
    if (!bits.length) return '';
    return `<div class="fwr-actbar"><span class="fwr-actbar__state">${esc(r.state_label)}</span>${bits.join('')}</div>`;
  }
  function wireReadonly() {
    $$('[data-restore]', formEl).forEach((b) => b.addEventListener('click', async () => { try { await api.post('receipts.php', { op: 'restore', ids: [ID] }); toast('Brought back'); load(true); } catch (e) { toastError(e); } }));
    $$('[data-ro]', formEl).forEach((b) => b.addEventListener('click', async () => {
      try {
        if (b.dataset.ro === 'note') await editNote();
        if (b.dataset.ro === 'next') go(D.queue.next);
        if (b.dataset.ro === 'delete') {
          if (!await dialog({ title: 'Delete this receipt?', body: 'The slip is removed for good.', confirm: 'Delete', danger: true })) return;
          await api.post('receipts.php', { op: 'delete', ids: [ID] });
          location.href = '/receipts/inbox.php';
        }
      } catch (e) { toastError(e); }
    }));
  }

  // --------------------------------------------------------- keyboard
  document.addEventListener('keydown', (e) => {
    if (document.querySelector('.fwm-modal:not([hidden])')) return;
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save(); return; }
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); post(); return; }
    if (typingIn(e.target) || e.ctrlKey || e.metaKey || e.altKey) return;
    if (e.key === 'j') { e.preventDefault(); go(D && D.queue.next); }
    else if (e.key === 'k') { e.preventDefault(); go(D && D.queue.prev); }
    else if (e.key === '+' || e.key === '=') viewer.zoom(1);
    else if (e.key === '-') viewer.zoom(-1);
    else if (e.key === '0') viewer.fit();
    else if (e.key === 'r') viewer.turn();
  });
  window.addEventListener('beforeunload', (e) => { if (dirty()) { e.preventDefault(); e.returnValue = ''; } });
  $('#backBtn').addEventListener('click', (e) => {
    if (!dirty()) return;
    e.preventDefault();
    dialog({ title: 'Leave without saving?', body: 'Your changes to this bill are not saved.', confirm: 'Leave', cancel: 'Stay', danger: true }).then((ok) => { if (ok) { saved = JSON.stringify(payload()); location.href = '/receipts/inbox.php'; } });
  });

  load();
})();
