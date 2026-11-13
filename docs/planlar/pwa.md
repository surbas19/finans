# Telefona kurulabilir uygulama (PWA) + mobil cilalama

## Bağlam
Kullanıcı uygulamayı en çok telefonda kullanacak; "ana ekrana ekle" istiyor. Mevcut durum:
- **Mobil uyum zaten iyi:** mobile-first; telefonda alt sekme çubuğu ([AnaDuzen.vue](frontend/src/layouts/AnaDuzen.vue), `pb-[env(safe-area-inset-bottom)]`), `viewport-fit=cover`, diyaloglar ekran genişliğine sığıyor (`w-[min(34rem,calc(100vw-1rem))]`), sayı alanlarında `inputmode="decimal"`, girişte "Beni hatırla" varsayılan açık (oturum telefonda düşmez).
- **Eksik:** web manifest yok, PNG / Apple ikonları yok (yalnızca `favicon.svg`), service worker yok, iOS meta etiketleri yok, kurulum yönlendirmesi yok. Bu yüzden ana ekrana eklenince tam ekran uygulama gibi açılmaz, ikon da bulanık / ekran görüntüsü olur.

Hedef: Android'de "Uygulamayı yükle", iPhone'da "Ana Ekrana Ekle" ile kendi ikonuyla, adres çubuğu olmadan açılan uygulama. Yeni npm paketi yok (vite-plugin-pwa gerekmez; birkaç statik dosya yeterli). Fiyat / portföy verisi **önbelleğe alınmaz** (hep taze); yalnızca bağlantı yokken sade bir "çevrimdışı" sayfası gösterilir.

İş başında: plan `docs/planlar/pwa.md`'ye kopyalanır, DEVAM.md'ye kayıt; adımlarda commit + push.

## 1. İkonlar — `frontend/public/` (+ üretim betiği `frontend/scripts/ikon-uret.ps1`)
`favicon.svg` tasarımı (teal `#0f766e` zemin, beyaz yükselen çizgi, amber nokta) PowerShell `System.Drawing` ile aynı şekiller çizilerek PNG'ye dökülür (SVG çevirici gerekmez, yalnızca basit şekiller):
- `icon-192.png`, `icon-512.png` (köşeleri yuvarlak, `purpose: any`)
- `icon-maskable-512.png` (tam zemin, çizim ortadaki %80 güvenli alanda; Android şekil maskesi için)
- `apple-touch-icon.png` 180×180 (tam zemin, köşesiz; iOS kendisi yuvarlar)
Betik depoda kalır; PNG'ler commit'lenir.

