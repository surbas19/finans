# Aile finansı: hane, gelirler, kredi kartı ekstreleri (PDF) ve fatura takibi

## Context
Kullanıcı, mevcut portföy uygulamasına (Laravel 13 + Vue 3) aile finansı eklenmesini istiyor:
- Kendi ve eşinin gelirleri (maaş + ek gelirler) ayrı ayrı, hane toplamı birlikte.
- Kredi kartı ekstreleri PDF olarak yüklenecek.
- Elektrik / su / doğalgaz faturaları kartla ödeniyor. Ekstredeki fatura ödemeleri bulunup hizmet ayına bağlanacak.
- Geçmiş maaş, ek gelir, ekstre ve fatura sonradan girilebilecek.

**Kullanıcının kararları:**
- **Hane:** ortak hane, ayrı girişler. Kullanıcılar (ben, eşim) aynı aile verisini görür ve düzenler. Girişi olmayan üyeler yalnızca adla eklenir. Ek olarak **mevcut yatırım hesapları ve işlemleri de hanedeki herkese açık** olur (görme + düzenleme). İzleme listesi kişisel kalır.
- **PDF:** saklanır (özel klasör, yalnızca hane üyeleri açar, ekstre silinince silinir).
- **Git:** `aile-finansi` dalı. Her anlamlı adımda yalnızca bu dala commit + push. Ana dala birleştirme ve canlıya yayın kullanıcının onayıyla.
- **Ayrıştırıcı:** genel. Örnek PDF yok; bankaya özel ayar sonra.

## 0. Çalışma ortamı
Diğer sekmenin "nakit / BES" işi bitti ve ana dalda (`8fe10c5`). Çalışma ağacı temiz, DEVAM.md boş.
- **Dal:** aynı klasörde `git switch -c aile-finansi` (`main`'den). Ana dala commit yapılmaz.
- **Veritabanları ve sunucular:** mevcut `finans` / `finans_test` ve 8001 / 5174 sunucuları kullanılır. Yeni migration yalnızca tablo ekler, mevcut veriye dokunmaz.
- **Kayıt:** plan `docs/planlar/aile-finansi.md`'ye kopyalanır, `docs/DEVAM.md`'ye kayıt yazılır. Her adımda "Yarım: …" commit + `git push -u origin aile-finansi`.
- **Birleştirme (onaydan sonra):** dal o günkü `main`'e göre güncellenir, testler yeniden çalışır, sonra `main`'e birleştirilir ve `yayinla.bat` ile yayınlanır.

## 1. Hane ve mevcut hesapların paylaşımı
- **Veri:**
  - `haneler`: ad.
  - `users.hane_id` (boş olabilir).
  - `hane_uyeleri`: hane, ad, renk, sıra, `user_id` (boş olabilir, tekil). Girişi olan kullanıcı otomatik üye olur.
- **Erişim tek yerde:** `User::haneKullaniciIdleri()` (hanesi yoksa yalnızca kendisi) ve `Hesap::scopeErisilebilir($q, $user)`.
  - Mevcut ~12 `where('user_id', …)` noktası buna geçer: [HesapPolicy](backend/app/Policies/HesapPolicy.php), [IslemPolicy](backend/app/Policies/IslemPolicy.php), [HesapController](backend/app/Http/Controllers/Api/V1/HesapController.php), [IslemController](backend/app/Http/Controllers/Api/V1/IslemController.php), [OzetController](backend/app/Http/Controllers/Api/V1/OzetController.php), [PerformansController](backend/app/Http/Controllers/Api/V1/PerformansController.php), [PortfoyHesabi](backend/app/Services/Portfoy/PortfoyHesabi.php) `hesapla` / `gecmis`.
  - İşlemler, işlemi girenle değil hesapla süzülür: eşin benim hesabıma girdiği işlem görünür.
  - İzleme (`izleme_listesi`) kişisel kalır.
  - Hesap adı tekilliği kişi başına kalır. Hesap kartında, hesap başkasınınsa sahibinin adı görünür.
- **Hane kurma:**
  - Bütçe'ye ilk girişte hanesi olmayan kullanıcıya tek kişilik hane kurulur.
  - Yönetici, Yönetim'de kullanıcıyı başka haneye bağlar ([KullaniciController](backend/app/Http/Controllers/Api/V1/Yonetim/KullaniciController.php) + YonetimView'de hane seçimi). Kullanıcının eski hanesinde başka üye ya da aile kaydı varsa taşıma reddedilir (veri kaybı ya da karışma olmaz).
