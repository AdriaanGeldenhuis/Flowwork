/**
 * ============================================================================
 * PROJECTS HEADER UI CONTROLLER
 * Theme toggle with COOKIE (same as home.js)
 * Kebab menu interactions
 * Uses COOKIE key: 'fw_theme' (NOT localStorage)
 * ============================================================================
 */

(function() {
  'use strict';

  const THEME_COOKIE_NAME = 'fw_theme';
  const THEME_DARK = 'dark';
  const THEME_LIGHT = 'light';
  const REDUCE_MOTION = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ===== COOKIE HELPERS =====
  function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
    return match ? match[2] : null;
  }

  function setCookie(name, value, days = 365) {
    const date = new Date();
    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
    document.cookie = name + '=' + value + ';expires=' + date.toUTCString() + ';path=/;SameSite=Lax';
  }

  // ===== THEME TOGGLE =====
  function initThemeToggle() {
    const toggleButton = document.getElementById('themeToggle');
    const body = document.querySelector('.fw-proj') || document.querySelector('.fw-crm');

    if (!toggleButton || !body) {
      console.warn('⚠️ ProHeader: Theme toggle elements not found');
      return;
    }

    // Prevent double initialization
    if (toggleButton.dataset.initialized === 'true') {
      console.log('✅ ProHeader: Theme toggle already initialized');
      return;
    }

    // Initialize theme from COOKIE (same as home.js)
    const savedTheme = getCookie(THEME_COOKIE_NAME) || THEME_LIGHT;
    applyTheme(savedTheme);

    // Toggle theme on button click
    toggleButton.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      
      const currentTheme = body.getAttribute('data-theme') || THEME_LIGHT;
      const newTheme = currentTheme === THEME_DARK ? THEME_LIGHT : THEME_DARK;
      
      applyTheme(newTheme);
      setCookie(THEME_COOKIE_NAME, newTheme);
      
      console.log('🎨 ProHeader: Theme toggled to:', newTheme);
    });

    function applyTheme(theme) {
      body.setAttribute('data-theme', theme);
      toggleButton.setAttribute('aria-label', 
        theme === THEME_DARK ? 'Switch to light mode' : 'Switch to dark mode'
      );
    }

    toggleButton.dataset.initialized = 'true';
    console.log('✅ ProHeader: Theme toggle initialized with theme:', savedTheme);
  }

  // ===== KEBAB MENU =====
  function initKebabMenu() {
    const toggleButton = document.getElementById('kebabToggle');
    const menu = document.getElementById('kebabMenu');

    if (!toggleButton || !menu) {
      console.warn('⚠️ ProHeader: Kebab menu elements not found');
      return;
    }

    if (toggleButton.dataset.initialized === 'true') {
      console.log('✅ ProHeader: Kebab menu already initialized');
      return;
    }

    menu.setAttribute('aria-hidden', 'true');
    toggleButton.setAttribute('aria-expanded', 'false');

    toggleButton.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      const isOpen = menu.getAttribute('aria-hidden') === 'false';
      setMenuState(!isOpen);
    });

    document.addEventListener('click', function(e) {
      if (!menu.contains(e.target) && !toggleButton.contains(e.target)) {
        setMenuState(false);
      }
    });

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && menu.getAttribute('aria-hidden') === 'false') {
        setMenuState(false);
        toggleButton.focus();
      }
    });

    const menuItems = menu.querySelectorAll('.fw-crm__kebab-item');
    menuItems.forEach(function(item, index) {
      item.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          menuItems[(index + 1) % menuItems.length].focus();
        } else if (e.key === 'ArrowUp') {
          e.preventDefault();
          menuItems[(index - 1 + menuItems.length) % menuItems.length].focus();
        }
      });
    });

    function setMenuState(isOpen) {
      menu.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
      toggleButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      if (isOpen && menuItems.length > 0) {
        setTimeout(() => menuItems[0].focus(), 50);
      }
    }

    toggleButton.dataset.initialized = 'true';
    console.log('✅ ProHeader: Kebab menu initialized');
  }

  // ===== 3D TILT EFFECT =====
  // Delegated pointer engine: works for cards rendered at any time (the
  // projects grid loads via fetch, so per-card binding at DOMContentLoaded
  // never sees them). Instead of writing inline transforms, it feeds the
  // CSS custom properties (--rx/--ry for tilt, --mx/--my for the specular
  // glare) that projects.css composes into the card transform.
  function init3DTilt() {
    if (REDUCE_MOTION) return;
    if (!window.matchMedia('(pointer: fine)').matches) return;

    const SELECTOR = '.fw-project-card, .fw-board-card';
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

  // ===== LOGO TILE HOVER EFFECT =====
  function initLogoTileEffect() {
    const logoTile = document.querySelector('.fw-crm__logo-tile');
    if (!logoTile || REDUCE_MOTION) return;

    logoTile.addEventListener('mouseenter', function() {
      this.style.transform = 'scale(1.05) rotate(-3deg)';
    });

    logoTile.addEventListener('mouseleave', function() {
      this.style.transform = '';
    });
  }

  // ===== SCROLL SHADOW ON HEADER =====
  function initHeaderShadow() {
    const header = document.querySelector('.fw-crm__header');
    if (!header) return;

    let ticking = false;
    window.addEventListener('scroll', function() {
      if (!ticking) {
        window.requestAnimationFrame(function() {
          const currentScroll = window.pageYOffset;
          if (currentScroll > 20) {
            header.style.boxShadow = 'var(--fw-shadow-lg)';
          } else {
            header.style.boxShadow = 'var(--fw-shadow-md)';
          }
          ticking = false;
        });
        ticking = true;
      }
    });
  }

  // ===== INITIALIZATION =====
  function init() {
    console.log('🚀 ProHeader: Initializing...');
    initThemeToggle();
    initKebabMenu();
    init3DTilt();
    initLogoTileEffect();
    initHeaderShadow();
    console.log('✅ ProHeader: Initialized successfully');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.ProHeader = {
    version: '2.1.0',
    initialized: true,
    themeKey: THEME_COOKIE_NAME
  };

})();