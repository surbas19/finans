# Finans

Kişisel varlık fiyat takibi ve portföy uygulaması (`https://finans.yoklm.com`). Altın, döviz, BIST hisseleri, kripto ve yatırım fonları; birden çok hesap / konum ("Yatırım hesabım", "Eşimin hesabı", "Banka kasası"…), kâr / zarar, geçmiş grafikleri. Okul sisteminden (`../okul sistemi`) **tamamen ayrı** uygulama: ayrı depo, veritabanı, cron ve yayın. Plan: [docs/planlar/ilk-surum.md](docs/planlar/ilk-surum.md). İlerleme: [docs/ILERLEME.md](docs/ILERLEME.md). **Oturuma başlarken ILERLEME.md ve DEVAM.md'yi oku, bitirirken güncelle.**

**İki bilgisayarda çalışılıyor:** oturuma başlarken `git pull` (migration / seeder değiştiyse `php artisan migrate:fresh --seed`), bitirirken commit + `git push`.

**KURAL — Kaldığın yeri kaydet:** kullanım limiti haber vermeden bitebilir. Birden çok adımlı her işte onaylanan plan `docs/planlar/<iş>.md`'ye kopyalanır, [docs/DEVAM.md](docs/DEVAM.md)'ye işin kaydı (plan, durum, yapılanlar, **sıradaki adım**, gereken komutlar) yazılır; her anlamlı adımdan sonra güncellenip commit + push edilir (testler geçmiyorsa mesaj "Yarım: …" ile başlar). İş bitince kaydı DEVAM.md'den silinir, tek satırı ILERLEME.md → "Son tamamlananlar"a yazılır.

## Teknoloji
- `backend/`: Laravel 13 (PHP 8.3+, MySQL `utf8mb4_turkish_ci`), Sanctum SPA çerezi. Derlenmiş arayüzü de Laravel sunar (aynı köken): API `/api/v1/...`, diğer her yol `resources/spa/index.html`.
- `frontend/`: Vue 3 + TypeScript + Vite + Pinia + Vue Router + PrimeVue 4.5 + Tailwind 4 + Chart.js. Yerelde `http://localhost:5174`; Vite `/api` ve `/sanctum`'u `http://localhost:8001`'e aktarır.
- **Lisans kuralı:** PrimeVue **4.x**, `@primeuix/themes` 2.x, `primeicons` **7.x** (MIT). PrimeVue 5 ve primeicons 8 ücretli lisansa geçti — **yükseltme yapma.** Yeni paketin lisansını kontrol et (`npm view <paket> license`).
- Barındırma: Turhost cPanel, **SSH yok**, `proc_open` kapalı. Kurulum ve yayın: [docs/CANLI.md](docs/CANLI.md) (`backend/deploy/yayinla.sh`). Canlıda elle artisan çalıştırılamaz; gereken adım `deploy/cron.sh`'ye eklenir. Zamanlanmış komutlar `routes/console.php`'de `$zamanla(komut, ad)` ile aynı süreçte çalışır; `Schedule::command()` kullanılmaz.

## Temel kurallar
- **Kullanıcı izolasyonu zorunlu:** her kayıt (`hesaplar`, `islemler`, `izleme_listesi`) bir `user_id` taşır; kullanıcı yalnızca kendi kaydını görür. Yetki kontrolü **Policy**'de. Yeni her uç için "başka kullanıcının verisi görünmüyor / değişmiyor" testi yazılır. Varlık kataloğu (`varliklar`, `fiyatlar`, `fiyat_gecmisi`) ortaktır.
- Kayıt kapalı; kullanıcıyı yönetici (`users.yonetici`) ekler. Şifre kuralı `Password::defaults()`.
- **Kişisel veri:** TC kimlik numarası, banka hesap numarası, IBAN, kart bilgisi **tutulmaz, istenmez**. Hesaplar yalnızca kullanıcının verdiği adla tutulur.
- **Çerez / CSRF:** okul sisteminin çerezleri `.yoklm.com` alanında ve buraya da gelir. Bu yüzden oturum çerezi `finans_oturum` adlı ve yalnızca bu adrese yazılır (`SESSION_DOMAIN=null`); CSRF Laravel 13'ün köken denetimiyle yapılır (`preventRequestForgery(originOnly: true)`, `Sec-Fetch-Site: same-origin`), XSRF çerezi kullanılmaz. Arayüz ve API aynı kökende kalmalı; ayrı alt alan adına bölünmez.
- **Fiyat kaynakları** `app/Fiyat/Kaynaklar/*` (`FiyatKaynagi` arayüzü). Testlerde **gerçek kaynağa istek atılmaz** (`Http::fake`). Kaynaklar resmî değildir ve değişebilir; biri bozulursa öbürleri sürer, durum `kaynak_durumlari`'nda görünür; her varlığa elle fiyat girilebilir. Güvenlik duvarı / bot korumasıyla engelleyen kaynak (ör. TEFAS) **aşılmaya çalışılmaz**.
- **Portföy hesabı tek yerde** (`App\Services\PortfoyHesabi`): ağırlıklı ortalama maliyet, gerçekleşen / gerçekleşmemiş kâr-zarar, TL değerleme (USD fiyatlılar USD/TRY ile). **Maliyeti bilinmeyen kalem sıfır maliyet sayılmaz** (kâr / zarar "—"). Para / miktar veritabanında `decimal` saklanır; hesap PHP float'ıyla yapılır, çıktı yuvarlanır (TL 2, fiyat 4, miktar 8 hane). Satış, o tarihte hesapta olandan fazla olamaz (ekleme / düzeltme / silmede `PortfoyHesabi::dogrula`).
- BIST verisi gecikmeli ve resmî olmayan kaynaktandır; ekranda "gecikmeli" yazar. Uygulama başkalarına açılırken BIST veri dağıtım lisansı yeniden ele alınır.
- Kod içi isimlendirme Türkçe alan adları (varlik, hesap, islem), İngilizce Laravel / Vue kalıpları. Arayüz mobile-first.
- Gizli bilgiler (`.env`, `canli-paket/ftp.env`) commit edilmez; yeni ayar eklenince `.env.example` güncellenir. Canlıda `APP_KEY` değiştirilmez.
- Demo verisi `database/seeders/DemoSeeder.php` (yalnızca `local`): sabit tohumlu `fake()`, gerçek kişi / hesap bilgisi yok.
- Her işten sonra **tüm** testler: `php artisan test`, `npm run type-check && npm run lint && npx vitest run`. Pint / prettier yalnızca işin dosyalarına.

## Komutlar
```bash
# backend (PHP: /c/laragon/bin/php/php-8.3.*; composer: php /c/laragon/bin/composer/composer.phar)
cd backend && php artisan migrate:fresh --seed
php artisan serve --port=8001
php artisan test
# frontend
cd frontend && npm install && npm run dev     # http://localhost:5174
npm run type-check && npm run lint && npx vitest run
npm run build                                 # çıktı backend/public/assets + backend/resources/spa
```

## Ortam notları
- Veritabanları: `finans` ve `finans_test` (`CREATE DATABASE finans CHARACTER SET utf8mb4 COLLATE utf8mb4_turkish_ci;`). MySQL root şifresiz (Laragon).
- Demo girişi (`migrate:fresh --seed`, şifre `password`): `ben@finans.test` (yönetici), `es@finans.test`.
- `backend/AGENTS.md` Laravel Boost kurallarıdır.
