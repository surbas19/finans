# Toplu eklemede katalogda olmayan hisse / kripto kodlarını kendiliğinden bulma

## Context
Toplu eklemede ("Elde olanları toplu ekle") daha önce kataloğa girilmemiş bir hisse kodu yazılınca (ör. `ASELS;100`) satır hata veriyor: *"Katalogda yok: ASELS. Hisse, kripto ya da fonsa önce "Kodla ekle" ile ekleyin."* ([topluIslem.ts:115-118](frontend/src/utils/topluIslem.ts#L115-L118)). Bu durumda kullanıcı her hisseyi tek tek "Kodla ekle" penceresinden eklemek zorunda kalıyor. Tek tek eklemek de zor, çünkü `POST /varliklar` dakikada 10 istekle sınırlı (`throttle:kaynak`).

**İstenen:** katalogda olmayan kodlar hata vermesin. "Ekle"ye basınca önce Borsa İstanbul'da, bulunamazsa kriptoda aranır. Bulunanlar kataloğa eklenir, ardından satırlar kaydedilir. Hepsi tek tıkla olur.

## Davranış
- **Önizleme:** katalogda olmayan ama kod biçimindeki alan (Türkçe harfler sadeleştirilmiş, `^[A-Z0-9]{2,12}$`) artık hata sayılmaz. Bu alan "yeni kod" olarak gösterilir:
  - mavi arama simgesi
  - açıklama: *"yeni kod: eklerken Borsa İstanbul'da, yoksa kriptoda aranır"*
  - satır "hazır" sayılır
  - miktar, fiyat ve tarih satırı yine gösterilir (birim olmadan)
- **Kod biçiminde olmayan alan:** boşluklu ad gibi (ör. "Türk Hava Yolları"). Hata olarak kalır, mesajı şöyle olur: *"Katalogda yok: …. Hisse ya da kriptoysa kodunu yazın (ör. THYAO); fonsa önce "Kodla ekle" ile ekleyin."*
- **Ekle'ye basınca:**
  1. Yeni kodlar tekilleştirilir ve 50'lik parçalar hâlinde sunucuya sorulur. Düğmede "Kodlar aranıyor…" yazar.
  2. Bulunan varlıklar `katalog.ekle` ile kataloğa eklenir; önizleme kendiliğinden adlarıyla güncellenir.
  3. Bulunamayan kod varsa o satırlar hata olur: *"ASELSAN Borsa İstanbul'da ve kriptoda bulunamadı. Borsa kodunu yazın (ör. ASELS); fonsa önce "Kodla ekle" ile ekleyin."* Kayıt yapılmaz.
  4. Kaynağa ulaşılamayan kod varsa bildirim çıkar (*"Fiyat kaynağına ulaşılamadı; biraz sonra yeniden deneyin"*) ve kayıt yapılmaz.
  5. Hepsi bulunduysa aynı tıklamada mevcut `topluMevcutEkle` kaydı yapılır. Başarı bildirimi: *"N varlık eklendi (K yeni kod kataloğa eklendi)"*.
- **Arama sırası:** Borsa İstanbul önce, sonra kripto. Aynı kodun hem borsada hem kriptoda olması çok seyrek. Böyle bir durumda kripto isteyen kullanıcı onu önce "Kodla ekle" ile kripto olarak ekler; katalogdaki kayıt kullanılır.
- **Fon:** adı gerektiği için otomatik eklenmez (yukarıdaki mesaj yönlendirir).
- **Kalıcılık:** bulunan varlıklar kayıt vazgeçilse de katalogda kalır ("Kodla ekle" ile aynı; katalog ortaktır ve yalnızca doğrulanmış kodlar girer).

## Arka uç
- **Yeni uç `POST /api/v1/varliklar/toplu`**:
  - `throttle:kaynak` altında; [routes/api.php](backend/routes/api.php)'de `varliklar.store`'un yanına.
  - Gövde `{ kodlar: string[] }`: 1–50 kod, her biri `^[A-Za-z0-9]{2,12}$`.
  - Yanıt 200: `{ varliklar: Varlik[], bulunamayan: string[], ulasilamayan: string[] }`.
- **[VarlikController](backend/app/Http/Controllers/Api/V1/VarlikController.php) `toplu()`:**
  1. Kodlar büyük harfe çevrilip tekilleştirilir.
  2. Katalogda zaten olan hisse / kripto / fon olduğu gibi döner (pasifse etkinleştirilir; `store`'daki gibi).
  3. Kalanlar için Yahoo'ya `KOD.IS` aynı anda sorulur. Bulunan ve TRY olan → hisse.
  4. Bulunmayanlar için Binance'e `KODTRY` aynı anda sorulur. Bulunan → kripto.
  5. Yalnızca 404 / 400 cevabı "bulunamadı" sayılır. Bağlantı hatası, 5xx ya da 429 "ulaşılamadı" sayılır; böyle bir kod kriptoda da yoksa `ulasilamayan`'a yazılır.
- **Oluşturma `store` ile ortak:** `store` içindeki `Varlik::create` ve `$guncelleyici->kaydet` adımı özel bir `olustur()` yardımcısına çıkarılır. `hisse()` / `kripto()` içindeki alan dizileri (`ad`, `birim`, `kaynak`, `kaynak_kodu`, `gecikmeli`) de ortak yardımcılara alınır. `ekleyen_id` kullanıcıdır, `sira` 1000.
- **Kaynaklara toplu doğrulama:** [YahooKaynagi](backend/app/Fiyat/Kaynaklar/YahooKaynagi.php) ve [BinanceKaynagi](backend/app/Fiyat/Kaynaklar/BinanceKaynagi.php)'ya `dogrulaHepsi(array $semboller): array{bulunan, bulunamayan, ulasilamayan}` eklenir.
  - `Http::pool` kullanır; Yahoo `guncel()`'deki kalıbın aynısı.
  - Mevcut `fiyatCoz()` / `coz()` yeniden kullanılır.
  - Binance'in çoklu `symbols=` sorgusu tek geçersiz sembolde bütün isteği düşürdüğü için her sembol ayrı istekle sorulur.
  - Tekil `dogrula()` değişmez.

## Ön yüz
- **[utils/topluIslem.ts](frontend/src/utils/topluIslem.ts):**
  - `TopluSatirSonucu`'na `yeniKod: string | null` eklenir.
  - `varlikBul` eşleşme yoksa ve alan kod biçimindeyse `yeniKod` döner.
  - `satirlariCoz`'a isteğe bağlı 5. parametre `bulunamayan: ReadonlySet<string>` eklenir; bu kümedeki yeni kod hata olur.
  - `YENI_KOD_PARCASI = 50` tanımlanır.
- **[api/finans.ts](frontend/src/api/finans.ts):** `varliklariKodlaBul(kodlar)` eklenir.
- **[TopluEkleDialog.vue](frontend/src/components/TopluEkleDialog.vue):**
  - `bulunamayan` (Set ref) eklenir; pencere açılınca sıfırlanır.
  - `kodlarAraniyor` ref'i eklenir.
  - `kaydet()` önce yeni kodları çözer (yukarıdaki adımlar), sonra mevcut kaydı yapar.
  - Önizlemede "yeni kod" görünümü ve "N yeni kod" sayacı.
  - Açıklama metnine şu cümle eklenir: *"Listede olmayan hisse ve kripto kodları (THYAO, SOL…) eklerken bulunur."*

## Testler
- **Arka uç** — yeni `tests/Feature/TopluVarlikTest.php`. Her testte `Http::fake` kullanılır, gerçek kaynağa istek gitmez:
  - THYAO borsada bulunur → hisse; SOL borsada 404, Binance'te bulunur → kripto; YOKBU iki yerde de 404 → `bulunamayan`.
  - Katalogda olan kod için kaynağa istek gitmez (`Http::assertSentCount`).
  - Bağlantı hatası (`Http::failedConnection()`) → `ulasilamayan`.
  - 51 kod ya da hatalı biçim → 422; girişsiz → 401; `ekleyen_id` doğru.
  - Katalog ortak olduğundan kullanıcıya ait veri yoktur. Yine de uç yalnızca katalog döndürür; başka kullanıcının hesap ve işlemleri yanıtta yer almaz.
- **[topluIslem.spec.ts](frontend/src/__tests__/topluIslem.spec.ts):**
  - Kod biçimindeki bilinmeyen alan → `yeniKod`, hata yok.
  - Türkçe harfli kod sadeleşir (`şok` → `SOK`).
  - Boşluklu ad → hata.
  - `bulunamayan` kümesindeki kod → hata.

## Kayıt ve yayın (CLAUDE.md)
- Plan `docs/planlar/toplu-yeni-kod.md`'ye kopyalanır.
- [docs/DEVAM.md](docs/DEVAM.md)'ye kayıt yazılır; her anlamlı adımdan sonra commit + push yapılır ("Yarım: …").
- Bitince ILERLEME.md'ye bir satır yazılır, DEVAM kaydı silinir.
- Tüm testler geçince canlıya `yayinla.bat yayinla` ile yayın yapılır. Açık uygulama yeni sürümü kendisi yükler.

## Doğrulama
1. Tüm testler: `php artisan test`, ardından `npm run type-check && npm run lint && npx vitest run`. Pint / prettier yalnızca değişen dosyalara.
2. **Yerelde uçtan uca** (scratchpad'deki `toplu-dene.mjs`, Edge, 360 px), metin `GRAM;5`, `ASELS;100`, `SOL;2`, `YOKBUKOD;1`:
   - Önizlemede 4 satır hazır görünür; bunların 3'ü "yeni kod" (ASELS, SOL, YOKBUKOD).
   - Ekle'ye basınca YOKBUKOD bulunamadı hatası çıkar, kayıt yapılmaz; ASELS ve SOL adlarıyla görünür.
   - O satır silinip yeniden Ekle'ye basınca 3 varlık eklenir; işlemler sunucuda görünür.
3. **Canlıda** `/api/v1/surum` yeni hash'i döner.
