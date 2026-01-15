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

  // Load files sequentially
  let loadedCount = 0;

  function loadNext(index) {
    if (index >= urls.length) {
      console.log('✅ All modules loaded successfully:', loadedCount);
      dispatchEvent('fw:modules-ready', { count: loadedCount });
      return;
    }

    const url = urls[index];
    console.log(`📥 Loading [${index + 1}/${urls.length}]:`, url);

    loadScript(url, timeout)
      .then(() => {
        loadedCount++;
        console.log(`✅ Loaded [${index + 1}/${urls.length}]:`, url);
        dispatchEvent('fw:module-loaded', { src: url, index: index });
        
        // Load next file
        loadNext(index + 1);
      })
      .catch(err => {
        console.error(`❌ Failed [${index + 1}/${urls.length}]:`, url, err.message);
        dispatchEvent('fw:modules-error', { src: url, error: err.message, index: index });
        
        // Stop loading on error
        console.error('❌ Stopping module loading due to error');
      });
  }

  // Start loading
  loadNext(0);

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