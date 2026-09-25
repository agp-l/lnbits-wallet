const CACHE = 'lite-ln-assets-v3';
const STATIC = ['assets/app.css', 'assets/app.js', 'assets/qrcodegen.js', 'icons/icon.svg', 'manifest.webmanifest'];
self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(STATIC)));
  self.skipWaiting();
});
self.addEventListener('activate', (event) => {
  event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key)))));
  self.clients.claim();
});
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (event.request.method !== 'GET' || url.origin !== location.origin || !STATIC.some((item) => url.pathname.endsWith('/' + item))) return;
  event.respondWith(caches.match(event.request).then((cached) => cached || fetch(event.request)));
});
