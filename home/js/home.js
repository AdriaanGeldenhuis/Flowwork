// /home/js/home.js — Flowwork home page.
// Theme switch, menu, notifications bell, the "Where to?" jump box, the
// engraved clock and rosette, the next-event countdown and the module tiles'
// spotlight. Boot data: <script id="homeBoot"> (now, name, jump).
(function () {
  'use strict';

  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const body = document.body;
  const REDUCE = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const FINE = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  const csrf = () => ($('meta[name="csrf-token"]') || {}).content || '';
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const icon = (id, cls) => `<svg class="i${cls ? ' ' + cls : ''}" aria-hidden="true"><use href="#${id}"/></svg>`;
  const safeUrl = (u) => (typeof u === 'string' && u.charAt(0) === '/' && u.charAt(1) !== '/' && u.charAt(1) !== '\\') ? u : null;

  let boot = { now: null, name: '', jump: [] };
  try { boot = Object.assign(boot, JSON.parse(($('#homeBoot') || {}).textContent || '{}')); } catch (e) { /* keep defaults */ }

  // Company-local "now", kept moving from the moment the page was rendered.
  const parseLocal = (s) => {
    const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?/.exec(s || '');
    return m ? new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0)) : null;
  };
  const bootNow = parseLocal(boot.now);
  const loadedAt = Date.now();
  const companyNow = () => new Date((bootNow ? bootNow.getTime() : loadedAt) + (Date.now() - loadedAt));

  // ===========================================================================
  // Popovers (menu, bell) — one open at a time
  // ===========================================================================
  let openPop = null;
  function placePop(pop, anchor) {
    const r = anchor.getBoundingClientRect();
    pop.style.top = Math.round(r.bottom + 8) + 'px';
    pop.style.left = '0px';
    const w = pop.offsetWidth;
    const left = Math.min(Math.max(12, r.right - w), window.innerWidth - w - 12);
    pop.style.left = Math.round(left) + 'px';
  }
  function closePop(restoreFocus) {
    if (!openPop) return;
    const { pop, anchor, owned } = openPop;
    openPop = null;
    anchor.setAttribute('aria-expanded', 'false');
    if (owned) pop.remove(); else pop.hidden = true;
    if (restoreFocus) anchor.focus();
  }
  function showPop(pop, anchor, owned) {
    closePop(false);
    if (owned) document.body.appendChild(pop);
    pop.hidden = false;
    placePop(pop, anchor);
    anchor.setAttribute('aria-expanded', 'true');
    openPop = { pop, anchor, owned };
  }
  document.addEventListener('pointerdown', (ev) => {
    if (openPop && !openPop.pop.contains(ev.target) && !openPop.anchor.contains(ev.target)) closePop(false);
  });
  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape' && openPop) { ev.preventDefault(); closePop(true); }
  });
  window.addEventListener('resize', () => { if (openPop) placePop(openPop.pop, openPop.anchor); });

  // Arrow-key navigation inside a popover's items
  function menuKeys(pop, selector) {
    pop.addEventListener('keydown', (ev) => {
      if (ev.key !== 'ArrowDown' && ev.key !== 'ArrowUp' && ev.key !== 'Home' && ev.key !== 'End') return;
      const items = $$(selector, pop).filter((el) => !el.disabled);
      if (!items.length) return;
      ev.preventDefault();
      const i = items.indexOf(document.activeElement);
      const next = ev.key === 'Home' ? 0 : ev.key === 'End' ? items.length - 1
        : ev.key === 'ArrowDown' ? (i + 1) % items.length : (i - 1 + items.length) % items.length;
      items[next].focus();
    });
  }

  // ===========================================================================
  // Theme
  // ===========================================================================
  function initTheme() {
    const btn = $('#themeToggle');
    if (!btn) return;
    btn.addEventListener('click', () => {
      const t = body.dataset.theme === 'dark' ? 'light' : 'dark';
      body.dataset.theme = t;
      btn.innerHTML = icon(t === 'dark' ? 'i-sun' : 'i-moon');
      btn.setAttribute('data-tip', t === 'dark' ? 'Light theme' : 'Dark theme');
      const meta = $('meta[name="theme-color"]');
      if (meta) meta.setAttribute('content', t === 'dark' ? '#0d0a09' : '#f2ece3');
      document.cookie = 'fw_theme=' + t + ';max-age=' + (365 * 86400) + ';path=/;SameSite=Lax';
    });
  }

  // ===========================================================================
  // Menu (server-rendered: company switcher, admin, devices, sign out)
  // ===========================================================================
  function initMenu() {
    const btn = $('#kebabBtn');
    const menu = $('#kebabMenu');
    if (!btn || !menu) return;
    menuKeys(menu, '.fwm-pop__item');
    btn.addEventListener('click', () => {
      if (openPop && openPop.pop === menu) return closePop(false);
      showPop(menu, btn, false);
      const first = $$('.fwm-pop__item', menu).find((el) => !el.disabled);
      if (first) first.focus();
    });
    btn.addEventListener('keydown', (ev) => { if (ev.key === 'ArrowDown') { ev.preventDefault(); btn.click(); } });
  }

  // ===========================================================================
  // Notifications bell (/notifications/api/list.php · read.php)
  // ===========================================================================
  function initBell() {
    const btn = $('#bellBtn');
    const badge = $('#bellBadge');
    if (!btn) return;
    let notes = [];
    let unread = 0;

    const setBadge = () => {
      if (!badge) return;
      badge.hidden = unread <= 0;
      badge.textContent = unread > 99 ? '99+' : String(unread);
      btn.setAttribute('aria-label', unread > 0 ? `Notifications, ${unread} unread` : 'Notifications');
    };
    const load = async () => {
      try {
        const res = await fetch('/notifications/api/list.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const data = await res.json();
        if (!data || !data.ok) return false;
        notes = Array.isArray(data.notifications) ? data.notifications : [];
        unread = +data.unread_count || 0;
        setBadge();
        return true;
      } catch (e) { return false; }
    };
    const markRead = (payload) => fetch('/notifications/api/read.php', {
      method: 'POST', credentials: 'same-origin', keepalive: true,
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() }, body: JSON.stringify(payload),
    }).catch(() => null);

    const ago = (s) => {
      const d = s ? new Date(String(s).replace(' ', 'T')) : null;
      if (!d || isNaN(d)) return '';
      const sec = Math.max(0, (Date.now() - d.getTime()) / 1000);
      if (sec < 60) return 'Just now';
      if (sec < 3600) return Math.floor(sec / 60) + ' min ago';
      if (sec < 86400) return Math.floor(sec / 3600) + ' h ago';
      if (sec < 7 * 86400) return Math.floor(sec / 86400) + ' d ago';
      return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
    };

    function render(pop, failed) {
      const list = notes.slice(0, 30);
      pop.innerHTML = `
        <div class="fwh-bell__head">
          <h2 id="bellTitle">Notifications</h2>
          ${unread > 0 ? '<button class="btn btn--ghost btn--sm" type="button" data-v="all">Mark all read</button>' : ''}
        </div>
        <div class="fwm-pop__sep" style="margin:0 14px 6px"></div>
        <div class="fwh-bell__list">
          ${failed ? '<div class="fwh-bell__empty"><b>Could not load</b>Please try again in a moment.</div>'
            : list.length ? list.map((n, i) => `
            <button class="fwh-note${+n.is_read ? '' : ' is-unread'}" type="button" data-i="${i}">
              <span class="fwh-note__dot" aria-hidden="true"></span>
              <span>
                <span class="fwh-note__title">${esc(n.title || 'Notification')}</span>
                ${n.message ? `<span class="fwh-note__msg">${esc(n.message)}</span>` : ''}
                <span class="fwh-note__time">${esc(ago(n.created_at))}</span>
              </span>
            </button>`).join('')
            : '<div class="fwh-bell__empty"><b>All caught up</b>New mentions, reminders and mail appear here.</div>'}
        </div>`;
    }

    btn.addEventListener('click', async () => {
      if (openPop && openPop.anchor === btn) return closePop(false);
      const pop = document.createElement('div');
      pop.className = 'fwm-pop fwh-bell';
      pop.setAttribute('role', 'dialog');
      pop.setAttribute('aria-labelledby', 'bellTitle');
      render(pop, false);
      showPop(pop, btn, true);
      menuKeys(pop, '.fwh-note, [data-v="all"]');
      pop.addEventListener('click', (ev) => {
        const all = ev.target.closest('[data-v="all"]');
        if (all) {
          markRead({ all: true });
          notes.forEach((n) => { n.is_read = 1; });
          unread = 0; setBadge(); render(pop, false);
          return;
        }
        const item = ev.target.closest('.fwh-note');
        if (!item) return;
        const n = notes[+item.dataset.i];
        if (!n) return;
        if (!+n.is_read) { n.is_read = 1; unread = Math.max(0, unread - 1); setBadge(); markRead({ id: n.id }); }
        const url = safeUrl(n.url) || safeUrl(n.link);
        if (url) location.href = url; else render(pop, false);
      });
      const ok = await load();
      if (openPop && openPop.pop === pop) { render(pop, !ok && !notes.length); placePop(pop, btn); }
    });

    load();
    // Keep the badge fresh when coming back to the tab.
    document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
  }

  // ===========================================================================
  // "Where to?" — jump to a module, action or page
  // ===========================================================================
  const MODULE_COLOURS = {};
  $$('.fwh-tile').forEach((t) => { MODULE_COLOURS[t.getAttribute('href')] = t.style.getPropertyValue('--c').trim(); });

  function initJump() {
    const input = $('#jumpInput');
    const list = $('#jumpList');
    if (!input || !list) return;
    const items = (boot.jump || []).filter((d) => d && d.name && d.href);
    let shown = [];
    let active = -1;

    const norm = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    const score = (d, q) => {
      const name = norm(d.name);
      if (name.startsWith(q)) return 0;
      if (name.split(/[\s&/-]+/).some((w) => w.startsWith(q))) return 1;
      if (name.includes(q)) return 2;
      if (norm(d.kw + ' ' + d.hint).includes(q)) return 3;
      return -1;
    };
    const mark = (name, q) => {
      if (!q) return esc(name);
      const i = norm(name).indexOf(q);
      return i < 0 ? esc(name) : esc(name.slice(0, i)) + '<mark>' + esc(name.slice(i, i + q.length)) + '</mark>' + esc(name.slice(i + q.length));
    };

    function render() {
      const q = norm(input.value.trim());
      if (q) {
        shown = items.map((d) => ({ d, s: score(d, q) })).filter((x) => x.s >= 0).sort((a, b) => a.s - b.s).slice(0, 9).map((x) => x.d);
      } else {
        shown = items.slice();
      }
      active = shown.length ? 0 : -1;
      let html = '';
      let group = null;
      shown.forEach((d, i) => {
        if (!q && d.group !== group) { group = d.group; html += `<div class="fwh-jump__group" role="presentation">${esc(group)}</div>`; }
        const c = MODULE_COLOURS[d.href];
        html += `<div class="fwh-opt" role="option" id="jumpOpt${i}" data-i="${i}" aria-selected="${i === active}"${c ? ` style="--c:${esc(c)}"` : ''}>
          <span class="fwh-opt__icon">${icon(d.icon || 'i-chev-right')}</span>
          <span class="fwh-opt__text"><span class="fwh-opt__name">${mark(d.name, q)}</span>${d.hint ? `<span class="fwh-opt__hint">${esc(d.hint)}</span>` : ''}</span>
          <span class="fwh-opt__enter" aria-hidden="true">↵</span>
        </div>`;
      });
      list.innerHTML = html || `<div class="fwh-jump__none">Nothing called “${esc(input.value.trim())}”.</div>`;
      setActive(active);
    }
    function setActive(i) {
      active = i;
      $$('.fwh-opt', list).forEach((o) => o.setAttribute('aria-selected', String(+o.dataset.i === i)));
      const el = i >= 0 ? $('#jumpOpt' + i, list) : null;
      if (el) { input.setAttribute('aria-activedescendant', el.id); el.scrollIntoView({ block: 'nearest' }); }
      else input.removeAttribute('aria-activedescendant');
    }
    function open() {
      closePop(false);
      render();
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }
    function close() {
      list.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
    }
    const go = (d) => { if (d && d.href) location.href = d.href; };

    input.addEventListener('focus', open);
    input.addEventListener('input', () => { if (list.hidden) open(); else render(); });
    input.addEventListener('keydown', (ev) => {
      if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        ev.preventDefault();
        if (list.hidden) return open();
        if (!shown.length) return;
        setActive(ev.key === 'ArrowDown' ? (active + 1) % shown.length : (active - 1 + shown.length) % shown.length);
      } else if (ev.key === 'Enter') {
        ev.preventDefault();
        go(shown[active]);
      } else if (ev.key === 'Escape') {
        ev.preventDefault();
        if (input.value) { input.value = ''; render(); } else { close(); input.blur(); }
      }
    });
    list.addEventListener('pointerdown', (ev) => ev.preventDefault()); // keep focus in the input
    list.addEventListener('click', (ev) => {
      const o = ev.target.closest('.fwh-opt');
      if (o) go(shown[+o.dataset.i]);
    });
    list.addEventListener('pointermove', (ev) => {
      const o = ev.target.closest('.fwh-opt');
      if (o && +o.dataset.i !== active) setActive(+o.dataset.i);
    });
    input.addEventListener('blur', () => setTimeout(close, 120));

    // "/" or Ctrl/⌘+K from anywhere on the page
    document.addEventListener('keydown', (ev) => {
      const t = ev.target;
      const typing = t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName));
      if ((ev.key === '/' && !typing && !ev.ctrlKey && !ev.metaKey && !ev.altKey) || ((ev.ctrlKey || ev.metaKey) && (ev.key === 'k' || ev.key === 'K'))) {
        ev.preventDefault();
        input.focus();
        input.select();
      }
    });
  }

  // ===========================================================================
  // Clock, greeting and the engraved rosette behind it
  // ===========================================================================
  function initClock() {
    const hH = $('#handH'), hM = $('#handM'), hS = $('#handS');
    const text = $('#clockText');
    const greet = $('#greeting');
    if (!hH || !hM || !hS) return;
    const pad = (n) => (n < 10 ? '0' : '') + n;
    let lastMinute = -1;

    function frame() {
      const d = new Date();
      const s = d.getSeconds() + (REDUCE ? 0 : d.getMilliseconds() / 1000);
      const m = d.getMinutes() + s / 60;
      const h = (d.getHours() % 12) + m / 60;
      hS.setAttribute('transform', `rotate(${(s * 6).toFixed(2)})`);
      hM.setAttribute('transform', `rotate(${(m * 6).toFixed(2)})`);
      hH.setAttribute('transform', `rotate(${(h * 30).toFixed(2)})`);
      if (d.getMinutes() !== lastMinute) {
        lastMinute = d.getMinutes();
        if (text) text.textContent = pad(d.getHours()) + ':' + pad(d.getMinutes());
        if (greet) {
          const hr = d.getHours();
          const g = hr < 12 ? 'Good morning' : hr < 17 ? 'Good afternoon' : 'Good evening';
          if (greet.textContent !== g) greet.textContent = g;
        }
      }
    }
    frame();
    if (REDUCE) { setInterval(frame, 1000); return; }
    const loop = () => { frame(); requestAnimationFrame(loop); };
    requestAnimationFrame(loop);
  }

  // Banknote-style guilloché: phase-shifted wavy rings that interlace.
  function initGuilloche() {
    const svg = $('#guilloche');
    if (!svg || getComputedStyle(svg).display === 'none') return;
    const NS = 'http://www.w3.org/2000/svg';
    const ring = (R, A, k, phase, steps, twist) => {
      let d = '';
      for (let i = 0; i <= steps; i++) {
        const t = (i / steps) * Math.PI * 2;
        const r = R + A * Math.sin(k * t + phase) + (twist || 0) * Math.sin(3 * t + phase * 0.5);
        d += (i ? 'L' : 'M') + (r * Math.cos(t)).toFixed(2) + ' ' + (r * Math.sin(t)).toFixed(2);
      }
      return d + 'Z';
    };
    const layers = [
      { R: 168, A: 13, k: 20, n: 22, w: 0.5, twist: 2 },
      { R: 146, A: 5, k: 40, n: 8, w: 0.4 },
      { R: 186, A: 4, k: 60, n: 4, w: 0.35 },
    ];
    const frag = document.createDocumentFragment();
    layers.forEach((L) => {
      for (let j = 0; j < L.n; j++) {
        const p = document.createElementNS(NS, 'path');
        p.setAttribute('d', ring(L.R, L.A, L.k, (j / L.n) * Math.PI * 2, 720, L.twist));
        p.setAttribute('stroke-width', String(L.w));
        frag.appendChild(p);
      }
    });
    [134, 196].forEach((r) => {
      const c = document.createElementNS(NS, 'circle');
      c.setAttribute('r', String(r));
      c.setAttribute('stroke-width', '0.6');
      c.setAttribute('stroke-dasharray', r === 196 ? '0.6 3.2' : '');
      frag.appendChild(c);
    });
    svg.appendChild(frag);
  }

  // ===========================================================================
  // "Up next" countdown (company-local times)
  // ===========================================================================
  function initCountdown() {
    const el = $('.fwh-next__in[data-start]');
    if (!el) return;
    const start = parseLocal(el.dataset.start);
    const end = parseLocal(el.dataset.end);
    const label = $('.fwh-next__label');
    if (!start) return;
    const span = (ms) => {
      const min = Math.round(ms / 60000);
      if (min < 1) return 'less than a minute';
      if (min < 60) return min + ' min';
      const h = Math.floor(min / 60), r = min % 60;
      return h + ' h' + (r ? ' ' + r + ' min' : '');
    };
    function tick() {
      const now = companyNow();
      if (now < start) {
        el.textContent = 'Starts in ' + span(start - now);
      } else if (end && now < end) {
        el.textContent = 'Ends in ' + span(end - now);
        if (label) label.textContent = 'Happening now';
      } else {
        el.textContent = 'Finished';
      }
    }
    tick();
    setInterval(tick, 30000);
  }

  // ===========================================================================
  // Top bar shadow once the page scrolls
  // ===========================================================================
  function initStuck() {
    const top = $('.fwh-top');
    if (!top) return;
    const on = () => top.classList.toggle('is-stuck', window.scrollY > 4);
    on();
    window.addEventListener('scroll', on, { passive: true });
  }

  // ===========================================================================
  // Module tiles: a soft light that follows the pointer and a gentle tilt
  // ===========================================================================
  function initTiles() {
    if (!FINE || REDUCE) return;
    $$('.fwh-tile').forEach((tile) => {
      let raf = 0;
      let pt = null;
      const apply = () => {
        raf = 0;
        if (!pt) return;
        const r = tile.getBoundingClientRect();
        const x = (pt.x - r.left) / r.width;
        const y = (pt.y - r.top) / r.height;
        tile.style.setProperty('--mx', (x * 100).toFixed(1) + '%');
        tile.style.setProperty('--my', (y * 100).toFixed(1) + '%');
        tile.style.transform = `perspective(900px) translateY(-4px) rotateX(${((0.5 - y) * 6).toFixed(2)}deg) rotateY(${((x - 0.5) * 7).toFixed(2)}deg)`;
      };
      tile.addEventListener('pointermove', (ev) => {
        pt = { x: ev.clientX, y: ev.clientY };
        if (!raf) raf = requestAnimationFrame(apply);
      });
      tile.addEventListener('pointerleave', () => {
        pt = null;
        if (raf) { cancelAnimationFrame(raf); raf = 0; }
        tile.style.transform = '';
      });
    });
  }

  // ===========================================================================
  initTheme();
  initMenu();
  initBell();
  initJump();
  initGuilloche();
  initClock();
  initCountdown();
  initStuck();
  initTiles();
})();
