# Finans: varlık fiyat takibi ve portföy (finans.yoklm.com, ayrı uygulama)

## Context
Kullanıcı borsa, altın ve diğer varlıkların fiyatlarını takip edecek bir sistem istiyor.

Kararlar:
- **Yer:** okul sisteminin içinde değil, **ayrı uygulama, `finans.yoklm.com`**, **ayrı Git deposu**.
  Böylece okul ürünüyle kod, veritabanı, cron ve yayın ayrı kalır; fiyat çekme işi okulun SMS ve hatırlatma işlerini geciktirmez.
- **Kullanıcı:** şimdilik kullanıcının kendisi, ileride başkaları da olabilir. Bu yüzden baştan çok kullanıcılı tasarlanır: herkes yalnızca kendi verisini görür, kayıt kapalıdır, kullanıcıyı yönetici ekler.
- **Varlıklar:** altın (gram, çeyrek, yarım, tam, cumhuriyet, ons), döviz, BIST hisseleri, kripto, TEFAS fonları.
- **İlk sürümün özellikleri:**
  - izleme listesi;
  - portföy, kâr / zarar;
  - geçmiş grafikleri;
  - **birden çok hesap / konum**, ayrı ayrı ve toplu görülür ("Yatırım hesabım", "Eşimin hesabı", "Banka kasası", "Evde"…).
  - Fiyat alarmı ilk sürümde yok.

**Okul deposunda hiçbir dosya değişmez.**

## Mimari
- **Depo:** `d:\yoklm\finans` olarak açılır ve ayrı, özel bir GitHub deposuna gönderilir. Okul deposunun yanında durur; iki bilgisayarda `git pull` / `git push` ile çalışılır.
- **Teknoloji:** okul sistemiyle aynı yığın, böylece bilgi ve kalıplar taşınır:
  - backend: Laravel 13, PHP 8.3, MySQL `utf8mb4_turkish_ci`, Sanctum SPA çerezi;
  - frontend: Vue 3, TS, Vite, Pinia, PrimeVue **4.5**, `@primeuix/themes` 2.x, primeicons 7, Tailwind 4, Chart.js.
  - Lisans kuralı aynı: PrimeVue 5 / primeicons 8 kullanılmaz.
  - Okuldaki genel parçalar kopyalanarak uyarlanır: `SayfaBasligi`, `IstatistikKarti`, `BosDurum`, `VeriGrafigi`, `FormAlani`, `http.ts` kalıbı, `useSonIstek`, `useFormGonder`.
- **Tek adres (aynı köken):**
  - Laravel derlenmiş Vue'yu `public/` içinden sunar; API `/api/v1/...`. CORS ve iki alt alan adı gerekmez.
  - Yerelde Vite, `/api` ve `/sanctum` isteklerini `localhost:8001`'e yönlendirir; arayüz `localhost:5174`'te çalışır.
- **Çerez tuzağı (önemli):** okulun oturum ve XSRF çerezi `.yoklm.com` alanında olduğu için `finans.yoklm.com`'a da gönderilir. Aynı ad iki kez gelirse 419 / oturum karışması olur. Bunun için:
  - `SESSION_DOMAIN=null` (çerez yalnızca bu adrese yazılır);
  - `SESSION_COOKIE=finans_oturum`;
  - XSRF çerezi ve başlığı farklı adla: Laravel CSRF ara katmanı genişletilir, axios'ta `xsrfCookieName` / `xsrfHeaderName` ayarlanır.
  - Okul tarafına dokunulmaz.
  - Test: aynı tarayıcıda okul ve finans açıkken ikisinde de giriş / form çalışır.
- **Barındırma (Turhost cPanel, SSH yok, `proc_open` kapalı varsayılır):**
  - Uygulama `~/finans-app`'e kurulur; `finans.yoklm.com`'un Document Root'u `finans-app/public`. Ayrı MySQL veritabanı, PHP 8.3.
  - Cron her dakika `/bin/bash /home/yoklmcom/finans-app/deploy/cron.sh` çalıştırır.
  - Yayın betikleri okuldakinden kopyalanıp sadeleştirilir: `deploy/yayinla.sh`, `paket-hazirla.sh`, `paket-ac.php`, `cron.sh`. Bunlar `~/finans-yukleme/`'ye gönderir, `deploy/KUR` işareti kullanılır, ayrı `canli-paket/ftp.env` dosyası git'e girmez.
  - Zamanlanmış işler okuldaki `$zamanla` kalıbıyla aynı PHP sürecinde çalışır; `Schedule::command` kullanılmaz.
- **Kişisel veri:** TC kimlik numarası, banka hesap numarası ve IBAN tutulmaz. Hesaplar yalnızca kullanıcının verdiği adla tutulur ("Yatırım hesabım").

## Fiyat kaynakları
Her kaynak `FiyatKaynagi` arayüzünü uygulayan ayrı bir sınıftır: `guncel(varliklar)` ve `gecmis(varlik, baslangic, bitis)`. Kaynaklar toplu istek atar, 10 sn zaman aşımı vardır, biri bozulursa öbürleri sürer. Her kaynağın son başarı ve son hata bilgisi `kaynak_durumlari` tablosunda tutulur ve ekranda görünür.

