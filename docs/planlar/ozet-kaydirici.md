# Özet: yana kaydırmalı hesap halkaları (Tüm hesaplar → her hesap), seçilenin detayı altta

## Context
Kullanıcı bir aracı kurum uygulamasının ekran görüntüsünü örnek verdi: üstte halka grafik ve ortasında toplam, yanında toplam portföy ve K/Z, altında varlık satırları (her satırın solunda halkadaki dilimin renginde şerit).

İstek: Özet açılınca **"Tüm hesaplar" halkası** görünsün. **Yana kaydırınca** (slider) sırayla her hesabın halkası gelsin. **Seçilen slaytın detayı altta** çıksın.

Kullanıcının seçimleri:
- **Halka:** Tüm hesaplar slaytında hesapların payı (hesap renkleriyle). Hesap slaytında o hesaptaki varlıklar: en büyük 6'sı renkli, kalanı gri "Diğer"; alttaki varlık satırlarında aynı renkte şerit.
- **Alt detay:** bugünkü Özet düzeni (kutular, performans, varlıklar, türlere göre dağılım) + "Hesabı aç" bağlantısı (işlemler, düzenleme, toplu ekleme hesap sayfasında kalır).

Bugün [OzetView.vue](frontend/src/views/OzetView.vue)'nun üstünde "Tümü / hesap" düğmeleri var. Her seçimde `GET /ozet?hesap_id=` yeniden çağrılıyor. Kaydırıcı bu düğmelerin yerini alır.

Bütün hesapların özeti (`/ozet`) her hesabın toplamlarını (`hesaplar[]`, `Toplam` alanlarının hepsi) ve hesap bazında pozisyonları (`pozisyonlar[]`, `hesap_id` ile) zaten içeriyor. Eksik olan yalnızca hesap başına tür dağılımı. O da eklenince bütün slaytlar ve detaylar **tek istekten** gelir: kaydırınca bekleme olmaz, yalnızca performans grafiği kendi isteğini atar.

## Arka uç (küçük)
- **[PortfoyHesabi::hesapla](backend/app/Services/Portfoy/PortfoyHesabi.php):** her hesap satırına `'dagilim' => $this->dagilim($acik->where('hesap_id', $h->id))` eklenir. Hesap toplamı tek yerde kalır; ön yüz hesap yapmaz, yalnızca süzer ve dizer.
- **Test:** [IslemVeHesapTest](backend/tests/Feature/IslemVeHesapTest.php)'e `/ozet` yanıtında `hesaplar.N.dagilim`'ın yalnızca o hesabın varlıklarını içerdiği denetimi.
- `/hesaplar` ucu da aynı diziyi döndürür; alan yalnızca eklenir, kırılan bir şey olmaz.

## Ön yüz
### `utils/halka.ts` (saf, test edilir)
- **`HALKA_RENKLERI`:** 6 ayırt edilir renk: gök, mor, kehribar, pembe, camgöbeği, çivit. Kâr / zarar anlamındaki yeşil ve kırmızı kullanılmaz. "Diğer" için `--p-surface-300`.
- **`varlikDilimleri(pozisyonlar, enCok = 6)`:**
  - Değeri olan pozisyonlar büyükten küçüğe dizilir.
  - İlk 6'sı renkli; kalanlar tek gri "Diğer" diliminde toplanır.
  - Döner: `{ dilimler: {etiket, deger, renk}[], renkler: Map<varlikId, renk> }` (gri olanlar da haritada).
- **`hesapDilimleri(hesaplar)`:** değeri sıfırdan büyük her hesap bir dilim, renk `--p-${renk ?? 'teal'}-500`.

### [VeriGrafigi.vue](frontend/src/components/VeriGrafigi.vue)
İsteğe bağlı iki prop eklenir (mevcut kullanımlar değişmez):
- `gosterge` (varsayılan `true`): halkada alttaki etiket listesi gizlenebilir.
- `degerBicimi`: ipucunda tutar `₺368.630,60` gibi görünür.

İnce halka için `kesim` (`cutout`) verilir.

### Yeni `components/PortfoyKaydirici.vue`
- **Prop'lar:**
  - `slaytlar: { id: number | null; ad; altBaslik; deger; kz; kz_yuzde; gunluk_degisim; dilimler; merkez }[]`
  - `v-model:secilen` (`number | null`)
- **Görünüm (ekran görüntüsündeki gibi):**
  - Kart içinde solda halka (~9rem, ince); ortasında kısa yazı. Hesap slaytında "payı %62", Tüm hesaplar slaytında "3 hesap".
  - Sağda ad, toplam değer (dar ekranda küçülür), K/Z (`DegisimEtiketi`, tutar + %) ve "Bugün".
  - Hiç değer yoksa grafik yerine gri boş halka (CSS).
- **Telefon (`useDarEkran`):**
  - Yatay şerit, `snap-x snap-mandatory`, kaydırma çubuğu gizli. Slayt genişliği %86; yandaki slaytın kenarı görünür, kaydırılabildiği anlaşılır.
  - Seçim ortada duran slayttır: `IntersectionObserver` (eşik 0.6), 150 ms durunca `update:secilen` yayılır, ara slaytlarda titreme olmaz.
  - Kenardan görünen slayta dokununca o slayta kayar.
  - Altta noktalar: düğmeler, `aria-label` = hesap adı, `aria-current`; dokununca o slayta kayar.
