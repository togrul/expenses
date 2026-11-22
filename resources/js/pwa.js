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

const urlBase64ToUint8Array = (base64) => {
    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(padded);

    return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)));
};

const csrfHeaders = () => ({
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
    'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? ''),
});

/**
 * Alpine component for Settings → Notifications: subscribe this device to Web Push.
 * On iPhone this only works inside the installed (home screen) app, iOS 16.4+.
 */
export const pushSubscription = (vapidPublicKey) => ({
    state: 'loading',
    busy: false,
    error: null,

    async init() {
        const isIos = /iPhone|iPad|iPod/.test(navigator.userAgent);

        if (isIos && !isStandalone()) {
            this.state = 'install';

            return;
        }

        if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window) || !vapidPublicKey) {
            this.state = 'unsupported';

            return;
        }

        if (Notification.permission === 'denied') {
            this.state = 'denied';

            return;
        }

        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();

        if (subscription) {
            await this.save(subscription);
        }

        this.state = subscription ? 'on' : 'off';
    },

    async save(subscription) {
        const json = subscription.toJSON();
        const encoding = (PushManager.supportedContentEncodings || ['aes128gcm'])[0];

        const response = await fetch('/push-subscriptions', {
            method: 'POST',
            headers: csrfHeaders(),
            credentials: 'same-origin',
            body: JSON.stringify({ endpoint: json.endpoint, keys: json.keys, contentEncoding: encoding }),
        });

        if (!response.ok) {
            throw new Error('save failed');
        }

        this.$dispatch('push-subscription-changed');
    },

    async enable() {
        this.busy = true;
        this.error = null;

        try {
            const permission = await Notification.requestPermission();

            if (permission !== 'granted') {
                this.state = permission === 'denied' ? 'denied' : 'off';

                return;
            }

            const registration = await navigator.serviceWorker.ready;
            const subscription =
                (await registration.pushManager.getSubscription()) ||
                (await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(vapidPublicKey) }));

            await this.save(subscription);
            this.state = 'on';
        } catch {
            this.error = 'Bildirişləri aktiv etmək alınmadı. Yenidən cəhd edin.';
        } finally {
            this.busy = false;
        }
    },

    async disable() {
        this.busy = true;
        this.error = null;

        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();

            if (subscription) {
                await fetch('/push-subscriptions', {
                    method: 'DELETE',
                    headers: csrfHeaders(),
                    credentials: 'same-origin',
                    body: JSON.stringify({ endpoint: subscription.endpoint }),
                });
                await subscription.unsubscribe();
            }

            this.state = 'off';
            this.$dispatch('push-subscription-changed');
        } catch {
            this.error = 'Söndürmək alınmadı. Yenidən cəhd edin.';
        } finally {
            this.busy = false;
        }
    },
});

/**
 * Keep the home-screen icon badge in sync with overdue + today's payments.
 */
export function syncAppBadge(count) {
    if (!('setAppBadge' in navigator)) {
        return;
    }

    (count > 0 ? navigator.setAppBadge(count) : navigator.clearAppBadge()).catch(() => {});
}