| Varlık | İlk kaynak | Not |
|---|---|---|
| Altın, döviz | Truncgil Finans `finans.truncgil.com/v4/today.json` | Ücretsiz, resmî değil; anahtar adları 1. adımda doğrulanır |
| Döviz (yedek, geçmiş) | TCMB `tcmb.gov.tr/kurlar/` günlük XML | Resmî, günde bir; geçmiş tarih arşivi var |
| BIST hisseleri | Yahoo Finance chart ucu (`THYAO.IS`) | Resmî değil, ~15 dk gecikmeli, kişisel kullanım; ekranda "gecikmeli" yazar |
| Kripto | Binance açık API (TRY pariteleri) | Anahtarsız; geçmiş için kline |
| Fonlar | TEFAS `api/DB/BindHistoryInfo` | Resmî değil, belgesiz; 90 günlük parçalar, günde bir |
| Hepsi | **Elle fiyat girişi** | Kaynak bozulursa ya da hiç yoksa yedek |

- **Güncelleme sıklığı:**
  - piyasa saatlerinde 15 dakikada bir;
  - günlük kapanış: BIST 18:30'dan sonra, öbürleri gece. Kapanış `fiyat_gecmisi` tablosuna yazılır.
  - Fonlar günde bir kez güncellenir.
- **Geçmiş:** varlık kataloğa ilk eklendiğinde geçmiş kaynakta varsa 1 yıllık geçmiş bir kez çekilir. Truncgil'in geçmişi yoktur; altının grafiği eklendiği günden başlar.
- **Uyarı (kullanıcıya iletilir):** BIST verisini başkalarına dağıtmak lisans gerektirir (BIST veri dağıtım sözleşmesi). Uygulama başkalarına açılırken bu konu yeniden ele alınır.

## Veri modeli
- `users`: ad, soyad, e-posta, şifre, `yonetici` (bool). Kayıt kapalıdır; yönetici kullanıcı ekler. Şifre kuralı `Password::defaults()`.
- `varliklar` (ortak katalog):
  - `tur`: altin, doviz, hisse, fon, kripto;
  - `kod`, `ad`;
  - `birim`: gram, adet, pay, birim;
  - `para_birimi`: TRY veya USD;
  - `kaynak`, `kaynak_kodu`, `aktif`.
- `fiyatlar`: varlık başına son fiyat; `alis`, `satis`, `fiyat`, `onceki_kapanis`, `zaman`, `kaynak`, `elle`.
- `fiyat_gecmisi`: `varlik_id`, `tarih`, `kapanis`; benzersiz anahtar (varlık + tarih).
- `hesaplar`: `user_id`, `ad`, `tur` (aracı kurum, banka, kasa, ev, kripto cüzdanı, diğer), `renk`, `sira`, `not`.
- `islemler`:
  - `user_id`, `hesap_id`, `varlik_id`;
  - `tur`: alış, satış veya mevcut (başlangıç bakiyesi);
  - `miktar` decimal(24,8);
  - `birim_fiyat`: boş bırakılabilir, maliyet bilinmiyorsa (ör. kasadaki eski altın);
  - `komisyon`, `tarih`, `not`.
- `izleme_listesi`: `user_id`, `varlik_id`, `sira`.
- **Portföy hesabı (tek yer, `PortfoyHesabi` servisi):**
  - hesap × varlık için miktar ve **ağırlıklı ortalama maliyet** hesaplanır; satışta gerçekleşen kâr / zarar ayrıca tutulur;
  - güncel değer `miktar × güncel fiyat` olarak hesaplanır, değerleme TL'dir: USD fiyatlılar USD/TRY ile çevrilir;
  - **maliyeti bilinmeyen kalem sıfır maliyet sayılmaz**: değeri gösterilir, kâr / zarar "—" olur;
  - elde olandan fazla satış reddedilir.
- **İzolasyon:** her uç kullanıcı kaydı ve Policy denetimiyle korunur. "Başka kullanıcının hesabı / işlemi görünmez" testi her uç için yazılır.

## Ekranlar (mobile-first)
- **Giriş.**
- **Özet** (ana sayfa):
  - toplam değer, toplam kâr / zarar, bugünkü değişim;
  - hesap kartları (her hesabın değeri);
  - varlık türü dağılımı (halka grafik);
  - üstte "Tümü / hesap seç" süzgeci.
- **Hesaplar → hesap detayı:**
  - içindeki varlıklar: miktar, ortalama maliyet, güncel değer, kâr / zarar;
  - işlem geçmişi;
  - "İşlem ekle" (alış / satış / mevcut).
- **İzleme listesi:** fiyat, günlük değişim yüzdesi (yeşil / kırmızı), son güncelleme saati, gecikmeli işareti; katalogdan ekleme.
  - Katalogda olmayan hisse / fon / kripto kodu yazılırsa kaynaktan doğrulanıp kataloğa eklenir.
