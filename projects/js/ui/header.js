//=====FILENAME:{header.js}=====//
/**
 * ============================================================================
 * FLOWWORK HEADER UI CONTROLLER (BOARD VIEW)
 * Theme toggle with cookie persistence (matches home.php)
 * Board menu interactions
 * Uses COOKIE: 'fw_theme' (same as home, projects, CRM)
 * ============================================================================
 */

(() => {
  'use strict';

  let initialized = false;
  const THEME_COOKIE = 'fw_theme';
  const THEME_DARK = 'dark';
  const THEME_LIGHT = 'light';

  // ===== COOKIE UTILITIES =====
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
    const themeToggle = document.getElementById('themeToggle');
    const projDiv = document.querySelector('.fw-proj');

    if (!themeToggle || !projDiv) {
      console.warn('⚠️ Theme toggle elements not found');
      return false;
    }

    // Prevent double initialization
    if (themeToggle.dataset.initialized === 'true') {
      console.log('✅ Theme toggle already initialized');
      return true;
    }

    // Load saved theme from COOKIE (unified across all Flowwork pages)
    const savedTheme = getCookie(THEME_COOKIE) || THEME_LIGHT;
    applyTheme(savedTheme);

    // Toggle function
    themeToggle.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      
      const current = projDiv.getAttribute('data-theme') || THEME_LIGHT;
      const newTheme = current === THEME_DARK ? THEME_LIGHT : THEME_DARK;
      
      applyTheme(newTheme);
      setCookie(THEME_COOKIE, newTheme);
      
      console.log('✅ Theme switched to:', newTheme);
      
      // Optional: Dispatch custom event for other modules
      window.dispatchEvent(new CustomEvent('fw:themeChanged', { detail: { theme: newTheme } }));
    });

    function applyTheme(theme) {
      projDiv.setAttribute('data-theme', theme);
      
      // Update button aria-label for accessibility
      themeToggle.setAttribute('aria-label', 
        theme === THEME_DARK ? 'Switch to light mode' : 'Switch to dark mode'
      );
    }

    themeToggle.dataset.initialized = 'true';
    console.log('✅ Theme toggle initialized with theme:', savedTheme);
    return true;
  }

  // ===== BOARD MENU TOGGLE =====
  function initBoardMenu() {
    const menuToggle = document.getElementById('boardMenuToggle');
    const menu = document.getElementById('boardMenu');

    if (!menuToggle || !menu) {
      console.warn('⚠️ Board menu elements not found');
      return false;
    }

    // Prevent double initialization
    if (menuToggle.dataset.initialized === 'true') {
      console.log('✅ Board menu already initialized');
      return true;
    }

    // Ensure menu starts closed
    menu.setAttribute('aria-hidden', 'true');
    menuToggle.setAttribute('aria-expanded', 'false');

    // Toggle menu on click
    menuToggle.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      
      const isHidden = menu.getAttribute('aria-hidden') === 'true';
      setMenuState(!isHidden);
      
      console.log('🎛️ Menu toggle clicked, isHidden was:', isHidden);
    });

    // Close on outside click
    document.addEventListener('click', (e) => {
      const isOpen = menu.getAttribute('aria-hidden') === 'false';
      if (isOpen && !menu.contains(e.target) && !menuToggle.contains(e.target)) {
        setMenuState(false);
      }
    });

    // Close on Escape
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && menu.getAttribute('aria-hidden') === 'false') {
        setMenuState(false);
        menuToggle.focus();
      }
    });

    // Keyboard navigation
    const menuItems = Array.from(menu.querySelectorAll('.fw-board-header__menu-item'));
    menuItems.forEach((item, index) => {
      item.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          menuItems[(index + 1) % menuItems.length].focus();
        } else if (e.key === 'ArrowUp') {
          e.preventDefault();
          menuItems[(index - 1 + menuItems.length) % menuItems.length].focus();
        } else if (e.key === 'Tab' && !e.shiftKey && index === menuItems.length - 1) {
          setMenuState(false);
        } else if (e.key === 'Tab' && e.shiftKey && index === 0) {
          setMenuState(false);
        }
      });
    });

    function setMenuState(isOpen) {
      menu.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
      menuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      
      if (isOpen && menuItems.length > 0) {
        setTimeout(() => menuItems[0].focus(), 50);
      }
      
      console.log('🎛️ Board menu:', isOpen ? 'opened' : 'closed');
    }

    menuToggle.dataset.initialized = 'true';
    console.log('✅ Board menu initialized');
    return true;
  }

  // ===== INITIALIZE =====
  function init() {
    if (initialized) {
      console.log('⚠️ Header already initialized, skipping');
      return;
    }

    const themeOk = initThemeToggle();
    const menuOk = initBoardMenu();

    if (themeOk || menuOk) {
      initialized = true;
      console.log('✅ Header initialized successfully');
    } else {
      console.warn('⚠️ Header initialization incomplete');
    }
  }

  // ===== MULTIPLE INITIALIZATION ATTEMPTS =====
  if (document.readyState !== 'loading') {
    init();
  }

  document.addEventListener('DOMContentLoaded', init);
  window.addEventListener('load', init);
  
  // Fallback with delay
  setTimeout(() => {
    if (!initialized) {
      console.log('⏰ Delayed header initialization attempt');
      init();
    }
  }, 1000);
})();