/*
 * Finans service worker: uygulamanın telefona kurulabilmesi ve bağlantı yokken sade bir uyarı sayfası için.
 * Fiyat / portföy verisi ve uygulama dosyaları ÖNBELLEĞE ALINMAZ (veri hep taze, yeni sürüm hemen gelir):
 * yalnızca sayfa açılışları ele alınır; ağ yoksa cevrimdisi.html gösterilir. API ve dosya istekleri tarayıcıya bırakılır.
 */
const ONBELLEK = 'finans-cevrimdisi-v1'
const CEVRIMDISI = '/cevrimdisi.html'

self.addEventListener('install', (olay) => {
  olay.waitUntil(
    caches
      .open(ONBELLEK)
      .then((c) => c.add(new Request(CEVRIMDISI, { cache: 'reload' })))
      .then(() => self.skipWaiting()),
  )
})

self.addEventListener('activate', (olay) => {
  olay.waitUntil(
    caches
      .keys()
      .then((adlar) =>
        Promise.all(adlar.filter((a) => a !== ONBELLEK).map((a) => caches.delete(a))),
      )
      .then(() => self.clients.claim()),
  )
})

self.addEventListener('fetch', (olay) => {
  if (olay.request.mode !== 'navigate') return

  olay.respondWith(
    fetch(olay.request).catch(() =>
      caches.match(CEVRIMDISI).then((yanit) => yanit ?? Response.error()),
    ),
  )
})