- **Varlık detayı:**
  - fiyat grafiği (1A / 3A / 1Y / Tümü);
  - bu varlıktan hangi hesapta ne kadar olduğu;
  - elle fiyat girme (yönetici).
- **Yönetim** (yönetici): kullanıcılar, kaynak durumu (son başarı / hata), katalog.

## Aşamalar (her adımda commit + push, DEVAM.md kaydı)
1. **Kaynak denemesi:** yeni depoda küçük bir artisan komutu her kaynaktan örnek çeker. Gerçek alan adları (gram, çeyrek, ons…) doğrulanır. Çalışmayan kaynak için seçenek kullanıcıya sorulur.
2. **İskelet:**
   - depo, Laravel ve Vue kurulumu, giriş, çerez ayarları;
   - `CLAUDE.md`, `docs/ILERLEME.md`, `docs/DEVAM.md`, `docs/planlar/` (bu plan buraya kopyalanır);
   - yayın betikleri ve `docs/CANLI.md`.
   - Kullanıcının cPanel adımları: alt alan adı, veritabanı, PHP 8.3, cron, ilk `.env`. Ardından ilk yayın; boş giriş sayfası canlıda açılır ve Turhost'tan dış istek denenir.
3. **Katalog ve fiyatlar:** `varliklar`, `fiyatlar`, `fiyat_gecmisi`, kaynak sınıfları, zamanlanmış güncelleme, geçmiş çekme, elle fiyat; demo seeder (sabit tohum, örnek fiyatlar).
4. **Portföy:** hesaplar, işlemler, `PortfoyHesabi`, uçlar, Policy; izolasyon ve hesap testleri (ortalama maliyet, kısmi satış, maliyeti bilinmeyen kalem, USD çevirimi).
5. **Ekranlar:** özet, hesaplar, izleme listesi, varlık detayı ve grafik, yönetim.
6. **Belgeler, tüm testler, canlıya yayın.**

## Kapsam dışı (sonraki sürümler)
- fiyat alarmı ve bildirim;
- hesabı başka kullanıcıyla paylaşma (eşin kendi girişiyle görmesi);
- hesaplar arası aktarma (kasadan yatırım hesabına);
- temettü / faiz / kira geliri;
- Excel'e aktarma;
- PWA.

## Doğrulama
- Backend: `php artisan test` (MySQL `finans_test`).
  - Kaynak sınıfları için `Http::fake` kullanılır; testlerde gerçek kaynağa istek atılmaz.
  - Portföy hesabı birim testleri, izolasyon testleri.
- Frontend: `npm run type-check && npm run lint && npx vitest run`.
- Yerelde: `migrate:fresh --seed`, demo kullanıcıyla giriş. İki hesapta alış / satış yapılır; özet, hesap ve grafik ekranları telefon genişliğinde denenir.
- Canlıda:
  - `finans.yoklm.com` açılır, giriş yapılır, cron fiyatları günceller (kaynak durumu ekranı);
  - aynı tarayıcıda `yoklm.com` okul girişi bozulmadan çalışır (çerez çakışması yok).

## 1. adım sonucu (2026-11-13, kaynak denemesi)
- Truncgil `v4/today.json` çalışıyor. Anahtarlar: dövizler `USD`, `EUR`… (`Buying`, `Selling`, `Change` %);
  altın `GRA` (gram), `HAS` (has gram), `CEYREKALTIN`, `YARIMALTIN`, `TAMALTIN`, `CUMHURIYETALTINI`, `ATAALTIN`,
  `14AYARALTIN`, `18AYARALTIN`, `YIA` (22 ayar bilezik), `GUMUS`; ayrıca `XU100`, `BRENT`. **`ONS` 0 dönüyor** →
  ons Yahoo `GC=F`'den (USD) alınır. `Update_Date` alanı var.
- Yahoo chart ucu çalışıyor (`THYAO.IS`: `regularMarketPrice`, `chartPreviousClose`, 52 hafta, geçmiş `range=1y&interval=1d`).
- Binance `api/v3/ticker/24hr?symbols=[...]` çalışıyor (`BTCTRY`, `lastPrice`, `priceChangePercent`, `prevClosePrice`).
- TCMB `kurlar/today.xml` çalışıyor (`ForexBuying`, `ForexSelling`).
- **TEFAS otomatik istekleri engelliyor** (güvenlik duvarı "Request Rejected"; `BindHistoryInfo` 404). Engel aşılmaz.
  **Kullanıcı kararı: fon fiyatları elle girilir** (son giriş tarihi görünür); resmî kaynak bulunursa sonra eklenir.
- **CSRF:** Laravel 13 `preventRequestForgery(originOnly: true)` → `Sec-Fetch-Site: same-origin` olmayan yazma isteği
  reddedilir, XSRF çerezi hiç yazılmaz. Arayüz ve API aynı köken olduğu için XSRF çerezi çakışması (okulun `.yoklm.com`
  çerezi) tamamen ortadan kalkar; oturum çerezi adı `finans_oturum`.
