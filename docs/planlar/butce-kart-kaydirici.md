# Bütçe arayüzü: kart kaydırıcı + kart renkleri, Gelir/Gider tek sekme, Zekât → Yönetim, üstte Onayla

## Context
Kullanıcı isteği (2026-11-15), hepsi mobil öncelikli ön yüz değişikliği (backend değişmez):
1. Kredi kartı ekstre ekleme / inceleme ekranında **Onayla düğmesi üstte de** olsun.
2. **Kart ekranında ekstre giriş düğmeleri** olsun.
3. Bütçedeki **Zekât, Yönetim'e sekme** olarak taşınsın (karar: **Yönetim yalnızca yöneticide kalır**, zekât onun içinde; yönetici olmayan hane üyeleri zekât ekranına erişemez).
4. **Kartlar sayfası**: telefonda ekranda tek kart, sağa-sola kaydırınca diğer kart; **her kart için renk seçme ayarı**.
5. **Kart renkleri taksitlerde ve kartın göründüğü diğer yerlerde** de kullanılsın.
6. **Gelir ve Gider sayfaları tek butonla açılsın**; Gelir panelinin üstünde ince **yeşil**, Gider panelinin üstünde ince **kırmızı** çizgi; yine **sağa-sola kaydırma** ile geçilsin.
7. **Ana menüdeki İşlemler** (alt gezinmedeki portföy İşlemler sayfası) kısmına **zekât ödemesi** eklensin (kullanıcı kararı: ana menüdeki İşlemler).

Keşif sonuçları: `Kart.renk` backend'de zaten var (`KartController` regex `^[a-z0-9#-]+$`, `ozet()` döner) ama `KartFormu`'nda alan yok, hiçbir ekranda kullanılmıyor; `HESAP_RENKLERI` seçicisi `HesapFormu.vue`'da satır içi; renkler `var(--p-<ad>-500)` ile boyanır; `PortfoyKaydirici.vue` (scroll-snap + IntersectionObserver + noktalar + `useDarEkran`) içeriğe sabit, slot yok; `EkstreView.vue` onay mantığı (`onayla()`, `onaylaniyor`, `aiSuruyor`, `yalnizOnizleme`) tek yerde, alt sabit çubukta; `KartDetayView` yalnızca "Ekstre yükle" düğmesi var (JSON / tutarla yok); `aile-gelirler` / `aile-giderler` adları yalnızca `AileDuzeni.vue`, router ve `AileOzetView.vue:317`'de; `YonetimView` PrimeVue `Tabs` (kaynaklar / kullanıcılar), router meta `yonetici: true`.

## Yeniden kullanılacaklar
`PortfoyKaydirici.vue` mantığı (`snap-x snap-mandatory`, `IntersectionObserver` %60, 150 ms debounce, noktalar, `composables/useDarEkran.ts`), `HESAP_RENKLERI` + seçici blok, `var(--p-X-500)` kalıbı, `AySecici`, `useSonIstek`, `GelirlerView` / `GiderlerView` gövdeleri, `EkstreYukleDialog` (`baslangic-turu`, `kart-id`), `ElleEkstreDialog` (`kart-id`), `ZekatView` (bağımsız), `YonetimView` `Tabs`.

