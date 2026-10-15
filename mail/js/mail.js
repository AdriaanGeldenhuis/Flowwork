/* =============================================================================
   Flowwork Mail — client
   Core helpers (API, toasts, popovers, dialogs, editor) are shared with the
   settings/templates pages via window.FWMail. The inbox app only boots when
   #mailApp is present.
   ============================================================================= */
(function () {
  'use strict';

  // ===========================================================================
  // Core helpers
  // ===========================================================================
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const icon = (name, cls = '') => `<svg class="i ${cls}" aria-hidden="true"><use href="#i-${name}"/></svg>`;
  const csrf = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
  const store = {
    get(k, d) { try { const v = localStorage.getItem('fwm.' + k); return v == null ? d : JSON.parse(v); } catch (e) { return d; } },
    set(k, v) { try { localStorage.setItem('fwm.' + k, JSON.stringify(v)); } catch (e) { /* private mode */ } },
  };

  class ApiError extends Error {
    constructor(message, status, data) { super(message); this.status = status; this.data = data; }
  }

  async function request(method, url, body, isForm) {
    const opts = { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
    if (method !== 'GET') opts.headers['X-CSRF-TOKEN'] = csrf();
    if (body !== undefined) {
      if (isForm) { opts.body = body; } else { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    }
    let res;
    try { res = await fetch(url, opts); } catch (e) {
      throw new ApiError('You appear to be offline. Check your connection and try again.', 0);
    }
    let data = null;
    try { data = await res.json(); } catch (e) { data = null; }
    if (res.status === 401) {
      location.href = '/login.php?redirect=' + encodeURIComponent(location.pathname + location.search + location.hash);
      throw new ApiError('Your session has expired.', 401);
    }
    if (res.status === 419) throw new ApiError('Your security token expired — reload the page and try again.', 419);
    if (!res.ok || !data || data.ok === false) {
      throw new ApiError((data && data.error) || `Something went wrong (${res.status}).`, res.status, data);
    }
    return data;
  }
  const cleanParams = (p) => Object.fromEntries(Object.entries(p || {}).filter(([, v]) => v !== undefined && v !== null && v !== '' && v !== 0));
  const api = {
    get: (path, params) => {
      const qs = new URLSearchParams(cleanParams(params)).toString();
      return request('GET', '/mail/api/' + path + (qs ? '?' + qs : ''));
    },
    post: (path, body) => request('POST', '/mail/api/' + path, body || {}),
    upload(path, formData, onProgress) {
      return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/mail/api/' + path);
        xhr.setRequestHeader('X-CSRF-TOKEN', csrf());
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.onprogress = (e) => { if (e.lengthComputable && onProgress) onProgress(e.loaded / e.total); };
        xhr.onload = () => {
          let data = null;
          try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
          if (xhr.status === 413) return reject(new ApiError('That file is too large for the server to accept.', 413));
          if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) resolve(data);
          else reject(new ApiError((data && data.error) || 'Upload failed', xhr.status, data));
        };
        xhr.onerror = () => reject(new ApiError('Upload failed — check your connection.', 0));
        xhr.send(formData);
      });
    },
  };

  // ---------------------------------------------------------------- toasts
  function toast(message, opts = {}) {
    const host = $('#toasts') || (() => {
      const d = document.createElement('div');
      d.id = 'toasts'; d.className = 'fwm-toasts'; d.setAttribute('aria-live', 'polite');
      document.body.appendChild(d);
      return d;
    })();
    const el = document.createElement('div');
    el.className = 'fwm-toast' + (opts.type === 'error' ? ' fwm-toast--error' : '');
    el.setAttribute('role', opts.type === 'error' ? 'alert' : 'status');
    el.innerHTML = `<span class="fwm-toast__msg"></span>${opts.action ? `<button class="fwm-toast__action" type="button">${esc(opts.action.label)}</button>` : ''}<button class="fwm-toast__close" type="button" aria-label="Dismiss">${icon('x', 'i--sm')}</button>`;
    $('.fwm-toast__msg', el).textContent = message;
    host.appendChild(el);
    while (host.children.length > 3) host.firstElementChild.remove();
    let timer = null;
    const close = () => {
      if (!el.isConnected) return;
      clearTimeout(timer);
      el.classList.add('is-leaving');
      setTimeout(() => el.remove(), 200);
    };
    if (opts.action) $('.fwm-toast__action', el).addEventListener('click', () => { close(); opts.action.run(); });
    $('.fwm-toast__close', el).addEventListener('click', close);
    const ms = opts.timeout != null ? opts.timeout : (opts.type === 'error' ? 8000 : (opts.action ? 7000 : 4000));
    if (ms > 0) timer = setTimeout(close, ms);
    el.addEventListener('mouseenter', () => clearTimeout(timer));
    el.addEventListener('mouseleave', () => { if (ms > 0) timer = setTimeout(close, 2500); });
    return { close, el, set(msg) { $('.fwm-toast__msg', el).textContent = msg; } };
  }
  const toastError = (e) => toast(e && e.message ? e.message : String(e), { type: 'error' });

  // ---------------------------------------------------------------- formatting
  const fmt = {
    date(iso) { return iso ? new Date(iso) : null; },
    list(iso) {
      const d = fmt.date(iso); if (!d || isNaN(d)) return '';
      const now = new Date();
      const sameDay = d.toDateString() === now.toDateString();
      if (sameDay) return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      const diffDays = (now - d) / 86400000;
      if (diffDays < 6 && diffDays > 0) return d.toLocaleDateString([], { weekday: 'short' });
      if (d.getFullYear() === now.getFullYear()) return d.toLocaleDateString([], { day: 'numeric', month: 'short' });
      return d.toLocaleDateString([], { day: 'numeric', month: 'short', year: 'numeric' });
    },
    full(iso) {
      const d = fmt.date(iso); if (!d || isNaN(d)) return '';
      return d.toLocaleString([], { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    },
    when(iso) {
      const d = fmt.date(iso); if (!d || isNaN(d)) return '';
      const now = new Date();
      const tomorrow = new Date(now); tomorrow.setDate(now.getDate() + 1);
      const t = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      if (d.toDateString() === now.toDateString()) return 'today at ' + t;
      if (d.toDateString() === tomorrow.toDateString()) return 'tomorrow at ' + t;
      return d.toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' }) + ' at ' + t;
    },
    ago(iso) {
      const d = fmt.date(iso); if (!d || isNaN(d)) return 'never';
      const s = Math.round((Date.now() - d) / 1000);
      if (s < 45) return 'just now';
      if (s < 3600) return Math.round(s / 60) + ' min ago';
      if (s < 86400) return Math.round(s / 3600) + ' h ago';
      return fmt.list(iso);
    },
    size(n) {
      n = Number(n) || 0;
      if (n < 1024) return n + ' B';
      if (n < 1048576) return (n / 1024).toFixed(n < 10240 ? 1 : 0) + ' KB';
      return (n / 1048576).toFixed(1) + ' MB';
    },
  };

  function hueOf(s) {
    let h = 0;
    for (const ch of String(s || '?')) h = (h * 31 + ch.charCodeAt(0)) % 360;
    return h;
  }
  function initials(name, email) {
    const n = String(name || '').replace(/["'()<>]/g, '').trim();
    if (n && !n.includes('@')) {
      const parts = n.split(/\s+/).filter(Boolean);
      return ((parts[0] || '')[0] + (parts.length > 1 ? parts[parts.length - 1][0] : (parts[0] || '')[1] || '')).toUpperCase();
    }
    return String(email || n || '?').slice(0, 2).toUpperCase();
  }
  function avatar(name, email, cls = '') {
    return `<span class="fwm-avatar ${cls}" style="--h:${hueOf(email || name)}" aria-hidden="true">${esc(initials(name, email))}</span>`;
  }
  function fileKind(name, mime) {
    const ext = String(name || '').split('.').pop().toLowerCase();
    if (/^image\//.test(mime || '') || ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'heic'].includes(ext)) return ['img', ext.toUpperCase()];
    if (ext === 'pdf' || mime === 'application/pdf') return ['pdf', 'PDF'];
    if (['doc', 'docx', 'odt', 'rtf', 'txt', 'pages'].includes(ext)) return ['doc', ext.toUpperCase()];
    if (['xls', 'xlsx', 'csv', 'ods', 'numbers'].includes(ext)) return ['xls', ext.toUpperCase()];
    if (['zip', 'rar', '7z', 'gz', 'tar'].includes(ext)) return ['zip', ext.toUpperCase()];
    return ['', (ext || 'FILE').slice(0, 4).toUpperCase()];
  }

  // ---------------------------------------------------------------- popovers
  let activePop = null;
  function closePop() {
    if (activePop) { const p = activePop; activePop = null; p.el.remove(); if (p.onClose) p.onClose(); }
  }
  /**
   * popover(anchor, html, {onClick(el, ev), onClose, align, width})
   * Items are elements with [data-v]; clicking one calls onClick and closes
   * (unless onClick returns false).
   */
  function popover(anchor, html, opts = {}) {
    closePop();
    const el = document.createElement('div');
    el.className = 'fwm-pop';
    el.setAttribute('role', 'menu');
    if (opts.width) el.style.minWidth = opts.width + 'px';
    el.innerHTML = html;
    (document.querySelector('.fwm') || document.body).appendChild(el);
    const r = anchor.getBoundingClientRect();
    const pw = el.offsetWidth, ph = el.offsetHeight;
    let left = opts.align === 'end' ? r.right - pw : r.left;
    left = Math.max(8, Math.min(left, window.innerWidth - pw - 8));
    let top = r.bottom + 6;
    if (top + ph > window.innerHeight - 8) top = Math.max(8, r.top - ph - 6);
    el.style.left = left + 'px';
    el.style.top = top + 'px';
    const pop = { el, onClose: opts.onClose, anchor };
    activePop = pop;
    el.addEventListener('click', (ev) => {
      const item = ev.target.closest('[data-v]');
      if (!item || !el.contains(item) || item.disabled) return;
      const keep = opts.onClick && opts.onClick(item, ev) === false;
      if (!keep) closePop();
    });
    el.addEventListener('keydown', (ev) => {
      const items = $$('[data-v]:not([disabled])', el);
      const i = items.indexOf(document.activeElement);
      if (ev.key === 'ArrowDown') { ev.preventDefault(); (items[i + 1] || items[0]).focus(); }
      if (ev.key === 'ArrowUp') { ev.preventDefault(); (items[i - 1] || items[items.length - 1]).focus(); }
      if (ev.key === 'Escape') { ev.preventDefault(); closePop(); anchor.focus && anchor.focus(); }
    });
    const first = $('input', el) || $('[data-v]', el);
    if (first && !opts.noFocus) setTimeout(() => first.focus(), 0);
    return pop;
  }
  document.addEventListener('mousedown', (e) => {
    if (activePop && !activePop.el.contains(e.target) && !(activePop.anchor && activePop.anchor.contains(e.target))) closePop();
  });
  window.addEventListener('resize', closePop);

  // ---------------------------------------------------------------- dialogs
  let lastFocus = null;
  function openModal(id) {
    const m = typeof id === 'string' ? document.getElementById(id) : id;
    if (!m) return null;
    closePop();
    lastFocus = document.activeElement;
    m.hidden = false;
    const f = $('input:not([type=hidden]), select, textarea, button.btn--primary', m);
    setTimeout(() => f && f.focus(), 30);
    return m;
  }
  function closeModal(id) {
    const m = typeof id === 'string' ? document.getElementById(id) : id;
    if (!m || m.hidden) return;
    m.hidden = true;
    if (m.dataset.temp) m.remove();
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  document.addEventListener('click', (e) => {
    const closer = e.target.closest('[data-close]');
    if (closer) { closeModal(closer.closest('.fwm-modal')); return; }
    if (e.target.classList && e.target.classList.contains('fwm-modal')) closeModal(e.target);
  });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    const open = $$('.fwm-modal:not([hidden])').pop();
    if (open) { e.preventDefault(); e.stopPropagation(); closeModal(open); }
  }, true);

  /** Promise-based dialog built on the fly. */
  function dialog({ title, body = '', confirm = 'OK', cancel = 'Cancel', danger = false, input = null }) {
    return new Promise((resolve) => {
      const m = document.createElement('div');
      m.className = 'fwm-modal'; m.dataset.temp = '1'; m.hidden = true;
      m.innerHTML = `<div class="fwm-dialog" role="dialog" aria-modal="true" style="width:min(440px,100%)">
        <div class="fwm-dialog__head"><h2>${esc(title)}</h2></div>
        <div class="fwm-dialog__body">${body ? `<p class="fwm-dialog__lead" style="margin-bottom:${input ? 14 : 0}px">${body}</p>` : ''}
          ${input ? `<input class="input" maxlength="${input.max || 200}" placeholder="${esc(input.placeholder || '')}" value="${esc(input.value || '')}">` : ''}</div>
        <div class="fwm-dialog__foot">${cancel ? `<button class="btn" data-act="no">${esc(cancel)}</button>` : ''}
          <button class="btn btn--primary ${danger ? 'btn--danger-solid' : ''}" data-act="yes">${esc(confirm)}</button></div></div>`;
      document.body.appendChild(m);
      if (danger) $('[data-act=yes]', m).style.background = 'var(--danger)';
      const inp = $('input', m);
      const done = (v) => { observer.disconnect(); closeModal(m); resolve(v); };
      m.addEventListener('click', (e) => {
        const b = e.target.closest('[data-act]');
        if (b) done(b.dataset.act === 'yes' ? (inp ? inp.value.trim() : true) : (inp ? null : false));
      });
      if (inp) inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); done(inp.value.trim()); } });
      // Esc / backdrop close → resolve as cancelled.
      const observer = new MutationObserver(() => { if (!m.isConnected || m.hidden) { observer.disconnect(); resolve(inp ? null : false); } });
      observer.observe(document.body, { childList: true });
      openModal(m);
      if (inp) setTimeout(() => { inp.focus(); inp.select(); }, 40);
    });
  }

  // ---------------------------------------------------------------- chrome
  function initChrome() {
    const body = document.body;
    const themeBtn = $('#themeToggle');
    const applyTheme = (t) => {
      body.dataset.theme = t;
      const meta = $('meta[name="theme-color"]');
      if (meta) meta.content = t === 'dark' ? '#0d0a09' : '#f2ece3';
      if (themeBtn) {
        themeBtn.innerHTML = icon(t === 'dark' ? 'sun' : 'moon');
        themeBtn.setAttribute('data-tip', t === 'dark' ? 'Light theme' : 'Dark theme');
      }
      $$('iframe[data-fwm-frame]').forEach((f) => f.dispatchEvent(new Event('fwm-theme')));
    };
    applyTheme(body.dataset.theme === 'light' ? 'light' : 'dark');
    if (themeBtn) themeBtn.addEventListener('click', () => {
      const t = body.dataset.theme === 'dark' ? 'light' : 'dark';
      applyTheme(t);
      document.cookie = 'fw_theme=' + t + ';max-age=' + (365 * 86400) + ';path=/;SameSite=Lax';
    });

    const kebab = $('#kebabBtn');
    if (kebab) kebab.addEventListener('click', () => {
      const onInbox = !!$('#mailApp');
      popover(kebab, `
        ${onInbox ? '' : `<a class="fwm-pop__item" data-v="inbox" href="/mail/">${icon('inbox')}Inbox</a>`}
        <a class="fwm-pop__item" data-v="tpl" href="/mail/templates.php">${icon('template')}Templates</a>
        <a class="fwm-pop__item" data-v="settings" href="/mail/settings.php">${icon('settings')}Mail settings</a>
        ${onInbox ? `<button class="fwm-pop__item" data-v="keys">${icon('keyboard')}Keyboard shortcuts <small>?</small></button>` : ''}
        ${kebab.dataset.admin === '1' ? `<a class="fwm-pop__item" data-v="admin" href="/admin/mail.php">${icon('shield')}Company mail settings</a>` : ''}
        <div class="fwm-pop__sep"></div>
        <a class="fwm-pop__item" data-v="home" href="/home.php">${icon('home')}Flowwork home</a>`, {
        align: 'end',
        onClick: (it) => { if (it.dataset.v === 'keys') openModal('shortcutsModal'); },
      });
    });

    const drawerBtn = $('#drawerBtn');
    if (drawerBtn) drawerBtn.addEventListener('click', () => toggleDrawer(true));
  }
  function toggleDrawer(open) {
    const body = document.body;
    body.classList.toggle('drawer-open', open);
    let scrim = $('.fwm-scrim');
    if (open && !scrim) {
      scrim = document.createElement('div');
      scrim.className = 'fwm-scrim';
      scrim.addEventListener('click', () => toggleDrawer(false));
      body.appendChild(scrim);
    } else if (!open && scrim) scrim.remove();
  }

  // ===========================================================================
  // Rich-text editor (composer, templates, signatures)
  // ===========================================================================
  const PASTE_TAGS = new Set(['P', 'BR', 'B', 'STRONG', 'I', 'EM', 'U', 'S', 'STRIKE', 'A', 'UL', 'OL', 'LI', 'BLOCKQUOTE', 'DIV', 'SPAN', 'H1', 'H2', 'H3', 'H4', 'TABLE', 'THEAD', 'TBODY', 'TR', 'TD', 'TH', 'IMG', 'PRE', 'CODE', 'HR', 'SUB', 'SUP']);
  function sanitizePaste(html) {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const walk = (node) => {
      for (const child of Array.from(node.childNodes)) {
        if (child.nodeType === 8) { child.remove(); continue; }
        if (child.nodeType !== 1) continue;
        const tag = child.tagName;
        if (['SCRIPT', 'STYLE', 'META', 'LINK', 'TITLE', 'IFRAME', 'OBJECT', 'EMBED', 'SVG', 'FORM', 'INPUT', 'BUTTON', 'TEXTAREA', 'SELECT', 'XML'].includes(tag) || tag.includes(':')) {
          child.remove(); continue;
        }
        walk(child);
        if (!PASTE_TAGS.has(tag)) {
          while (child.firstChild) node.insertBefore(child.firstChild, child);
          child.remove(); continue;
        }
        for (const attr of Array.from(child.attributes)) {
          const n = attr.name.toLowerCase();
          const keep = (n === 'href' && tag === 'A' && /^(https?:|mailto:|tel:)/i.test(attr.value.trim()))
            || (n === 'src' && tag === 'IMG' && /^(https:|data:image\/(png|jpe?g|gif|webp);)/i.test(attr.value.trim()))
            || (['colspan', 'rowspan', 'alt'].includes(n));
          if (!keep) child.removeAttribute(attr.name);
        }
        if (tag === 'A') { child.setAttribute('target', '_blank'); child.setAttribute('rel', 'noopener noreferrer'); }
      }
    };
    walk(doc.body);
    return doc.body.innerHTML;
  }

  const Editor = {
    create(host, opts = {}) {
      host.innerHTML = `<div class="fwm-editor ${opts.boxed ? 'fwm-editor--boxed' : ''}">
        <div class="fwm-editor__tools" role="toolbar" aria-label="Formatting">
          <button type="button" class="icon-btn" data-cmd="bold" aria-label="Bold" data-tip="Bold">${icon('bold')}</button>
          <button type="button" class="icon-btn" data-cmd="italic" aria-label="Italic" data-tip="Italic">${icon('italic')}</button>
          <button type="button" class="icon-btn" data-cmd="underline" aria-label="Underline" data-tip="Underline">${icon('underline')}</button>
          <button type="button" class="icon-btn" data-cmd="strikeThrough" aria-label="Strikethrough" data-tip="Strikethrough">${icon('strike')}</button>
          <span class="sep"></span>
          <button type="button" class="icon-btn" data-cmd="insertUnorderedList" aria-label="Bulleted list" data-tip="Bullets">${icon('list')}</button>
          <button type="button" class="icon-btn" data-cmd="insertOrderedList" aria-label="Numbered list" data-tip="Numbering">${icon('list-num')}</button>
          <button type="button" class="icon-btn" data-cmd="quote" aria-label="Quote" data-tip="Quote">${icon('quote')}</button>
          <span class="sep"></span>
          <button type="button" class="icon-btn" data-cmd="link" aria-label="Insert link" data-tip="Link (Ctrl K)">${icon('link')}</button>
          <button type="button" class="icon-btn" data-cmd="removeFormat" aria-label="Clear formatting" data-tip="Clear formatting">${icon('eraser')}</button>
          ${opts.extraTools || ''}
        </div>
        <div class="fwm-editor__area scroll" contenteditable="true" role="textbox" aria-multiline="true" spellcheck="true"></div>
      </div>`;
      const area = $('.fwm-editor__area', host);
      const tools = $('.fwm-editor__tools', host);
      area.dataset.placeholder = opts.placeholder || '';
      area.innerHTML = opts.html || '';
      try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) { /* old browsers */ }

      const changed = () => opts.onInput && opts.onInput();
      let savedRange = null;
      const saveRange = () => {
        const sel = window.getSelection();
        if (sel.rangeCount && area.contains(sel.anchorNode)) savedRange = sel.getRangeAt(0).cloneRange();
      };
      const restoreRange = () => {
        area.focus();
        if (savedRange) { const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(savedRange); }
      };
      area.addEventListener('keyup', saveRange);
      area.addEventListener('mouseup', saveRange);
      area.addEventListener('blur', saveRange);

      const insertLink = () => {
        saveRange();
        const sel = window.getSelection();
        const selectedText = sel && sel.toString();
        const anchorBtn = $('[data-cmd=link]', tools);
        popover(anchorBtn, `<div style="padding:6px;width:300px">
            <input class="fwm-pop__input" id="fwmLinkUrl" placeholder="https://example.com" value="">
            ${selectedText ? '' : '<input class="fwm-pop__input" id="fwmLinkText" placeholder="Text to show (optional)">'}
            <div style="display:flex;justify-content:flex-end;gap:6px;margin-top:4px">
              <button class="btn btn--sm btn--primary" data-v="ok" type="button">Insert link</button></div></div>`, {
          onClick: (it) => {
            if (it.dataset.v !== 'ok') return;
            let url = ($('#fwmLinkUrl') || {}).value || '';
            url = url.trim();
            if (!url) return;
            if (!/^(https?:|mailto:|tel:)/i.test(url)) url = (url.includes('@') && !url.includes('/') ? 'mailto:' : 'https://') + url;
            const text = (($('#fwmLinkText') || {}).value || '').trim();
            restoreRange();
            if (selectedText) document.execCommand('createLink', false, url);
            else document.execCommand('insertHTML', false, `<a href="${esc(url)}" target="_blank" rel="noopener noreferrer">${esc(text || url)}</a>&nbsp;`);
            changed();
          },
        });
        setTimeout(() => {
          const u = $('#fwmLinkUrl');
          if (u) u.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); $('.fwm-pop [data-v=ok]').click(); } });
        }, 0);
      };

      tools.addEventListener('mousedown', (e) => { if (e.target.closest('[data-cmd]')) e.preventDefault(); });
      tools.addEventListener('click', (e) => {
        const b = e.target.closest('[data-cmd]');
        if (!b) return;
        const cmd = b.dataset.cmd;
        area.focus();
        if (cmd === 'link') return insertLink();
        if (cmd === 'quote') {
          const inQuote = document.queryCommandValue('formatBlock').toLowerCase() === 'blockquote';
          document.execCommand('formatBlock', false, inQuote ? 'p' : 'blockquote');
        } else {
          document.execCommand(cmd, false, null);
        }
        changed();
      });
      area.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); insertLink(); }
      });
      area.addEventListener('input', changed);
      area.addEventListener('paste', (e) => {
        const cd = e.clipboardData;
        if (!cd) return;
        const files = Array.from(cd.files || []).filter((f) => /^image\/(png|jpe?g|gif|webp)$/.test(f.type));
        if (files.length) {
          e.preventDefault();
          files.forEach((f) => {
            if (f.size > 3 * 1024 * 1024) { toast('Pasted images must be under 3 MB — attach larger files instead.', { type: 'error' }); return; }
            const r = new FileReader();
            r.onload = () => { document.execCommand('insertHTML', false, `<img src="${r.result}" alt="">`); changed(); };
            r.readAsDataURL(f);
          });
          return;
        }
        const html = cd.getData('text/html');
        e.preventDefault();
        if (html) document.execCommand('insertHTML', false, sanitizePaste(html));
        else document.execCommand('insertText', false, cd.getData('text/plain'));
        changed();
      });
      area.addEventListener('click', (e) => {
        const a = e.target.closest('a');
        if (a && (e.ctrlKey || e.metaKey)) window.open(a.href, '_blank', 'noopener');
      });

      return {
        root: host, area, tools,
        getHTML: () => area.innerHTML,
        setHTML: (h) => { area.innerHTML = h || ''; },
        focus(atStart) {
          area.focus();
          if (atStart) {
            const r = document.createRange();
            r.setStart(area, 0); r.collapse(true);
            const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(r);
          }
        },
        insertHTML(h) { restoreRange(); document.execCommand('insertHTML', false, h); changed(); },
        textLength: () => (area.innerText || '').trim().length,
      };
    },
  };

  // Expose shared helpers for the settings / templates pages.
  window.FWMail = { $, $$, esc, icon, api, ApiError, toast, toastError, fmt, avatar, popover, closePop, openModal, closeModal, dialog, Editor, debounce, store };

  initChrome();
  if (!$('#mailApp')) return;

  // ===========================================================================
  // Inbox app
  // ===========================================================================
  const VIEWS = [
    { id: 'inbox', label: 'Inbox', icon: 'inbox', count: 'inbox' },
    { id: 'starred', label: 'Starred', icon: 'star' },
    { id: 'snoozed', label: 'Snoozed', icon: 'clock', count: 'snoozed' },
    { id: 'drafts', label: 'Drafts', icon: 'draft', count: 'drafts' },
    { id: 'outbox', label: 'Scheduled', icon: 'scheduled', count: 'outbox' },
    { id: 'sent', label: 'Sent', icon: 'send' },
    { id: 'archive', label: 'Archive', icon: 'archive' },
    { id: 'spam', label: 'Spam', icon: 'spam', count: 'spam' },
    { id: 'trash', label: 'Trash', icon: 'trash' },
    { id: 'all', label: 'All mail', icon: 'all' },
  ];
  const viewInfo = (id) => VIEWS.find((v) => v.id === id) || VIEWS[0];
  const LABEL_COLORS = ['#7b1e2b', '#b42318', '#c08a2b', '#6b7d2b', '#2f7d5b', '#1f7a7a', '#2c5f8a', '#4b4fa8', '#7a5ab8', '#b4577a', '#5b6670', '#8a5a3c'];

  const S = {
    accounts: [], labels: [], prefs: {}, signatures: [], templates: [], views: [], counts: {}, me: {},
    view: 'inbox', labelId: 0, accountId: store.get('account', 0), q: '', filter: '',
    threads: [], total: 0, hasMore: false, loading: false, reqId: 0,
    selected: new Set(), lastClickedIdx: -1, focusIdx: -1,
    openKey: null, thread: null, threadReq: 0,
    latestId: 0, lastSyncAt: 0, syncing: false, undo: null,
  };
  const el = {
    side: $('#side'), navFolders: $('#navFolders'), navLabels: $('#navLabels'), navViews: $('#navViews'), viewsHead: $('#viewsHead'),
    sideFoot: $('#sideFoot'), threads: $('#threads'), reader: $('#readerPane'), listTitle: $('#listTitle'), listSub: $('#listSub'), listEyebrow: $('#listEyebrow'),
    bulkBar: $('#bulkBar'), bulkCount: $('#bulkCount'), bulkActions: $('#bulkActions'), listHead: $('#listHead'),
    filters: $('#filterChips'), search: $('#searchInput'), searchClear: $('#searchClear'), syncBtn: $('#syncBtn'), syncDot: $('#syncDot'),
    selectAll: $('#selectAll'), saveSearch: $('#saveSearchBtn'),
  };
  const narrow = () => window.matchMedia('(max-width: 820px)').matches;
  const accountById = (id) => S.accounts.find((a) => a.account_id === Number(id));
  const labelById = (id) => S.labels.find((l) => l.tag_id === Number(id));

  // ---------------------------------------------------------------- boot
  async function boot() {
    applyPrefs();
    renderListSkeleton();
    try {
      const b = await api.get('bootstrap.php');
      Object.assign(S, { accounts: b.accounts, labels: b.labels, prefs: b.prefs, signatures: b.signatures, templates: b.templates, views: b.views, counts: b.counts, me: b.me });
      S.latestId = b.counts.latest_id || 0;
    } catch (e) {
      el.threads.innerHTML = emptyState('alert', 'Mail could not load', esc(e.message), '<button class="btn" onclick="location.reload()">Try again</button>');
      return;
    }
    applyPrefs();
    if (S.accountId && !accountById(S.accountId)) S.accountId = 0;

    if (!S.accounts.length) return renderOnboarding();

    renderNav();
    if (!S.openKey) readerEmpty();
    readRoute();
    handleQueryParams();
    window.addEventListener('hashchange', readRoute);
    startBackground();
  }

  function applyPrefs() {
    const p = S.prefs || {};
    document.body.classList.toggle('is-compact', p.density === 'compact');
    document.body.classList.toggle('pane-off', p.reading_pane === 'off');
    document.body.classList.toggle('no-snippets', p.show_snippets === false);
  }

  function renderOnboarding() {
    $('#composeBtn').hidden = true;
    $('#fabCompose').hidden = true;
    el.filters.hidden = true;
    el.listHead.hidden = true;
    el.navFolders.innerHTML = VIEWS.slice(0, 6).map((v) => `<span class="fwm-nav__item" aria-disabled="true">${icon(v.icon)}<span class="fwm-nav__label">${v.label}</span></span>`).join('');
    $$('.fwm-nav--labels').forEach((n) => { n.hidden = true; });
    el.threads.innerHTML = emptyState('letter', 'Connect your mailbox',
      'Add your work email (IMAP/SMTP — Gmail, Microsoft 365, cPanel, Xneelo and most hosts) to read, send and file mail right inside Flowwork.',
      '<a class="btn btn--primary btn--lg" href="/mail/settings.php#accounts/new">' + icon('plus') + 'Connect an account</a>');
    el.reader.innerHTML = emptyState('shield', 'Private by design',
      'Your password is encrypted at rest, remote images are blocked until you allow them, and every message is shown in a locked-down frame.', '');
  }

  // ---------------------------------------------------------------- routing
  // Hash routes: #inbox · #label/12 · #search?q=… — plus &open=t123 / ?open=t123
  function routeHash(openKey) {
    let h = S.q ? '#search?q=' + encodeURIComponent(S.q) : S.labelId ? '#label/' + S.labelId : '#' + S.view;
    if (openKey) h += (h.includes('?') ? '&' : '?') + 'open=' + openKey;
    return h;
  }
  function go(spec, { replace = false } = {}) {
    S.view = spec.view || 'inbox';
    S.labelId = spec.labelId || 0;
    S.q = spec.q || '';
    const hash = routeHash(spec.open);
    if (location.hash !== hash) {
      if (replace) history.replaceState(null, '', hash); else location.hash = hash;
    }
    readRoute(true);
  }
  let lastListSig = '';
  function readRoute(force) {
    const h = location.hash.replace(/^#/, '');
    const qi = h.indexOf('?');
    const base = qi >= 0 ? h.slice(0, qi) : h;
    const params = new URLSearchParams(qi >= 0 ? h.slice(qi + 1) : '');
    let open = params.get('open');
    if (open && !/^[te]\d+$/.test(open)) open = null;
    if (base === 'search') { S.q = params.get('q') || ''; S.labelId = 0; S.view = 'all'; }
    else if (base.startsWith('label/')) { S.labelId = parseInt(base.slice(6), 10) || 0; S.q = ''; S.view = 'all'; }
    else { S.view = VIEWS.some((v) => v.id === base) ? base : 'inbox'; S.labelId = 0; S.q = ''; }
    if (el.search) { el.search.value = S.q; el.searchClear.hidden = !S.q; }

    const sig = [S.view, S.labelId, S.q, S.accountId, S.filter].join('|');
    if (sig !== lastListSig || force === true) {
      lastListSig = sig;
      S.selected.clear();
      S.focusIdx = -1;
      renderNav();
      loadThreads();
    }
    if (open && open !== S.openKey) openThread(open, { fromRoute: true });
    if (!open && S.openKey) { S.pushedOpen = false; closeReader({ fromRoute: true }); }
  }
  // On phones the reader is a separate screen, so opening pushes a history entry
  // and the device Back button returns to the list.
  function setOpenInHash(key) {
    const hash = routeHash(key);
    if (location.hash === hash) return;
    if (key && narrow() && !S.pushedOpen) { S.pushedOpen = true; history.pushState(null, '', hash); }
    else history.replaceState(null, '', hash);
  }

  function handleQueryParams() {
    const p = new URLSearchParams(location.search);
    if (!p.toString()) return;
    const open = p.get('open');
    const compose = p.get('compose');
    history.replaceState(null, '', location.pathname + location.hash);
    if (open && /^[te]\d+$/.test(open)) openThread(open);
    if (compose) {
      const params = { mode: compose };
      ['email_id', 'template_id', 'to', 'subject', 'key'].forEach((k) => { if (p.get(k)) params[k] = p.get(k); });
      Composer.openFrom(params);
    }
  }

  // ---------------------------------------------------------------- sidebar
  function renderNav() {
    if (!S.accounts.length) return;
    refreshGreeting();
    const c = S.counts || {};
    const acctSwitch = S.accounts.length > 1 ? (() => {
      const a = accountById(S.accountId);
      return `<button class="fwm-acct-switch" id="acctSwitch" type="button">${a ? avatar(a.display_name || a.account_name, a.email_address) : icon('inbox')}<span>${a ? esc(a.email_address) : 'All inboxes'}</span>${icon('chev-down', 'i--sm')}</button>`;
    })() : '';
    el.navFolders.innerHTML = acctSwitch + VIEWS.map((v) => {
      const n = v.count ? (v.count === 'inbox' && S.accountId ? (c.by_account || {})[S.accountId] || 0 : c[v.count] || 0) : 0;
      const active = !S.q && !S.labelId && S.view === v.id;
      return `<a class="fwm-nav__item ${active ? 'is-active' : ''}" href="#${v.id}" data-view="${v.id}" ${active ? 'aria-current="page"' : ''}>
        ${icon(v.icon)}<span class="fwm-nav__label">${v.label}</span><span class="fwm-nav__count">${n > 0 ? n : ''}</span></a>`;
    }).join('');

    el.navLabels.innerHTML = S.labels.length ? S.labels.map((l) => {
      const n = (c.by_label || {})[l.tag_id] || 0;
      const active = S.labelId === l.tag_id;
      return `<a class="fwm-nav__item ${active ? 'is-active' : ''}" href="#label/${l.tag_id}" data-label="${l.tag_id}">
        <span class="fwm-nav__dot" style="background:${esc(l.color)}"></span><span class="fwm-nav__label">${esc(l.name)}</span><span class="fwm-nav__count">${n > 0 ? n : ''}</span></a>`;
    }).join('') : '<div class="fwm-nav__empty">No labels yet</div>';

    el.viewsHead.hidden = !S.views.length;
    el.navViews.innerHTML = S.views.map((v) => `<a class="fwm-nav__item" href="#" data-saved="${v.id}">${icon('bookmark')}<span class="fwm-nav__label">${esc(v.name)}</span>
        <button class="icon-btn icon-btn--sm" data-del-view="${v.id}" aria-label="Remove saved search" style="margin-right:-6px">${icon('x', 'i--xs')}</button></a>`).join('');

    renderSideFoot();
    updateTitle();
  }

  function renderSideFoot() {
    const broken = S.accounts.filter((a) => a.is_active && a.last_error);
    const last = S.accounts.map((a) => a.last_sync_at).filter(Boolean).sort().pop();
    el.sideFoot.innerHTML = (broken.length ? `<div class="fwm-side__warn">${icon('alert', 'i--sm')}<span>${broken.length === 1 ? esc(broken[0].email_address) + ' can\'t sync.' : broken.length + ' accounts can\'t sync.'} <a href="/mail/settings.php#accounts">Fix</a></span></div>` : '')
      + `<span>${S.syncing ? 'Checking for mail…' : 'Synced ' + esc(fmt.ago(last))}</span>`;
    if (el.syncDot) el.syncDot.hidden = !broken.length;
  }

  function updateTitle() {
    const n = (S.counts || {}).inbox || 0;
    const name = S.q ? 'Search' : S.labelId ? ((labelById(S.labelId) || {}).name || 'Label') : viewInfo(S.view).label;
    document.title = (n ? `(${n}) ` : '') + name + ' · Mail';
  }

  el.side.addEventListener('click', (e) => {
    const item = e.target.closest('.fwm-nav__item');
    const del = e.target.closest('[data-del-view]');
    if (del) {
      e.preventDefault(); e.stopPropagation();
      api.post('view.php', { op: 'delete', id: Number(del.dataset.delView) }).then((r) => { S.views = r.views; renderNav(); }).catch(toastError);
      return;
    }
    if (e.target.closest('#acctSwitch')) return accountMenu(e.target.closest('#acctSwitch'));
    if (!item) return;
    if (narrow()) toggleDrawer(false);
    if (item.dataset.saved) {
      e.preventDefault();
      const v = S.views.find((x) => x.id === Number(item.dataset.saved));
      if (!v) return;
      const f = v.filters || {};
      S.filter = f.filter || '';
      syncFilterChips();
      if (f.account_id && accountById(f.account_id)) { S.accountId = f.account_id; store.set('account', S.accountId); }
      go({ view: f.view || 'all', labelId: f.label_id || 0, q: f.q || '' });
      return;
    }
    if (item.dataset.view || item.dataset.label) {
      e.preventDefault();
      const target = item.getAttribute('href');
      if (location.hash === target) { loadThreads(); return; } // same folder → refresh
      S.openKey = null; S.thread = null; S.pushedOpen = false;
      document.body.classList.remove('is-reading');
      readerEmpty();
      if (item.dataset.label) go({ view: 'all', labelId: Number(item.dataset.label) });
      else go({ view: item.dataset.view });
    }
  });
  function accountMenu(anchor) {
    const c = S.counts || {};
    popover(anchor, `<div class="fwm-pop__title">Show mail from</div>
      <button class="fwm-pop__item ${!S.accountId ? 'is-active' : ''}" data-v="0">${icon('inbox')}All inboxes<small>${c.inbox || ''}</small></button>
      ${S.accounts.map((a) => `<button class="fwm-pop__item ${S.accountId === a.account_id ? 'is-active' : ''}" data-v="${a.account_id}">${avatar(a.display_name || a.account_name, a.email_address)}<span style="min-width:0;overflow:hidden;text-overflow:ellipsis">${esc(a.email_address)}</span><small>${(c.by_account || {})[a.account_id] || ''}</small></button>`).join('')}`, {
      width: 280,
      onClick: (it) => {
        S.accountId = Number(it.dataset.v) || 0;
        store.set('account', S.accountId);
        closeReader({ silent: true });
        readRoute(true);
      },
    });
  }
  $('#newLabelBtn').addEventListener('click', () => createLabel());

  // ---------------------------------------------------------------- list
  function renderListSkeleton() {
    el.threads.innerHTML = Array.from({ length: 7 }, () => `<div class="fwm-skel"><div class="fwm-skel__a"></div><div><div class="fwm-skel__l" style="width:${40 + Math.random() * 30}%"></div><div class="fwm-skel__l" style="width:${60 + Math.random() * 30}%"></div><div class="fwm-skel__l" style="width:${30 + Math.random() * 40}%;margin-bottom:0"></div></div></div>`).join('');
  }
  // A sealed letter, drawn inline so it follows the theme colours.
  const LETTER_ART = `<svg class="fwm-art" viewBox="0 0 190 132" aria-hidden="true">
      <ellipse class="a-shadow" cx="95" cy="124" rx="62" ry="5"/>
      <g class="a-float">
        <rect class="a-paper-2" x="50" y="8" width="90" height="70" rx="4" transform="rotate(-6 95 43)"/>
        <path class="a-lines" d="M64 26h44M64 36h58M64 46h36" transform="rotate(-6 95 43)"/>
        <rect class="a-paper" x="30" y="40" width="130" height="80" rx="7"/>
        <path class="a-fold" d="M32 117 82 78M158 117 108 78"/>
        <path class="a-paper" d="M31 43 95 88 159 43Z"/>
        <circle class="a-seal" cx="95" cy="86" r="14"/>
        <circle class="a-seal-hi" cx="91" cy="81" r="6"/>
        <circle class="a-seal-ring" cx="95" cy="86" r="9.5"/>
        <path class="a-gold" d="M95 79.5 96.6 84.4 101.5 86 96.6 87.6 95 92.5 93.4 87.6 88.5 86 93.4 84.4Z"/>
      </g>
      <path class="a-spark" d="M22 30l1.6 4.4L28 36l-4.4 1.6L22 42l-1.6-4.4L16 36l4.4-1.6Z"/>
      <path class="a-spark" d="M170 16l1.2 3.3 3.3 1.2-3.3 1.2-1.2 3.3-1.2-3.3-3.3-1.2 3.3-1.2Z"/>
      <path class="a-spark" d="M176 72l1 2.6 2.6 1-2.6 1-1 2.6-1-2.6-2.6-1 2.6-1Z"/>
    </svg>`;
  function emptyState(ic, title, text, actions, opts = {}) {
    const art = ic === 'letter' ? `<div class="fwm-empty__art fwm-empty__art--letter">${LETTER_ART}</div>` : `<div class="fwm-empty__art">${icon(ic)}</div>`;
    return `<div class="fwm-empty">${art}${opts.eyebrow ? `<div class="fwm-empty__eyebrow">${esc(opts.eyebrow)}</div>` : ''}<h3>${opts.titleHtml || esc(title)}</h3>${text ? `<p>${text}</p>` : ''}${actions || ''}</div>`;
  }
  function todayLabel() {
    return new Date().toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long' });
  }

  async function loadThreads({ append = false, silent = false } = {}) {
    const id = ++S.reqId;
    if (!append && !silent) { renderListSkeleton(); renderListHead(); }
    S.loading = true;
    try {
      const r = await api.get('threads.php', {
        view: S.view, account_id: S.accountId, label_id: S.labelId, q: S.q, filter: S.filter,
        offset: append ? S.threads.length : 0,
      });
      if (id !== S.reqId) return;
      S.threads = append ? S.threads.concat(r.threads) : r.threads;
      if (r.total != null) S.total = r.total;
      S.hasMore = r.has_more;
      if (!append && !silent) el.threads.scrollTop = 0;
      renderList();
      if (!append && !silent) {
        el.threads.classList.add('is-entering');
        clearTimeout(S.enterTimer);
        S.enterTimer = setTimeout(() => el.threads.classList.remove('is-entering'), 1100);
      }
    } catch (e) {
      if (id !== S.reqId) return;
      if (!append) el.threads.innerHTML = emptyState('alert', 'Couldn\'t load this folder', esc(e.message), '<button class="btn" id="retryList">Try again</button>');
      const b = $('#retryList'); if (b) b.addEventListener('click', () => loadThreads());
    } finally {
      if (id === S.reqId) S.loading = false;
    }
  }

  function renderListHead() {
    const lbl = S.labelId ? labelById(S.labelId) : null;
    el.listTitle.textContent = S.q ? 'Search results' : lbl ? lbl.name : viewInfo(S.view).label;
    let sub = '';
    if (S.q) sub = `“${S.q}”`;
    else if (S.total) {
      sub = S.total === 1 ? '1 conversation' : S.total.toLocaleString() + ' conversations';
      const unread = S.view === 'inbox' ? (S.accountId ? (S.counts.by_account || {})[S.accountId] : S.counts.inbox) : 0;
      if (unread) sub += ` · ${unread} unread`;
    }
    el.listSub.innerHTML = sub ? esc(sub) : '&nbsp;';
    refreshGreeting();
    if (el.listEyebrow) {
      const acct = S.accountId ? S.accounts.find((a) => a.account_id === S.accountId) : null;
      el.listEyebrow.textContent = S.q ? 'Search' : lbl ? 'Label' : acct ? acct.email_address : S.view === 'inbox' ? todayLabel() : 'All mailboxes';
    }
    el.saveSearch.hidden = !S.q;
    updateTitle();
  }

  function rowHTML(t, idx) {
    const isDraftView = S.view === 'drafts';
    const isOutbox = S.view === 'outbox';
    const isSentish = S.view === 'sent' || isDraftView || isOutbox;
    const people = t.participants.length ? t.participants : ['(unknown)'];
    let from = isSentish ? 'To: ' + people.join(', ') : people.join(', ');
    const draftTag = t.has_draft && !isDraftView ? ' <span class="is-draft">Draft</span>' : '';
    const unread = t.unread > 0;
    const active = t.key === S.openKey;
    const selected = S.selected.has(t.key);
    const avatarName = isSentish ? people[0] : people[people.length - 1];
    const avatarEmail = isSentish ? people[0] : (t.from_email || avatarName);
    const meta = [];
    if (t.send_status === 'failed') meta.push(`<span class="fwm-tag fwm-tag--danger">${icon('alert', 'i--xs')}Not sent</span>`);
    else if (isOutbox && t.scheduled_at) meta.push(`<span class="fwm-tag fwm-tag--plain">${icon('scheduled', 'i--xs')}${esc(fmt.when(t.scheduled_at))}</span>`);
    if (S.view === 'snoozed' && t.snoozed_until) meta.push(`<span class="fwm-tag fwm-tag--warn">${icon('clock', 'i--xs')}Until ${esc(fmt.when(t.snoozed_until))}</span>`);
    if (t.reply_due_at) {
      const overdue = new Date(t.reply_due_at) < new Date();
      meta.push(`<span class="fwm-tag ${overdue ? 'fwm-tag--danger' : 'fwm-tag--warn'}">${icon('clock', 'i--xs')}${overdue ? 'Reply overdue' : 'Reply by ' + esc(fmt.when(t.reply_due_at))}</span>`);
    }
    t.labels.slice(0, 3).forEach((l) => meta.push(`<span class="fwm-tag" style="--c:${esc(l.color)}">${esc(l.name)}</span>`));
    if (t.attachments) meta.push(`<span class="fwm-row__icon" title="Has attachments">${icon('clip', 'i--sm')}</span>`);

    const hover = rowHoverActions();
    return `<div class="fwm-row ${unread ? 'is-unread' : ''} ${active ? 'is-active' : ''} ${selected ? 'is-selected' : ''} ${idx === S.focusIdx ? 'is-focus' : ''}"
        role="option" aria-selected="${active}" data-key="${t.key}" data-idx="${idx}" style="--i:${Math.min(idx, 16)}">
      <div class="fwm-row__lead">
        ${avatar(avatarName, avatarEmail, isSentish ? 'fwm-avatar--me' : '')}
        <span class="fwm-check ${selected ? 'is-on' : ''}" data-check role="checkbox" aria-checked="${selected}" aria-label="Select">${icon('check')}</span>
      </div>
      <div class="fwm-row__main">
        <div class="fwm-row__top"><span class="fwm-row__from">${esc(from)}${t.count > 1 ? `<span class="fwm-row__count">${t.count}</span>` : ''}${draftTag}</span>
          <span class="fwm-row__date">${esc(fmt.list(isOutbox ? (t.scheduled_at || t.date) : t.date))}</span></div>
        <div class="fwm-row__subject">${esc(t.subject || '(no subject)')}</div>
        <div class="fwm-row__snippet">${esc(t.snippet)}</div>
        <div class="fwm-row__meta">${meta.join('')}</div>
      </div>
      <div class="fwm-row__side">
        ${['drafts', 'outbox', 'trash', 'spam'].includes(S.view) ? '' : `<button class="icon-btn icon-btn--sm fwm-row__star ${t.starred ? 'is-on' : ''}" data-star aria-label="${t.starred ? 'Unstar' : 'Star'}">${icon('star', 'i--sm')}</button>`}
      </div>
      ${hover}
    </div>`;
  }
  function rowHoverActions() {
    const b = (act, ic, label) => `<button class="icon-btn icon-btn--sm" data-act="${act}" aria-label="${label}" data-tip="${label}">${icon(ic, 'i--sm')}</button>`;
    switch (S.view) {
      case 'trash': return `<div class="fwm-row__hover">${b('restore', 'undo', 'Restore')}${b('delete_forever', 'trash', 'Delete forever')}</div>`;
      case 'spam': return `<div class="fwm-row__hover">${b('not_spam', 'inbox', 'Not spam')}${b('delete_forever', 'trash', 'Delete forever')}</div>`;
      case 'drafts': return `<div class="fwm-row__hover">${b('delete_forever', 'trash', 'Discard draft')}</div>`;
      case 'outbox': return `<div class="fwm-row__hover">${b('cancel_send', 'x', 'Cancel send')}</div>`;
      case 'archive': return `<div class="fwm-row__hover">${b('inbox', 'inbox', 'Move to Inbox')}${b('trash', 'trash', 'Delete')}${b('toggle_read', 'mail-open', 'Read / unread')}</div>`;
      default: return `<div class="fwm-row__hover">${S.view === 'inbox' || S.view === 'starred' || S.view === 'all' ? b('archive', 'archive', 'Archive') : ''}${b('trash', 'trash', 'Delete')}${b('toggle_read', 'mail-open', 'Read / unread')}${S.view === 'inbox' ? b('snooze', 'clock', 'Snooze') : ''}</div>`;
    }
  }

  function renderList() {
    renderListHead();
    if (!S.threads.length) {
      const empty = {
        inbox: ['letter', 'You\'re all caught up', 'Nothing new in your inbox. Enjoy the quiet.'],
        starred: ['star', 'No starred mail', 'Star important conversations to find them here quickly.'],
        snoozed: ['clock', 'Nothing snoozed', 'Snooze a conversation to have it come back when you need it.'],
        drafts: ['draft', 'No drafts', 'Messages you start but don\'t send are kept here automatically.'],
        outbox: ['scheduled', 'Nothing scheduled', 'Use the arrow next to Send to schedule a message for later.'],
        sent: ['send', 'Nothing sent yet', 'Messages you send from Flowwork appear here.'],
        archive: ['archive', 'Archive is empty', 'Archive conversations to clear your inbox without deleting them.'],
        spam: ['shield', 'No spam', 'Messages you report as spam land here.'],
        trash: ['trash', 'Trash is empty', 'Deleted conversations stay here until you delete them forever.'],
        all: ['all', 'No mail', 'There\'s nothing here yet.'],
      }[S.view] || ['inbox', 'No mail', ''];
      if (S.q) el.threads.innerHTML = emptyState('search', 'No results', `Nothing matched “${esc(S.q)}”. Try fewer words, or operators like <b>from:</b>, <b>has:attachment</b> or <b>after:2026-01-01</b>.`, '');
      else if (S.filter) el.threads.innerHTML = emptyState('filter', 'Nothing matches this filter', '', '<button class="btn" id="clearFilter">Show all</button>');
      else if (S.labelId) el.threads.innerHTML = emptyState('tag', 'No conversations with this label', 'Apply labels from the reading pane or a selection.', '');
      else el.threads.innerHTML = emptyState(empty[0], empty[1], empty[2], '');
      const cf = $('#clearFilter'); if (cf) cf.addEventListener('click', () => setFilter(''));
      updateBulk();
      return;
    }
    el.threads.innerHTML = S.threads.map(rowHTML).join('') + (S.hasMore ? '<div class="fwm-list__more" id="listMore">Loading more…</div>' : '');
    el.threads.classList.toggle('is-selecting', S.selected.size > 0);
    updateBulk();
  }
  function rerenderRow(key) {
    const idx = S.threads.findIndex((t) => t.key === key);
    const row = el.threads.querySelector(`.fwm-row[data-key="${key}"]`);
    if (idx < 0 || !row) return;
    const tmp = document.createElement('div');
    tmp.innerHTML = rowHTML(S.threads[idx], idx);
    row.replaceWith(tmp.firstElementChild);
  }

  el.threads.addEventListener('scroll', () => {
    if (!S.hasMore || S.loading) return;
    if (el.threads.scrollTop + el.threads.clientHeight > el.threads.scrollHeight - 300) loadThreads({ append: true });
  }, { passive: true });

  el.threads.addEventListener('click', (e) => {
    const row = e.target.closest('.fwm-row');
    if (!row) return;
    const key = row.dataset.key;
    const idx = Number(row.dataset.idx);
    const t = S.threads[idx];
    if (e.target.closest('[data-check]') || (e.target.closest('.fwm-row__lead') && (S.selected.size || e.shiftKey))) {
      e.stopPropagation();
      toggleSelect(key, idx, e.shiftKey);
      return;
    }
    if (e.target.closest('[data-star]')) { act([key], t.starred ? 'unstar' : 'star'); return; }
    const a = e.target.closest('[data-act]');
    if (a) {
      const action = a.dataset.act;
      if (action === 'toggle_read') return act([key], t.unread ? 'read' : 'unread');
      if (action === 'snooze') return snoozeMenu(a, [key]);
      if (action === 'cancel_send') return cancelSend(t.latest_email_id);
      if (action === 'delete_forever') return deleteForever([key]);
      return act([key], action);
    }
    if (e.metaKey || e.ctrlKey) { toggleSelect(key, idx, false); return; }
    S.focusIdx = idx;
    if (S.view === 'drafts') { Composer.openFrom({ mode: 'draft', email_id: t.latest_email_id }); return; }
    openThread(key);
  });

  function toggleSelect(key, idx, range) {
    if (range && S.lastClickedIdx >= 0) {
      const [a, b] = [Math.min(S.lastClickedIdx, idx), Math.max(S.lastClickedIdx, idx)];
      const on = !S.selected.has(key);
      for (let i = a; i <= b; i++) on ? S.selected.add(S.threads[i].key) : S.selected.delete(S.threads[i].key);
    } else {
      S.selected.has(key) ? S.selected.delete(key) : S.selected.add(key);
    }
    S.lastClickedIdx = idx;
    $$('.fwm-row', el.threads).forEach((r) => {
      const on = S.selected.has(r.dataset.key);
      r.classList.toggle('is-selected', on);
      const c = $('[data-check]', r); c.classList.toggle('is-on', on); c.setAttribute('aria-checked', on);
    });
    el.threads.classList.toggle('is-selecting', S.selected.size > 0);
    updateBulk();
  }
  function selectAllToggle() {
    if (S.selected.size) S.selected.clear(); else S.threads.forEach((t) => S.selected.add(t.key));
    renderList();
  }
  el.selectAll.addEventListener('click', selectAllToggle);
  $('#bulkAll').addEventListener('click', selectAllToggle);
  [el.selectAll, $('#bulkAll')].forEach((c) => c.addEventListener('keydown', (e) => { if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); selectAllToggle(); } }));

  function updateBulk() {
    const n = S.selected.size;
    el.bulkBar.hidden = n === 0;
    el.listHead.hidden = n > 0;
    if (!n) return;
    el.bulkCount.textContent = n === 1 ? '1 selected' : n + ' selected';
    const sel = S.threads.filter((t) => S.selected.has(t.key));
    const anyUnread = sel.some((t) => t.unread > 0);
    const b = (act, ic, label) => `<button class="icon-btn" data-bulk="${act}" aria-label="${label}" data-tip="${label}">${icon(ic)}</button>`;
    let html = '';
    if (S.view === 'trash') html = b('restore', 'undo', 'Restore') + b('delete_forever', 'trash', 'Delete forever');
    else if (S.view === 'spam') html = b('not_spam', 'inbox', 'Not spam') + b('delete_forever', 'trash', 'Delete forever');
    else if (S.view === 'drafts') html = b('delete_forever', 'trash', 'Discard drafts');
    else if (S.view === 'outbox') html = b('cancel_send', 'x', 'Cancel sending');
    else {
      if (S.view === 'archive') html += b('inbox', 'inbox', 'Move to Inbox');
      else html += b('archive', 'archive', 'Archive');
      html += b('trash', 'trash', 'Delete') + b('spam', 'spam', 'Report spam')
        + b(anyUnread ? 'read' : 'unread', anyUnread ? 'mail-open' : 'mail', anyUnread ? 'Mark as read' : 'Mark as unread')
        + (S.view === 'inbox' ? b('snooze', 'clock', 'Snooze') : '') + b('label', 'tag', 'Label') + b('move', 'folder', 'Move to');
    }
    el.bulkActions.innerHTML = html;
  }
  el.bulkActions.addEventListener('click', (e) => {
    const b = e.target.closest('[data-bulk]');
    if (!b) return;
    const keys = Array.from(S.selected);
    const a = b.dataset.bulk;
    if (a === 'snooze') return snoozeMenu(b, keys);
    if (a === 'label') return labelMenu(b, keys);
    if (a === 'move') return moveMenu(b, keys);
    if (a === 'delete_forever') return deleteForever(keys);
    if (a === 'cancel_send') {
      const ids = S.threads.filter((t) => S.selected.has(t.key)).map((t) => t.latest_email_id);
      Promise.all(ids.map((id) => api.post('send.php', { op: 'cancel', email_id: id }))).then(() => { toast('Moved back to Drafts'); refreshCounts(); loadThreads(); }).catch(toastError);
      return;
    }
    act(keys, a);
  });

  function setFilter(f) {
    S.filter = f;
    syncFilterChips();
    readRoute(true);
  }
  function syncFilterChips() { $$('.fwm-chip', el.filters).forEach((c) => c.classList.toggle('is-on', c.dataset.filter === S.filter)); }
  el.filters.addEventListener('click', (e) => { const c = e.target.closest('.fwm-chip'); if (c) setFilter(c.dataset.filter); });

  $('#listMoreBtn').addEventListener('click', (e) => {
    const p = S.prefs;
    popover(e.currentTarget, `
      <button class="fwm-pop__item" data-v="readall">${icon('mail-open')}Mark all shown as read</button>
      <button class="fwm-pop__item" data-v="refresh">${icon('refresh')}Refresh</button>
      <div class="fwm-pop__sep"></div>
      <div class="fwm-pop__title">Layout</div>
      <button class="fwm-pop__item" data-v="density">${icon('list')}${p.density === 'compact' ? 'Comfortable rows' : 'Compact rows'}</button>
      <button class="fwm-pop__item" data-v="pane">${icon('maximize')}${p.reading_pane === 'off' ? 'Show reading pane' : 'Hide reading pane'}</button>
      <button class="fwm-pop__item" data-v="snip">${icon('menu')}${p.show_snippets === false ? 'Show previews' : 'Hide previews'}</button>
      ${['trash', 'spam'].includes(S.view) && S.threads.length ? `<div class="fwm-pop__sep"></div><button class="fwm-pop__item is-danger" data-v="empty">${icon('trash')}Delete all shown forever</button>` : ''}`, {
      align: 'end',
      onClick: (it) => {
        const v = it.dataset.v;
        if (v === 'readall') { const keys = S.threads.filter((t) => t.unread).map((t) => t.key); if (keys.length) act(keys, 'read'); else toast('Everything here is already read'); }
        if (v === 'refresh') loadThreads();
        if (v === 'density') savePref({ density: p.density === 'compact' ? 'comfortable' : 'compact' });
        if (v === 'pane') savePref({ reading_pane: p.reading_pane === 'off' ? 'right' : 'off' });
        if (v === 'snip') savePref({ show_snippets: p.show_snippets === false });
        if (v === 'empty') deleteForever(S.threads.map((t) => t.key));
      },
    });
  });
  function savePref(patch) {
    Object.assign(S.prefs, patch);
    applyPrefs();
    api.post('prefs.php', patch).then((r) => { S.prefs = r.prefs; applyPrefs(); }).catch(toastError);
  }

  el.saveSearch.addEventListener('click', async () => {
    const name = await dialog({ title: 'Save search', body: 'Saved searches appear in the sidebar.', confirm: 'Save', input: { value: S.q, max: 100 } });
    if (!name) return;
    try {
      const r = await api.post('view.php', { op: 'save', name, filters: { q: S.q, view: 'all', label_id: S.labelId, account_id: S.accountId, filter: S.filter } });
      S.views = r.views; renderNav(); toast('Search saved');
    } catch (e) { toastError(e); }
  });

  // ---------------------------------------------------------------- search
  if (el.search) {
    $('#searchForm').addEventListener('submit', (e) => {
      e.preventDefault();
      const q = el.search.value.trim();
      closeReader({ silent: true });
      if (q) go({ view: 'all', q }); else go({ view: 'inbox' });
      el.search.blur();
    });
    el.search.addEventListener('input', () => { el.searchClear.hidden = !el.search.value; });
    el.search.addEventListener('keydown', (e) => { if (e.key === 'Escape') { el.search.value = ''; el.searchClear.hidden = true; if (S.q) go({ view: 'inbox' }); el.search.blur(); } });
    el.searchClear.addEventListener('click', () => { el.search.value = ''; el.searchClear.hidden = true; if (S.q) go({ view: 'inbox' }); el.search.focus(); });
    $('#searchHelp').addEventListener('click', (e) => {
      const ops = [['from:', 'from:alice'], ['to:', 'to:accounts@'], ['subject:', 'subject:invoice'], ['has:attachment', 'has:attachment'], ['is:unread', 'is:unread'], ['is:starred', 'is:starred'], ['label:', 'label:RFQ'], ['after:', 'after:2026-01-01'], ['before:', 'before:2026-06-30'], ['in:', 'in:sent'], ['"exact phrase"', '"exact phrase"']];
      popover(e.currentTarget, `<div class="fwm-pop__title">Search operators</div>${ops.map(([k, ex]) => `<button class="fwm-pop__item" data-v="${esc(ex)}"><code style="font-size:12.5px">${esc(k)}</code><small>${esc(ex)}</small></button>`).join('')}`, {
        align: 'end', width: 290,
        onClick: (it) => { el.search.value = (el.search.value ? el.search.value + ' ' : '') + it.dataset.v; el.searchClear.hidden = false; el.search.focus(); },
      });
    });
  }

  // ---------------------------------------------------------------- actions
  const REMOVES = {
    inbox: ['archive', 'trash', 'spam', 'snooze'], starred: ['trash', 'spam', 'unstar'], snoozed: ['unsnooze', 'archive', 'trash', 'spam'],
    archive: ['inbox', 'trash', 'spam'], spam: ['not_spam', 'delete_forever', 'trash'], trash: ['restore', 'delete_forever'],
    sent: ['trash'], all: ['trash', 'spam'], drafts: ['delete_forever', 'trash'],
  };
  const INVERSE = { archive: 'inbox', inbox: 'archive', trash: 'restore', restore: 'trash', spam: 'not_spam', not_spam: 'spam', snooze: 'unsnooze', read: 'unread', unread: 'read', star: 'unstar', unstar: 'star' };
  const DONE_MSG = {
    archive: 'Archived', inbox: 'Moved to Inbox', trash: 'Moved to Trash', restore: 'Restored', spam: 'Reported as spam',
    not_spam: 'Moved to Inbox', snooze: 'Snoozed', unsnooze: 'Unsnoozed', delete_forever: 'Deleted forever', read: 'Marked as read', unread: 'Marked as unread',
  };

  async function act(keys, action, opts = {}) {
    keys = Array.from(keys);
    if (!keys.length) return;
    const removes = (REMOVES[S.labelId || S.q ? 'all' : S.view] || []).includes(action);
    const prev = S.threads.slice();
    const openWasAffected = S.openKey && keys.includes(S.openKey);
    let nextKey = null;

    // Optimistic update
    if (removes) {
      if (openWasAffected) {
        const i = S.threads.findIndex((t) => t.key === S.openKey);
        const nxt = S.threads.slice(i + 1).find((t) => !keys.includes(t.key)) || S.threads.slice(0, i).reverse().find((t) => !keys.includes(t.key));
        nextKey = nxt && !narrow() && S.prefs.reading_pane !== 'off' ? nxt.key : null;
      }
      keys.forEach((k) => { const r = el.threads.querySelector(`.fwm-row[data-key="${k}"]`); if (r) r.classList.add('is-leaving'); });
      setTimeout(() => {
        S.threads = S.threads.filter((t) => !keys.includes(t.key));
        S.total = Math.max(0, S.total - keys.length);
        keys.forEach((k) => S.selected.delete(k));
        renderList();
        if (openWasAffected) { if (nextKey) openThread(nextKey); else closeReader(); }
        if (S.hasMore && S.threads.length < 15) loadThreads({ append: true });
      }, 180);
    } else {
      S.threads.forEach((t) => {
        if (!keys.includes(t.key)) return;
        if (action === 'read') t.unread = 0;
        if (action === 'unread') t.unread = Math.max(1, t.unread);
        if (action === 'star') t.starred = true;
        if (action === 'unstar') t.starred = false;
      });
      keys.forEach(rerenderRow);
      if (S.thread && keys.includes(S.openKey)) {
        if (action === 'star' || action === 'unstar') { S.thread.messages.forEach((m, i, arr) => { m.is_starred = action === 'star' ? i === arr.length - 1 || m.is_starred : false; }); renderReader(); }
      }
      updateBulk();
    }

    try {
      const r = await api.post('action.php', { keys, action, view: S.view, until: opts.until });
      S.counts = r.counts; renderNav(); renderListHead();
      if (!opts.silent && DONE_MSG[action] && (removes || ['read', 'unread'].includes(action) && keys.length > 1)) {
        const inv = INVERSE[action];
        const label = (keys.length > 1 ? keys.length + ' conversations ' : 'Conversation ') + DONE_MSG[action].toLowerCase();
        const undo = inv ? () => act(keys, inv, { silent: true, isUndo: true }).then(() => loadThreads({ silent: true })) : null;
        S.undo = undo;
        toast(action === 'snooze' && opts.until ? `Snoozed until ${fmt.when(new Date(opts.until * 1000).toISOString())}` : label, undo ? { action: { label: 'Undo', run: undo } } : {});
      }
      if (opts.isUndo) toast('Action undone');
    } catch (e) {
      toastError(e);
      S.threads = prev;
      renderList();
    }
  }

  async function deleteForever(keys) {
    const ok = await dialog({
      title: keys.length > 1 ? `Delete ${keys.length} conversations forever?` : 'Delete forever?',
      body: S.view === 'drafts' ? 'The draft will be discarded.' : 'They will be removed from this mailbox and your mail server. This can\'t be undone.',
      confirm: 'Delete forever', danger: true,
    });
    if (ok) act(keys, 'delete_forever');
  }
  async function cancelSend(emailId) {
    try {
      await api.post('send.php', { op: 'cancel', email_id: emailId });
      toast('Sending cancelled — moved to Drafts', { action: { label: 'Edit', run: () => Composer.openFrom({ mode: 'draft', email_id: emailId }) } });
      refreshCounts(); loadThreads({ silent: true });
    } catch (e) { toastError(e); }
  }

  function snoozeTimes() {
    const now = new Date();
    const at = (d, h, m = 0) => { const x = new Date(d); x.setHours(h, m, 0, 0); return x; };
    const later = now.getHours() < 17 ? at(now, 18) : new Date(now.getTime() + 3 * 3600e3);
    const tomorrow = at(new Date(now.getTime() + 86400e3), 8);
    const sat = new Date(now); sat.setDate(now.getDate() + ((6 - now.getDay() + 7) % 7 || 7));
    const mon = new Date(now); mon.setDate(now.getDate() + ((1 - now.getDay() + 7) % 7 || 7));
    return [
      ['later', 'Later today', later],
      ['tomorrow', 'Tomorrow', tomorrow],
      ['weekend', 'This weekend', at(sat, 8)],
      ['nextweek', 'Next week', at(mon, 8)],
    ];
  }
  function snoozeMenu(anchor, keys) {
    const opts = snoozeTimes();
    popover(anchor, `<div class="fwm-pop__title">Snooze until</div>
      ${opts.map(([v, l, d]) => `<button class="fwm-pop__item" data-v="${v}">${l}<small>${esc(d.toLocaleString([], { weekday: 'short', hour: '2-digit', minute: '2-digit' }))}</small></button>`).join('')}
      <div class="fwm-pop__sep"></div><button class="fwm-pop__item" data-v="pick">${icon('scheduled')}Pick date &amp; time</button>`, {
      onClick: (it) => {
        if (it.dataset.v === 'pick') { pickWhen('Snooze until', 'Snooze').then((ts) => ts && act(keys, 'snooze', { until: ts })); return; }
        const d = opts.find((o) => o[0] === it.dataset.v)[2];
        act(keys, 'snooze', { until: Math.floor(d.getTime() / 1000) });
      },
    });
  }
  function pickWhen(title, okLabel) {
    return new Promise((resolve) => {
      const m = $('#whenModal');
      $('#whenTitle').textContent = title;
      $('#whenOk').textContent = okLabel;
      const d = new Date(Date.now() + 86400e3);
      const pad = (n) => String(n).padStart(2, '0');
      $('#whenDate').value = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
      $('#whenDate').min = new Date().toISOString().slice(0, 10);
      $('#whenTime').value = '08:00';
      let done = false;
      const ok = () => {
        const v = new Date(`${$('#whenDate').value}T${$('#whenTime').value || '08:00'}`);
        if (isNaN(v) || v < new Date()) { toast('Pick a time in the future', { type: 'error' }); return; }
        done = true; cleanup(); closeModal(m); resolve(Math.floor(v.getTime() / 1000));
      };
      const obs = new MutationObserver(() => { if (m.hidden && !done) { cleanup(); resolve(null); } });
      const cleanup = () => { $('#whenOk').removeEventListener('click', ok); obs.disconnect(); };
      $('#whenOk').addEventListener('click', ok);
      obs.observe(m, { attributes: true, attributeFilter: ['hidden'] });
      openModal(m);
    });
  }
  function moveMenu(anchor, keys) {
    const items = [['inbox', 'inbox', 'Inbox'], ['archive', 'archive', 'Archive'], ['spam', 'spam', 'Spam'], ['trash', 'trash', 'Trash']]
      .filter(([v]) => v !== S.view);
    popover(anchor, `<div class="fwm-pop__title">Move to</div>${items.map(([v, ic, l]) => `<button class="fwm-pop__item" data-v="${v}">${icon(ic)}${l}</button>`).join('')}`, {
      onClick: (it) => {
        const target = it.dataset.v;
        const action = target === 'inbox' ? (S.view === 'spam' ? 'not_spam' : S.view === 'trash' ? 'restore' : 'inbox') : target;
        act(keys, action);
      },
    });
  }

  function labelMenu(anchor, keys, onDone) {
    const sel = keys.map((k) => S.threads.find((t) => t.key === k) || (S.thread && S.thread.key === k ? { labels: S.thread.labels } : { labels: [] }));
    const has = (id) => sel.filter((t) => (t.labels || []).some((l) => l.tag_id === id)).length;
    const render = (filter = '') => `<input class="fwm-pop__input" placeholder="Filter or create label" value="${esc(filter)}" data-lfilter>
      ${S.labels.filter((l) => l.name.toLowerCase().includes(filter.toLowerCase())).map((l) => {
        const n = has(l.tag_id); const on = n === sel.length && n > 0;
        return `<button class="fwm-pop__item" data-v="${l.tag_id}" data-on="${on ? 1 : 0}"><span class="fwm-pop__check ${on ? 'is-on' : ''}" style="${on ? `background:${esc(l.color)};border-color:${esc(l.color)}` : `border-color:${esc(l.color)}`}">${on ? icon('check') : ''}</span>${esc(l.name)}</button>`;
      }).join('')}
      ${filter && !S.labels.some((l) => l.name.toLowerCase() === filter.toLowerCase()) ? `<button class="fwm-pop__item" data-v="new">${icon('plus')}Create “${esc(filter)}”</button>` : ''}
      <div class="fwm-pop__sep"></div><a class="fwm-pop__item" data-v="manage" href="/mail/settings.php#labels">${icon('settings')}Manage labels</a>`;
    const pop = popover(anchor, render(), {
      width: 250,
      onClick: (it) => {
        if (it.dataset.v === 'manage') return true;
        if (it.dataset.v === 'new') {
          const name = $('[data-lfilter]', pop.el).value.trim();
          api.post('label.php', { op: 'create', name, color: LABEL_COLORS[S.labels.length % LABEL_COLORS.length] })
            .then((r) => { S.labels = r.labels; renderNav(); return applyLabel(keys, r.tag_id, true); }).then(onDone).catch(toastError);
          return;
        }
        applyLabel(keys, Number(it.dataset.v), it.dataset.on !== '1').then(onDone);
      },
    });
    pop.el.addEventListener('input', (e) => {
      if (!e.target.matches('[data-lfilter]')) return;
      const v = e.target.value;
      pop.el.innerHTML = render(v);
      const inp = $('[data-lfilter]', pop.el); inp.focus(); inp.setSelectionRange(v.length, v.length);
    });
    pop.el.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && e.target.matches('[data-lfilter]')) {
        e.preventDefault();
        const first = $('[data-v]', pop.el); if (first) first.click();
      }
    });
  }
  async function applyLabel(keys, tagId, on) {
    const l = labelById(tagId);
    try {
      await api.post('label.php', { op: 'apply', keys, tag_id: tagId, on });
      S.threads.forEach((t) => {
        if (!keys.includes(t.key)) return;
        t.labels = t.labels.filter((x) => x.tag_id !== tagId);
        if (on && l) t.labels.push(l);
        rerenderRow(t.key);
      });
      if (S.thread && keys.includes(S.thread.key)) {
        S.thread.labels = S.thread.labels.filter((x) => x.tag_id !== tagId);
        if (on && l) S.thread.labels.push(l);
        renderReader({ keepScroll: true });
      }
      if (S.labelId === tagId && !on) loadThreads({ silent: true });
      toast(on ? `Labelled “${l ? l.name : ''}”` : `Removed “${l ? l.name : ''}”`);
      refreshCounts();
    } catch (e) { toastError(e); }
  }

  async function createLabel() {
    const m = $('#labelModal');
    let color = LABEL_COLORS[S.labels.length % LABEL_COLORS.length];
    const sw = $('#labelSwatches');
    const paint = () => { sw.innerHTML = LABEL_COLORS.map((c) => `<button type="button" class="fwm-swatch ${c === color ? 'is-on' : ''}" style="background:${c}" data-c="${c}" aria-label="Colour ${c}"></button>`).join(''); };
    paint();
    sw.onclick = (e) => { const b = e.target.closest('[data-c]'); if (b) { color = b.dataset.c; paint(); } };
    $('#labelName').value = '';
    $('#labelSave').onclick = async () => {
      const name = $('#labelName').value.trim();
      if (!name) { $('#labelName').focus(); return; }
      try {
        const r = await api.post('label.php', { op: 'create', name, color });
        S.labels = r.labels; renderNav(); closeModal(m); toast(`Label “${name}” created`);
      } catch (e) { toastError(e); }
    };
    $('#labelName').onkeydown = (e) => { if (e.key === 'Enter') { e.preventDefault(); $('#labelSave').click(); } };
    openModal(m);
  }

  // ---------------------------------------------------------------- reader
  function greetingText() {
    const c = S.counts || {};
    const unread = S.accountId ? (c.by_account || {})[S.accountId] || 0 : c.inbox || 0;
    if (S.view !== 'inbox' || S.q || S.labelId) return 'Choose a conversation to read it here. Press <kbd class="fwm-kbd">?</kbd> for keyboard shortcuts.';
    return unread === 0 ? 'Your inbox is clear — every conversation has been read.'
      : `You have <b>${unread}</b> unread conversation${unread === 1 ? '' : 's'}. Choose one to read it here.`;
  }
  function readerEmpty() {
    const h = new Date().getHours();
    const greet = h < 5 ? 'Good evening' : h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening';
    const first = (S.me && S.me.first_name) || '';
    const text = greetingText();
    el.reader.innerHTML = emptyState('letter', '', text, '', {
      eyebrow: todayLabel(),
      titleHtml: esc(greet) + (first ? `, <span class="fwm-empty__greet">${esc(first)}</span>` : ''),
    });
    const p = $('.fwm-empty p', el.reader);
    if (p) { p.dataset.greet = '1'; p.dataset.text = text; }
  }
  /** Keep the greeting's unread line current without replaying its entrance. */
  function refreshGreeting() {
    const p = !S.openKey && $('[data-greet]', el.reader);
    if (!p) return;
    const text = greetingText();
    if (p.dataset.text !== text) { p.innerHTML = text; p.dataset.text = text; }
  }

  async function openThread(key, opts = {}) {
    S.openKey = key;
    document.body.classList.add('is-reading');
    $$('.fwm-row', el.threads).forEach((r) => { const on = r.dataset.key === key; r.classList.toggle('is-active', on); r.setAttribute('aria-selected', on); });
    const idx = S.threads.findIndex((t) => t.key === key);
    if (idx >= 0) {
      S.focusIdx = idx;
      const row = el.threads.querySelector(`.fwm-row[data-key="${key}"]`);
      if (row && !opts.fromRoute) row.scrollIntoView({ block: 'nearest' });
    }
    if (!opts.fromRoute) setOpenInHash(key);
    const req = ++S.threadReq;
    el.reader.innerHTML = `<div class="fwm-reader__bar"></div><div class="fwm-reader__scroll"><div class="fwm-reader__inner">
      <div class="fwm-skel__l" style="height:26px;width:60%;margin:6px 0 26px"></div>${'<div class="fwm-skel" style="border:0;padding:8px 0"><div class="fwm-skel__a"></div><div><div class="fwm-skel__l" style="width:40%"></div><div class="fwm-skel__l" style="width:85%"></div><div class="fwm-skel__l" style="width:70%"></div></div></div>'.repeat(2)}</div></div>`;
    try {
      const data = await api.get('thread.php', { key, view: S.view, images: opts.images ? 1 : 0 });
      if (req !== S.threadReq) return;
      S.thread = data;
      S.thread.quick = null;
      renderReader();
      const t = S.threads.find((x) => x.key === key);
      if (data.messages.some((m) => !m.is_read)) {
        if (t) { t.unread = 0; rerenderRow(key); }
        api.post('action.php', { keys: [key], action: 'read', view: S.view }).then((r) => { S.counts = r.counts; renderNav(); renderListHead(); }).catch(() => {});
      }
      loadQuickReplies(key);
    } catch (e) {
      if (req !== S.threadReq) return;
      el.reader.innerHTML = `<div class="fwm-reader__bar">${backBtn()}</div>` + emptyState('alert', 'Couldn\'t open this conversation', esc(e.message), '');
    }
  }

  function closeReader(opts = {}) {
    S.openKey = null;
    S.thread = null;
    S.threadReq++;
    document.body.classList.remove('is-reading');
    $$('.fwm-row.is-active', el.threads).forEach((r) => { r.classList.remove('is-active'); r.setAttribute('aria-selected', 'false'); });
    if (!opts.fromRoute) {
      if (S.pushedOpen && narrow()) { S.pushedOpen = false; history.back(); }
      else setOpenInHash(null);
    }
    S.pushedOpen = opts.fromRoute ? false : S.pushedOpen;
    readerEmpty();
  }

  const backBtn = () => `<button class="icon-btn ${S.prefs.reading_pane === 'off' ? '' : 'only-narrow'}" data-rb="back" aria-label="Back to list" data-tip="Back">${icon('arrow-left')}</button>`;

  function readerBarHTML() {
    const th = S.thread;
    const b = (act, ic, label, extra = '') => `<button class="icon-btn" data-rb="${act}" aria-label="${label}" data-tip="${label}" ${extra}>${icon(ic)}</button>`;
    const inView = (f) => th.messages.some((m) => m.folder === f);
    let left = backBtn();
    if (S.view === 'trash') left += b('restore', 'undo', 'Restore') + b('delete_forever', 'trash', 'Delete forever');
    else if (S.view === 'spam') left += b('not_spam', 'inbox', 'Not spam') + b('delete_forever', 'trash', 'Delete forever');
    else {
      left += inView('INBOX') ? b('archive', 'archive', 'Archive (e)') : (inView('Archive') ? b('inbox', 'inbox', 'Move to Inbox') : '');
      left += b('trash', 'trash', 'Delete (#)') + b('spam', 'spam', 'Report spam (!)');
      left += '<span class="sep hide-narrow"></span>';
      left += b('unread', 'mail', 'Mark as unread (⇧U)');
      if (inView('INBOX')) left += b('snooze', 'clock', 'Snooze (b)');
      left += b('move', 'folder', 'Move to');
      left += b('label', 'tag', 'Label (l)');
    }
    left += '<span class="sep hide-narrow"></span>' + b('crm', 'building', 'Link to CRM') + b('print', 'print', 'Print');
    const idx = S.threads.findIndex((t) => t.key === S.openKey);
    const nav = idx >= 0 ? `<span class="fwm-reader__pos hide-narrow">${idx + 1} of ${S.total || S.threads.length}</span>
      ${b('prev', 'chev-up', 'Newer (k)', idx <= 0 ? 'disabled' : '')}${b('next', 'chev-down', 'Older (j)', idx >= S.threads.length - 1 ? 'disabled' : '')}` : '';
    return `${left}<span class="grow"></span>${nav}`;
  }

  function renderReader(opts = {}) {
    const th = S.thread;
    if (!th) return readerEmpty();
    const scroller = $('.fwm-reader__scroll', el.reader);
    const keepTop = opts.keepScroll && scroller ? scroller.scrollTop : 0;
    const expanded = new Set(opts.expanded || []);
    if (opts.keepScroll) $$('.fwm-msg:not(.is-collapsed)', el.reader).forEach((a) => expanded.add(Number(a.dataset.id)));
    const msgs = th.messages;
    const lastIdx = msgs.length - 1;

    const chips = th.labels.map((l) => `<span class="fwm-tag" style="--c:${esc(l.color)}">${esc(l.name)}<button data-unlabel="${l.tag_id}" aria-label="Remove label">${icon('x', 'i--xs')}</button></span>`)
      .concat(th.links.map((k) => k.url ? `<a class="fwm-tag fwm-tag--link" href="${esc(k.url)}">${icon(k.type === 'customer' || k.type === 'supplier' ? 'building' : 'link', 'i--xs')}${esc(k.label)}</a>` : `<span class="fwm-tag fwm-tag--link">${icon('link', 'i--xs')}${esc(k.label)}</span>`)).join('');

    const banners = [];
    if (th.remote_images && !th.images_shown) {
      banners.push(`<div class="fwm-banner">${icon('image')}<span class="grow">Remote images are hidden to protect your privacy.</span><button class="btn btn--sm" data-rb="images">Show images</button></div>`);
    }
    const out = msgs.find((m) => m.folder === 'Outbox');
    if (out) {
      banners.push(out.send_status === 'failed'
        ? `<div class="fwm-banner fwm-banner--danger">${icon('alert')}<span class="grow">Not sent: ${esc(out.send_error || 'unknown error')}</span><button class="btn btn--sm" data-retry="${out.email_id}">Retry</button><button class="btn btn--sm" data-edit="${out.email_id}">Edit</button></div>`
        : `<div class="fwm-banner fwm-banner--warn">${icon('scheduled')}<span class="grow">Scheduled to send ${esc(fmt.when(out.scheduled_at))}</span><button class="btn btn--sm" data-cancel="${out.email_id}">Cancel send</button></div>`);
    }

    const msgHTML = msgs.map((m, i) => {
      const open = expanded.has(m.email_id) || i === lastIdx || !m.is_read || m.is_draft || (opts.expandAll);
      const toNames = m.to.concat(m.cc).map((a) => a.name || a.email);
      const toLine = (m.outgoing ? 'to ' : 'to ') + (toNames.length ? esc(toNames.slice(0, 3).join(', ')) + (toNames.length > 3 ? ` +${toNames.length - 3}` : '') : 'undisclosed recipients');
      const files = m.attachments.map((a) => {
        const [kind, label] = fileKind(a.name, a.mime);
        const inline = kind === 'img' || kind === 'pdf';
        const thumb = kind === 'img' ? `<img src="${esc(a.url)}&inline=1" alt="" loading="lazy">` : esc(label);
        return `<a class="fwm-file" href="${esc(a.url)}${inline ? '&inline=1' : ''}" ${inline ? 'target="_blank" rel="noopener"' : 'download'} title="${esc(a.name)}">
          <span class="fwm-file__icon ${kind ? 'fwm-file__icon--' + kind : ''}">${thumb}</span>
          <span class="fwm-file__meta"><div class="fwm-file__name">${esc(a.name)}</div><div class="fwm-file__size">${fmt.size(a.size)}</div></span>
          <span class="fwm-file__act"><span class="icon-btn icon-btn--sm" data-dl="${esc(a.url)}" aria-label="Download ${esc(a.name)}">${icon('download', 'i--sm')}</span></span></a>`;
      }).join('');
      const details = `<dl class="fwm-msg__details" hidden>
        <dt>From</dt><dd>${esc(m.from.name ? m.from.name + ' <' + m.from.email + '>' : m.from.email)}</dd>
        ${m.reply_to.length ? `<dt>Reply-to</dt><dd>${esc(m.reply_to.map((a) => a.email).join(', '))}</dd>` : ''}
        <dt>To</dt><dd>${esc(m.to.map((a) => a.name ? `${a.name} <${a.email}>` : a.email).join(', ') || '—')}</dd>
        ${m.cc.length ? `<dt>Cc</dt><dd>${esc(m.cc.map((a) => a.name ? `${a.name} <${a.email}>` : a.email).join(', '))}</dd>` : ''}
        ${m.bcc.length ? `<dt>Bcc</dt><dd>${esc(m.bcc.map((a) => a.email).join(', '))}</dd>` : ''}
        <dt>Date</dt><dd>${esc(fmt.full(m.date))}</dd>
        <dt>Subject</dt><dd>${esc(m.subject || '(no subject)')}</dd></dl>`;
      const name = m.outgoing ? (S.me.name || 'Me') : (m.from.name || m.from.email);
      return `<article class="fwm-msg ${open ? '' : 'is-collapsed'} ${m.is_draft ? 'is-draft' : ''}" data-id="${m.email_id}">
        <div class="fwm-msg__head" data-toggle>
          ${avatar(name, m.from.email, 'fwm-avatar--lg' + (m.outgoing ? ' fwm-avatar--me' : ''))}
          <div class="fwm-msg__who">
            <div><span class="fwm-msg__name">${esc(name)}</span>${m.from.email && !m.outgoing ? `<span class="fwm-msg__email">&lt;${esc(m.from.email)}&gt;</span>` : ''}</div>
            <div class="fwm-msg__to">${toLine} <button data-details aria-label="Show details">${icon('chev-down', 'i--xs')}</button></div>
            <div class="fwm-msg__snippet">${esc(m.snippet)}</div>
            ${details}
          </div>
          <div class="fwm-msg__side">
            <span class="fwm-msg__date" title="${esc(fmt.full(m.date))}">${esc(fmt.list(m.date))}</span>
            ${m.attachments.length ? `<span title="Attachments">${icon('clip', 'i--sm')}</span>` : ''}
            ${m.is_draft ? '' : `<button class="icon-btn icon-btn--sm fwm-row__star ${m.is_starred ? 'is-on' : ''}" data-mstar aria-label="Star">${icon('star', 'i--sm')}</button>
            <button class="icon-btn icon-btn--sm" data-mreply aria-label="Reply" data-tip="Reply">${icon('reply', 'i--sm')}</button>
            <button class="icon-btn icon-btn--sm" data-mmore aria-label="More" data-tip-pos="left">${icon('more-v', 'i--sm')}</button>`}
          </div>
        </div>
        <div class="fwm-msg__body"><div class="fwm-paper" data-frame-host></div>
          <button class="fwm-quote-toggle" data-quote hidden aria-label="Show quoted text">•••</button></div>
        ${files ? `<div class="fwm-msg__files">${files}</div>` : ''}
        ${m.is_draft ? `<div class="fwm-msg__actions"><button class="btn btn--sm" data-edit="${m.email_id}">${icon('pen', 'i--sm')}Edit draft</button><button class="btn btn--sm btn--ghost btn--danger" data-discard="${m.email_id}">Discard</button></div>`
          : (m.unsubscribe ? `<div class="fwm-msg__actions"><a class="btn btn--sm btn--ghost" href="${esc(m.unsubscribe)}" target="_blank" rel="noopener noreferrer" data-unsub>${icon('ban', 'i--sm')}Unsubscribe</a></div>` : '')}
      </article>`;
    }).join('');

    const last = msgs[lastIdx];
    const canReply = last && !['trash', 'spam', 'outbox'].includes(S.view);
    const multi = last && (last.to.length + last.cc.length) > 1;
    el.reader.innerHTML = `<div class="fwm-reader__bar">${readerBarHTML()}</div>
      <div class="fwm-reader__scroll scroll"><div class="fwm-reader__inner">
        <h1 class="fwm-subject">${esc(th.subject || '(no subject)')}</h1>
        <div class="fwm-subject-meta">${chips}</div>
        ${banners.join('')}
        ${msgHTML}
        <div class="fwm-quick" id="quickReplies" hidden></div>
        ${canReply ? `<div class="fwm-replybar">
          <button class="btn" data-reply="reply">${icon('reply', 'i--sm')}Reply</button>
          ${multi ? `<button class="btn" data-reply="reply_all">${icon('reply-all', 'i--sm')}Reply all</button>` : ''}
          <button class="btn" data-reply="forward">${icon('forward', 'i--sm')}Forward</button></div>` : ''}
      </div></div>`;

    $$('.fwm-msg', el.reader).forEach((art) => {
      if (!art.classList.contains('is-collapsed')) mountFrame(art);
    });
    if (S.thread.quick) renderQuickReplies();
    const sc = $('.fwm-reader__scroll', el.reader);
    if (opts.keepScroll) sc.scrollTop = keepTop;
    else {
      // Bring the first unread (or last) message into view on long threads.
      const target = msgs.findIndex((m) => !m.is_read);
      if (msgs.length > 2) {
        const art = $$('.fwm-msg', el.reader)[target >= 0 ? target : lastIdx];
        if (art) setTimeout(() => art.scrollIntoView({ block: 'start' }), 30);
      }
    }
  }

  const FRAME_CSS = `html{color-scheme:light}body{display:flow-root;margin:0;padding:0;background:#fff;color:#1f1a17;font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;overflow-wrap:anywhere;-webkit-font-smoothing:antialiased}
    img{max-width:100%;height:auto}table{max-width:100%}pre{white-space:pre-wrap}a{color:#7a1f30}
    blockquote{margin:0 0 0 .8ex;border-left:2px solid #dccbae;padding-left:1ex;color:#55504b}
    body>*:first-child{margin-top:0}.fwm-folded{display:none!important}`;

  function mountFrame(art) {
    const host = $('[data-frame-host]', art);
    if (!host || host.firstChild) return;
    const m = S.thread.messages.find((x) => String(x.email_id) === art.dataset.id);
    if (!m) return;
    const images = S.thread.images_shown;
    const csp = `default-src 'none'; img-src 'self' data:${images ? ' https: http:' : ''}; style-src 'unsafe-inline'; font-src data:${images ? ' https:' : ''}`;
    const f = document.createElement('iframe');
    f.setAttribute('sandbox', 'allow-same-origin allow-popups allow-popups-to-escape-sandbox');
    f.setAttribute('referrerpolicy', 'no-referrer');
    f.setAttribute('title', 'Message from ' + (m.from.name || m.from.email));
    f.dataset.fwmFrame = '1';
    f.srcdoc = `<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="${csp}"><base target="_blank"><style>${FRAME_CSS}</style></head><body>${m.html}</body></html>`;
    host.appendChild(f);
    f.style.height = '40px';
    // Measure the body (flow-root, so child margins count): the document's own
    // scrollHeight never drops below the frame height, so it can't shrink.
    const fit = () => {
      try {
        const d = f.contentDocument;
        if (!d || !d.body) return;
        const h = Math.ceil(Math.max(d.body.scrollHeight, d.body.getBoundingClientRect().height));
        f.style.height = Math.max(24, h) + 'px';
      } catch (e) { /* cross-origin should not happen */ }
    };
    f.addEventListener('load', () => {
      const d = f.contentDocument;
      if (!d) return;
      foldQuotes(d, art);
      fit();
      $$('img', d).forEach((img) => img.addEventListener('load', fit));
      if (window.ResizeObserver) new ResizeObserver(fit).observe(d.body);
      setTimeout(fit, 400);
    });
  }

  /** Hide quoted history (Gmail/Outlook/Apple/our own) behind a "•••" toggle. */
  function foldQuotes(doc, art) {
    const body = doc.body;
    const candidates = $$('.gmail_quote, blockquote[type="cite"], [data-fw-quote], #divRplyFwdMsg, #appendonsend, .moz-cite-prefix, div[name="quote"], .yahoo_quoted', body);
    let target = candidates.find((c) => !candidates.some((o) => o !== c && o.contains(c)));
    if (!target) return;
    // The quote block and everything after it at the same level is history
    // (Outlook puts the header block first, the original after it).
    const toFold = [];
    for (let n = target; n; n = n.nextElementSibling) toFold.push(n);
    const before = (body.innerText || '').length - toFold.reduce((s, n) => s + (n.innerText || '').length, 0);
    if (before < 2) return; // the message is only a quote — show it
    toFold.forEach((n) => n.classList.add('fwm-folded'));
    const btn = $('[data-quote]', art);
    btn.hidden = false;
    btn.onclick = () => {
      const show = toFold[0].classList.contains('fwm-folded');
      toFold.forEach((n) => n.classList.toggle('fwm-folded', !show));
      btn.setAttribute('aria-label', show ? 'Hide quoted text' : 'Show quoted text');
      const f = $('iframe', art);
      if (f) f.style.height = Math.max(24, Math.ceil(doc.body.getBoundingClientRect().height)) + 'px';
    };
  }

  function readerAction(a, anchor) {
    const key = S.openKey;
    if (!key) return;
    switch (a) {
      case 'back': closeReader(); break;
      case 'prev': case 'next': stepThread(a === 'next' ? 1 : -1); break;
      case 'snooze': snoozeMenu(anchor, [key]); break;
      case 'label': labelMenu(anchor, [key]); break;
      case 'move': moveMenu(anchor, [key]); break;
      case 'crm': openCrm(key); break;
      case 'print': window.open('/mail/api/print.php?key=' + encodeURIComponent(key) + (S.thread.images_shown ? '&images=1' : ''), '_blank', 'noopener'); break;
      case 'images': openThread(key, { images: true, fromRoute: true }); break;
      case 'delete_forever': deleteForever([key]); break;
      case 'unread': act([key], 'unread', { silent: true }).then(() => { toast('Marked as unread'); closeReader(); }); break;
      default: act([key], a);
    }
  }
  function stepThread(dir) {
    const idx = S.threads.findIndex((t) => t.key === S.openKey);
    const next = S.threads[(idx < 0 ? S.focusIdx : idx) + dir];
    if (next) {
      if (S.view === 'drafts') { S.focusIdx += dir; renderList(); return; }
      openThread(next.key);
    }
  }

  el.reader.addEventListener('click', (e) => {
    const t = e.target;
    const rb = t.closest('[data-rb]');
    if (rb) { readerAction(rb.dataset.rb, rb); return; }
    const art = t.closest('.fwm-msg');
    if (t.closest('[data-details]')) {
      e.stopPropagation();
      const d = $('.fwm-msg__details', art); d.hidden = !d.hidden; return;
    }
    if (t.closest('[data-dl]')) {
      e.preventDefault(); e.stopPropagation();
      const a = document.createElement('a'); a.href = t.closest('[data-dl]').dataset.dl; a.download = ''; document.body.appendChild(a); a.click(); a.remove();
      return;
    }
    if (t.closest('[data-mstar]')) {
      e.stopPropagation();
      const id = Number(art.dataset.id);
      const m = S.thread.messages.find((x) => x.email_id === id);
      const on = !m.is_starred;
      api.post('action.php', { keys: ['e' + id], action: on ? 'star' : 'unstar', view: S.view }).then(() => {
        m.is_starred = on;
        t.closest('[data-mstar]').classList.toggle('is-on', on);
        const row = S.threads.find((x) => x.key === S.openKey);
        if (row) { row.starred = S.thread.messages.some((x) => x.is_starred); rerenderRow(row.key); }
      }).catch(toastError);
      return;
    }
    if (t.closest('[data-mreply]')) { e.stopPropagation(); Composer.openFrom({ mode: S.prefs.default_reply === 'reply_all' ? 'reply_all' : 'reply', email_id: art.dataset.id }); return; }
    if (t.closest('[data-mmore]')) {
      e.stopPropagation();
      const id = art.dataset.id;
      popover(t.closest('[data-mmore]'), `
        <button class="fwm-pop__item" data-v="reply">${icon('reply')}Reply</button>
        <button class="fwm-pop__item" data-v="reply_all">${icon('reply-all')}Reply all</button>
        <button class="fwm-pop__item" data-v="forward">${icon('forward')}Forward</button>
        <div class="fwm-pop__sep"></div>
        <button class="fwm-pop__item" data-v="unread">${icon('mail')}Mark unread from here</button>
        <button class="fwm-pop__item" data-v="print">${icon('print')}Print conversation</button>`, {
        align: 'end',
        onClick: (it) => {
          const v = it.dataset.v;
          if (['reply', 'reply_all', 'forward'].includes(v)) Composer.openFrom({ mode: v, email_id: id });
          if (v === 'unread') act(['e' + id], 'unread', { silent: true }).then(() => { toast('Marked as unread'); closeReader(); });
          if (v === 'print') readerAction('print');
        },
      });
      return;
    }
    if (t.closest('[data-edit]')) { Composer.openFrom({ mode: 'draft', email_id: t.closest('[data-edit]').dataset.edit }); return; }
    if (t.closest('[data-discard]')) {
      api.post('draft.php', { op: 'discard', draft_id: Number(t.closest('[data-discard]').dataset.discard) })
        .then((r) => { S.counts = r.counts; renderNav(); toast('Draft discarded'); openThread(S.openKey, { fromRoute: true }); loadThreads({ silent: true }); }).catch(toastError);
      return;
    }
    if (t.closest('[data-cancel]')) { cancelSend(Number(t.closest('[data-cancel]').dataset.cancel)).then(() => closeReader()); return; }
    if (t.closest('[data-retry]')) {
      const b = t.closest('[data-retry]'); b.classList.add('is-busy');
      api.post('send.php', { op: 'retry', email_id: Number(b.dataset.retry) }).then(() => { toast('Message sent'); refreshCounts(); loadThreads({ silent: true }); closeReader(); })
        .catch((err) => { b.classList.remove('is-busy'); toastError(err); });
      return;
    }
    if (t.closest('[data-unsub]')) {
      const href = t.closest('[data-unsub]').getAttribute('href') || '';
      if (/^mailto:/i.test(href)) {
        e.preventDefault();
        const u = new URL(href.replace(/^mailto:/i, 'mailto://'));
        Composer.openFrom({ mode: 'new', to: decodeURIComponent(href.replace(/^mailto:/i, '').split('?')[0]), subject: u.searchParams.get('subject') || 'Unsubscribe' });
      }
      return;
    }
    if (t.closest('[data-unlabel]')) { applyLabel([S.openKey], Number(t.closest('[data-unlabel]').dataset.unlabel), false); return; }
    if (t.closest('[data-reply]')) {
      const mode = t.closest('[data-reply]').dataset.reply;
      const lastReal = S.thread.messages.filter((m) => !m.is_draft && m.folder !== 'Outbox').pop();
      Composer.openFrom({ mode, email_id: lastReal ? lastReal.email_id : undefined, key: S.openKey });
      return;
    }
    if (t.closest('[data-quick]')) {
      const q = S.thread.quick.replies[Number(t.closest('[data-quick]').dataset.quick)];
      Composer.openFrom({ mode: 'reply', email_id: S.thread.quick.email_id }, { body_html: q.body_html });
      return;
    }
    const head = t.closest('[data-toggle]');
    if (head && !t.closest('a, button')) {
      const collapsed = art.classList.toggle('is-collapsed');
      if (!collapsed) mountFrame(art);
    }
  });

  async function loadQuickReplies(key) {
    if (['trash', 'spam', 'sent', 'outbox', 'drafts'].includes(S.view)) return;
    const last = S.thread && S.thread.messages[S.thread.messages.length - 1];
    if (!last || last.outgoing || last.is_draft) return;
    try {
      const r = await api.get('quick_replies.php', { key });
      if (!S.thread || S.thread.key !== key || !r.replies.length) return;
      S.thread.quick = r;
      renderQuickReplies();
    } catch (e) { /* optional */ }
  }
  function renderQuickReplies() {
    const host = $('#quickReplies');
    if (!host || !S.thread.quick) return;
    host.hidden = false;
    host.innerHTML = `<span class="fwm-quick__label">${icon('sparkle', 'i--xs')} Quick reply</span>` + S.thread.quick.replies.map((r, i) => `<button type="button" data-quick="${i}">${esc(r.label)}</button>`).join('');
  }

  // ---------------------------------------------------------------- CRM link
  async function openCrm(key) {
    openModal('crmModal');
    const input = $('#crmSearch');
    const results = $('#crmResults');
    input.value = '';
    let links = [];
    let suggestions = [];
    let found = [];
    const row = (a, linked, reason) => `<button class="fwm-result ${linked ? 'is-linked' : ''}" data-crm="${a.id}" data-on="${linked ? 0 : 1}">
      ${avatar(a.name || a.label, a.name || a.label)}<span class="fwm-result__text"><b>${esc(a.name || a.label)}</b><small>${esc(reason || (a.type ? a.type.charAt(0).toUpperCase() + a.type.slice(1) : ''))}</small></span>
      <span class="btn btn--sm ${linked ? 'btn--ghost' : ''}">${linked ? 'Unlink' : 'Link'}</span></button>`;
    const paint = () => {
      const linkedIds = new Set(links.filter((l) => l.type === 'customer' || l.type === 'supplier').map((l) => l.id));
      let html = '';
      if (linkedIds.size) html += '<div class="fwm-section-title">Linked</div><div class="fwm-results">' + links.filter((l) => linkedIds.has(l.id)).map((l) => row({ id: l.id, name: l.label, type: l.type }, true)).join('') + '</div>';
      const sug = suggestions.filter((s) => !linkedIds.has(s.id));
      if (sug.length && !input.value.trim()) html += '<div class="fwm-section-title">Suggested</div><div class="fwm-results">' + sug.map((s) => row(s, false, s.reason)).join('') + '</div>';
      if (input.value.trim()) html += '<div class="fwm-section-title">Results</div><div class="fwm-results">' + (found.length ? found.filter((f) => !linkedIds.has(f.id)).map((f) => row(f, false, (f.type || '') + (f.email ? ' · ' + f.email : ''))).join('') : '<div class="fwm-nav__empty" style="padding:8px 10px">No matching customers or suppliers.</div>') + '</div>';
      if (!html) html = '<div class="fwm-nav__empty" style="padding:14px 4px">Search for a customer or supplier to link this conversation.</div>';
      results.innerHTML = html;
    };
    try {
      const r = await api.get('crm.php', { key });
      links = r.links; suggestions = r.suggestions; paint();
    } catch (e) { results.innerHTML = `<p class="hint">${esc(e.message)}</p>`; }
    input.oninput = debounce(async () => {
      const q = input.value.trim();
      if (!q) { found = []; paint(); return; }
      try { const r = await api.get('crm.php', { q }); found = r.results; paint(); } catch (e) { toastError(e); }
    }, 220);
    results.onclick = async (e) => {
      const b = e.target.closest('[data-crm]');
      if (!b) return;
      try {
        const r = await api.post('crm.php', { key, crm_account_id: Number(b.dataset.crm), on: b.dataset.on === '1' });
        links = r.links; paint();
        if (S.thread && S.thread.key === key) { S.thread.links = r.links; renderReader({ keepScroll: true }); }
        toast(b.dataset.on === '1' ? 'Linked to CRM' : 'Link removed');
      } catch (err) { toastError(err); }
    };
  }

  // ===========================================================================
  // Composer
  // ===========================================================================
  const Composer = (() => {
    let C = null;   // current composer state
    let node = null;

    function signatureFor(accountId) {
      const acc = accountById(accountId);
      let sig = null;
      if (acc && acc.default_signature_id) sig = S.signatures.find((s) => s.signature_id === acc.default_signature_id);
      if (!sig && S.prefs.signature_id) sig = S.signatures.find((s) => s.signature_id === S.prefs.signature_id);
      if (!sig) sig = S.signatures.find((s) => s.is_default);
      return sig || null;
    }
    const sigBlock = (sig) => sig ? `<div data-fw-signature="${sig.signature_id}"><br>${sig.content_html}</div>` : '';

    async function openFrom(params, overrides = {}) {
      if (!S.accounts.some((a) => a.is_active)) { toast('Connect an active mail account first', { action: { label: 'Settings', run: () => { location.href = '/mail/settings.php#accounts'; } } }); return; }
      if (C && C.dirty) await save(true).catch(() => {});
      let ctx;
      try { ctx = await api.get('compose.php', params); } catch (e) { toastError(e); return; }
      open(ctx, overrides);
    }

    function open(ctx, overrides = {}) {
      close(true);
      const accountId = ctx.account_id || (S.accounts.find((a) => a.is_active) || {}).account_id;
      C = {
        mode: ctx.mode, draftId: ctx.draft_id || null, accountId,
        to: ctx.to || [], cc: ctx.cc || [], bcc: ctx.bcc || [], subject: ctx.subject || '',
        threadKey: ctx.thread_key, inReplyTo: ctx.in_reply_to, references: ctx.references,
        copyFrom: ctx.copy_attachments_from || null, attachments: ctx.draft_id ? (ctx.attachments || []) : [],
        forwardAtts: !ctx.draft_id && ctx.copy_attachments_from ? (ctx.attachments || []) : [],
        skipForward: [], dirty: false, saving: null, savedAt: null, uploads: 0, sendError: ctx.send_error,
        templateId: ctx.template_id || null, bodyTouched: false, subjectTouched: false,
      };
      let html = overrides.body_html != null ? overrides.body_html : ctx.body_html || '';
      if (ctx.mode !== 'draft') {
        const sig = signatureFor(accountId);
        const lead = html ? html : '<p><br></p>';
        html = lead + sigBlock(sig) + (ctx.quote ? ctx.quote : '');
      }
      build(html, !!ctx.quote && ctx.mode !== 'forward');
      if (ctx.send_error) setStatus('Last attempt failed: ' + ctx.send_error, true);
      if (overrides.body_html) markDirty();
    }

    function title() {
      if (C.subject) return C.subject;
      return { reply: 'Reply', reply_all: 'Reply all', forward: 'Forward', draft: 'Draft' }[C.mode] || 'New message';
    }

    function build(html, foldQuote) {
      node = document.createElement('section');
      node.className = 'fwm-composer';
      node.setAttribute('role', 'dialog');
      node.setAttribute('aria-label', 'Compose message');
      const active = S.accounts.filter((a) => a.is_active);
      node.innerHTML = `
        <div class="fwm-composer__bar" data-cbar>
          <span class="fwm-composer__title" data-ctitle>${esc(title())}</span>
          <button class="icon-btn" data-c="min" aria-label="Minimise">${icon('minus', 'i--sm')}</button>
          <button class="icon-btn hide-narrow" data-c="max" aria-label="Full screen">${icon('maximize', 'i--sm')}</button>
          <button class="icon-btn" data-c="close" aria-label="Save &amp; close">${icon('x', 'i--sm')}</button>
        </div>
        <div class="fwm-composer__fields">
          <div class="fwm-cf" ${active.length > 1 ? '' : 'hidden'}><span class="fwm-cf__label">From</span><div class="fwm-cf__control">
            <select data-from aria-label="From">${active.map((a) => `<option value="${a.account_id}" ${a.account_id === C.accountId ? 'selected' : ''}>${esc((a.display_name ? a.display_name + ' ' : '') + '<' + a.email_address + '>')}</option>`).join('')}</select></div></div>
          <div class="fwm-cf"><span class="fwm-cf__label">To</span><div class="fwm-cf__control" data-chips="to"></div>
            <div class="fwm-cf__toggles"><button type="button" data-show="cc" ${C.cc.length ? 'hidden' : ''}>Cc</button><button type="button" data-show="bcc" ${C.bcc.length ? 'hidden' : ''}>Bcc</button></div></div>
          <div class="fwm-cf" data-row="cc" ${C.cc.length ? '' : 'hidden'}><span class="fwm-cf__label">Cc</span><div class="fwm-cf__control" data-chips="cc"></div></div>
          <div class="fwm-cf" data-row="bcc" ${C.bcc.length ? '' : 'hidden'}><span class="fwm-cf__label">Bcc</span><div class="fwm-cf__control" data-chips="bcc"></div></div>
          <div class="fwm-cf fwm-cf--subject"><span class="fwm-cf__label">Subject</span><div class="fwm-cf__control"><input data-subject maxlength="500" value="${esc(C.subject)}" aria-label="Subject"></div></div>
        </div>
        <div class="fwm-editor" data-editor style="flex:1;min-height:0"></div>
        <div class="fwm-composer__atts" data-atts></div>
        <div class="fwm-composer__foot">
          <span class="fwm-sendgroup">
            <button class="btn btn--primary" data-c="send">${icon('send', 'i--sm')}Send</button>
            <button class="btn btn--primary" data-c="sendmenu" aria-label="Send options">${icon('chev-down', 'i--sm')}</button>
          </span>
          <button class="icon-btn" data-c="attach" aria-label="Attach files" data-tip="Attach files">${icon('clip')}</button>
          <button class="icon-btn" data-c="template" aria-label="Insert template" data-tip="Templates">${icon('template')}</button>
          <button class="icon-btn" data-c="signature" aria-label="Signature" data-tip="Signature">${icon('signature')}</button>
          <span class="fwm-composer__status" data-status></span>
          <button class="icon-btn" data-c="discard" aria-label="Discard draft" data-tip="Discard" data-tip-pos="left">${icon('trash')}</button>
          <input type="file" multiple hidden data-file>
        </div>
        <div class="fwm-composer__drop">Drop files to attach</div>`;
      document.body.appendChild(node);

      C.editor = Editor.create($('[data-editor]', node), { html, placeholder: 'Write your message…', onInput: () => { C.bodyTouched = true; markDirty(); } });
      C.editor.root.style.flex = '1';
      C.editor.root.style.minHeight = '0';
      C.editor.root.firstElementChild.style.height = '100%';
      const q = $('[data-fw-quote]', C.editor.area);
      if (q && foldQuote) {
        q.classList.add('is-folded');
        const t = document.createElement('button');
        t.type = 'button'; t.className = 'fwm-quote-toggle'; t.textContent = '•••'; t.setAttribute('aria-label', 'Show quoted text');
        t.addEventListener('click', () => { q.classList.toggle('is-folded'); });
        C.editor.root.firstElementChild.appendChild(t);
      }

      ['to', 'cc', 'bcc'].forEach((k) => chipInput($(`[data-chips="${k}"]`, node), k));
      renderAtts();

      $('[data-subject]', node).addEventListener('input', (e) => { C.subject = e.target.value; C.subjectTouched = true; $('[data-ctitle]', node).textContent = title(); markDirty(); });
      const fromSel = $('[data-from]', node);
      if (fromSel) fromSel.addEventListener('change', () => {
        C.accountId = Number(fromSel.value);
        swapSignature(signatureFor(C.accountId));
        markDirty();
      });
      $$('[data-show]', node).forEach((b) => b.addEventListener('click', () => {
        $(`[data-row="${b.dataset.show}"]`, node).hidden = false; b.hidden = true;
        $(`[data-row="${b.dataset.show}"] input`, node).focus();
      }));
      node.addEventListener('click', onClick);
      $('[data-cbar]', node).addEventListener('click', (e) => { if (!e.target.closest('button') && node.classList.contains('is-min')) node.classList.remove('is-min'); });
      $('[data-file]', node).addEventListener('change', (e) => { addFiles(Array.from(e.target.files)); e.target.value = ''; });
      node.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); send(); }
        if (e.key === 'Escape' && !activePop && !$('.fwm-suggest')) { e.preventDefault(); close(); }
      });
      // Drag & drop
      let dragDepth = 0;
      node.addEventListener('dragenter', (e) => { if (e.dataTransfer && Array.from(e.dataTransfer.types).includes('Files')) { dragDepth++; node.classList.add('is-dragging'); } });
      node.addEventListener('dragleave', () => { dragDepth = Math.max(0, dragDepth - 1); if (!dragDepth) node.classList.remove('is-dragging'); });
      node.addEventListener('dragover', (e) => { if (node.classList.contains('is-dragging')) e.preventDefault(); });
      node.addEventListener('drop', (e) => {
        if (!e.dataTransfer || !e.dataTransfer.files.length) return;
        e.preventDefault(); dragDepth = 0; node.classList.remove('is-dragging');
        addFiles(Array.from(e.dataTransfer.files));
      });

      setTimeout(() => {
        if (!C.to.length) $('[data-chips="to"] input', node).focus();
        else if (!C.subject) $('[data-subject]', node).focus();
        else C.editor.focus(true);
      }, 40);
    }

    function swapSignature(sig) {
      const area = C.editor.area;
      const cur = $('[data-fw-signature]', area);
      const html = sigBlock(sig);
      if (cur) { if (html) cur.outerHTML = html; else cur.remove(); }
      else if (html) {
        const quote = $('[data-fw-quote]', area);
        const tmp = document.createElement('div'); tmp.innerHTML = html;
        quote ? area.insertBefore(tmp.firstElementChild, quote) : area.appendChild(tmp.firstElementChild);
      }
    }

    // ---- recipient chips
    function chipInput(host, field) {
      const paint = () => {
        host.innerHTML = C[field].map((a, i) => `<span class="fwm-rchip ${a.invalid ? 'is-invalid' : ''}" title="${esc(a.email)}">${avatar(a.name, a.email)}<span>${esc(a.name || a.email)}</span><button type="button" data-rm="${i}" aria-label="Remove ${esc(a.email)}">${icon('x', 'i--xs')}</button></span>`).join('')
          + `<input type="text" inputmode="email" autocomplete="off" aria-label="${field === 'to' ? 'To' : field === 'cc' ? 'Cc' : 'Bcc'}" ${field === 'to' && !C.to.length ? 'placeholder="Recipients"' : ''}>`;
        bindInput($('input', host));
      };
      const commit = (text) => {
        const parts = String(text).split(/[,;\n]+/).map((s) => s.trim()).filter(Boolean);
        let added = false;
        parts.forEach((p) => {
          const m = p.match(/^\s*"?([^"<]*?)"?\s*<([^>]+)>\s*$/);
          const email = (m ? m[2] : p).trim().toLowerCase();
          const name = m ? m[1].trim() : '';
          if (C[field].some((a) => a.email === email)) return;
          const invalid = !/^[^\s@<>(),;:"]+@[^\s@<>(),;:"]+\.[^\s@<>(),;:"]{2,}$/.test(email);
          C[field].push({ email, name, invalid });
          added = true;
        });
        if (added) { markDirty(); paint(); $('input', host).focus(); if (field === 'to') fillTemplate(); }
        return added;
      };
      let sug = null;
      let sugItems = [];
      let sugIdx = 0;
      const closeSug = () => { if (sug) { sug.remove(); sug = null; } };
      const showSug = (inp, list) => {
        closeSug();
        sugItems = list.filter((c) => !C[field].some((a) => a.email === c.email));
        if (!sugItems.length) return;
        sugIdx = 0;
        sug = document.createElement('div');
        sug.className = 'fwm-suggest';
        sug.setAttribute('role', 'listbox');
        sug.innerHTML = sugItems.map((c, i) => `<div class="fwm-suggest__item ${i === 0 ? 'is-active' : ''}" role="option" data-i="${i}">${avatar(c.name, c.email)}
          <div class="fwm-suggest__text"><div class="fwm-suggest__name">${esc(c.name || c.email)}</div><div class="fwm-suggest__email">${esc(c.email)}${c.org ? ' · ' + esc(c.org) : ''}</div></div>
          <span class="fwm-suggest__src">${c.source === 'crm' ? 'CRM' : c.source === 'team' ? 'Team' : ''}</span></div>`).join('');
        document.body.appendChild(sug);
        const r = inp.getBoundingClientRect();
        sug.style.left = Math.min(r.left, window.innerWidth - sug.offsetWidth - 8) + 'px';
        const below = r.bottom + 4 + sug.offsetHeight < window.innerHeight;
        sug.style.top = (below ? r.bottom + 4 : r.top - sug.offsetHeight - 4) + 'px';
        sug.addEventListener('mousedown', (e) => {
          const it = e.target.closest('[data-i]');
          if (!it) return;
          e.preventDefault();
          pick(inp, Number(it.dataset.i));
        });
      };
      const pick = (inp, i) => {
        const c = sugItems[i];
        if (!c) return;
        closeSug();
        inp.value = '';
        C[field].push({ email: c.email, name: c.name });
        markDirty(); paint(); $('input', host).focus();
        if (field === 'to') fillTemplate();
      };
      const lookup = debounce(async (inp) => {
        const q = inp.value.trim();
        if (q.length < 1 || document.activeElement !== inp) { closeSug(); return; }
        try { const r = await api.get('contacts.php', { q }); if (inp.value.trim() === q && document.activeElement === inp) showSug(inp, r.contacts); } catch (e) { closeSug(); }
      }, 160);
      function bindInput(inp) {
        inp.addEventListener('input', () => { if (/[,;]$/.test(inp.value)) { const v = inp.value; inp.value = ''; commit(v); closeSug(); return; } lookup(inp); });
        inp.addEventListener('keydown', (e) => {
          if (sug && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
            e.preventDefault();
            sugIdx = (sugIdx + (e.key === 'ArrowDown' ? 1 : -1) + sugItems.length) % sugItems.length;
            $$('.fwm-suggest__item', sug).forEach((n, i) => n.classList.toggle('is-active', i === sugIdx));
            return;
          }
          if ((e.key === 'Enter' || e.key === 'Tab') && sug) { e.preventDefault(); pick(inp, sugIdx); return; }
          if ((e.key === 'Enter' || e.key === ',' || e.key === ';' || (e.key === 'Tab' && inp.value.trim())) && inp.value.trim()) {
            e.preventDefault(); const v = inp.value; inp.value = ''; commit(v); return;
          }
          if (e.key === 'Escape' && sug) { e.stopPropagation(); closeSug(); return; }
          if (e.key === 'Backspace' && !inp.value && C[field].length) { C[field].pop(); markDirty(); paint(); $('input', host).focus(); }
        });
        inp.addEventListener('paste', (e) => {
          const t = (e.clipboardData || window.clipboardData).getData('text');
          if (/[,;\n]/.test(t) || /@/.test(t)) { e.preventDefault(); commit(t); }
        });
        inp.addEventListener('blur', () => { setTimeout(closeSug, 120); if (inp.value.trim()) { const v = inp.value; inp.value = ''; commit(v); } });
      }
      host.addEventListener('click', (e) => {
        const rm = e.target.closest('[data-rm]');
        if (rm) { C[field].splice(Number(rm.dataset.rm), 1); markDirty(); paint(); $('input', host).focus(); return; }
        if (e.target === host) $('input', host).focus();
      });
      paint();
    }

    // A message started from a template fills its {placeholders} once the
    // first recipient is known — unless the user already edited the text.
    async function fillTemplate() {
      if (!C || !C.templateId || C.bodyTouched || !C.to.length) return;
      const myC = C;
      const tplId = C.templateId;
      C.templateId = null;
      try {
        const r = await api.get('template.php', { op: 'render', id: tplId, to: myC.to[0].email });
        if (myC !== C || myC.bodyTouched) return;
        const sig = $('[data-fw-signature]', myC.editor.area);
        myC.editor.setHTML(r.body_html + (sig ? sig.outerHTML : ''));
        if (!myC.subjectTouched && r.subject) { myC.subject = r.subject; $('[data-subject]', node).value = r.subject; $('[data-ctitle]', node).textContent = title(); }
        markDirty();
        if (r.unresolved.length) toast(`Fill in: ${r.unresolved.join(', ')}`, { timeout: 8000 });
      } catch (e) { /* keep the unfilled template */ }
    }

    // ---- attachments
    function renderAtts() {
      if (!node) return;
      const host = $('[data-atts]', node);
      const fwd = C.forwardAtts.filter((a) => !C.skipForward.includes(a.attachment_id));
      host.innerHTML = fwd.map((a) => `<span class="fwm-att" title="${esc(a.name)}">${icon('clip', 'i--sm')}<span>${esc(a.name)}</span><small>${fmt.size(a.size)}</small>
          <button class="icon-btn icon-btn--sm" data-rmfwd="${a.attachment_id}" aria-label="Remove">${icon('x', 'i--xs')}</button></span>`).join('')
        + C.attachments.map((a) => `<span class="fwm-att" title="${esc(a.name)}">${icon('clip', 'i--sm')}<span>${esc(a.name)}</span><small>${a.uploading ? Math.round((a.progress || 0) * 100) + '%' : fmt.size(a.size)}</small>
          ${a.uploading ? '' : `<button class="icon-btn icon-btn--sm" data-rmatt="${a.attachment_id}" aria-label="Remove ${esc(a.name)}">${icon('x', 'i--xs')}</button>`}
          ${a.uploading ? `<span class="fwm-att__bar" style="width:${Math.round((a.progress || 0) * 100)}%"></span>` : ''}</span>`).join('');
    }
    async function addFiles(files) {
      if (!files.length) return;
      const big = files.filter((f) => f.size > 20 * 1024 * 1024);
      big.forEach((f) => toast(`${f.name} is larger than 20 MB`, { type: 'error' }));
      files = files.filter((f) => f.size <= 20 * 1024 * 1024);
      if (!files.length) return;
      try { await save(true); } catch (e) { toastError(e); return; }
      for (const f of files) {
        const tmp = { attachment_id: 'tmp' + Math.random(), name: f.name, size: f.size, uploading: true, progress: 0 };
        C.attachments.push(tmp); C.uploads++; renderAtts();
        const fd = new FormData();
        fd.append('draft_id', C.draftId);
        fd.append('files[]', f);
        const myC = C;
        try {
          const r = await api.upload('upload.php', fd, (p) => { tmp.progress = p; renderAtts(); });
          myC.attachments.splice(myC.attachments.indexOf(tmp), 1, ...r.added);
          r.errors.forEach((er) => toast(er, { type: 'error' }));
        } catch (e) {
          myC.attachments.splice(myC.attachments.indexOf(tmp), 1);
          toastError(e);
        } finally {
          myC.uploads--;
          if (myC === C) renderAtts();
        }
      }
    }

    // ---- saving
    function markDirty() {
      if (!C) return;
      C.dirty = true;
      setStatus('');
      autosave();
    }
    const autosave = debounce(() => { if (C && C.dirty) save().catch(() => setStatus('Not saved — retrying', true)); }, 1500);
    function isEmpty() {
      return !C.to.length && !C.cc.length && !C.bcc.length && !C.subject.trim() && C.editor.textLength() < 1 && !C.attachments.length && C.mode === 'new';
    }
    function setStatus(text, err) {
      const s = node && $('[data-status]', node);
      if (s) { s.textContent = text; s.style.color = err ? 'var(--danger)' : ''; }
    }
    async function save(force) {
      if (!C) return null;
      if (!force && isEmpty()) return null;
      if (C.saving) { await C.saving.catch(() => {}); if (!C.dirty && C.draftId) return C.draftId; }
      const myC = C;
      const payload = {
        op: 'save', draft_id: C.draftId, account_id: C.accountId,
        to: C.to.filter((a) => !a.invalid).map((a) => a.name ? `${a.name} <${a.email}>` : a.email),
        cc: C.cc.filter((a) => !a.invalid).map((a) => a.name ? `${a.name} <${a.email}>` : a.email),
        bcc: C.bcc.filter((a) => !a.invalid).map((a) => a.email),
        subject: C.subject, body_html: C.editor.getHTML(),
        thread_key: C.threadKey, in_reply_to: C.inReplyTo, references: C.references,
        copy_attachments_from: C.draftId ? null : C.copyFrom, skip_attachments: C.skipForward,
      };
      C.dirty = false;
      setStatus('Saving…');
      C.saving = api.post('draft.php', payload).then((r) => {
        const isNew = !myC.draftId;
        myC.draftId = r.draft_id;
        if (isNew && myC.copyFrom) { myC.forwardAtts = []; myC.attachments = r.attachments; }
        myC.savedAt = new Date();
        if (myC === C) { setStatus('Saved ' + myC.savedAt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })); renderAtts(); }
        if (isNew) { refreshCounts(); if (S.view === 'drafts') loadThreads({ silent: true }); }
        return r.draft_id;
      }).catch((e) => { myC.dirty = true; if (myC === C) setStatus('Couldn\'t save draft', true); throw e; })
        .finally(() => { myC.saving = null; });
      return C.saving;
    }

    // ---- actions
    async function onClick(e) {
      const b = e.target.closest('[data-c]');
      if (b) {
        const a = b.dataset.c;
        if (a === 'min') node.classList.toggle('is-min');
        if (a === 'max') node.classList.toggle('is-max');
        if (a === 'close') close();
        if (a === 'send') send();
        if (a === 'sendmenu') sendMenu(b);
        if (a === 'attach') $('[data-file]', node).click();
        if (a === 'template') templateMenu(b);
        if (a === 'signature') signatureMenu(b);
        if (a === 'discard') discard();
        return;
      }
      const rm = e.target.closest('[data-rmatt]');
      if (rm) {
        const id = Number(rm.dataset.rmatt);
        api.post('upload.php', { op: 'remove', attachment_id: id }).then(() => {
          C.attachments = C.attachments.filter((a) => a.attachment_id !== id); renderAtts();
        }).catch(toastError);
        return;
      }
      const rf = e.target.closest('[data-rmfwd]');
      if (rf) { C.skipForward.push(Number(rf.dataset.rmfwd)); renderAtts(); }
    }

    function sendMenu(anchor) {
      const opts = snoozeTimes().slice(1);
      popover(anchor, `<div class="fwm-pop__title">Schedule send</div>
        ${opts.map(([v, l, d]) => `<button class="fwm-pop__item" data-v="${v}">${l} morning<small>${esc(d.toLocaleString([], { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }))}</small></button>`).join('')}
        <div class="fwm-pop__sep"></div><button class="fwm-pop__item" data-v="pick">${icon('scheduled')}Pick date &amp; time</button>`, {
        onClick: (it) => {
          if (it.dataset.v === 'pick') { pickWhen('Schedule send', 'Schedule').then((ts) => ts && send(ts)); return; }
          send(Math.floor(opts.find((o) => o[0] === it.dataset.v)[2].getTime() / 1000));
        },
      });
    }

    async function send(sendAt) {
      if (!C) return;
      const invalid = [...C.to, ...C.cc, ...C.bcc].filter((a) => a.invalid);
      if (invalid.length) { toast(`Check the address “${invalid[0].email}”`, { type: 'error' }); return; }
      if (!C.to.length && !C.cc.length && !C.bcc.length) { toast('Add at least one recipient', { type: 'error' }); $('[data-chips="to"] input', node).focus(); return; }
      if (C.uploads > 0) { toast('Wait for attachments to finish uploading', { type: 'error' }); return; }
      if (!C.subject.trim()) {
        const ok = await dialog({ title: 'Send without a subject?', body: 'Messages without a subject are easy to miss.', confirm: 'Send anyway', cancel: 'Add subject' });
        if (!ok) { $('[data-subject]', node).focus(); return; }
      }
      const text = (C.editor.area.innerText || '').replace(/\s+/g, ' ');
      const ownText = text.split('wrote:')[0];
      if (/\b(attach(ed|ment|ing)?|enclosed)\b/i.test(ownText) && !C.attachments.length && !C.forwardAtts.filter((a) => !C.skipForward.includes(a.attachment_id)).length) {
        const ok = await dialog({ title: 'Forgot an attachment?', body: 'You mention an attachment, but nothing is attached.', confirm: 'Send anyway', cancel: 'Go back' });
        if (!ok) return;
      }
      const sendBtn = $('[data-c="send"]', node);
      sendBtn.classList.add('is-busy');
      let draftId;
      try {
        C.dirty = true;
        draftId = await save(true);
        const r = await api.post('send.php', { op: 'send', draft_id: draftId, send_at: sendAt || null });
        S.counts = r.counts; renderNav();
        const subject = C.subject;
        destroy();
        if (r.sent) { toast('Message sent', { action: { label: 'View', run: () => go({ view: 'sent' }) } }); afterSend(); return; }
        if (sendAt) {
          toast(`Scheduled for ${fmt.when(r.send_at)}`, { action: { label: 'Undo', run: () => undoSend(draftId) } });
          if (S.view === 'outbox') loadThreads({ silent: true });
          return;
        }
        // Undo window, then flush.
        const secs = r.undo_seconds || 0;
        const t = toast('Sending…', { timeout: (secs + 1) * 1000, action: { label: 'Undo', run: () => { clearTimeout(timer); undoSend(draftId); } } });
        const timer = setTimeout(async () => {
          t.close();
          try {
            const f = await api.post('send.php', { op: 'flush' });
            S.counts = f.counts; renderNav();
            const failed = f.failed.find((x) => x.email_id === draftId);
            if (failed) toast(`“${subject || 'Message'}” wasn't sent: ${failed.error}`, { type: 'error', action: { label: 'Open', run: () => openFrom({ mode: 'draft', email_id: draftId }) } });
            else toast('Message sent', { action: { label: 'View', run: () => go({ view: 'sent' }) } });
            afterSend();
          } catch (e) { toastError(e); }
        }, secs * 1000);
      } catch (e) {
        if (sendBtn) sendBtn.classList.remove('is-busy');
        toastError(e);
      }
    }
    async function undoSend(draftId) {
      try {
        await api.post('send.php', { op: 'undo', email_id: draftId });
        refreshCounts();
        openFrom({ mode: 'draft', email_id: draftId });
        toast('Sending undone');
      } catch (e) { toastError(e); }
    }
    function afterSend() {
      refreshCounts();
      if (['sent', 'drafts', 'outbox'].includes(S.view)) loadThreads({ silent: true });
      if (S.openKey) openThread(S.openKey, { fromRoute: true });
    }

    async function templateMenu(anchor) {
      if (!S.templates.length) { toast('No templates yet', { action: { label: 'Create one', run: () => { location.href = '/mail/templates.php'; } } }); return; }
      popover(anchor, `<div class="fwm-pop__title">Insert template</div>${S.templates.map((t) => `<button class="fwm-pop__item" data-v="${t.template_id}">${icon('template')}<span style="min-width:0;overflow:hidden;text-overflow:ellipsis">${esc(t.name)}</span><small>${esc(t.category || '')}</small></button>`).join('')}
        <div class="fwm-pop__sep"></div><a class="fwm-pop__item" data-v="manage" href="/mail/templates.php">${icon('settings')}Manage templates</a>`, {
        width: 260,
        onClick: (it) => {
          if (it.dataset.v === 'manage') return true;
          const first = C.to[0];
          api.get('template.php', { op: 'render', id: it.dataset.v, to: first ? first.email : '' }).then((r) => {
            if (!C.subject.trim() && r.subject) { C.subject = r.subject; $('[data-subject]', node).value = r.subject; $('[data-ctitle]', node).textContent = title(); }
            const area = C.editor.area;
            const sig = $('[data-fw-signature]', area) || $('[data-fw-quote]', area);
            const blank = (area.innerText || '').replace($('[data-fw-signature]', area) ? $('[data-fw-signature]', area).innerText : '', '').replace(($('[data-fw-quote]', area) || {}).innerText || '', '').trim() === '';
            if (blank) {
              $$(':scope > p, :scope > div:not([data-fw-signature]):not([data-fw-quote]), :scope > br', area).forEach((n) => { if (!n.closest('[data-fw-signature],[data-fw-quote]')) n.remove(); });
              const tmp = document.createElement('div'); tmp.innerHTML = r.body_html;
              const frag = document.createDocumentFragment(); while (tmp.firstChild) frag.appendChild(tmp.firstChild);
              sig ? area.insertBefore(frag, sig) : area.appendChild(frag);
            } else {
              C.editor.insertHTML(r.body_html);
            }
            markDirty();
            if (r.unresolved.length) toast(`Fill in: ${r.unresolved.join(', ')}`, { timeout: 8000 });
          }).catch(toastError);
        },
      });
    }
    function signatureMenu(anchor) {
      const cur = $('[data-fw-signature]', C.editor.area);
      const curId = cur ? Number(cur.dataset.fwSignature) : 0;
      popover(anchor, `<div class="fwm-pop__title">Signature</div>
        <button class="fwm-pop__item ${!curId ? 'is-active' : ''}" data-v="0">No signature</button>
        ${S.signatures.map((s) => `<button class="fwm-pop__item ${curId === s.signature_id ? 'is-active' : ''}" data-v="${s.signature_id}">${icon('signature')}${esc(s.name)}${s.is_default ? '<small>Default</small>' : ''}</button>`).join('')}
        <div class="fwm-pop__sep"></div><a class="fwm-pop__item" data-v="manage" href="/mail/settings.php#signatures">${icon('settings')}Manage signatures</a>`, {
        onClick: (it) => {
          if (it.dataset.v === 'manage') return true;
          swapSignature(S.signatures.find((s) => s.signature_id === Number(it.dataset.v)) || null);
          markDirty();
        },
      });
    }

    async function discard() {
      if (!C) return;
      const id = C.draftId;
      destroy();
      if (id) {
        try { const r = await api.post('draft.php', { op: 'discard', draft_id: id }); S.counts = r.counts; renderNav(); if (S.view === 'drafts') loadThreads({ silent: true }); }
        catch (e) { toastError(e); }
      }
      toast('Draft discarded');
    }
    async function close(silent) {
      if (!C) return;
      const myC = C;
      if (myC.dirty && !isEmpty()) {
        destroy();
        try { await saveFor(myC); if (!silent) toast('Draft saved', { action: { label: 'Open', run: () => openFrom({ mode: 'draft', email_id: myC.draftId }) } }); }
        catch (e) { toast('Couldn\'t save the draft: ' + e.message, { type: 'error' }); }
        return;
      }
      destroy();
    }
    function saveFor(myC) {
      // Save a composer that is no longer on screen.
      const prev = C; C = myC;
      const p = save(true);
      C = prev;
      return p;
    }
    function destroy() {
      if (node) node.remove();
      $$('.fwm-suggest').forEach((n) => n.remove());
      node = null;
      C = null;
    }

    window.addEventListener('beforeunload', (e) => {
      if (C && (C.dirty || C.uploads || C.saving)) {
        if (C.dirty && !isEmpty()) {
          // Best effort: synchronous-ish save with keepalive.
          try {
            fetch('/mail/api/draft.php', {
              method: 'POST', keepalive: true, credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
              body: JSON.stringify({ op: 'save', draft_id: C.draftId, account_id: C.accountId, to: C.to.map((a) => a.email), cc: C.cc.map((a) => a.email), bcc: C.bcc.map((a) => a.email), subject: C.subject, body_html: C.editor.getHTML(), thread_key: C.threadKey, in_reply_to: C.inReplyTo, references: C.references }),
            });
          } catch (err) { /* ignore */ }
        }
        if (C.uploads) { e.preventDefault(); e.returnValue = ''; }
      }
    });

    return { openFrom, isOpen: () => !!C, close };
  })();

  $('#composeBtn').addEventListener('click', () => Composer.openFrom({ mode: 'new' }));
  $('#fabCompose').addEventListener('click', () => Composer.openFrom({ mode: 'new' }));

  // ---------------------------------------------------------------- keyboard
  let gPending = 0;
  document.addEventListener('keydown', (e) => {
    const t = e.target;
    const typing = t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName));
    if (typing || e.ctrlKey || e.metaKey || e.altKey) return;
    if ($$('.fwm-modal:not([hidden])').length || activePop) return;
    const k = e.key;
    const openKey = S.openKey;
    const focusKey = S.threads[S.focusIdx] ? S.threads[S.focusIdx].key : null;
    const targetKeys = S.selected.size ? Array.from(S.selected) : (openKey || focusKey ? [openKey || focusKey] : []);

    if (gPending && Date.now() - gPending < 1200) {
      gPending = 0;
      const map = { i: 'inbox', s: 'starred', t: 'sent', d: 'drafts', a: 'all', b: 'snoozed' };
      if (map[k]) { e.preventDefault(); closeReader({ silent: true }); go({ view: map[k] }); }
      return;
    }
    switch (k) {
      case 'g': gPending = Date.now(); return;
      case '?': e.preventDefault(); openModal('shortcutsModal'); return;
      case '/': e.preventDefault(); if (el.search) el.search.focus(); return;
      case 'c': e.preventDefault(); Composer.openFrom({ mode: 'new' }); return;
      case 'j': case 'ArrowDown': if (k === 'ArrowDown' && openKey && !narrow()) return; e.preventDefault(); moveFocus(1); return;
      case 'k': case 'ArrowUp': if (k === 'ArrowUp' && openKey && !narrow()) return; e.preventDefault(); moveFocus(-1); return;
      case 'Enter': case 'o': if (focusKey) { e.preventDefault(); if (S.view === 'drafts') Composer.openFrom({ mode: 'draft', email_id: S.threads[S.focusIdx].latest_email_id }); else openThread(focusKey); } return;
      case 'Escape': case 'u': if (S.selected.size) { S.selected.clear(); renderList(); } else if (openKey) closeReader(); return;
      case 'x': if (focusKey) toggleSelect(focusKey, S.focusIdx, false); return;
      case 'e': if (targetKeys.length && !['trash', 'spam', 'archive', 'drafts', 'outbox'].includes(S.view)) act(targetKeys, 'archive'); return;
      case '#': case 'Delete': if (targetKeys.length) S.view === 'trash' ? deleteForever(targetKeys) : act(targetKeys, 'trash'); return;
      case '!': if (targetKeys.length && !['spam', 'drafts', 'outbox', 'sent'].includes(S.view)) act(targetKeys, 'spam'); return;
      case 's': if (targetKeys.length) { const th = S.threads.find((x) => x.key === targetKeys[0]); act(targetKeys, th && th.starred ? 'unstar' : 'star'); } return;
      case 'U': if (targetKeys.length) act(targetKeys, 'unread'); return;
      case 'I': if (targetKeys.length) act(targetKeys, 'read'); return;
      case 'b': if (targetKeys.length && S.view === 'inbox') { const a = $('[data-rb="snooze"]') || $('[data-bulk="snooze"]') || el.listHead; snoozeMenu(a, targetKeys); } return;
      case 'l': if (targetKeys.length) { const a = $('[data-rb="label"]') || $('[data-bulk="label"]') || el.listHead; labelMenu(a, targetKeys); } return;
      case 'r': case 'a': case 'f':
        if (openKey && S.thread) {
          e.preventDefault();
          const last = S.thread.messages.filter((m) => !m.is_draft && m.folder !== 'Outbox').pop();
          if (last) Composer.openFrom({ mode: k === 'r' ? 'reply' : k === 'a' ? 'reply_all' : 'forward', email_id: last.email_id });
        }
        return;
      case 'z': if (S.undo) { const u = S.undo; S.undo = null; u(); } return;
      default:
    }
  });
  function moveFocus(dir) {
    if (!S.threads.length) return;
    let i = S.focusIdx < 0 ? (S.openKey ? S.threads.findIndex((t) => t.key === S.openKey) : -1) : S.focusIdx;
    i = Math.max(0, Math.min(S.threads.length - 1, i + dir));
    S.focusIdx = i;
    $$('.fwm-row', el.threads).forEach((r) => r.classList.toggle('is-focus', Number(r.dataset.idx) === i));
    const row = el.threads.querySelector(`.fwm-row[data-idx="${i}"]`);
    if (row) row.scrollIntoView({ block: 'nearest' });
    if (S.openKey && !narrow() && S.prefs.reading_pane !== 'off' && S.view !== 'drafts') openThread(S.threads[i].key);
  }

  // ---------------------------------------------------------------- background
  async function refreshCounts() {
    try {
      const r = await api.get('counts.php');
      const prevLatest = S.latestId;
      S.counts = r.counts;
      S.latestId = r.counts.latest_id || 0;
      r.accounts.forEach((a) => { const acc = accountById(a.account_id); if (acc) Object.assign(acc, { last_sync_at: a.last_sync_at, last_error: a.last_error }); });
      renderNav();
      renderListHead();
      return S.latestId > prevLatest;
    } catch (e) { return false; }
  }
  async function sync(force) {
    if (S.syncing) return;
    S.syncing = true;
    if (el.syncBtn) el.syncBtn.classList.add('is-spinning');
    renderSideFoot();
    try {
      const r = await api.post('sync.php', { force: !!force });
      S.lastSyncAt = Date.now();
      S.counts = r.counts;
      const before = S.latestId;
      S.latestId = r.counts.latest_id || S.latestId;
      r.accounts.forEach((a) => { const acc = accountById(a.account_id); if (acc) Object.assign(acc, { last_sync_at: a.last_sync_at, last_error: a.last_error }); });
      renderNav();
      if (r.new > 0 || S.latestId > before || r.sent > 0) {
        if (!S.selected.size && el.threads.scrollTop < 200) loadThreads({ silent: true });
        if (r.new > 0 && !force && document.visibilityState === 'visible') toast(r.new === 1 ? '1 new message' : `${r.new} new messages`, { action: S.view !== 'inbox' ? { label: 'Inbox', run: () => go({ view: 'inbox' }) } : null });
      }
      if (force) {
        if (r.errors.length) toast(`${r.errors[0].account}: ${r.errors[0].error}`, { type: 'error', action: { label: 'Settings', run: () => { location.href = '/mail/settings.php#accounts'; } } });
        else toast(r.new ? (r.new === 1 ? '1 new message' : `${r.new} new messages`) : 'You\'re up to date');
      }
    } catch (e) {
      if (force) toastError(e);
    } finally {
      S.syncing = false;
      if (el.syncBtn) el.syncBtn.classList.remove('is-spinning');
      renderSideFoot();
    }
  }
  function startBackground() {
    if (el.syncBtn) el.syncBtn.addEventListener('click', () => sync(true));
    // Pull new mail shortly after load, then on the user's interval.
    setTimeout(() => sync(false), 1200);
    const every = Math.max(1, Number(S.prefs.sync_minutes) || 3) * 60000;
    setInterval(() => { if (document.visibilityState === 'visible') sync(false); }, every);
    // Counters (picks up cron-delivered mail between syncs).
    setInterval(async () => {
      if (document.visibilityState !== 'visible') return;
      const fresh = await refreshCounts();
      if (fresh && !S.selected.size && el.threads.scrollTop < 200) loadThreads({ silent: true });
    }, 45000);
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible' && Date.now() - S.lastSyncAt > 60000) sync(false);
    });
    // Relative "synced x ago" label.
    setInterval(renderSideFoot, 30000);
  }

  window.addEventListener('resize', debounce(() => {
    if (!narrow()) toggleDrawer(false);
  }, 150));

  readerEmpty();
  boot();
})();
