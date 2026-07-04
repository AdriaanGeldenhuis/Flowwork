/*!
 * Flowwork main.js - Sequential Module Loader
 * Loads JS files in order with proper error handling
 */

(function () {
  "use strict";

  console.log('🔄 main.js loader starting...');

  // Find the script tag that loaded main.js
  const scriptTag = document.currentScript || 
                    document.querySelector('script[src*="main.js"]');

  if (!scriptTag) {
    console.error('❌ [main.js] Could not find script tag');
    return;
  }

  // Read configuration from data attributes
  const baseUrl = (scriptTag.getAttribute("data-base") || "/projects/js").replace(/\/$/, "");
  const version = scriptTag.getAttribute("data-version") || String(Date.now());
  const timeout = parseInt(scriptTag.getAttribute("data-timeout") || "15000", 10);

  console.log('📋 Config:', { baseUrl, version, timeout });

  // Parse files list
  let files = [];
  try {
    const filesAttr = scriptTag.getAttribute("data-files") || "[]";
    files = JSON.parse(filesAttr);
    
    if (!Array.isArray(files)) {
      throw new Error("data-files must be an array");
    }
    
    // Remove empty values and duplicates
    files = files.filter(Boolean);
    files = [...new Set(files)];
    
  } catch (e) {
    console.error('❌ [main.js] Invalid data-files:', e);
    return;
  }

  if (files.length === 0) {
    console.warn('⚠️ [main.js] No files to load');
    return;
  }

  console.log('📦 Files to load:', files);

  // Build full URLs with cache busting
  const urls = files.map(file => {
    let url;
    
    // Handle absolute URLs
    if (/^https?:\/\//i.test(file)) {
      url = file;
    } 
    // Handle root-relative URLs
    else if (file.startsWith('/')) {
      url = file;
    } 
    // Handle relative URLs
    else {
      url = baseUrl + '/' + file.replace(/^\/+/, '');
    }
    
    // Add cache busting
    const separator = url.includes('?') ? '&' : '?';
    return url + separator + 'v=' + version;
  });

  console.log('🔗 Full URLs:', urls);

  // Inject all scripts at once. script.async = false preserves execution order,
  // so this is equivalent to the old sequential chain but loads in parallel —
  // and one failed/timed-out file no longer kills every module after it.
  const results = urls.map((url, index) =>
    loadScript(url, timeout)
      .then(() => {
        dispatchEvent('fw:module-loaded', { src: url, index: index });
        return { url, ok: true };
      })
      .catch(err => {
        console.error(`❌ Failed [${index + 1}/${urls.length}]:`, url, err.message);
        dispatchEvent('fw:modules-error', { src: url, error: err.message, index: index });
        return { url, ok: false, error: err.message };
      })
  );

  Promise.all(results).then(outcomes => {
    const failed = outcomes.filter(o => !o.ok);
    const loadedCount = outcomes.length - failed.length;

    console.log(`✅ Modules loaded: ${loadedCount}/${outcomes.length}`);
    dispatchEvent('fw:modules-ready', { count: loadedCount, failed: failed.map(f => f.url) });

    if (failed.length > 0) {
      showLoadWarning(failed.length);
    }
  });

  function showLoadWarning(failedCount) {
    const show = () => {
      const banner = document.createElement('div');
      banner.setAttribute('role', 'alert');
      banner.style.cssText = 'position:fixed;bottom:60px;left:50%;transform:translateX(-50%);' +
        'background:#b45309;color:#fff;padding:10px 18px;border-radius:8px;font:600 13px/1.4 sans-serif;' +
        'z-index:10001;box-shadow:0 8px 24px rgba(0,0,0,0.35);display:flex;gap:12px;align-items:center;';
      banner.innerHTML = '<span>⚠️ Some board features failed to load (' + failedCount + ' module' +
        (failedCount > 1 ? 's' : '') + ').</span>';
      const btn = document.createElement('button');
      btn.textContent = 'Refresh';
      btn.style.cssText = 'background:rgba(255,255,255,0.2);border:none;color:#fff;padding:4px 12px;' +
        'border-radius:6px;font-weight:700;cursor:pointer;';
      btn.addEventListener('click', () => location.reload());
      banner.appendChild(btn);
      document.body.appendChild(banner);
    };
    if (document.body) show();
    else document.addEventListener('DOMContentLoaded', show);
  }

  // ========== HELPER FUNCTIONS ==========

  function loadScript(src, timeoutMs) {
    return new Promise((resolve, reject) => {
      const script = document.createElement("script");
      script.type = "text/javascript";
      script.src = src;
      script.async = false; // Preserve order
      
      let timeoutId = null;
      let isSettled = false;

      function cleanup() {
        if (isSettled) return;
        isSettled = true;
        
        if (timeoutId) clearTimeout(timeoutId);
        script.onload = script.onerror = null;
      }

      script.onload = function() {
        cleanup();
        resolve();
      };

      script.onerror = function() {
        cleanup();
        reject(new Error('Script load failed: ' + src));
      };

      // Set timeout
      if (timeoutMs > 0) {
        timeoutId = setTimeout(() => {
          cleanup();
          script.remove();
          reject(new Error('Script load timeout: ' + src));
        }, timeoutMs);
      }

      // Append to head
      document.head.appendChild(script);
    });
  }

  function dispatchEvent(eventName, detail) {
    try {
      const event = new CustomEvent(eventName, { detail: detail });
      window.dispatchEvent(event);
    } catch (e) {
      // Fallback for older browsers
      try {
        const event = document.createEvent('CustomEvent');
        event.initCustomEvent(eventName, false, false, detail);
        window.dispatchEvent(event);
      } catch (e2) {
        console.error('Failed to dispatch event:', eventName, e2);
      }
    }
  }

})();