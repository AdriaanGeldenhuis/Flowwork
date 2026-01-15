// qi/assets/qi.ui.js - Shared UI utilities
// Provides basic confirm and toast helpers and a JSON fetch wrapper

const UI = {
    /**
     * Display a confirm dialog. Returns true if user confirms.
     * For now uses native confirm; can be replaced with custom modal later.
     * @param {string} message
     * @returns {boolean}
     */
    confirm(message) {
        return window.confirm(message);
    },

    /**
     * Show a toast/alert message. For now uses native alert; can be swapped for custom toast.
     * @param {string} message
     */
    toast(message) {
        window.alert(message);
    },

    /**
     * Perform a JSON fetch request and parse the response.
     * @param {string} url
     * @param {object} options
     * @returns {Promise<object>} parsed JSON
     */
    async fetchJSON(url, options = {}) {
        const res = await fetch(url, options);
        return res.json();
    }
};