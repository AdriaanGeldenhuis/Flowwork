/**
 * ========================================
 * SCROLL SYNC - 100% PERFECT MOBILE SYNC
 * ========================================
 */

const ScrollSync = (() => {
    let wrappers = [];
    let container = null;
    let track = null;
    let thumb = null;
    let info = null;

    // State
    let isInternalUpdate = false;
    let isDragging = false;
    let dragStartX = 0;
    let dragStartScroll = 0;

    /**
     * Initialize
     */
    const init = () => {
        container = document.getElementById('boardContainer');
        track = document.getElementById('globalScrollTrack');
        thumb = document.getElementById('globalScrollThumb');
        info = document.getElementById('scrollInfo');

        if (!container || !track || !thumb || !info) {
            console.warn('⚠️ Scroll sync elements missing');
            return;
        }

        updateWrappers();

        if (wrappers.length === 0) {
            console.warn('⚠️ No wrappers found');
            return;
        }

        console.log('✅ Syncing', wrappers.length, 'wrappers');

        setupScrollListeners();
        setupThumbInteraction();
        setupTrackClick();
        setupMutationObserver();
        setupResizeHandler();

        setTimeout(updateThumb, 100);
    };

    /**
     * Update wrapper list
     */
    const updateWrappers = () => {
        if (!container) return;
        wrappers = Array.from(container.querySelectorAll('.fw-table-wrapper'));
    };

    /**
     * Sync all wrappers instantly
     */
    const syncAll = (scrollLeft) => {
        if (isInternalUpdate) return;
        isInternalUpdate = true;

        wrappers.forEach(w => {
            if (w.scrollLeft !== scrollLeft) {
                w.scrollLeft = scrollLeft;
            }
        });

        isInternalUpdate = false;
    };

    /**
     * Update thumb position
     */
    const updateThumb = () => {
        if (wrappers.length === 0) {
            thumb.style.width = '100%';
            thumb.style.left = '0%';
            info.textContent = '0%';
            return;
        }

        const w = wrappers[0];
        const scrollWidth = w.scrollWidth;
        const clientWidth = w.clientWidth;
        const scrollLeft = w.scrollLeft;

        if (scrollWidth <= clientWidth) {
            thumb.style.width = '100%';
            thumb.style.left = '0%';
            info.textContent = '0%';
            return;
        }

        const thumbWidthPercent = Math.max((clientWidth / scrollWidth) * 100, 5);
        thumb.style.width = thumbWidthPercent + '%';

        const maxScroll = scrollWidth - clientWidth;
        const scrollPercent = (scrollLeft / maxScroll) * 100;
        const maxThumbPos = 100 - thumbWidthPercent;
        const thumbPos = (scrollPercent / 100) * maxThumbPos;

        thumb.style.left = Math.max(0, Math.min(maxThumbPos, thumbPos)) + '%';
        info.textContent = Math.round(scrollPercent) + '%';
    };

    /**
     * Setup scroll listeners
     */
    const setupScrollListeners = () => {
        wrappers.forEach(w => {
            // ✅ PASSIVE FALSE FOR INSTANT SYNC
            w.addEventListener('scroll', (e) => {
                if (isInternalUpdate) return;
                
                const scrollLeft = e.target.scrollLeft;
                syncAll(scrollLeft);
                updateThumb();
            }, { passive: false });

            // ✅ TOUCH START - PREPARE FOR SYNC
            w.addEventListener('touchstart', () => {
                isInternalUpdate = false;
            }, { passive: true });
        });
    };

    /**
     * Setup thumb dragging (mouse + touch)
     */
    const setupThumbInteraction = () => {
        const startDrag = (clientX) => {
            if (wrappers.length === 0) return;
            isDragging = true;
            dragStartX = clientX;
            dragStartScroll = wrappers[0].scrollLeft;
            document.body.style.userSelect = 'none';
            thumb.style.transition = 'none';
        };

        const doDrag = (clientX) => {
            if (!isDragging || wrappers.length === 0) return;

            const w = wrappers[0];
            const trackRect = track.getBoundingClientRect();
            const trackWidth = trackRect.width;
            const thumbWidth = thumb.offsetWidth;
            const maxThumbPos = trackWidth - thumbWidth;

            const deltaX = clientX - dragStartX;
            const deltaPercent = deltaX / maxThumbPos;
            const maxScroll = w.scrollWidth - w.clientWidth;
            const newScrollLeft = dragStartScroll + (deltaPercent * maxScroll);

            const clampedScroll = Math.max(0, Math.min(maxScroll, newScrollLeft));

            syncAll(clampedScroll);
            updateThumb();
        };

        const stopDrag = () => {
            if (!isDragging) return;
            isDragging = false;
            document.body.style.userSelect = '';
            thumb.style.transition = '';
        };

        // Mouse events
        thumb.addEventListener('mousedown', (e) => {
            startDrag(e.clientX);
            e.preventDefault();
        });

        document.addEventListener('mousemove', (e) => {
            if (isDragging) doDrag(e.clientX);
        });

        document.addEventListener('mouseup', stopDrag);

        // Touch events
        thumb.addEventListener('touchstart', (e) => {
            startDrag(e.touches[0].clientX);
            e.preventDefault();
        }, { passive: false });

        document.addEventListener('touchmove', (e) => {
            if (isDragging) {
                doDrag(e.touches[0].clientX);
                e.preventDefault();
            }
        }, { passive: false });

        document.addEventListener('touchend', stopDrag);
    };

    /**
     * Setup track clicking
     */
    const setupTrackClick = () => {
        track.addEventListener('click', (e) => {
            if (e.target === thumb || wrappers.length === 0) return;

            const w = wrappers[0];
            const rect = track.getBoundingClientRect();
            const clickX = e.clientX - rect.left;
            const percent = clickX / rect.width;
            const maxScroll = w.scrollWidth - w.clientWidth;
            const targetScroll = percent * maxScroll;

            syncAll(targetScroll);
            updateThumb();
        });
    };

    /**
     * Setup mutation observer
     */
    const setupMutationObserver = () => {
        const observer = new MutationObserver(() => {
            clearTimeout(window.syncMutationTimeout);
            window.syncMutationTimeout = setTimeout(() => {
                updateWrappers();
                updateThumb();
            }, 200);
        });

        observer.observe(container, {
            childList: true,
            subtree: true
        });
    };

    /**
     * Setup resize handler
     */
    const setupResizeHandler = () => {
        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(updateThumb, 100);
        });
    };

    return { init, updateThumb, syncAll };
})();

// Auto-init
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ScrollSync.init);
} else {
    ScrollSync.init();
}

window.ScrollSync = ScrollSync;