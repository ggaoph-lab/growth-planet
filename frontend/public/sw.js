const CACHE = 'growth-planet-shell-v9'

self.addEventListener('install', (event) => {
  // Don't precache HTML — it must always point at the latest hashed assets.
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(['/favicon.svg', '/manifest.webmanifest'])))
  self.skipWaiting()
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.map((k) => caches.delete(k)))).then(() => caches.open(CACHE)),
  )
  self.clients.claim()
})

self.addEventListener('fetch', (event) => {
  const { request } = event
  if (request.method !== 'GET') return

  const url = new URL(request.url)
  if (url.pathname.startsWith('/api') || url.pathname.startsWith('/sanctum')) return

  // HTML / SPA navigations: always try network first so rebuilds take effect.
  const isDocument =
    request.mode === 'navigate' ||
    url.pathname === '/' ||
    url.pathname === '/index.html' ||
    (request.headers.get('accept') || '').includes('text/html')

  if (isDocument) {
    event.respondWith(fetch(request).catch(() => Response.error()))
    return
  }

  // Hashed /assets/*: cache-first. Build hashes change on deploy, so stale shells cannot stick.
  // Network-first made every planet tap wait on NAS even when the chunk was already cached.
  if (url.pathname.startsWith('/assets/')) {
    event.respondWith(
      caches.match(request).then((cached) => {
        if (cached) return cached
        return fetch(request).then((response) => {
          if (response.ok && url.origin === self.location.origin) {
            const copy = response.clone()
            caches.open(CACHE).then((cache) => cache.put(request, copy))
          }
          return response
        })
      }),
    )
    return
  }

  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) return cached
      return fetch(request)
    }),
  )
})
