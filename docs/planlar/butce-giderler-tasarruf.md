# Bütçe: Giderler (düzenli ödemeler), Tasarruf ve elle ekstre

## Context
Bütçe (Aile) ayın özetinde bugün gelir, kart harcaması ve faturalar var. Üç eksik giderilecek:
1. **Giderler alanı:** düzenli ödemeler (eğitim, bakıcı…) ve kredi kartları; aylık döneme girer.
2. **Tasarruf:** ay içinde "Tasarruf yap" ile altın / hisse / döviz vb. alınır, seçilen portföy hesabına (ev, banka kasası, yatırım…) aktarılır; ayın bütçesinde görünür.
3. **Elle ekstre:** ekstre bugün yalnızca PDF ile girilebiliyor; yalnızca tutarla (dosyasız) girilebilsin.

Kullanıcı kararları (2026-11-15): düzenli gider = şablon + aylık gerçekleşme (gelir kaynağı kalıbı); tasarruf gider değil, ayrı kalem (**Gelir − Gider − Tasarruf = Kalan**); elle ekstre = dönem borcu tek gider satırı; tasarruf = portföy hesabı + alış işlemi.

## Mevcut yapıdan yeniden kullanılacaklar
- `GelirKaynagi` / `Gelir` tabloları, `GelirTakvimi` (`bekleniyorMu`, `beklenenler`), `GelirSikligi::ay()`, `Donem`, `Kurus`; `GelirKaynagiFormu`, `GelirFormu`, `GelirlerView` kalıbı.
- `ButceRaporu::giderOzeti` / `aylikOzet` (gider kuralları tek yerde burada); `AileOzetView` bölümleri.
- Dosyasız ekstre: `ekstreler` dosya sütunları zaten nullable (`dosya_yolu`, `dosya_adi`, `dosya_ozeti`…); `KartIslemKurallari::onayla` (rapor_donemi + parmak izi), `KartIslemiController::store`.
- Tasarruf: `IslemController::store` mantığı (`PortfoyHesabi::dogrula`, `miktarYeterliMi`), `IslemFormu` seçicileri, `Hesap::erisilebilir`, `User::haneKullaniciIdleri()`.

