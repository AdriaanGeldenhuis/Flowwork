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
    const body = document.querySelector('.fw-payroll');
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

  // ========== DIMENSION 3D ENGINE ==========
  const REDUCE_MOTION = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Delegated pointer engine: feeds the CSS custom properties (--rx/--ry for
  // tilt, --mx/--my for the specular glare) that payroll.css composes into the
  // KPI slab transform. Works for cards rendered at any time (the overview
  // dashboard hydrates its KPI values via fetch after load).
  function init3DTilt() {
    if (REDUCE_MOTION) return;
    if (!window.matchMedia('(pointer: fine)').matches) return;

    const SELECTOR = '.kpi';
    const MAX_TILT = 6; // degrees
    let activeCard = null;
    let lastEvent = null;
    let rafId = 0;

    function applyFrame() {
      rafId = 0;
      if (!activeCard || !lastEvent) return;

      const rect = activeCard.getBoundingClientRect();
      if (!rect.width || !rect.height) return;

      const px = (lastEvent.clientX - rect.left) / rect.width;
      const py = (lastEvent.clientY - rect.top) / rect.height;
      const rx = (0.5 - py) * MAX_TILT;
      const ry = (px - 0.5) * MAX_TILT;

      activeCard.style.setProperty('--rx', rx.toFixed(2) + 'deg');
      activeCard.style.setProperty('--ry', ry.toFixed(2) + 'deg');
      activeCard.style.setProperty('--mx', (px * 100).toFixed(1) + '%');
      activeCard.style.setProperty('--my', (py * 100).toFixed(1) + '%');
    }

    function resetCard(card) {
      if (!card) return;
      card.style.removeProperty('--rx');
      card.style.removeProperty('--ry');
      card.style.removeProperty('--mx');
      card.style.removeProperty('--my');
    }

    document.addEventListener('pointermove', function(e) {
      if (e.buttons > 0) return; // don't tilt mid-drag / text selection
      const card = e.target && e.target.closest ? e.target.closest(SELECTOR) : null;

      if (card !== activeCard) {
        resetCard(activeCard);
        activeCard = card;
      }
      if (!card) return;

      lastEvent = e;
      if (!rafId) rafId = requestAnimationFrame(applyFrame);
    }, { passive: true });

    // Pointer left the page entirely (no pointermove fires on the way out)
    document.documentElement.addEventListener('pointerleave', function() {
      resetCard(activeCard);
      activeCard = null;
    });
  }

  // ========== LOGO TILE PLAYFUL TILT ==========
  function initLogoTileEffect() {
    const logoTile = document.querySelector('.fw-payroll__logo-tile');
    if (!logoTile || REDUCE_MOTION) return;

    logoTile.addEventListener('mouseenter', function() {
      this.style.transform = 'scale(1.05) rotate(-3deg)';
    });

    logoTile.addEventListener('mouseleave', function() {
      this.style.transform = '';
    });
  }

  // ========== INIT ==========
  function init() {
    initTheme();
    initKebabMenu();
    init3DTilt();
    initLogoTileEffect();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();