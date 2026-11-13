# Canlı ortam (finans.yoklm.com)

| Adres | Ne | Sunucuda |
|---|---|---|
| https://finans.yoklm.com | Arayüz + API (aynı köken; Laravel derlenmiş Vue'yu sunar) | `~/finans-app` — alt alan adının kökü **`~/finans-app/public`** |

Okul sisteminden tamamen ayrıdır: ayrı klasör, ayrı veritabanı, ayrı cron satırı, ayrı yükleme klasörü (`~/finans-yukleme`). Okulun `~/okul-api`, `~/public_html`, `~/okul-yukleme` klasörlerine ve `.env`'ine dokunulmaz.

## İlk kurulum (bir kez, cPanel'de)
1. **Alt alan adı:** cPanel → *Domains* → *Create A New Domain* → `finans.yoklm.com`, *Document Root*: `finans-app/public` ("Share document root" işaretsiz). cPanel kutuya kendiliğinden `finans.yoklm.com` yazar; değiştirmeyi unutursanız site boş "Index of /" gösterir. Sonradan *Domains* → *Manage*'den düzeltilir, ardından PHP 8.3 seçimi yeniden kaydedilir.
2. **PHP:** *MultiPHP Manager* → `finans.yoklm.com` için **PHP 8.3**. Eklentiler (Select PHP Version → Extensions): pdo_mysql, mbstring, openssl, fileinfo, zip, intl, dom/xml, curl, zlib, iconv (ekstre PDF'i okumak için). *MultiPHP INI Editor* → `upload_max_filesize` en az **12M**, `post_max_size` en az **16M** (ekstre PDF'i en çok 10 MB).
3. **SSL:** *SSL/TLS Status* → `finans.yoklm.com` için *Run AutoSSL* (HTTPS şart: oturum çerezi `Secure`).
4. **Veritabanı:** *MySQL Databases* → yeni veritabanı (ör. `yoklmcom_finans`) + yeni kullanıcı (güçlü şifre) → kullanıcıyı veritabanına **ALL PRIVILEGES** ile ekleyin. Okulun veritabanı kullanılmaz.
5. **İlk paket:** yerelde `bash backend/deploy/yayinla.sh` (aşağıda "Yayın"). İlk çalışmada `canli-paket/ftp.env` şablonu oluşur; okul sisteminin `canli-paket/ftp.env`'indeki FTP bilgilerini buraya da yazın (aynı yayın hesabı kullanılabilir, "Directory" ev klasörüdür). Paket `~/finans-yukleme/`'ye gider.
   - **İlk paketi her zaman elle açın:** `deploy/cron.sh` paketin içinde geldiği için ilk açılışı cron yapamaz (`finans-app/deploy` boş kalır). *File Manager* → `finans-yukleme/finans-app.zip` → *Extract* → hedef kutusuna tam yol `/home/yoklmcom` yazın (varsayılan hedef zip'in kendi klasörüdür; göreli yol ya da sonda boşluk yeni klasör açar); `~/finans-app/` oluşur. Sonra zip'i silin. Sonraki paketleri cron açar.
6. **`.env`:** *File Manager* → `finans-app` → *+ File* → `.env` → `finans-app/.env.production.example` içeriğini yapıştırın; `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` ve `KURULUM_YONETICI="e-posta|Ad|Soyad"` doldurun. `APP_KEY` boş kalır (kurulum üretir, **sonra asla değiştirilmez**).
7. **Cron:** *Cron Jobs* → *Once Per Minute* → komut: `/bin/bash /home/yoklmcom/finans-app/deploy/cron.sh >/dev/null 2>&1` (okulun cron satırı ayrı kalır).
8. 1–2 dakika sonra `finans-app/deploy/kurulum.log`'u açın: **`KURULUM TAMAM`** ve geçici şifre yazar. https://finans.yoklm.com'da giriş yapın, **Profil → Şifre değiştir**, sonra **log dosyasını silin**.
9. **Yönetim → Fiyat kaynakları → Şimdi güncelle**: Truncgil / Yahoo / Binance "çalışıyor" görünmeli. Sonra cron her 15 dakikada günceller.

## Yayın (güncelleme)
Windows'ta proje kökündeki **`yayinla.bat`**'a çift tıklayın: menüden "Yayınla" ya da "Yalnızca denetle" seçilir (`yayinla.bat yayinla` / `yayinla.bat denetle` sormadan çalışır). Laragon'daki PHP 8.3 ve Composer'ı kendisi bulur, Git Bash ile aşağıdaki betiği çalıştırır. `canli-paket/ftp.env` yoksa ya da şifre boşsa dosyayı Not Defteri'nde açar. GitHub'a gönderilmemiş commit varsa uyarır (yayından sonra `git push`).
```bash
bash backend/deploy/yayinla.sh            # main dalı: paketle + FTP ile gönder + cron'un açmasını bekle
bash backend/deploy/yayinla.sh denetle    # yalnızca sürüm denetimi
```
- **Açık uygulamalar yeni sürümü kendisi yükler:** telefonda ana ekrandan açılmış ya da tarayıcıda açık kalmış uygulama, öne gelince ya da sayfa değişince `/api/v1/surum`'u (paketteki `deploy/SURUM`) kendi derleme sürümüyle karşılaştırır; farklıysa açık pencere / dolu form yokken sessizce yenilenir, varsa sonraki sayfa geçişinde yeni sürümle açılır (`frontend/src/utils/surumDenetimi.ts`). Bu özelliği içermeyen eski sürümle açılmış uygulama bir kez elle yenilenir.
- Yalnızca commit'lenmiş kod paketlenir; yalnızca `main` dalı yayınlanır. Canlıdaki sürüm gönderilecek kodun atası değilse yayın durur (öbür bilgisayarda yapılmış yayını ezmemek için).
- Cron paketi açar, `deploy/KUR` işaretiyle migration + katalog eşitleme + önbellek adımlarını bir kez çalıştırır. Hata olursa işaret `KUR.hata` olur; sorun giderilince adını `KUR` yapın.
- **Canlıda elle artisan çalıştırılamaz**; sunucuda yeni bir adım gerekiyorsa `deploy/cron.sh`'ye eklenir.

## Bakım
- **Telefona kurulum:** Android Chrome'da Profil → "Ana ekrana ekle" (ya da menü ⋮ → Uygulamayı yükle); iPhone Safari'de Paylaş → Ana Ekrana Ekle. Service worker (`public/sw.js`) veri saklamaz, yalnızca bağlantı yokken `cevrimdisi.html`'i gösterir. İkon tasarımı değişirse `frontend/scripts/ikon-uret.ps1` yeniden çalıştırılır.
- **Yönetici şifresi unutulursa:** *File Manager* → `finans-app/deploy/` içinde `SIFRE-SIFIRLA` adlı boş dosya oluşturun; bir dakika içinde `.env`'deki `KURULUM_YONETICI` e-postasına yeni geçici şifre verilir ve `kurulum.log`'a yazılır (giriş yapınca log silinir).
- Zamanlanmış işlerin son çıktısı: `finans-app/storage/logs/zamanlayici.log`; hatalar: `storage/logs/laravel-*.log`.
- **Ekstre PDF'leri** `finans-app/storage/app/private/ekstreler/{hane}/` altındadır (açık adresi yok; yalnızca hane üyeleri uygulamadan açar). Yayın paketi `storage/`'a dokunmaz; bu klasör silinmez, yedeğe alınır. Ekstre uygulamadan silinince dosyası da silinir. Loglara PDF içeriği yazılmaz.
- **Hane:** Yönetim → Kullanıcılar'da kişinin hanesi seçilir (ör. eşinizi kendi hanenize bağlayın); aynı hanedekiler hesapları ve Bütçe kayıtlarını birlikte görür.
- **Çerezler:** okul sisteminin oturum çerezi `.yoklm.com` alanında olduğu için bu adrese de gelir; finansın çerezi `finans_oturum` adlı ve yalnızca `finans.yoklm.com`'a yazılır (`SESSION_DOMAIN=null`). CSRF köken denetimiyle yapılır, XSRF çerezi yok. Bu ayarlar değiştirilmez (CLAUDE.md).
- **BIST verisi** resmî olmayan kaynaktan, gecikmeli ve kişisel kullanım içindir. Uygulama başkalarına açılırken BIST veri dağıtım lisansı yeniden değerlendirilir.
