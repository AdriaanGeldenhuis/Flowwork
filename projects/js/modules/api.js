/**
 * API Client - Enhanced Error Logging
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};

  /**
   * Make API request with enhanced error handling
   */
  window.BoardApp.apiCall = function(url, data = {}) {
    const form = new FormData();
    for (const [key, value] of Object.entries(data)) {
      if (Array.isArray(value)) {
        // PHP array convention: ordered_item_ids[]=1&ordered_item_ids[]=2 ...
        value.forEach(v => form.append(key + '[]', v));
      } else {
        form.append(key, value);
      }
    }

    return fetch(url, {
      method: 'POST',
      headers: { 
        'X-CSRF-Token': window.BOARD_DATA.csrfToken 
      },
      credentials: 'same-origin',
      body: form
    })
    .then(r => {
      // Get response text first
      return r.text().then(text => {
        // Check if response is empty
        if (!text || text.trim() === '') {
          console.error('❌ EMPTY RESPONSE from server');
          throw new Error('Server returned empty response. Check PHP error logs.');
        }
        
        // Try to parse JSON
        let json;
        try {
          json = JSON.parse(text);
        } catch (e) {
          console.error('❌ JSON parse error:', e, text.substring(0, 300));

          // Check if it's HTML error page
          if (text.includes('<html') || text.includes('<!DOCTYPE')) {
            throw new Error('Server returned HTML instead of JSON. Check PHP errors.');
          }

          throw new Error('Invalid JSON response: ' + e.message);
        }

        // Check HTTP status
        if (!r.ok) {
          throw new Error(json.error || `HTTP ${r.status}: ${r.statusText}`);
        }

        // Check API response status
        if (!json.ok) {
          throw new Error(json.error || 'API request failed');
        }

        return json.data || json;
      });
    })
    .catch(err => {
      console.error(`❌ API Error (${url}):`, err.message);
      throw err;
    });
  };

  console.log('✅ API module loaded');

})();