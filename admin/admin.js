(function() {
  'use strict';

  const THEME_COOKIE = 'fw_theme';

  // ========== THEME TOGGLE ==========
  function initTheme() {
    const toggle = document.getElementById('themeToggle');
    const body = document.querySelector('.fw-admin');
    
    if (!toggle || !body) return;

    let theme = getCookie(THEME_COOKIE) || 'light';
    body.setAttribute('data-theme', theme);

    toggle.addEventListener('click', () => {
      theme = theme === 'dark' ? 'light' : 'dark';
      body.setAttribute('data-theme', theme);
      setCookie(THEME_COOKIE, theme, 365);
    });
  }

  // ========== USER MODAL ==========
  function initUserModal() {
    const btnAdd = document.getElementById('btnAddUser');
    const modal = document.getElementById('modalUser');
    const btnClose = modal?.querySelector('.fw-admin__modal-close');
    const btnCancel = document.getElementById('btnCancelUser');
    const form = document.getElementById('formUser');

    if (!modal) return;

    if (btnAdd) {
      btnAdd.addEventListener('click', () => {
        openUserModal();
      });
    }

    if (btnClose) {
      btnClose.addEventListener('click', () => closeModal(modal));
    }

    if (btnCancel) {
      btnCancel.addEventListener('click', () => closeModal(modal));
    }

    const backdrop = modal.querySelector('.fw-admin__modal-backdrop');
    if (backdrop) {
      backdrop.addEventListener('click', () => closeModal(modal));
    }

    if (form) {
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(form);
        
        const userId = formData.get('user_id');
        const action = userId ? 'update_user' : 'add_user';
        formData.append('action', action);

        try {
          const res = await fetch('/admin/api.php', {
            method: 'POST',
            body: formData
          });
          
          const data = await res.json();
          
          if (data.success) {
            if (action === 'add_user' && data.temp_password) {
              alert(`User created!\n\nTemporary password: ${data.temp_password}\n\nPlease share this with the user securely.`);
            } else {
              alert('User updated successfully!');
            }
            location.reload();
          } else {
            alert(data.error || 'Failed to save user');
          }
        } catch (err) {
          console.error(err);
          alert('Network error');
        }
      });
    }

    // Role change handler
    const roleSelect = document.getElementById('userRole');
    const seatCheckbox = document.getElementById('userIsSeat');
    
    if (roleSelect && seatCheckbox) {
      roleSelect.addEventListener('change', () => {
        if (roleSelect.value === 'bookkeeper') {
          seatCheckbox.checked = false;
        } else {
          seatCheckbox.checked = true;
        }
      });
    }
  }

  function openUserModal(userId = null) {
    const modal = document.getElementById('modalUser');
    const form = document.getElementById('formUser');
    const title = document.getElementById('modalUserTitle');
    
    if (!modal || !form) return;

    if (userId) {
      title.textContent = 'Edit User';
      // TODO: Fetch user data and populate form
      form.user_id.value = userId;
    } else {
      title.textContent = 'Add User';
      form.reset();
      form.user_id.value = '';
    }
    
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function closeModal(modal) {
    if (!modal) return;
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  // ========== UTILITIES ==========
  function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
    return match ? match[2] : null;
  }

  function setCookie(name, value, days) {
    const date = new Date();
    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
    document.cookie = name + '=' + value + ';expires=' + date.toUTCString() + ';path=/;SameSite=Lax';
  }

  // ========== GLOBAL FUNCTIONS (for inline onclick handlers) ==========
  window.editUser = function(userId) {
    openUserModal(userId);
  };

  window.deleteUser = async function(userId) {
    if (!confirm('Are you sure you want to remove this user? This will suspend their access.')) {
      return;
    }

    try {
      const res = await fetch('/admin/api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'delete_user', user_id: userId })
      });
      
      const data = await res.json();
      
      if (data.success) {
        alert('User removed successfully');
        location.reload();
      } else {
        alert(data.error || 'Failed to remove user');
      }
    } catch (err) {
      console.error(err);
      alert('Network error');
    }
  };

  // ========== KEYBOARD SHORTCUTS ==========
  function initKeyboardShortcuts() {
    document.addEventListener('keydown', (e) => {
      // Escape to close modal
      if (e.key === 'Escape') {
        const openModal = document.querySelector('.fw-admin__modal[aria-hidden="false"]');
        if (openModal) {
          closeModal(openModal);
        }
      }

      // Ctrl/Cmd + K for quick add user
      if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        const btnAdd = document.getElementById('btnAddUser');
        if (btnAdd) btnAdd.click();
      }
    });
  }

  // ========== INIT ==========
  function init() {
    initTheme();
    initUserModal();
    initKeyboardShortcuts();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();