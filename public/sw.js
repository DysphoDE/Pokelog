/* Pokélog Service Worker
 * Strategie:
 *  - App-Shell (HTML/JS/Manifest/Icon): stale-while-revalidate (schneller Start).
 *  - Bilder & CDN-Assets (TCGdex-Set-Symbole/Logos/Kartenbilder, Fonts, Libs):
 *    cache-first und dauerhaft gespeichert -> laden sofort, bleiben offline.
 *  - API (api.php): immer Netzwerk (dynamische Daten, nie cachen).
 */
// Shell-Cache-Version bei Bedarf erhoehen, um alte HTML/JS-Caches zu verwerfen.
const SHELL_CACHE = 'pokelog-shell-v2';
const ASSET_CACHE = 'pokelog-assets-v1';
const SHELL_FILES = ['./', 'index.php', 'assets/app.js', 'manifest.webmanifest', 'icon.svg'];

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(SHELL_CACHE);
        await Promise.allSettled(SHELL_FILES.map((f) => cache.add(f)));
        self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keep = [SHELL_CACHE, ASSET_CACHE];
        const names = await caches.keys();
        await Promise.all(names.filter((n) => !keep.includes(n)).map((n) => caches.delete(n)));
        await self.clients.claim();
    })());
});

function isAsset(url) {
    return (
        url.hostname.endsWith('tcgdex.net') ||
        url.hostname.includes('jsdelivr.net') ||
        url.hostname.includes('tailwindcss.com') ||
        url.hostname.includes('fonts.googleapis.com') ||
        url.hostname.includes('fonts.gstatic.com')
    );
}

async function cacheFirst(request) {
    const cache = await caches.open(ASSET_CACHE);
    const hit = await cache.match(request);
    if (hit) return hit;
    try {
        const res = await fetch(request);
        // Auch opaque (no-cors) Antworten cachen – ideal fuer Bilder.
        if (res && (res.ok || res.type === 'opaque')) {
            cache.put(request, res.clone());
        }
        return res;
    } catch (e) {
        return hit || Response.error();
    }
}

async function staleWhileRevalidate(request) {
    const cache = await caches.open(SHELL_CACHE);
    const hit = await cache.match(request);
    const fetching = fetch(request)
        .then((res) => {
            if (res && res.ok) cache.put(request, res.clone());
            return res;
        })
        .catch(() => hit);
    return hit || fetching;
}

// Network-first: immer das Netz versuchen (frische Version nach Deploy),
// nur offline auf den Cache zurueckfallen. Fuer die HTML-Navigation.
async function networkFirst(request) {
    const cache = await caches.open(SHELL_CACHE);
    try {
        const res = await fetch(request);
        if (res && res.ok) cache.put(request, res.clone());
        return res;
    } catch (e) {
        const hit = await cache.match(request);
        return hit || (await cache.match('index.php')) || (await cache.match('./')) || Response.error();
    }
}

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);

    // API niemals cachen.
    if (url.origin === self.location.origin && url.pathname.endsWith('/api.php')) {
        return;
    }
    if (isAsset(url)) {
        event.respondWith(cacheFirst(request));
        return;
    }
    if (url.origin === self.location.origin) {
        // HTML-Seiten immer frisch aus dem Netz holen (Updates ohne Cache-Leeren);
        // alles andere (versioniertes app.js?v=…, Manifest, Icon) stale-while-revalidate.
        if (request.mode === 'navigate') {
            event.respondWith(networkFirst(request));
        } else {
            event.respondWith(staleWhileRevalidate(request));
        }
    }
});
