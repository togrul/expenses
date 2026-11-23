/*
 * Maliyyə service worker.
 *
 * - Build assets (/build/*, hashed) and icons: cache-first.
 * - Page navigations: network first; the last copy of each page is kept so the app still opens
 *   offline (entries made then are queued on the phone and synced later), else the offline page.
 * - Livewire requests and every non-GET request are never cached: money figures must be fresh.
 * - Web Push: shows the notification and opens its URL on tap.
 */
const VERSION = 'v3';
const STATIC_CACHE = `maliyye-static-${VERSION}`;
const PAGES_CACHE = 'maliyye-pages';
const OFFLINE_URL = '/offline.html';
const PRECACHE = [OFFLINE_URL, '/icons/icon-192.png', '/manifest.json'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(STATIC_CACHE).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== STATIC_CACHE && key !== PAGES_CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

const isStaticAsset = (url) =>
    url.origin === self.location.origin && (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/') || url.pathname.startsWith('/fonts/'));

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (isStaticAsset(url)) {
        event.respondWith(
            caches.match(request).then(
                (cached) =>
                    cached ||
                    fetch(request).then((response) => {
                        if (response.ok) {
                            const copy = response.clone();
                            caches.open(STATIC_CACHE).then((cache) => cache.put(request, copy));
                        }

                        return response;
                    }),
            ),
        );

        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    const isPage = response.ok && !response.redirected && (response.headers.get('content-type') || '').includes('text/html');

                    if (isPage && url.origin === self.location.origin) {
                        const copy = response.clone();
                        caches.open(PAGES_CACHE).then((cache) => cache.put(url.pathname, copy));
                    }

                    return response;
                })
                .catch(async () => {
                    const cache = await caches.open(PAGES_CACHE);

                    return (await cache.match(url.pathname)) || (await cache.match('/')) || caches.match(OFFLINE_URL);
                }),
        );
    }
});

self.addEventListener('push', (event) => {
    let payload = {};

    try {
        payload = event.data ? event.data.json() : {};
    } catch {
        payload = { body: event.data ? event.data.text() : '' };
    }

    const title = payload.title || 'Maliyyə';
    const options = {
        body: payload.body || '',
        icon: payload.icon || '/icons/icon-192.png',
        badge: '/icons/icon-192.png',
        tag: payload.tag,
        renotify: Boolean(payload.tag),
        data: { url: (payload.data && payload.data.url) || payload.url || '/' },
    };

    const tasks = [self.registration.showNotification(title, options)];
    const badge = payload.data && payload.data.badge;

    if (typeof badge === 'number' && self.navigator.setAppBadge) {
        tasks.push(badge > 0 ? self.navigator.setAppBadge(badge) : self.navigator.clearAppBadge());
    }

    event.waitUntil(Promise.all(tasks));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const target = new URL(event.notification.data?.url || '/', self.location.origin).href;

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            for (const client of windows) {
                if ('focus' in client) {
                    return client.navigate(target).then((navigated) => (navigated || client).focus());
                }
            }

            return self.clients.openWindow(target);
        }),
    );
});