## 1. Düzenli giderler
**Veri (migration, geri alınabilir; `HaneYonetimi::AILE_TABLOLARI`'na eklenir):**
- `gider_kaynaklari`: `hane_id`, `ad`, `kategori_id` (nullable, `harcama_kategorileri`), `siklik` (`aylik`/`iki_aylik`/`uc_aylik`/`alti_aylik`/`yillik`), `beklenen_tutar`, `gun`, `baslangic`, `bitis`, `aktif`, `not`, `uye_id` (nullable, kimin gideri).
- `duzenli_giderler` (gerçekleşen): `hane_id`, `gider_kaynagi_id` (nullable: kaynaksız tek seferlik gider de girilebilir), `kategori_id`, `aciklama`, `tutar` (decimal), `tarih`, `donem` (char 7), `not`, `olusturan_id`; `unique(gider_kaynagi_id, donem)`.
- Modeller `GiderKaynagi`, `DuzenliGider` (`HaneyeAit`); `GiderTakvimi` servisi `GelirTakvimi` ile aynı mantıkta (`beklenenler`: o dönemin kaydı girilmemiş aktif kaynaklar, beklenen tarih ay sonunu aşmaz).
- Controller'lar `GiderKaynagiController`, `DuzenliGiderController` (apiResource, `Gate::authorize('hane-kaydi')`); route'lar `gider-kaynaklari`, `duzenli-giderler`.

**Rapor (`ButceRaporu`):**
- `giderOzeti`: `duzenli_giderler` (dönem = ay) gider toplamına ve kategori dağılımına eklenir; yeni alan `gider.duzenli` ({toplam, kalemler[]}), `gider.beklenenler[]` (toplama katılmaz, gelirdeki gibi). Kart ekstresi harcamaları kart satırlarından zaten gelir; düzenli gider yalnızca kartsız / elle ödemeleri tutar, **çift sayım uyarısı** formda ("Bu ödeme kartla yapıldıysa kart ekstresine girer, burada tekrar girmeyin").
- `gider.kartlar[]` zaten var; "Giderler" bölümünde kart başına ay toplamı + o kartın ekstre borcu / son ödeme tarihi bilgi olarak gösterilir (toplama eklenmez).

## 2. Tasarruf
**Veri:**
- `tasarruflar`: `hane_id`, `uye_id` (nullable), `hesap_id` (portföy `hesaplar`), `islem_id` (oluşan `islemler` alış kaydı; silinirse null'a düşmez, birlikte silinir), `tarih`, `donem` (char 7), `tutar_tl` (decimal, alış tutarı + komisyon), `not`, `olusturan_id`.
- Portföy `islemler` kaydı **gerçek kayıt**; tasarruf satırı yalnızca bütçe bağıdır. Tek DB işleminde: `PortfoyHesabi::dogrula` → `Islem::create(alis)` → `Tasarruf::create`.
- Erişim: `hesap_id` kullanıcının görebildiği hesaplardan (`Hesap::erisilebilir`), kayıt `hane_id` ile; başka hane göremez / değiştiremez testi.

**Uçlar:** `TasarrufController` — `index?ay=`, `store` (`varlik_id`, `hesap_id`, `miktar`, `birim_fiyat`, `komisyon?`, `tarih`, `not`; nakit/BES gibi sabit fiyatlıda birim fiyat gerekmez), `destroy` (portföy işlemiyle birlikte siler; silme satışı bozuyorsa `PortfoyHesabi::dogrula` reddeder). Düzenleme yok (sil + yeniden gir).

**Rapor:** `aylikOzet` → `tasarruf: {toplam, kalemler[{id, varlik, miktar, hesap, tutar, tarih}]}`, `kalan = gelir − gider − tasarruf` (mevcut `fark` korunur, `kalan` eklenir). Açıklama "Toplamlar nasıl hesaplanıyor" metnine yazılır.

## 3. Dosyasız (elle) ekstre
- `EkstreIceAktarici::elleOlustur(kart, kesim_tarihi, son_odeme_tarihi, donem_borcu, asgari?)`: `ekstreler` kaydı (`durum=onaylandi`, dosya alanları null, `banka_profili='elle'`, `onayli_kesim_tarihi`; `unique(kart_id, onayli_kesim_tarihi)` aynı dönemi iki kez engeller) + tek `kart_islemleri` satırı (`tur=harcama`, açıklama "Ekstre toplamı (elle)", tutar = dönem borcu, `islem_tarihi = kesim_tarihi`, `rapor_donemi` = kesim ayı, kategori boş) `KartIslemKurallari::onayla` ile onaylanır.
- Uç: `POST /aile/ekstreler/elle` (`EkstreController::elle`, throttle:ekstre dışı). Doğrulama: kart hanede, kesim tarihi ≤ bugün, son ödeme ≥ kesim, borç > 0.
- Sonradan aynı döneme PDF yüklenirse mevcut "aynı dönem" koruması çalışır; elle ekstre silinip PDF yüklenir (arayüzde açıklama). Ekstre silinince tek satır da gider (`sil()` dosya yokken davranışı doğrulanıp testlenir).
- Mevcut `Ekstre::ozet()` `dosya_var=false` verir; EkstreView/Ekstreler listesinde "elle girildi" rozeti, dosya düğmesi gizli.

## 4. Arayüz (mobil öncelikli)
- `AileDuzeni` sekmesi: **Giderler** (`/aile/giderler`, `GiderlerView`): ay seçici + bu ayın düzenli ödemeleri (ödendi / bekleyen), "Gider ekle", "Düzenli ödeme tanımla" (`GiderKaynagiFormu`, `GiderFormu`), alt bölüm Kartlar (kart başına ay toplamı + ekstre borcu/son ödeme).
- `AileOzetView`: Gelir / Gider / **Tasarruf** / Kalan dörtlüsü (320 px'te 2×2); "Beklenen giderler" ("Ödendi" → `GiderFormu` varsayılanlı); "Tasarruf" kartı + "Tasarruf yap" düğmesi → `TasarrufFormu`.
- `TasarrufFormu`: varlık seçici (mevcut `VarlikAdi` + katalog), hesap seçici (ev, banka kasası, yatırım…; "Yeni hesap" bağlantısı), miktar, birim fiyat (güncel fiyattan önerilir, `fiyatOner`), komisyon, tarih (varsayılan bugün), not.
- Kartlar / Ekstreler: "Elle ekstre gir" düğmesi + `ElleEkstreDialog` (kart, kesim, son ödeme, dönem borcu, asgari); `EkstreYukleDialog` yanında.
- `api/aile.ts`: tipler `GiderKaynagi`, `DuzenliGider`, `Tasarruf`, `AylikOzet` genişler; `stores/aile.ts` gerekmedikçe değişmez.

## 5. Gizlilik / güvenlik
- Yeni kişisel veri yok. Tutarlar loglanmaz. Her uç `hane-kaydi` + portföy hesabı erişimi; yeni uçlar için "başka hane" testleri.

## Aşamalar (her biri commit + push; CLAUDE.md kuralları: plan `docs/planlar/butce-giderler-tasarruf.md`, DEVAM/ILERLEME)
1. Düzenli giderler: migration, modeller, `GiderTakvimi`, controller'lar, `ButceRaporu`, testler.
2. Tasarruf: migration, controller, rapor (`kalan`), testler.
3. Elle ekstre: servis + uç + testler.
4. Arayüz: Giderler sekmesi, Özet'te dörtlü / beklenen / tasarruf, formlar, Vitest.
5. CLAUDE.md ("Aile finansı" kuralı: giderler, tasarruf ≠ gider, elle ekstre), ILERLEME, e2e (360/320/1280), yayın onayı için rapor (yayın ayrı onayla).

## Doğrulama
- Backend: `php artisan test` (yeni: `GiderTest` — şablon, beklenen, çift dönem engeli, rapora giriş, başka hane; `TasarrufTest` — portföy işlemi + bütçe bağı, yetersiz/başka hesap reddi, silme, `kalan`; `ElleEkstreTest` — onaylı ekstre + tek satır, aynı dönem engeli, gider toplamı, silme). Migration ileri-geri-ileri.
- Frontend: `npm run type-check && npm run lint && npx vitest run` (+ form / özet dörtlüsü testleri).
- Uçtan uca (Edge, yerel demo): Giderler'e düzenli ödeme tanımla → Özet'te beklenen → "Ödendi" → gider artar; Tasarruf yap (altın, ev hesabı) → Portföy'de işlem ve bütçede tasarruf, kalan düşer; elle ekstre → kart gideri. Taşma 320/360/1280.

## Kapsam dışı
Tasarrufun otomatik tekrarı, hedef belirleme, banka hesabı hareketlerinin bütçeye aktarımı, düzenli giderin otomatik "ödendi" sayılması.
