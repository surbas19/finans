# Yeni sürüm yayınlanınca açık uygulama kendini yenilesin

## Bağlam
`yayinla.bat` ile yeni sürüm çıkınca açık kalmış uygulama (telefonda ana ekrandan açılan ya da tarayıcı sekmesi) eski kodu tutar; [paket-ac.php](../../backend/deploy/paket-ac.php) eski `public/assets` dosyalarını sildiği için tembel yüklenen sayfalar (Hesaplar, İzleme…) açılmaz. Seçim: uygulama yeni sürümü kendisi fark edip **uygun anda sessizce** yenilensin; form / pencere açıkken beklesin. `index.html` zaten `no-cache`; service worker dosya önbelleklemiyor.

## Adımlar
1. **Arka uç** `GET /api/v1/surum` (`Api/V1/SurumController`): `base_path('deploy/SURUM')`'un ilk kelimesi (`paket-hazirla.sh` yazar) → `{ surum }`, dosya yoksa `null`; `Cache-Control: no-store`; girişsiz, `throttle:api`. `.gitignore`'a `/backend/deploy/SURUM`. Test `SurumTest`.
2. **Derleme sürümü:** `vite.config.ts` `define: { __SURUM__ }` = `git rev-parse --short HEAD`; `env.d.ts` tipi; `finansApi.surum()`.
3. **`utils/surumDenetimi.ts`** (`main.ts`'te `surumDenetiminiKur(router)`):
   - Denetim: öne gelince (`visibilitychange`, en sık 30 sn) ve sayfa geçişinde (en sık 5 dk). Yerelde, sürüm boşsa ya da sunucu `null` dönerse kapalı.
   - Yeni sürüm → uygun an (görünür, açık pencere yok, `main`'de dolu yazı alanı yok) → `location.reload()`; değilse sonraki sayfa geçişinde tam yükleme.
   - Eski dosya 404 (`vite:preloadError`, `router.onError`) → hedef sayfaya tam yükleme.
   - Döngü koruması: 20 sn içinde ikinci yenileme yok (`sessionStorage`).
   - Test `surumDenetimi.spec.ts`.

## Doğrulama
Testler; yerelde canlı taklidi (derlenmiş arayüz 8001'de + `deploy/SURUM`): sürüm değişince yenilenir, pencere açıkken yenilenmez, sayfa geçişinde tam yükleme, silinmiş dosyada tam yükleme.
