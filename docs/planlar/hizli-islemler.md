# 1) Ay çarkı: fare tekerleği bir tıkta bir ay · 2) Hızlı işlemler menüsü (⚡ pop-up)

## İŞ 1 — Ay çarkı (Kasım 2026 seçicisi) fare tekerleğiyle bir ay gidip gelmiyor

### Context
Kullanıcı: fare tekerleği ("orta tuş") ile ay seçilemiyor; çok hızlı dönüyor, bir tıkta bir ay yerine iki ay ileri/geri gidiyor.

### Neden (`components/aile/AySecici.vue`)
Çark yerel kaydırma kutusu (`overflow-y-auto`, `scroll-snap`, satır 24 px). Masaüstü fare tekerleği bir tıkta ~100 px kaydırır (≈ 4 satır) ve tarayıcı bunu birden çok `scroll` olayıyla, yumuşak kaydırmayla yapar; 120 ms durunca `kaydirmaBitti` ortadaki satırı seçer, ardından seçim `ortala(true)` ile yeniden kaydırır → atlama ve titreme. Dokunmatik kaydırma bu yüzden iyi çalışıyor; sorun yalnız tekerlekte.

### Çözüm
Tekerleği çarkta kendimiz yönetiriz (`@wheel.prevent="teker"`; dokunmatik kaydırma yerel kalır):
- `teker(e)`: `pasif` ise çık; `deltaMode` satırsa piksele çevir; yön = `deltaY` işareti; **bir tıkta tam bir ay** → `sec(donemEkle(secili, ±1))` (ileri sınır ve `bos` kuralı `sec` içinde zaten uygulanıyor).
- Hızlı dönmeyi önleme: bir adımdan sonra ~140 ms kilit (tekerlek ivmesi / dokunmatik yüzey kaydırması art arda ay atlatmaz); dokunmatik yüzeyde küçük `deltaY`'ler 30 px eşiğine kadar birikir, eşik aşılınca bir ay.
- Seçim sonrası mevcut `watch(secili) → ortala(true)` çarkı yumuşakça yeni aya kaydırır (değişmez).
- Test (`__tests__/aySecici.spec.ts`'e): tekerlek aşağı → tek `update:modelValue` (+1 ay); kilit süresi içinde ikinci tıkta yeni olay yok; kilitten sonra yukarı → −1 ay; `ileriSinir`'de aşağı tekerlek ileri gitmez; `pasif`'te işlem yok; olay `preventDefault` edilir (sayfa kaymaz).

---

## İŞ 2 — Hızlı işlemler menüsü

### Context
Kullanıcı: ekranın bir yerinde ikona basınca küçük bir pop-up hızlı işlemler menüsü açılsın; başlangıçta **nakit harcama, tasarruf, ekstre yükle** (+ seçilen: **gelir ekle**) olsun. Cevaplar: menü 4 öğe; kayıt sonrası **açık sayfa kendiliğinden yenilensin**.

### Keşif (özet)
- Üst çubuk `layouts/AnaDuzen.vue:68`: sağ küme (profil + çıkış `Button`); ⚡ ikonu çıkışın hemen önüne. Projede PrimeVue Popover / Menu hiç kullanılmıyor (ilk kullanım; `primevue/popover` 4.x içinde, MIT, ek paket yok). Alt sekme çubuğu dolu (5-6 sekme) → üst çubuk.
- Mevcut formlar (hepsi `UygulamaPenceresi` ile açılır, `v-model:visible` + `@kaydedildi`): `GiderFormu` (kartsız gerçekleşen gider; `kaynaklar` zorunlu prop → `aileApi.giderKaynaklari()`), `TasarrufFormu` (kendi verisini yükler), `EkstreYukleDialog` (kendi `aile.yukle()`; props'suz açılır), `GelirFormu` (`kaynaklar` zorunlu → `aileApi.kaynaklar()`; `aile.hane.ben_uye_id` kullanır).
- Sayfa yenileme mekanizması yok; her sayfa kendi formunu `@kaydedildi="yukle"` ile yeniler. `AnaDuzen.vue:106` `RouterView` zaten `:key="r.path"` ile yol değişince sayfayı yeniden kurar → anahtara bir sayaç eklemek tüm sayfaları tek yerden yeniler.

### Tasarım
**`components/HizliIslemler.vue`** (yeni, `AnaDuzen`'de üst çubuğa mount edilir):
- Düğme: `Button` `icon="pi pi-bolt"`, `text rounded`, `aria-label="Hızlı işlemler"` (40 px dokunma alanı, profil düğmesiyle aynı boy), `data-test="hizli-dugme"`; basınca PrimeVue **`Popover`** küçük pop-up olarak düğmenin altına açılır (genişlik ~15 rem, ekran kenarından taşmaz; dışarı dokunma / Esc ile kapanır; klavyeyle gezilir).
- Menü **veri listesinden** üretilir (sonradan öğe eklemek tek satır): `HIZLI_ISLEMLER = [{ ad: 'nakit-harcama', etiket: 'Nakit harcama', ikon: 'pi pi-wallet', form: 'gider' }, { 'tasarruf', 'Tasarruf', 'pi pi-piggy-bank' }, { 'ekstre', 'Ekstre yükle', 'pi pi-upload' }, { 'gelir', 'Gelir ekle', 'pi pi-plus-circle' }]`; her satır ikon + etiket, 48 px yükseklik, dokunmatik dostu.
- Öğeye dokununca pop-up kapanır ve ilgili form **aynı bileşenin içinde** açılır (sayfadan bağımsız, her sayfada çalışır): formlar `defineAsyncComponent` ile yalnız ilk açılışta yüklenir; açılırken gereken veri tembel yüklenir (`aile.yukle()`, `aileApi.giderKaynaklari()` / `aileApi.kaynaklar()` yalnız o form açılırken, önbellekli).
  - **Nakit harcama** → `GiderFormu` (yeni isteğe bağlı `baslik` prop'u: "Nakit harcama"; kaynak seçimi isteğe bağlı kalır, tutar / açıklama / kategori / tarih).
  - **Tasarruf** → `TasarrufFormu` (kendi hesap / varlık verisini yükler).
  - **Ekstre yükle** → `EkstreYukleDialog` (kart seçimi kendi içinde).
  - **Gelir ekle** → `GelirFormu` (`kaynaklar` yüklenir).
- Kayıt sonrası: bileşen `kaydedildi` olayı yayar; `AnaDuzen` bir `yenileme` sayacını artırır ve `RouterView` anahtarı `r.path + ':' + sayac` olur → açık sayfa yeniden kurulup güncel veriyi yükler (ay / süzgeç adreste olduğundan korunur).
- Giriş yapılmamışken / hane yokken menü görünmez (`auth.kullanici` yoksa).

### Aşamalar (her biri commit + push; plan `docs/planlar/hizli-islemler.md`, ILERLEME + CLAUDE.md)
0. **Görsel seçenekler (kullanıcı isteği: ASCII değil, gerçek görsel):** uygulamanın gerçek renk / yazı tipi / bileşenleriyle (Tailwind + PrimeVue teması) 360 px telefon çerçevesinde render edilmiş seçenekler üretilir ve ekran görüntüsü olarak gösterilir: **ikon yeri** A) üst çubukta çıkışın yanında, B) sağ altta yüzen düğme, C) alt çubuğun ortasında "+"; **menü görünümü** 1) ikon + yazı dikey liste, 2) 2×2 büyük kareler, 3) liste + açıklama satırı. Seçiminiz alınmadan kod yazılmaz; seçilen düzen İş 2'de uygulanır (varsayılan öneri: A + 1). Bu aşama yalnızca geçici dosya üretir (depoya girmez, commit yok).
1. **İş 1:** `AySecici` tekerlek yönetimi + Vitest.
2. **İş 2:** `HizliIslemler.vue` + `GiderFormu` `baslik` + `AnaDuzen` entegrasyonu (düğme, sayaç anahtarı) + Vitest (düğme pop-up'ı açar ve 4 öğe listeler; öğe formu açar; `kaydedildi` olayı sayacı artırır).
3. Edge e2e 320 / 360 / 1280: pop-up taşmıyor, kapanıyor, nakit harcama kaydı sonrası açık sayfa yeni kaydı gösteriyor (demo kaydı temizlenir); tekerlekle ay seçici bir tıkta bir ay.
4. Belgeler.

### Doğrulama
`DB_DATABASE=finans_test php artisan test` (arka uç değişmez); `npm run type-check && npm run lint` ve vitest (PowerShell betiğiyle, `--testTimeout=30000`); Pint yok; prettier yalnız değişen dosyalara. Canlıya yayın yalnız kullanıcı isterse (migration yok).

### Kapsam dışı
Menü ayarları ekranı (öğeleri kullanıcı özelleştirme), kısayol tuşları, arka uç değişikliği, alt sekme çubuğuna düğme.
