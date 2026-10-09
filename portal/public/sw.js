/* efaPortal service worker — cache application shell/assets only; APIs always network. */
const CACHE = "efaportal-shell-v4";
const SHELL = [
  "/portal/",
  "/portal/index.html",
  "/portal/manifest.webmanifest",
  "/portal/icons/icon.svg",
  "/portal/icons/icon-maskable.svg",
];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches
      .open(CACHE)
      .then((cache) => cache.addAll(SHELL))
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
      )
      .then(() => self.clients.claim())
  );
});

self.addEventListener("message", (event) => {
  if (event.data && event.data.type === "SKIP_WAITING") {
    self.skipWaiting();
  }
});

self.addEventListener("fetch", (event) => {
  const url = new URL(event.request.url);
  if (event.request.method !== "GET") return;
  // Authenticated API and other app backends: network-only (never cache).
  if (url.pathname.startsWith("/api/")) return;
  if (url.pathname.startsWith("/forms/") || url.pathname.startsWith("/pages/")) return;

  event.respondWith(
    fetch(event.request)
      .then((response) => {
        const copy = response.clone();
        if (response.ok && url.pathname.startsWith("/portal/")) {
          caches.open(CACHE).then((cache) => cache.put(event.request, copy));
        }
        return response;
      })
      .catch(() =>
        caches
          .match(event.request)
          .then((hit) => hit || caches.match("/portal/index.html") || caches.match("/portal/"))
      )
  );
});