## 2. Manifest — `frontend/public/manifest.webmanifest`
`name`/`short_name` "Finans", `lang: tr`, `start_url: "/"`, `scope: "/"`, `display: "standalone"`, `orientation: "portrait"`, `theme_color: "#0f766e"`, `background_color` (sayfa zemini, `main.css`'teki surface-50 tonu), yukarıdaki ikonlar. Kısayollar (`shortcuts`): "İşlem ekle" yok (form sayfa değil diyalog) → yalnızca Özet / Hesaplar / İzleme.

## 3. Service worker — `frontend/public/sw.js` + `frontend/public/cevrimdisi.html`
- `install`: yalnızca `cevrimdisi.html`'i önbelleğe al, `skipWaiting`.
- `activate`: eski sürüm önbelleklerini sil, `clients.claim`.
- `fetch`: yalnızca sayfa açılışları (`request.mode === 'navigate'`) — önce ağ, ağ yoksa `cevrimdisi.html`. API, `/sanctum`, varlık dosyaları hiç ele alınmaz (tarayıcı normal davranır; veri asla bayat gösterilmez).
- `cevrimdisi.html`: tek dosyalık, satır içi stilli Türkçe sayfa ("İnternet bağlantısı yok", "Yeniden dene" düğmesi).
- Kayıt: `frontend/src/main.ts`'te `import.meta.env.PROD && 'serviceWorker' in navigator` ise `navigator.serviceWorker.register('/sw.js')` (yerel geliştirmede kayıt yok, Vite ile çakışmaz).

## 4. `frontend/index.html` etiketleri
`<link rel="manifest" href="/manifest.webmanifest">`, `<link rel="apple-touch-icon" href="/apple-touch-icon.png">`, `<meta name="mobile-web-app-capable" content="yes">`, `<meta name="apple-mobile-web-app-capable" content="yes">`, `<meta name="apple-mobile-web-app-title" content="Finans">`, `<meta name="apple-mobile-web-app-status-bar-style" content="default">`. Mevcut `theme-color` kalır.

## 5. Sunucu — `backend/public/.htaccess`
`AddType application/manifest+json .webmanifest` (LiteSpeed bu uzantıyı tanımayabilir). `sw.js` ve manifest uzun süre önbelleğe alınmasın: `<FilesMatch "^(sw\.js|manifest\.webmanifest)$"> Header set Cache-Control "no-cache" </FilesMatch>` (mod_headers yoksa `IfModule` ile sessizce atlanır). Paketleme değişmez: [paket-hazirla.sh](backend/deploy/paket-hazirla.sh) `dist/` altındaki `assets` ve `index.html` dışındaki her dosyayı zaten `public/`'e kopyalıyor; Laravel'in `SpaController` yönlendirmesi var olan dosyalara dokunmaz (`.htaccess` `!-f`).

## 6. Kurulum yönlendirmesi (arayüz)
- `frontend/src/composables/useUygulamaKurulumu.ts`: modül düzeyinde `beforeinstallprompt` olayını yakalar (dinleyici `main.ts`'te erken kurulur), `kurulabilir`, `kurulu` (`display-mode: standalone` ya da iOS `navigator.standalone`), `ios` (iPhone/iPad Safari) durumlarını ve `kur()` (Android istemini açar) verir.
- Saf yardımcı `frontend/src/utils/platform.ts` (`iosMu(userAgent, maxTouchPoints)`, `kuruluMu(...)`) → vitest testi.
- `frontend/src/components/UygulamaKurKarti.vue`:
  - Android / Chrome, istem hazır → "Ana ekrana ekle" düğmesi.
  - iPhone Safari → kısa adımlar: Paylaş simgesi (`pi pi-upload`) → "Ana Ekrana Ekle".
  - Zaten kuruluysa ya da masaüstündeyse gösterilmez.
- Yerleşim: [ProfilView.vue](frontend/src/views/ProfilView.vue)'da kalıcı kart; [OzetView.vue](frontend/src/views/OzetView.vue)'nın üstünde telefonda bir kez çıkan, kapatılabilir küçük ipucu (kapatma `localStorage`'da, try/catch'li).

## 7. Kurulu (tam ekran) modda küçük düzeltmeler
- [AnaDuzen.vue](frontend/src/layouts/AnaDuzen.vue) üst çubuğa `pt-[env(safe-area-inset-top)]` (çentikli iPhone'larda başlık saat çubuğunun altına kaçmasın).
- Tam ekranda adres çubuğu olmadığı için tarayıcının yenileme düğmesi yok: Özet'teki "Fiyatlar … güncellendi" satırına küçük bir yenile düğmesi (`yukle()` çağırır) eklenir.

## Belgeler
`docs/ILERLEME.md` → "Sıradaki"deki "PWA (ana ekrana ekleme)" kısmı "Son tamamlananlar"a; "Excel'e aktarma" maddesi kalır. `docs/CANLI.md` "Bakım"a: telefonda kurulum adımları (Android / iPhone) bir paragraf.

## Doğrulama
1. `php artisan test`; `npm run type-check && npm run lint`; vitest PowerShell'den (`D:\`), yeni `platform.spec.ts` dahil.
2. `npm run build` → `dist/` içinde manifest, sw.js, cevrimdisi.html, 4 PNG var; paket zip'inde `finans-app/public/` altında oldukları `unzip -l` ile kontrol.
3. Yayın (`bash backend/deploy/yayinla.sh`), sonra canlıda: `curl -I https://finans.yoklm.com/manifest.webmanifest` → 200 ve `application/manifest+json`; `sw.js`, ikonlar 200.
4. Kullanıcı telefonda: Android Chrome → menüde "Uygulamayı yükle" / Profil'deki düğme; iPhone Safari → Paylaş → Ana Ekrana Ekle. Ana ekrandaki ikon net, açılınca adres çubuğu yok, alt sekmeler ve üst çubuk çentiğe taşmıyor; uçak modunda açınca "İnternet bağlantısı yok" sayfası.