- **Masaüstü (sm+):**
  - Yan yana 2 (sm) / 3 (lg) slayt. Tıklanan slayt seçilir; seçilende `ring-2 ring-primary-500`.
  - Taşma varsa sol / sağ ok düğmeleri şeridi kaydırır.
- **Seçim dışarıdan değişince:** ilk açılış ya da "Hesaplar" listesinden seçimde şerit, sayfayı dikey kaydırmadan o slayta yatay kayar (`serit.scrollTo`, ortalanmış `left`).
- **Erişilebilirlik:** `section aria-roledescription="carousel"`, her slayt `aria-label="2 / 4: Kasa"`.
- **Test ortamında:** `IntersectionObserver` yoksa gözlem kapalı (jsdom).

### [OzetView.vue](frontend/src/views/OzetView.vue)
- **Veri:** tek `ozet` (bütün hesaplar) + `secilenHesap`. Mevcut `sec()` / `tumHesaplar` / hesap başına istek kaldırılır.
- **Slaytlar:**
  - **Tüm hesaplar:** toplam; dilimler hesaplar. Tek hesap varsa varlıklar.
  - **Her hesap:** sırayla gelir; dilimler o hesabın pozisyonları.
- **Seçilenin detayı (hepsi `ozet`'ten, `computed`):**
  - **Başlık kartı:**
    - "Tüm hesaplar" ya da hesap adı + "Hesabı aç →" (`/hesaplar/:id`), ve "İşlem ekle" (hesap seçiliyse o hesapla açılır).
    - Kutular: Maliyet, Gerçekleşen (satışlar), Bugün.
    - Uyarılar (maliyeti eksik, fiyatsız) ve "Fiyatlar … güncellendi" + yenile düğmesi (bugünkü yerinden taşınır).
  - **Performans:** `PerformansGrafigi :hesap-id="secilenHesap"` (değişmez).
  - **Tüm hesaplarda "Hesaplar" listesi:** halkanın açıklaması gibi çalışır. Her satırda dilim renginde şerit; satıra dokununca o hesabın slaytı seçilir ve sayfa kaydırıcıya çıkar (hesap sayfası "Hesabı aç" ile).
  - **Varlıklar:**
    - Tüm hesaplarda birleşik `varliklar` (şeritsiz).
    - Hesapta `pozisyonlar.filter(hesap_id)` ve `renkler` şeridi.
  - **Dağılım:** `DagilimKarti` (türlere göre): tümünde `ozet.dagilim`, hesapta `hesap.dagilim`.
- **Seçim adreste:** `?hesap=ID` (`router.replace`). Varlık sayfasına gidip geri dönünce aynı slayt açılır. Geçersiz ya da boşsa "Tüm hesaplar".
- **Yükleniyor:** kaydırıcı yerine iskelet kart.

### [PozisyonListesi.vue](frontend/src/components/PozisyonListesi.vue)
İsteğe bağlı `renkler?: Map<number, string>` eklenir: satırın solunda 4 px yuvarlak şerit (ekran görüntüsündeki gibi). Verilmezse görünüm aynı kalır.

### [api/finans.ts](frontend/src/api/finans.ts)
`HesapOzeti`'ye `dagilim?: DagilimDilimi[]` eklenir.

## Testler
- **`halka.spec.ts`:**
  - İlk 6 + "Diğer" toplamı; değeri `null` / 0 olan atlanır; renk haritası (gri dahil).
  - `hesapDilimleri` renk ve sıfır değer.
- **`PortfoyKaydirici` (mount):**
  - Slaytlar ve noktalar çizilir; noktaya tıklayınca `update:secilen`.
  - Masaüstünde slayta tıklayınca seçilir; seçilende `aria-current`.
- **Arka uç:** hesap başına `dagilim` testi.
- **Tüm testler:** `php artisan test`, ardından `npm run type-check && npm run lint && npx vitest run`.

## Kayıt ve yayın (CLAUDE.md)
- Plan `docs/planlar/ozet-kaydirici.md`'ye kopyalanır.
- `docs/DEVAM.md`'ye kayıt yazılır; adım adım "Yarım: …" commit + push.
- Bitince ILERLEME satırı yazılır ve DEVAM kaydı silinir. Testler geçince canlıya `yayinla.bat yayinla` ile yayın yapılır.

## Doğrulama (Edge + playwright-core, scratchpad betikleri)
1. **Telefon, 360 px:**
   - Açılışta "Tüm hesaplar" slaytı ve hesap dilimli halka görünür; altta "Hesaplar" listesi şeritli.
   - Şerit yana kaydırılınca (`scrollBy` ve dokunma kaydırması) 2. slayt seçilir; alttaki başlık hesap adı olur, varlıklar o hesabınkiler ve şeritli; adreste `?hesap=` yazar.
   - Noktaya dokununca ilgili slayta kayar.
   - Varlık satırına dokunup geri dönünce aynı slayt seçili kalır.
2. **Ölçüm:** `mobil-olc.mjs` ile 320 / 360 / 390 px'te Özet'te yatay taşma yok ve alt çubuk yerinde. Ekran görüntüleri: Tüm hesaplar slaytı, hesap slaytı ve detayı.
3. **1280 px:** 3 slayt yan yana; tıklayınca seçilir ve halka belirginleşir; oklar çalışır.
4. **Canlı:** `/api/v1/surum` yeni hash'i döner. Açık uygulama yeni sürümü kendisi yükler.
