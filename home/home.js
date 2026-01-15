(function() {
  'use strict';

  // ========== CONSTANTS ==========
  const THEME_COOKIE_NAME = 'fw_theme';
  const THEME_DARK = 'dark';
  const THEME_LIGHT = 'light';
  const REDUCE_MOTION = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

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

  // ========== THEME TOGGLE ==========
  function initThemeToggle() {
    const toggleButton = document.getElementById('themeToggle');
    const themeIndicator = document.getElementById('themeIndicator');
    const body = document.querySelector('.fw-home');

    if (!toggleButton || !body) return;

    // Initialize theme from cookie or default to light
    let currentTheme = getCookie(THEME_COOKIE_NAME) || THEME_LIGHT;
    applyTheme(currentTheme);

    toggleButton.addEventListener('click', function() {
      currentTheme = currentTheme === THEME_DARK ? THEME_LIGHT : THEME_DARK;
      applyTheme(currentTheme);
      setCookie(THEME_COOKIE_NAME, currentTheme);
    });

    function applyTheme(theme) {
      body.setAttribute('data-theme', theme);
      if (themeIndicator) {
        themeIndicator.textContent = 'Theme: ' + (theme === THEME_DARK ? 'Dark' : 'Light');
      }
    }
  }

  // ========== KEBAB MENU ==========
  function initKebabMenu() {
    const toggleButton = document.getElementById('kebabToggle');
    const menu = document.getElementById('kebabMenu');

    if (!toggleButton || !menu) return;

    toggleButton.addEventListener('click', function(e) {
      e.stopPropagation();
      const isOpen = menu.getAttribute('aria-hidden') === 'false';
      setMenuState(!isOpen);
    });

    // Close on outside click
    document.addEventListener('click', function(e) {
      if (!menu.contains(e.target) && !toggleButton.contains(e.target)) {
        setMenuState(false);
      }
    });

    // Close on Escape
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && menu.getAttribute('aria-hidden') === 'false') {
        setMenuState(false);
        toggleButton.focus();
      }
    });

    // Keyboard navigation inside menu
    const menuItems = menu.querySelectorAll('.fw-home__kebab-item');
    menuItems.forEach(function(item, index) {
      item.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          const nextIndex = (index + 1) % menuItems.length;
          menuItems[nextIndex].focus();
        } else if (e.key === 'ArrowUp') {
          e.preventDefault();
          const prevIndex = (index - 1 + menuItems.length) % menuItems.length;
          menuItems[prevIndex].focus();
        } else if (e.key === 'Tab' && !e.shiftKey && index === menuItems.length - 1) {
          setMenuState(false);
        } else if (e.key === 'Tab' && e.shiftKey && index === 0) {
          setMenuState(false);
        }
      });
    });

    function setMenuState(isOpen) {
      menu.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
      toggleButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      if (isOpen && menuItems.length > 0) {
        menuItems[0].focus();
      }
    }
  }

  // ========== 3D TILE TILT ==========
  function init3DTilt() {
    if (REDUCE_MOTION) return;

    const tiles = document.querySelectorAll('.fw-home__tile');

    tiles.forEach(function(tile) {
      const inner = tile.querySelector('.fw-home__tile-inner');
      if (!inner) return;

      tile.addEventListener('mousemove', function(e) {
        const rect = tile.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;
        const centerX = rect.width / 2;
        const centerY = rect.height / 2;
        const rotateX = (y - centerY) / centerY * -5; // Max 5deg tilt
        const rotateY = (x - centerX) / centerX * 5;

        inner.style.transform = 'perspective(1000px) rotateX(' + rotateX + 'deg) rotateY(' + rotateY + 'deg) translateZ(20px)';
      });

      tile.addEventListener('mouseleave', function() {
        inner.style.transform = '';
      });
    });
  }

  // ========== INITIALIZATION ==========
  function init() {
    initThemeToggle();
    initKebabMenu();
    init3DTilt();
  }

  // Run on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();