- **Hanede yapılanlar:** üyeleri (girişsiz) ekleme, adlandırma ve silme. Üyenin kaydı varsa silme yerine pasif.
- **CLAUDE.md "Kullanıcı izolasyonu" kuralı:** "hane izolasyonu" olur. Kullanıcı, kendi hanesinin hesap / işlem / aile kayıtlarını görür; başka hanenin hiçbir kaydını görmez. Her yeni uç için "başka hane göremez / değiştiremez" testi yazılır.
- **Mevcut veri:** taşıma gerekmez. Hanesi olmayan kullanıcı bugünkü gibi yalnızca kendini görür. Migration geri alınabilir (`down` bütün yeni tabloları ve `users.hane_id`'yi kaldırır).

## 2. Veri modeli (yeni migration; para `decimal(15,2)`, hesaplar tam sayı kuruşla)
- **Para hesabı:** `App\Support\Kurus` (decimal metin ↔ kuruş). Aile modülünde toplama / çıkarma kuruşla yapılır, float kullanılmaz. Portföy modülü bugünkü yaklaşımında kalır.
- **`gelir_kaynaklari`:**
  - Alanlar: üye, tür, ad / kaynak, düzen, sıklık, beklenen tutar, para birimi, beklenen gün, başlangıç / bitiş, aktif, not.
  - Türler: `maas`, `kira`, `serbest`, `ek_is`, `prim`, `faiz_temettu`, `emekli`, `diger`.
  - Düzen: `duzenli` / `duzensiz` / `tek_seferlik`. Sıklık: aylık, 2 / 3 / 6 aylık, yıllık.
- **`gelirler`:**
  - Alanlar: üye, kaynak (boş olabilir), tür, düzen, açıklama, tutar, para birimi, TL karşılığı, kur, tarih (elde edilme), `donem` (`YYYY-AA`, ait olduğu ay), giren kullanıcı.
  - Tekillik: `(gelir_kaynagi_id, donem)`; aynı maaş bir ayda iki kez girilmez.
  - Döviz geliri: TL karşılığı o günün `fiyat_gecmisi` kapanışıyla önerilir. Kapanış yoksa kullanıcı girer.
- **`bankalar`:** ad, renk.
- **`kartlar`:** banka, görünen ad, sahibi (üye), kesim günü, son ödeme günü, aktif. **Kart numarası, son 4 hane ve CVV tutulmaz** (CLAUDE.md).
- **`harcama_kategorileri` + `kategori_kurallari`:**
  - Hane kurulunca varsayılan kategoriler yazılır: Market, Akaryakıt, Yeme-içme, Giyim, Sağlık, Eğitim, Ulaşım, Eğlence, Faturalar, Diğer.
  - Kural: açıklamada geçen kelime → kategori.
- **`ekstreler`:**
  - Alanlar: kart, dönem başlangıcı, kesim tarihi, son ödeme tarihi, dönem borcu, asgari ödeme.
  - Kaynak / durum alanları: dönem bilgisinin kaynağı (`pdf` / `kullanici`), durum (`taslak` / `onaylandi`), okunamayan alanlar, ödendi işareti.
  - Dosya alanları: dosya yolu (özel disk), adı, boyutu, sayfa sayısı, `dosya_ozeti` (SHA-256).
  - Tekillik: `(hane_id, dosya_ozeti)`; aynı dosya tekrar yüklenemez. Onayda ayrıca `(kart_id, kesim_tarihi)`; aynı dönem iki kez onaylanamaz.
- **`kart_islemleri`:**
  - Temel alanlar: kart, ekstre (boş olabilir: elle giriş), satır sırası, işlem tarihi, açıklama, tutar (her zaman pozitif).
  - Tür: `harcama` / `iade` / `odeme` (borç ödemesi) / `transfer` / `ucret` (faiz-ücret) / `nakit_avans`.
  - Taksit alanları: taksit no, taksit sayısı, taksit toplamı.
  - İlişki ve sınıflandırma: `iade_islem_id` (iadenin asıl harcaması), kategori.
  - Durum: `taslak` / `onayli` / `atlandi`. `rapor_donemi` (`YYYY-AA`, onayda yazılır).
  - Kaynağa dönük alanlar: ham satır metni, sayfa no, okunamayan alanlar, fatura önerisi (json).
  - `parmak_izi`: yalnızca onaylı satırda dolu. Kart + tarih + tutar + sade açıklama + taksit + aynı satırın sırası. Tekillik `(kart_id, parmak_izi)` olduğundan aynı işlem veritabanında iki kez onaylanamaz.
- **`fatura_turleri`:**
  - Sistem kayıtları (hane boş): elektrik, su, doğalgaz. Her birinin genel anahtar kelimeleri var (ELEKTRIK / ENERJI / EDAS…, SU / ISKI / ASKI…, DOGALGAZ / IGDAS…).
  - Hane kendi türünü ekleyebilir (internet, aidat…).
- **`fatura_saglayicilari` + `saglayici_desenleri`:**
  - Sağlayıcı: tür, ad, `otomatik_kayit` (güvenilir eşleşmede onay beklemeden fatura yazılsın mı).
  - Desen: ekstrede görünen farklı yazımlar / anahtar kelimeler.
- **`faturalar`:**
  - Alanlar: sağlayıcı, tür, `hizmet_donemi` (`YYYY-AA`, boş = bilinmiyor), dönemin kaynağı (`aciklama` / `kullanici`), tutar, son ödeme tarihi.
  - Ödeme alanları: durum (`bekliyor` / `odendi`), ödeme tarihi, `kart_islemi_id` (tekil: bir ekstre satırı tek faturaya bağlanır).
  - Diğer: eşleşme güveni ve gerekçesi, kaynak (`elle` / `ekstre`), giren kullanıcı.
- **Denetim:** mevcut yaklaşım Policy + sunucuda doğrulama; ayrıca "giren kullanıcı" tutulur. Ayrı değişiklik geçmişi tablosu yok (portföyde de yok).

## 3. İş kuralları (her biri tek serviste: `app/Services/Aile/*`, `app/Services/Ekstre/*`)
- **`GelirTakvimi`:** düzenli kaynaklardan bir ay için beklenen gelirler. Kaynağın o dönem kaydı yoksa "beklenen" sayılır; gerçekleşen toplama **katılmaz**. "Alındı" ile beklenen tutarla gelir kaydı açılır. Kayıt seçilen döneme yazılır, bugünkü aya kaymaz.
- **`ButceRaporu`:** aylık özet, filtreli listeler ve uyarılar. Toplam kuralları yalnızca burada:
  - **Gelir:** dönemi o ay olan gerçekleşen gelirler. Kişi ve tür bazında, düzenli ile düzensiz / tek seferlik ayrı; beklenenler ayrı listede.
  - **Gider:**
    - Onaylı kart satırlarından `harcama + ucret − iade` (iade kendi ayında, asıl harcamanın kategorisinden düşer).
    - **`odeme`, `transfer` ve `nakit_avans` gidere girmez.** Böylece kart borcu ödemesi ekstredeki harcamaları ikinci kez saymaz.
    - Ek olarak kart satırına bağlı **olmayan** ödenmiş faturalar (elle girilmiş) gider sayılır. Böylece kartla ödenmiş fatura bir kez sayılır.
  - **Gider ayı (varsayım):** kart satırının `rapor_donemi`.
    - Normal satırda işlem tarihinin ayı.
    - Taksit satırında (n / N) ekstrenin kesim ayı. Bu "aya düşen taksit"tir; ekstreler çoğunlukla taksitte asıl alış tarihini yazar.
    - Taksitli alışın toplam borcu ayrıca gösterilir, toplama katılmaz.
  - **Faturalar (hizmet ayı):** ayrı bölümde gösterilir. Kart satırına bağlı olanlar giderde zaten var; özet bunu "kart harcamalarının içinde" diye belirtir, ikinci kez toplamaz.
  - **Gelir − gider farkı.**
  - **Uyarılar:**
    - Bekleyen faturalar.
    - Gecikmiş faturalar (`bekliyor` ve son ödeme geçmiş).
    - 10 gün içinde son ödeme tarihi olan, ödendi işaretlenmemiş kart ekstreleri.
- **`FaturaEslestirici`** (kart satırı → fatura adayı):
  - Açıklama sadeleştirilir (Türkçe büyük harf, işaretsiz, boşluk tek).
  - **Kanıtlar:**
    - Sağlayıcı deseni ya da adı geçiyor (güçlü).
    - Tür anahtar kelimesi geçiyor (zayıf).
    - Tutar o sağlayıcının önceki faturalarına yakın.
    - Tarih önceki ödemelerin ayın gününe yakın.
    - Açıklamada hizmet dönemi var (`OCAK 2026`, `01/2026`, `2026/01`, `202601`, `DONEM`…).
  - **Puan → güven:**
    - Yüksek: desen + bir kanıt daha.
    - Orta: yalnızca desen.
    - Düşük: yalnızca tür kelimesi.
    - Birden çok sağlayıcı eşleşirse "belirsiz".
  - **Gerekçe listesi:** kullanıcıya aynen gösterilir.
  - **Otomatik kayıt:** yalnızca sağlayıcı `otomatik_kayit` açıksa, güven yüksekse ve tek sağlayıcı eşleştiyse; inceleme ekranında önceden işaretli gelir. Diğerleri kullanıcı onayına kalır.
  - **Hizmet dönemi:** açıklamada açıkça yoksa **tahmin edilmez**. Kullanıcı seçer ya da "dönem bilinmiyor" kalır.
  - **Bağlama:** aynı sağlayıcı ve dönem için elle girilmiş bekleyen bir fatura varsa yeni fatura açılmaz, ona bağlanır ve fatura "ödendi" olur.
  - **Düzeltme:** bağı çözme ya da başka faturaya bağlama raporlara anında yansır (raporlar her seferinde kayıtlardan hesaplanır).
- **`KategoriBulucu`:** açıklamaya göre kategori önerir.
  - Hane kuralları önce gelir, sonra fatura sağlayıcısı (Faturalar).
  - Bulunamazsa boş kalır, uydurulmaz.

## 4. PDF ekstre
- **Kütüphane:**
  - `smalot/pdfparser` v2.13: saf PHP, **LGPL-3.0**, bakımı sürüyor, zlib + iconv yeterli.
  - Turhost'ta `proc_open` kapalı olduğundan `pdftotext` kullanılamaz. Yeni bağımlılık olarak tek bu eklenir; lisansı CLAUDE.md'ye not edilir.
- **Yükleme** `POST /api/v1/aile/ekstreler` (çok parçalı: dosya + kart):
  - **Doğrulama:** en çok 10 MB, `mimetypes:application/pdf` ve dosya başında `%PDF-` imzası. Sahte uzantıda 422.
  - **Tekrar yükleme:** SHA-256 hanede varsa "Bu dosya 12.01.2026'da yüklendi (… kartı, Şubat 2026 ekstresi)" mesajı ve o ekstreye bağlantı.
  - **Saklama:** `Storage::disk('local')` (özel), `ekstreler/{hane}/{uuid}.pdf`. Açık adres yok.
  - **Açma:** `GET /aile/ekstreler/{id}/dosya` yalnızca hane üyelerine (Policy). `inline`, `no-store`, `X-Content-Type-Options: nosniff`.
  - **Ayrıştırma ve taslak:** dosya okunur, taslak ekstre ve taslak satırlar **tek veritabanı işleminde** yazılır. Hata olursa dosya da silinir; yarım kayıt kalmaz.
- **`PdfMetinOkuyucu`** (smalot sarmalı → sayfa sayfa satırlar):
  - Şifreli PDF: "PDF şifreli; şifresiz kaydedip yükleyin".
  - Metni olmayan (taranmış) PDF: "Okunabilir metin yok. OCR yok: sunucuda OCR aracı bulunmuyor; dış servise finansal belge gönderilmez. Satırları elle girebilirsiniz." OCR eklenmez, dış servis kullanılmaz.
- **`EkstreAyristirici`** (saf; metin satırları → sonuç):
  - **İşlem satırı:** başta tarih (`gg.aa.yyyy`, `gg/aa/yyyy`, `gg.aa.yy`) + açıklama + sonda TL tutar (`1.234,56`, eksi, `-`, `+`, `CR`, `ALACAK`). Sayfa sonunda bölünen açıklama bir önceki satıra eklenir.
  - **Taksit:** `3/6`, `(3/6)`, `3. TAKSIT / 6`, `TAKSIT 3/6`. Taksit toplamı açıkça yazılıysa alınır.
  - **Tür tahmini:**
    - `ODEME` / `HESAPTAN ODEME` / `BORC ODEME` → `odeme`.
    - `IADE` / `IPTAL` / eksi tutar → `iade`.
    - `FAIZ` / `UCRET` / `KKDF` / `BSMV` → `ucret`.
    - `NAKIT AVANS` → `nakit_avans`.
    - `VIRMAN` / `HAVALE` → `transfer`.
    - Diğerleri → `harcama`.
  - **Dönem bilgileri:** "Hesap kesim / Ekstre tarihi", "Son ödeme tarihi", "Dönem borcu", "Asgari ödeme". Bulunamayan alan `okunamayan` listesine girer, **uydurulmaz**. Kullanıcı inceleme ekranında doldurur; o alanın kaynağı "kullanıcı" olur.
  - **Satır bazında okunamayanlar:** tarihi ya da tutarı okunamayan satır "okunamadı" uyarısıyla ve ham metniyle gösterilir; kullanıcı düzeltmeden onaylanmaz.
- **İnceleme sayfası** `/aile/ekstreler/:id`:
  - Kart, dönem ve hangi tarihin ne olduğu açıklaması: "işlem tarihi: harcama günü · kesim: ekstrenin kapandığı gün · son ödeme: kart borcunun ödeneceği gün". Dönem bilgisinin kaynağı (PDF / siz) yazar.
  - Her satırda: ham metin + sayfa no ("PDF'de aç" → `#page=N`), düzenlenebilir tarih / açıklama / tutar / tür / taksit / kategori, "dışarıda bırak".
  - Tekrar satır: aynı parmak izi onaylı bir satırda varsa "Ekim ekstresinde zaten var" yazar ve satır varsayılan olarak dışarıda bırakılır.
  - Fatura adayı: sağlayıcı, güven, gerekçe, hizmet dönemi seçimi (ya da "bilinmiyor").
  - Telefonda satırlar kart; düzenleme alttan açılan pencerede (`UygulamaPenceresi`).
- **Onay** `POST /aile/ekstreler/{id}/onayla`:
  - Tek işlemde: satırlar `onayli` / `atlandi`, parmak izleri, kategoriler, iade bağları, faturalar, ekstre `onaylandi`.
  - Aynı kart + kesim tarihi ya da parmak izi çakışması → 422 ve hiçbir şey yazılmaz.
  - Sonuç raporu: "38 satır eklendi, 2 atlandı (1 tekrar, 1 sizin seçiminiz), 3 fatura bağlandı".
- **Silme:** ekstre silinince satırları ve dosyası silinir. Bağlı faturaların bağı çözülür; fatura "bekliyor"a döner, kendisi silinmez.
- **Gizlilik:** PDF içeriği, tutarlar ve satır metinleri loglanmaz. Ayrıştırma hataları içeriksiz, genel mesajla yakalanır.

## 5. API (`/api/v1/aile/...`, `auth:sanctum` + `throttle:api`; yükleme ayrıca `throttle:kaynak` benzeri sınır)
- **Uçlar:** `hane` (bilgi, üyeler), `gelir-kaynaklari`, `gelirler`, `bankalar`, `kartlar`, `kategoriler` (+ kurallar), `ekstreler`, `kart-islemleri`, `fatura-turleri`, `saglayicilar` (+ desenler), `faturalar` (+ bağla / çöz), `ozet?ay=YYYY-AA`, `uyarilar`.
- **Yetki:** her model `hane_id` taşır. Tek `HaneKaydiPolicy` + route model binding; doğrulamada `Rule::exists(...)->where('hane_id', …)`.
- **Liste süzgeçleri:** ay, kişi, banka, kart, kategori, ekstre dönemi, tür. Faturada hizmet ayı; kart işleminde işlem ayı ya da ekstre dönemi.

## 6. Arayüz (mevcut bileşenler: `UygulamaPenceresi`, `FormAlani`, `useFormGonder`, `DegisimEtiketi`, `VeriGrafigi`, `BosDurum`, `kart` sınıfları)
- **Menü:** "Bütçe" (`pi pi-calculator`) eklenir. İçinde yatay sekmeler: **Özet · Gelirler · Kartlar · İşlemler · Faturalar · Ayarlar**. Alt çubuk 6 öğede 320 px'te ölçülür.
- **Özet (`/aile`):**
  - Ay seçici.
  - Kişi kartları: maaş ve ek gelir, düzenli / düzensiz.
  - Hane toplam geliri, beklenen gelirler (toplama katılmadan).
  - Kategorilere göre gider: halka + liste.
  - Fatura toplamları (hizmet ayı).
  - Gelir − gider.
  - "Nasıl hesaplandı" açıklaması (hangi satırların neden dahil / hariç).
  - Uyarılar: bekleyen / gecikmiş fatura, yaklaşan son ödeme.
- **Gelirler:**
  - Kişi bazında kaynaklar ve aylık kayıtlar.
  - Gelir formu: üye, tür, kaynak, tutar, para birimi, tarih, dönem. Dönem ay seçicisi; geçmiş ay seçilebilir.
  - Düzenli kaynak formu.
  - Beklenenlerde "Alındı".
- **Kartlar:**
  - Banka → kartlar.
  - Kart sayfası: ekstreler, işlemler, faturalar, son ödeme.
  - "Ekstre yükle" penceresi (dosya + kart). Elle işlem ekleme.
- **İşlemler:** süzgeçli liste. Telefonda kart görünümü; tür, kategori ve iade bağı düzeltme.
- **Faturalar:**
  - Hizmet ayına göre liste ve elle fatura.
  - Bağla / bağı çöz: kart satırı seçimi, yalnızca bağlanmamış satırlar listelenir.
- **Ayarlar:** üyeler, bankalar, kategoriler ve kuralları, fatura türleri, sağlayıcılar ve desenleri, otomatik kayıt anahtarı.
- **Hesaplar (mevcut):** başkasının hesabında sahip adı görünür. Başka değişiklik yok.
- **Yönetim:** kullanıcı satırında hane seçimi, yeni hane.

## 7. Demo ve belgeler
- **DemoSeeder** (yalnızca local): ben + eşim aynı hanede; maaş ve ek gelir, iki kart, bir onaylı ekstre (PDF'siz, elle), elektrik / su / doğalgaz sağlayıcıları, faturalar.
- **Belgeler:**
  - CLAUDE.md: hane izolasyonu, PDF saklama istisnası, smalot lisansı, aile modülünde kuruş kuralı.
  - CANLI.md: PHP yükleme sınırı ≥ 10 MB notu, `storage/app/private` korunur.
  - ILERLEME.md.

## 8. Testler (gerçek banka / OCR / dış servis yok; sahte PDF test içinde üretilir)
- **`Tests\Support\OrnekPdf`:** test içinde gerçek, küçük metin PDF'i yazar. Ayrıca metinsiz ve şifreli örnekler.
- **Arka uç feature testleri:**
  - Hane izolasyonu: başka hanenin kullanıcısı hiçbir uca erişemez / değiştiremez. Hanedeki eş mevcut hesapları ve işlemleri görür, düzenler. Hanesiz kullanıcı bugünkü gibi yalnızca kendini görür. İzleme kişisel kalır.
  - Geçersiz PDF: uzantısı PDF olan metin, boyut aşımı, metinsiz, şifreli.
  - Tekrar içe aktarma: aynı dosya; farklı dosyada aynı işlem; aynı kesim tarihi.
  - Belirsiz fatura eşleşmesi: iki sağlayıcı ya da yalnızca tür kelimesi; otomatik kayıt yok. Güvenilir + otomatik açık: kayıt.
  - Dönem: açıklamada yoksa boş; varsa açıklamadan.
  - Geçmiş döneme gelir ve fatura kaydı ilgili aya yazılır.
  - Kart borcu ödemesinin gider sayılmaması; transfer ve nakit avans hariç; iade düşer; taksit kesim ayına; elle ve bağlı fatura bir kez.
  - Rapor toplamları (kuruş hassasiyeti).
  - Onayda yarım kayıt kalmaması: çakışmada geri alma.
  - Fatura bağını çözme / yeniden bağlama.
  - PDF dosyasına yetkisiz erişim.
- **Birim testleri:** `EkstreAyristirici` (çeşitli satır biçimleri, okunamayan alan, taksit, eksi / CR), `FaturaEslestirici`, `GelirTakvimi`, `Kurus`.
- **Ön yüz:** dönem / ay yardımcıları ve ekstre inceleme satırı (dışarıda bırakma, tekrar rozeti) vitest.
- **Uçtan uca (Edge + playwright-core, 360 / 320 / 1280, 8001 / 5174):**
  1. Gelir gir (geçmiş ay).
  2. Örnek PDF yükle.
  3. İncele: satır düzelt, tekrarı gör, fatura onayla, dönem seç.
  4. Onayla.
  5. Özet toplamlarını denetle.
  6. Eşin girişiyle aynı veriyi gör.
  7. Taşma ölçümü.

## Doğrulama
- **Her adım sonunda:**
  - `php artisan test`
  - `npm run type-check && npm run lint && npx vitest run`
  - `npm run build`
  - Pint / prettier yalnızca değişen dosyalara.
  - Sonuçlar olduğu gibi bildirilir; mevcut başarısız kontroller yeni sorunlardan ayrılır.
- **Son gözden geçirme:** `git diff origin/main...aile-finansi`; kapsam dışı dosya ve biçim değişikliği kalmaz.
- **Teslim:**
  - Dal push'lanmış olur. Ana dala birleştirme ve `yayinla.bat` **kullanıcı onayıyla**.
  - Tamamlanma raporu: akışlar, dosyalar, migration, PDF ve eşleştirme sınırları, kontroller, nasıl denenir.
