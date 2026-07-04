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
    console.log(`📤 API Call: ${url}`, data);
    
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
      console.log(`📥 ${url} [${r.status}] ${r.statusText}`);
      
      // Get response text first
      return r.text().then(text => {
        // Log FULL raw response for debugging
        console.log(`📥 Full response [${text.length} bytes]:`, text);
        
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
          console.error('❌ JSON parse error:', e);
          console.error('❌ Response was:', text.substring(0, 1000));
          
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
        
        // Return data
        console.log('✅ API Success:', json.message || 'OK');
        return json.data || json;
      });
    })
    .catch(err => {
      console.error(`❌ API Error (${url}):`, err.message);
      console.error('Full error:', err);
      throw err;
    });
  };

  console.log('✅ API module loaded (ENHANCED DEBUG)');

})();