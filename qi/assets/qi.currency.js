// /qi/assets/qi.currency.js
// Shared client-side currency state for the quote/invoice/credit-note forms.
// Pages initialise it with window.qiCurrency = { code, rate, base, symbols }
// (rate = 1 unit of document currency in ZAR). Handles the currency <select>,
// the exchange-rate input, live rate lookup and converting entered prices
// when the user switches currency.
window.QICurrency = (function () {
    'use strict';

    let code = 'ZAR';
    let rate = 1;
    let base = 'ZAR';
    let symbols = { ZAR: 'R' };

    function init(cfg) {
        cfg = cfg || {};
        code = (cfg.code || 'ZAR').toUpperCase();
        rate = parseFloat(cfg.rate) || 0;
        base = (cfg.base || 'ZAR').toUpperCase();
        symbols = cfg.symbols || symbols;
        if (code === base) rate = 1;
    }

    function symbol(c) {
        c = (c || code).toUpperCase();
        return symbols[c] || c;
    }

    function getCode() { return code; }
    function getRate() { return rate; }

    // Update every element tagged with the symbol/code classes after a switch.
    function refreshSymbols(root) {
        const scope = root || document;
        scope.querySelectorAll('.qi-currency-symbol').forEach(el => { el.textContent = symbol(); });
        scope.querySelectorAll('.qi-currency-code').forEach(el => { el.textContent = code; });
    }

    async function fetchRate(c, date) {
        try {
            const res = await fetch('/qi/ajax/get_exchange_rate.php?currency=' + encodeURIComponent(c) +
                (date ? '&date=' + encodeURIComponent(date) : ''));
            return await res.json();
        } catch (err) {
            return { ok: false, rate: null };
        }
    }

    /**
     * Wire up the form controls.
     * opts: {
     *   select:         currency <select>
     *   rateInput:      exchange-rate <input>
     *   rateRow:        wrapper shown only for non-base currencies
     *   getDate():      returns the document date for rate lookup
     *   getPriceInputs(): returns price <input>s to offer conversion on switch
     *   recalc():       totals recalculation callback
     *   customerSelect: customer <select> whose options carry data-currency —
     *                   picking a customer auto-switches to their currency
     * }
     */
    function attach(opts) {
        const select = opts.select;
        if (!select) return;

        function syncRateRow() {
            if (opts.rateRow) opts.rateRow.style.display = (code === base) ? 'none' : '';
        }

        async function loadRate(forCode) {
            const data = await fetchRate(forCode, opts.getDate ? opts.getDate() : '');
            if (data && data.ok && data.rate) {
                return parseFloat(data.rate);
            }
            return 0;
        }

        select.addEventListener('change', async function () {
            const oldCode = code;
            const oldRate = rate;
            const newCode = select.value.toUpperCase();
            let newRate = 1;

            if (newCode !== base) {
                select.disabled = true;
                newRate = await loadRate(newCode);
                select.disabled = false;
                if (!newRate) {
                    alert('No exchange rate found for ' + newCode + '.\n\nEnter the rate manually (1 ' + newCode + ' = ? ZAR) or add one under Finances → Exchange Rates.');
                }
            }

            code = newCode;
            rate = newRate;
            if (opts.rateInput) opts.rateInput.value = rate ? rate.toFixed(6) : '';
            syncRateRow();
            refreshSymbols();

            // Convert prices already typed in, via ZAR: new = old × oldRate ÷ newRate
            if (opts.getPriceInputs && oldCode !== code && oldRate > 0 && rate > 0) {
                const inputs = (opts.getPriceInputs() || []).filter(i => parseFloat(i.value) > 0);
                if (inputs.length > 0 &&
                    confirm('Convert the prices already entered from ' + oldCode + ' to ' + code + ' at the current rate?')) {
                    const factor = oldRate / rate;
                    inputs.forEach(i => { i.value = (parseFloat(i.value) * factor).toFixed(2); });
                }
            }
            if (opts.recalc) opts.recalc();
        });

        if (opts.rateInput) {
            opts.rateInput.addEventListener('input', function () {
                const v = parseFloat(opts.rateInput.value);
                rate = (v > 0) ? v : 0;
            });
        }

        // Picking a customer with a preferred billing currency switches the
        // document to it (runs the normal change flow: rate lookup + optional
        // price conversion).
        if (opts.customerSelect) {
            opts.customerSelect.addEventListener('change', function () {
                const opt = opts.customerSelect.options[opts.customerSelect.selectedIndex];
                const cur = opt ? (opt.getAttribute('data-currency') || '').toUpperCase() : '';
                if (cur && cur !== code) {
                    select.value = cur;
                    if (select.value === cur) { // only if the option exists
                        select.dispatchEvent(new Event('change'));
                    }
                }
            });
        }

        syncRateRow();
        refreshSymbols();

        // New document opened with a non-ZAR default currency: fetch its rate now.
        if (code !== base && rate <= 0) {
            loadRate(code).then(r => {
                if (r) {
                    rate = r;
                    if (opts.rateInput) opts.rateInput.value = r.toFixed(6);
                }
            });
        }
    }

    return { init, attach, symbol, code: getCode, rate: getRate, refreshSymbols, fetchRate };
})();
