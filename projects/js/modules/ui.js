/**
 * UI Helper Functions
 */

(() => {
  'use strict';

  if (!window.BoardApp) {
    console.error('❌ BoardApp not initialized');
    return;
  }

  window.BoardApp.showDropdown = function(target, html) {
    const menu = document.createElement('div');
    menu.className = 'fw-dropdown';
    menu.innerHTML = html;
    menu.style.position = 'fixed';
    
    const rect = target.getBoundingClientRect();
    let left = rect.left - 200;
    let top = rect.bottom + 8;
    
    if (left < 20) left = rect.left;
    if (top + 250 > window.innerHeight) top = rect.top - 260;
    
    menu.style.left = left + 'px';
    menu.style.top = top + 'px';
    menu.style.zIndex = '9999';
    
    // ✅ FIX: Append to .fw-proj instead of body
    const container = document.querySelector('.fw-proj') || document.body;
    container.appendChild(menu);
    
    setTimeout(() => {
      document.addEventListener('click', () => menu.remove(), { once: true });
    }, 100);
  };

  window.BoardApp.closeAllDropdowns = function() {
    document.querySelectorAll('.fw-dropdown').forEach(m => m.remove());
  };

  console.log('✅ UI module loaded');

})();