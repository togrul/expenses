/**
 * Installed-app (PWA) behaviour: service worker, pull-to-refresh and quick-add links.
 */

export const isStandalone = () =>
    window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

export function registerServiceWorker() {
    if (!('serviceWorker' in navigator) || !window.isSecureContext) {
        return;
    }

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
    });
}

/**
 * "/?add=expense" or "/?add=income" opens the new-transaction sheet.
 * Used by iOS Shortcuts / Siri ("Xərc yaz") and the manifest shortcuts.
 */
export function setUpQuickAddLinks() {
    document.addEventListener('livewire:navigated', () => {
        const url = new URL(window.location.href);
        const type = url.searchParams.get('add');

        if (type !== 'expense' && type !== 'income') {
            return;
        }

        url.searchParams.delete('add');
        window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
        window.Livewire?.dispatch('open-transaction-modal', { type });
    });
}

/**
 * iOS gives an installed web app no pull-to-refresh, so add a small one.
 */
export function setUpPullToRefresh() {
    if (!isStandalone() || !('ontouchstart' in window)) {
        return;
    }

    const threshold = 80;
    let startY = null;
    let distance = 0;

    const indicator = document.createElement('div');
    indicator.setAttribute('aria-hidden', 'true');
    indicator.className = 'ptr-indicator';
    indicator.innerHTML =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>';
    document.body.appendChild(indicator);

    const blocked = (target) => document.querySelector('dialog[open]') || target.closest('[data-flux-sidebar], [data-no-ptr]');

    const reset = () => {
        startY = null;
        distance = 0;
        indicator.style.transform = '';
        indicator.style.opacity = '0';
        indicator.classList.remove('is-ready');
    };

    document.addEventListener(
        'touchstart',
        (event) => {
            if (!indicator.isConnected) {
                document.body.appendChild(indicator);
            }

            startY = window.scrollY <= 0 && event.touches.length === 1 && !blocked(event.target) ? event.touches[0].clientY : null;
        },
        { passive: true },
    );

    document.addEventListener(
        'touchmove',
        (event) => {
            if (startY === null) {
                return;
            }

            distance = Math.max(0, event.touches[0].clientY - startY);

            if (distance <= 0 || window.scrollY > 0) {
                return;
            }

            const pull = Math.min(distance * 0.5, threshold);
            indicator.style.opacity = String(Math.min(pull / threshold, 1));
            indicator.style.transform = `translate(-50%, ${pull}px) rotate(${pull * 4}deg)`;
            indicator.classList.toggle('is-ready', distance * 0.5 >= threshold);
        },
        { passive: true },
    );

    document.addEventListener('touchend', () => {
        if (startY !== null && distance * 0.5 >= threshold) {
            indicator.classList.add('is-loading');
            window.Livewire ? window.Livewire.navigate(window.location.href) : window.location.reload();
            document.addEventListener('livewire:navigated', () => indicator.classList.remove('is-loading'), { once: true });
        }

        reset();
    });
}
