/*
 * A game's service worker, for offline play. Served by GamePlatform\Offline\ServiceWorker,
 * which puts PREFIX, OLD_PREFIXES, VERSION and PRECACHE (the built code, the offline
 * page and icons) above this.
 *
 * - Pages come from the network; when it can't be reached, the offline page is shown
 *   instead, and it plays the games kept in the browser.
 * - The built files never change (their names carry a hash), so they're served from
 *   the cache first.
 * - Everything else (saves, maps, the API) goes to the network as normal.
 */

/* global PREFIX, OLD_PREFIXES, VERSION, PRECACHE */

const CACHE = PREFIX + VERSION;
const KEPT = new Set(PRECACHE);

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE)
            // The offline page is fetched fresh, never from an earlier cache.
            .then((cache) =>
                cache.addAll(
                    PRECACHE.map(
                        (url) => new Request(url, { cache: 'reload' }),
                    ),
                ),
            )
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter(
                            (key) =>
                                (key.startsWith(PREFIX) && key !== CACHE) ||
                                OLD_PREFIXES.some((old) => key.startsWith(old)),
                        )
                        .map((key) => caches.delete(key)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() =>
                caches
                    .open(CACHE)
                    .then((cache) => cache.match('/offline'))
                    .then((page) => page || Response.error()),
            ),
        );

        return;
    }

    if (KEPT.has(url.pathname)) {
        event.respondWith(
            caches
                .open(CACHE)
                .then((cache) => cache.match(url.pathname))
                .then((cached) => cached || fetch(request)),
        );
    }
});
