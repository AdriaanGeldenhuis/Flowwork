//=====FILENAME:/shared/auth.js=====//
/**
 * Shared Authentication JavaScript
 * Handles theme toggle, password visibility, form validation, and AJAX
 */

(function() {
  'use strict';

  // ========== CONSTANTS ==========
  const THEME_COOKIE_NAME = 'fw_theme';
  const THEME_DARK = 'dark';
  const THEME_LIGHT = 'light';

  // ========== UTILITIES ==========
  function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
    return match ? decodeURIComponent(match[2]) : null;
  }

  function setCookie(name, value, days = 365) {
    const date = new Date();
    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
    document.cookie = name + '=' + encodeURIComponent(value) + 
                      ';expires=' + date.toUTCString() + 
                      ';path=/;SameSite=Lax';
  }

  function showError(message, container) {
    if (!container) return;
    
    const errorDiv = document.createElement('div');
    errorDiv.className = 'fw-auth__message fw-auth__message--error';
    errorDiv.innerHTML = `
      <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:16px;height:16px;display:inline-block;vertical-align:middle;margin-right:8px;">
        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
        <line x1="12" y1="8" x2="12" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        <circle cx="12" cy="16" r="1" fill="currentColor"/>
      </svg>
      ${escapeHtml(message)}
    `;
    
    container.insertBefore(errorDiv, container.firstChild);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
      errorDiv.style.opacity = '0';
      setTimeout(() => errorDiv.remove(), 300);
    }, 5000);
  }

  function showSuccess(message, container) {
    if (!container) return;
    
    const successDiv = document.createElement('div');
    successDiv.className = 'fw-auth__message fw-auth__message--success';
    successDiv.innerHTML = `
      <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:16px;height:16px;display:inline-block;vertical-align:middle;margin-right:8px;">
        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
        <path d="M9 12l2 2 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      ${escapeHtml(message)}
    `;
    
    container.insertBefore(successDiv, container.firstChild);
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  function setButtonLoading(button, isLoading, originalText) {
    if (isLoading) {
      button.disabled = true;
      button.classList.add('fw-auth__button--loading');
      button.dataset.originalText = button.textContent;
      button.innerHTML = '<span class="fw-auth__button-loader"></span> Processing...';
    } else {
      button.disabled = false;
      button.classList.remove('fw-auth__button--loading');
      button.textContent = originalText || button.dataset.originalText || 'Submit';
    }
  }

  // ========== THEME TOGGLE ==========
  function initThemeToggle() {
    const toggleButton = document.getElementById('themeToggle');
    const body = document.querySelector('.fw-auth');
    
    if (!toggleButton || !body) return;

    // Initialize theme from cookie
    let currentTheme = getCookie(THEME_COOKIE_NAME) || THEME_LIGHT;
    applyTheme(currentTheme);

    toggleButton.addEventListener('click', function() {
      currentTheme = currentTheme === THEME_DARK ? THEME_LIGHT : THEME_DARK;
      applyTheme(currentTheme);
      setCookie(THEME_COOKIE_NAME, currentTheme);
    });

    function applyTheme(theme) {
      body.setAttribute('data-theme', theme);
    }
  }

  // ========== PASSWORD VISIBILITY TOGGLE ==========
  function initPasswordToggles() {
    const toggleButtons = document.querySelectorAll('.fw-auth__password-toggle');
    
    toggleButtons.forEach(function(button) {
      button.addEventListener('click', function(e) {
        e.preventDefault();
        const input = this.previousElementSibling;
        if (!input) return;

        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';
        
        // Update icon
        const eyeIcon = this.querySelector('[data-eye-open]');
        const eyeSlashIcon = this.querySelector('[data-eye-closed]');
        
        if (eyeIcon && eyeSlashIcon) {
          eyeIcon.style.display = isPassword ? 'none' : 'block';
          eyeSlashIcon.style.display = isPassword ? 'block' : 'none';
        }
        
        // Update ARIA label
        this.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
      });
    });
  }

  // ========== PASSWORD STRENGTH CHECKER ==========
  function initPasswordStrength() {
    const passwordInputs = document.querySelectorAll('input[name="password"]');
    
    passwordInputs.forEach(function(input) {
      const strengthContainer = input.closest('.fw-auth__form-group')
                                     ?.querySelector('.fw-auth__password-strength');
      if (!strengthContainer) return;

      input.addEventListener('input', function() {
        const password = this.value;
        const strength = calculatePasswordStrength(password);
        
        updateStrengthDisplay(strengthContainer, strength, password.length > 0);
      });
    });
  }

  function calculatePasswordStrength(password) {
    if (!password) return { level: 'weak', score: 0, text: '' };

    let score = 0;
    
    // Length
    if (password.length >= 8) score += 1;
    if (password.length >= 12) score += 1;
    if (password.length >= 16) score += 1;
    
    // Character variety
    if (/[a-z]/.test(password)) score += 1;
    if (/[A-Z]/.test(password)) score += 1;
    if (/[0-9]/.test(password)) score += 1;
    if (/[^a-zA-Z0-9]/.test(password)) score += 1;
    
    // Determine level
    let level = 'weak';
    let text = 'Weak password';
    
    if (score >= 5) {
      level = 'medium';
      text = 'Medium password';
    }
    if (score >= 7) {
      level = 'strong';
      text = 'Strong password';
    }
    
    return { level, score, text };
  }

  function updateStrengthDisplay(container, strength, show) {
    if (!container) return;
    
    if (show) {
      container.classList.add('visible');
    } else {
      container.classList.remove('visible');
      return;
    }
    
    // Update bar
    container.className = 'fw-auth__password-strength visible fw-auth__password-strength--' + strength.level;
    
    // Update text
    const textEl = container.querySelector('.fw-auth__password-strength-text');
    if (textEl) {
      textEl.textContent = strength.text;
    }
  }

  // ========== FORM VALIDATION ==========
  function initFormValidation() {
    const forms = document.querySelectorAll('form[data-validate]');
    
    forms.forEach(function(form) {
      form.addEventListener('submit', function(e) {
        const isValid = validateForm(form);
        if (!isValid) {
          e.preventDefault();
        }
      });
      
      // Real-time validation
      const inputs = form.querySelectorAll('input, select, textarea');
      inputs.forEach(function(input) {
        input.addEventListener('blur', function() {
          validateField(this);
        });
        
        input.addEventListener('input', function() {
          // Clear error on input
          clearFieldError(this);
        });
      });
    });
  }

  function validateForm(form) {
    let isValid = true;
    const inputs = form.querySelectorAll('input[required], select[required], textarea[required]');
    
    inputs.forEach(function(input) {
      if (!validateField(input)) {
        isValid = false;
      }
    });
    
    // Password confirmation check
    const password = form.querySelector('input[name="password"]');
    const passwordConfirm = form.querySelector('input[name="password_confirm"]');
    
    if (password && passwordConfirm && password.value !== passwordConfirm.value) {
      showFieldError(passwordConfirm, 'Passwords do not match');
      isValid = false;
    }
    
    return isValid;
  }

  function validateField(field) {
    clearFieldError(field);
    
    // Required check
    if (field.hasAttribute('required') && !field.value.trim()) {
      showFieldError(field, 'This field is required');
      return false;
    }
    
    // Email validation
    if (field.type === 'email' && field.value) {
      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailRegex.test(field.value)) {
        showFieldError(field, 'Please enter a valid email address');
        return false;
      }
    }
    
    // Min length
    if (field.hasAttribute('minlength')) {
      const minLength = parseInt(field.getAttribute('minlength'));
      if (field.value.length < minLength) {
        showFieldError(field, `Minimum ${minLength} characters required`);
        return false;
      }
    }
    
    // Password strength
    if (field.name === 'password' && field.value) {
      const strength = calculatePasswordStrength(field.value);
      if (strength.score < 3) {
        showFieldError(field, 'Password is too weak. Use at least 8 characters with mixed case, numbers, and symbols.');
        return false;
      }
    }
    
    return true;
  }

  function showFieldError(field, message) {
    field.classList.add('fw-auth__input--error');
    
    let errorDiv = field.parentElement.querySelector('.fw-auth__field-error');
    if (!errorDiv) {
      errorDiv = document.createElement('div');
      errorDiv.className = 'fw-auth__field-error';
      field.parentElement.appendChild(errorDiv);
    }
    
    errorDiv.innerHTML = `
      <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
        <line x1="12" y1="8" x2="12" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        <circle cx="12" cy="16" r="1" fill="currentColor"/>
      </svg>
      ${escapeHtml(message)}
    `;
  }

  function clearFieldError(field) {
    field.classList.remove('fw-auth__input--error');
    const errorDiv = field.parentElement.querySelector('.fw-auth__field-error');
    if (errorDiv) {
      errorDiv.remove();
    }
  }

  // ========== PLAN SELECTION (for register page) ==========
  function initPlanSelection() {
    const planCards = document.querySelectorAll('.fw-auth__plan-card');
    
    planCards.forEach(function(card) {
      card.addEventListener('click', function() {
        // Deselect all
        planCards.forEach(c => c.classList.remove('fw-auth__plan-card--selected'));
        
        // Select this one
        this.classList.add('fw-auth__plan-card--selected');
        const radio = this.querySelector('input[type="radio"]');
        if (radio) {
          radio.checked = true;
        }
      });
    });
  }

  // ========== PASSWORD RESET REQUEST ==========
  function initPasswordResetRequest() {
    const form = document.getElementById('passwordResetForm');
    if (!form) return;

    form.addEventListener('submit', async function(e) {
      e.preventDefault();
      
      const email = form.querySelector('input[name="email"]').value;
      const submitButton = form.querySelector('button[type="submit"]');
      const messageContainer = form;
      
      setButtonLoading(submitButton, true);
      
      try {
        const response = await fetch('/shared/ajax/send_reset_email.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email: email })
        });
        
        const data = await response.json();
        
        if (data.ok) {
          showSuccess(data.message || 'If an account exists with this email, a reset link has been sent.', messageContainer);
          form.querySelector('input[name="email"]').value = '';
        } else {
          showError(data.message || 'An error occurred. Please try again.', messageContainer);
        }
      } catch (error) {
        showError('Network error. Please check your connection and try again.', messageContainer);
      } finally {
        setButtonLoading(submitButton, false);
      }
    });
  }

  // ========== AUTO-DISMISS MESSAGES ==========
  function initAutoDismissMessages() {
    const messages = document.querySelectorAll('.fw-auth__message');
    messages.forEach(function(msg) {
      setTimeout(function() {
        msg.style.transition = 'opacity 0.3s ease';
        msg.style.opacity = '0';
        setTimeout(() => msg.remove(), 300);
      }, 5000);
    });
  }

  // ========== INITIALIZATION ==========
  function init() {
    initThemeToggle();
    initPasswordToggles();
    initPasswordStrength();
    initFormValidation();
    initPlanSelection();
    initPasswordResetRequest();
    initAutoDismissMessages();
  }

  // Run on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Expose utilities for external use
  window.FWAuth = {
    showError: showError,
    showSuccess: showSuccess,
    setButtonLoading: setButtonLoading,
    validateForm: validateForm
  };
})();