## Tasarım
### A. Ortak parçalar (`frontend/src/`)
- `components/KaydirmaliPaneller.vue` (jenerik, slot'lu): `v-model` seçili sıra; dar ekranda tek panel (%88–100 genişlik, kenarda sonraki panelin ucu görünür), `snap-x snap-mandatory`, `IntersectionObserver` ile aktif panel, gösterge noktaları, `sm+` ekranda panelleri yan yana (2–3 sütun) ve oklar; `aria-roledescription="carousel"`. Mantık `PortfoyKaydirici`'dan alınır (o bileşen **değişmez**, `portfoyKaydirici.spec.ts` bozulmaz). Panel başlığı slotu (renkli üst çizgi için).
- `components/aile/RenkSecici.vue`: `HesapFormu`'daki seçici bloğun çıkarılmış hâli (`v-model`, `renkler` prop; daire düğmeler, `aria-pressed`). `HesapFormu` da bunu kullanır (davranış aynı).
- `utils/kartRengi.ts`: `KART_RENKLERI` (12 renk: teal, sky, blue, indigo, violet, pink, rose, red, orange, amber, emerald, slate; hepsi PrimeVue paletinde var) ve `kartRengi(kart)` = `kart.renk` geçerliyse o, yoksa `KART_RENKLERI[kart.id % n]` (migration gerekmez, rengi seçilmemiş kartlar da renkli görünür), `kartRengiCss(kart)` = `var(--p-<renk>-500)`.
- `components/aile/KartEtiketi.vue`: küçük renkli nokta / şerit + kart adı (`kart-id` ya da `kart` prop; `aile.kart(id)` ile). Kart adının göründüğü yerlerde kullanılır (aşağıda).

### B. Kartlar sayfası (`views/aile/KartlarView.vue`)
- Banka grupları listesi yerine **kart kaydırıcısı** (`KaydirmaliPaneller`): her slayt kredi kartı görünümlü karttır — kart renginden degrade zemin (`kartRengiCss`), banka adı, kart adı, sahip, "kesim X · son ödeme Y", son onaylı ekstre borcu ve son ödeme tarihi, üzerine dokununca `aile-kart` detayı. Slayt altında **ekstre giriş düğmeleri**: "Ekstre yükle", "Dış yapay zekâ (JSON)", "Tutarla gir" (o kartı önceden seçili açar: `EkstreYukleDialog :kart-id`, `ElleEkstreDialog :kart-id`). Telefonda tek kart + noktalar; geniş ekranda 2–3 kart yan yana.
- Üst satır: "Ekstreler" bağlantısı ve "Kart ekle" kalır; genel "Ekstre yükle / JSON / Tutarla" düğmeleri kart seçiciyle çalışmaya devam eder (kart yoksa kapalı). "İncelemeyi bekleyen ekstreler" bölümü kalır, kart rengi etiketiyle.
- `KartFormu.vue`: **Renk** alanı (`RenkSecici`, varsayılan: kullanılmayan ilk renk), `renk` gövdeye eklenir (backend zaten kabul ediyor).
- `KartDetayView.vue`: başlık kartın renginde bir şeritle; "Ekstre yükle" yanına "Dış yapay zekâ (JSON)" ve "Tutarla ekstre gir".

### C. Kart renkleri diğer yerlerde
`KartEtiketi` / `kartRengiCss` şu yerlerde kullanılır: `AileOzetView` taksitler (`kart_id` → renk şeridi) ve beklenen ekstre uyarıları, `GiderlerView` kart satırları, `KartIslemListesi` / `AileIslemlerView` (işlem satırında kart noktası ve kart süzgeci), `EkstreView` ve `EkstrelerView` başlıkları, `KartlarView` bekleyen ekstreler, `KartDetayView`, `FaturalarView` (kartla ödenen fatura bağlantısı). Backend yanıtları zaten `kart_id` taşıyor; renk `aile.kartlar` (store) üzerinden eşlenir.

### D. Gelir / Gider tek sekme
- Yeni `views/aile/GelirGiderView.vue` (rota `aile-gelir-gider`, yol `aile/gelir-gider`, başlık "Gelir / Gider"): üstte **tek ortak `AySecici`** (`donem` burada tutulur), altında `KaydirmaliPaneller` ile iki panel: **Gelir** (panelin üstünde ince yeşil çizgi, `var(--p-emerald-500)`, ~3 px) ve **Gider** (ince kırmızı çizgi, `var(--p-red-500)`); sağa-sola kaydırma, noktalar, geniş ekranda yan yana. Panel etiketleri çizginin altında ("Gelir", "Gider"); `?panel=gider` sorgusuyla Gider açık başlar.
- `GelirlerView.vue` / `GiderlerView.vue`: `donem` artık **prop** (içlerinden `AySecici` ve `donem` ref'i kalkar; `watch(props.donem)`); iki panel aynı anda monte olur ve kendi verisini yükler. Kendi başına rota olmaktan çıkar, bileşen olarak kullanılır.
- `AileDuzeni.vue`: `SEKMELER`'de "Gelirler" + "Giderler" tek **"Gelir / Gider"** (`aile-gelir-gider`) olur; `seciliMi` bu sekme için `aile-gelir-gider`. Router'da `aile-gelirler` ve `aile-giderler` eski adları `redirect` ile yeni sekmeye (panel sorgusuyla) yönlenir; `AileOzetView.vue:317` bağlantısı yeni adı kullanır.

### E. Zekât → Yönetim
- `YonetimView.vue`: `Tabs`'a **"Zekât"** sekmesi (`value="zekat"`, içinde `<ZekatView />`; `?sekme=zekat` sorgusu desteklenir). Yönetim zaten yönetici-özel (router meta + menü + Gate), değişmez.
- `AileDuzeni.vue`: "Zekât" sekmesi kalkar. Router: `aile-zekat` → `redirect` `{ name: 'yonetim', query: { sekme: 'zekat' } }` (yönetici değilse mevcut guard Özet'e atar).
- `AileOzetView.vue`: zekât uyarı kartı **yalnızca yönetici** için gösterilir (bağlantı Yönetim → Zekât); yönetici olmayan hane üyeleri zekât uyarısını ve ekranını görmez (kullanıcı kararı). Backend uçları değişmez.

### F. EkstreView: üstte Onayla
- `views/aile/EkstreView.vue`: başlık kartının sağ grubuna (Tag'ın yanı, satır ~228) `v-if="taslak"` ikinci **Onayla** düğmesi; ortak `onayEngelli = computed(() => aiSuruyor || yalnizOnizleme)` ve aynı `onayla()` (aynı onay penceresi). Alt sabit çubuk (mobil) kalır. Backend kuralları (kesim tarihi, tekrar dönem, inceleme kapısı) değişmez; kaydedilmemiş dönem alanı varsa üstte düğmeye basınca önce `alanlariKaydet()` (ya da uyarı) — onay öncesi değişiklik kaybolmasın.

### G. Ana menü İşlemler'e zekât ödemesi (`views/IslemlerView.vue`, `components/IslemListesi.vue`)
- **Düğme:** sayfa eylemlerine "Zekât ödemesi" (`pi-heart`/benzeri ikon); mevcut `components/zekat/ZekatOdemeFormu.vue` açılır (`yil-id` null = yıla bağlanmayan serbest ödeme; sunucu bunu zaten kabul ediyor; tutar, tarih ≤ bugün, alıcı serbest ad — IBAN / hesap no yok). Kayıt `POST aile/zekat/odemeler` (`aileApi.zekatOdemeEkle`), zekât ekranındaki kalan borç otomatik güncellenir.
- **Liste:** zekât ödemeleri işlem listesinde tarihe göre **aynı akışa karışır**, "Zekât" rozetli satır (tarih, alıcı, not, tutar; hesap / varlık yok). `IslemListesi`'ne isteğe bağlı `zekatOdemeleri` prop'u (birleşik, tarihe göre sıralı `satirlar` computed; mevcut işlem satırları ve `islemler` kullanan diğer sayfalar aynen çalışır); telefonda satıra dokununca "Zekât ödemesini sil?" onayı, masaüstünde silme düğmesi (düzenleme yok: yanlışsa sil + yeniden gir). Hesap süzgeci seçiliyken zekât satırları gizlenir (hesapla ilgisiz). Boş durum: ikisi de yoksa "Henüz işlem yok".
- **Veri:** `aileApi.zekatOdemeleri()` (mevcut `GET aile/zekat/odemeler`, hane süzgeçli) `IslemlerView.yukle()` içinde `finansApi.islemler` ile birlikte çekilir; hane yoksa / hata olursa liste yalnızca işlemlerle çalışır (zekât çağrısı hatası sayfayı bozmaz).
- Not: zekât ekranı Yönetim'e (yalnız yönetici) taşındığı için yönetici olmayan hane üyeleri zekâtı yalnızca buradan görür ve ödeme ekleyebilir; zekât **hesabı / ayarları** yönetici ekranında kalır. Backend değişmez.

## Aşamalar (commit + push; DEVAM / ILERLEME; planı `docs/planlar/butce-kart-kaydirici.md`'ye kopyala)
1. Ortak parçalar (`KaydirmaliPaneller`, `RenkSecici`, `kartRengi`, `KartEtiketi`) + Vitest.
2. Kartlar sayfası kaydırıcı + kart rengi (`KartFormu`, `KartDetayView`) + kart renkleri diğer yerlerde.
3. Gelir / Gider tek sekme (`GelirGiderView`, prop'lu `donem`, router yönlendirmeleri, `AileDuzeni`).
4. Zekât → Yönetim sekmesi + `AileOzetView` zekât uyarısı yalnızca yönetici.
5. `EkstreView` üstte Onayla.
6. Ana menü İşlemler'e zekât ödemesi (düğme + birleşik liste + silme).
7. Tüm testler, CLAUDE.md / ILERLEME, tarayıcı e2e. Yayın ayrı onayla.

## Doğrulama
- Vitest: `KaydirmaliPaneller` (panel sayısı, noktalar, `v-model`, dar / geniş ekran, `portfoyKaydirici.spec.ts` kalıbı), `RenkSecici`, `kartRengi` (seçili / null / geçersiz renk), `KartEtiketi`, `GelirGiderView` (iki panel, ortak ay, `?panel=gider`), `YonetimView` zekât sekmesi ve `?sekme=zekat`, `EkstreView` üst Onayla (taslakta görünür, AI sürerken kapalı), `IslemListesi` zekât satırları (rozet, tarihe göre karışık sıralama, silme onayı, hesap süzgecinde gizli), `IslemlerView` zekât ödemesi düğmesi, mevcut `butceOzeti.spec.ts` (link adı) güncellenir. `npm run type-check && npm run lint && npx vitest run` (PowerShell); `php artisan test` (backend değişmez, regresyon).
- Edge uçtan uca (320 / 360 / 1280): Kartlar'da tek kart görünür, kaydırınca diğeri, nokta değişir; kart rengi seçip kaydet → kart, taksit ve işlem satırlarında aynı renk; kartın düğmelerinden ekstre yükleme penceresi o kart seçili açılır; Gelir / Gider sekmesi tek, panel kaydırınca Gelir (yeşil çizgi) ↔ Gider (kırmızı çizgi), ortak ay değişince ikisi yenilenir; Yönetim → Zekât sekmesi ve `aile-zekat` yönlendirmesi; yönetici olmayan kullanıcıda zekât uyarısı yok; EkstreView'da üstte Onayla; yatay taşma yok.

## Kapsam dışı
Backend / migration değişikliği, kart rengi için serbest renk kodu (yalnızca 12'li palet), `PortfoyKaydirici`'nın yeni bileşene taşınması.
