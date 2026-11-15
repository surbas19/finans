# Bütçe → İşlemler: kart başına akordiyon, kategori ekleme / uzun basarak kategorileme / sol gradient şerit / toplu işaretleme; gelir türü "Ek ders"

## Context
Kullanıcı (2026-11-15), yayından sonra:
1. İşlemler sayfasındaki "Bu ay / Geçen ay / Son 30 gün" kısayolları kalksın.
2. Standart görünüm: **ekstre dönemindeki tüm harcamalar, kart isimlerinin altında akordiyon**; süzgeç (arama, tarih, kişi, banka, kart, kategori, tür) uygulanınca **hepsi açık**.
3. Gelir türlerinde **Maaş'tan sonra "Ek ders"** gelsin (cevap: yalnızca açılır kutuda Maaş'ın hemen ardından yer; alt tür / hiyerarşi yok).
4. **İşlem kategorisi ekleyebileyim**; işlem üzerine **basılı tutunca kategoriler çıksın**, seçince işlem kategorilensin.
5. Satırın solunda kategoriye göre **farklı gradient renkli şerit** (kategorisizde nötr gri).
6. Bir işlem kategorilenince sistemdeki **aynı harcamalar** da işaretlensin (cevap: **açıklaması aynı olan hepsi, mevcut kategori ne olursa olsun**).

## Keşif (özet)
- `KartIslemListesi.vue`: satır = tek `<button data-test="kart-islemi">` (`sec` olayı), kategori yalnız düz metin, sol şerit yok; projede uzun basma yok. `AileAyarlarView.vue` kategori bölümü (ad, sil, kural çipleri; renk / ikon girişi yok). `HarcamaKategorisi` (`ad, ikon, renk, sira, fatura`), `KategoriKurali` (`desen`), `KategoriController` (store/update/kuralEkle; desen `Metin::sade`, 2-60 karakter, hanede benzersiz), `KategoriBulucu` (kuralları uzun desenden başlayarak `Metin::geciyor` ile eşler; ekstre içe aktarma ve elle ekleme bunu kullanır). Kartların güncel dönemi = `Ekstre` en yeni onaylı (`kesim_tarihi` azalan, `id` azalan); elle satırlar `ekstre_id` null. `GelirTuru` düz liste (`hane_id, ad, sira`), varsayılanlar `VarsayilanAileKayitlari::GELIR_TURLERI` ("Maaş", "Kira geliri", "Serbest çalışma", "Ek iş"…). Kart renk paleti `utils/kartRengi.ts` (12 PrimeVue rengi, `kartDegradesi`), `components/aile/RenkSecici.vue` mevcut.

## Tasarım

### A. Arka uç (`Api\V1\Aile\KartIslemiController`, yeni `App\Services\Aile\KategoriAtayici`)
1. **Güncel dönem görünümü** — `GET aile/kart-islemleri?guncel=1` (süzgeçsiz varsayılan): her kartın **en yeni onaylı ekstresinin satırları** + o kartın **ekstreye girmemiş elle satırları** (kesim tarihinden sonra; ekstresi olmayan kartta hepsi). Diğer süzgeçlerle (kişi, banka, kart, kategori, tür) birleşebilir; `ara` / tarih verilirse `guncel` uygulanmaz (tüm dönemlerde arar). Yanıta `toplam.kartlar[{kart_id, gider, adet, ekstre_donemi}]` eklenir (aynı koleksiyondan, `ButceRaporu::giderEtkisi` ile), ön yüz grup başlığında gösterir.
2. **Kategori atama** — `PUT aile/kart-islemleri/{islem}/kategori` `{kategori_id}` (`kategori_id` null → "kategorisiz"): `Gate hane-kaydi`, kategori hanede olmalı. `KategoriAtayici`:
   - işlemin açıklamasını `Metin::sade`'ye çevirir; **kural kaydeder** (desen = bu sade açıklama, en çok 60 karakter, kelime sınırında kısaltılır; aynı desen hanede başka kategorideyse o kural **yeni kategoriye taşınır**, böylece sonraki ekstrelerde otomatik uygulanır; kategorisiz yapılırsa kural silinir);
   - hanenin **tüm** kart işlemlerinden (onaylı / taslak / atlanan) sade açıklaması **aynı** olanların `kategori_id`'sini toplu günceller (PHP'de `Metin::sade` eşitliği, `id, aciklama` parça parça okunur, tek `UPDATE … WHERE id IN`); iade satırının kendi kategorisi yoksa asıl satırdan geldiği mevcut mantık bozulmaz;
   - raporları etkilemez ama onaylı satırların `rapor_donemi`/tutarı değişmez (yalnız `kategori_id`); yanıt `{islem, guncellenen: N, kategori_ad}`.
3. **Kategori ekleme** — mevcut `POST aile/kategoriler` (`ad`, isteğe bağlı `renk`, `ikon`); `renk` verilmezse sunucu hanenin henüz kullanılmayan paletindeki sıradaki rengi atar (12'li palet, tükenince döner) → her kategori ayrışan bir renk alır. Var olan renksiz kategoriler ön yüzde kimlikten sabit renk alır (migration gerekmez).
4. **Gelir türü "Ek ders"**: `VarsayilanAileKayitlari::GELIR_TURLERI`'nde "Maaş"ın hemen ardına eklenir (yeni haneler); migration mevcut hanelerde "Ek ders" yoksa Maaş'ın `sira + 1`'ine ekler ve sonrakileri bir kaydırır (varsa dokunmaz; ad benzersizliği korunur, geri alınabilir `down`).

### B. Ön yüz
1. `AileIslemlerView.vue`: kısayol çipleri (`HIZLI`) ve ilgili kod silinir; varsayılan `aralik = null`, istek `guncel: 1` (hiçbir süzgeç yokken). Sonuç **karta göre gruplanır**: PrimeVue `Accordion` (`multiple`), grup başlığı `KartEtiketi` + dönem etiketi + satır sayısı + gider; **süzgeç varsa tüm gruplar açık**, yoksa yalnız ilk kart açık (kullanıcı serbestçe açıp kapatır; süzgeç değişince açık durum sıfırlanır). Taşma çözümü sınıfı (`[&_.p-accordioncontent-wrapper]:min-w-0 …overflow-hidden`). Toplam kutusu ve notlar mevcut halinde; "süzgeçleri temizle" `guncel` görünüme döner. Boş kart grubu gösterilmez.
2. **Sol gradient şerit** (`KartIslemListesi.vue`): her satırın solunda 5 px dikey şerit, kategorinin `utils/kategoriRengi.ts` ile üretilen **çok yumuşak, yarı saydam (%50 opaklık)** gradient'i: `linear-gradient(to right, color-mix(in srgb, var(--p-<renk>-400) 50%, transparent), transparent)` (soldaki kenarda kategori rengi %50 saydam, sağa doğru tamamen saydama sönen yumuşak bir geçiş; sert çizgi yok; palet `KART_RENKLERI`, renksiz kategori için kimlikten sabit renk); kategorisiz satırda gri aynı yumuşaklıkta (%50 saydam). Şerit satır metnini okunaksız yapmaz (metin üstünde kalır, kontrast bozulmaz). Kategori adı metni küçük renkli noktayla birlikte gösterilir.
3. **Uzun basma** — yeni `composables/useUzunBasma.ts`: `pointerdown` ile 450 ms zamanlayıcı, 8 px hareket / `pointerup` / `pointercancel` / kaydırma iptal eder; tetiklenince titreşim (`navigator.vibrate`) ve sonraki tıklama yutulur (düzenleme penceresi açılmaz); `contextmenu` olayı (sağ tık / klavye menü tuşu) aynı işi görür. Satırda `touch-callout: none` + `user-select: none` + `contextmenu.prevent`. Kısa dokunuş eskisi gibi düzenleme formunu açar.
4. **Kategori seçici** — yeni `components/aile/KategoriSecDialog.vue` (`UygulamaPenceresi`, alttan açılır): başlıkta işlemin açıklaması; kategoriler renkli şerit örneğiyle liste; seçili olan işaretli; "Kategorisiz yap"; **"Yeni kategori"** (ad + `RenkSecici`/palet, hemen oluşturup işleme atar). Seçince `aileApi.kartIslemiKategori(id, kategori_id)`, toast: "N işlem 'Market' olarak işaretlendi", liste yenilenir (aynı açıklamalı tüm satırlar). Ayarlar → kategoriler bölümüne de renk seçimi eklenir (mevcut ekleme formuna palet).
5. `api/aile.ts`: `kartIslemiKategori`, `Kategori.renk`, süzgeç `guncel`, `toplam.kartlar` tipi; `GelirTuruSecici` sıralamayı sunucudan alır (değişiklik gerekmez).

## Aşamalar (her biri ayrı commit + push; plan `docs/planlar/islem-kategori-akordiyon.md`, ILERLEME + CLAUDE.md)
1. Arka uç: `guncel` görünüm + `toplam.kartlar`, `KategoriAtayici` + uç, kategori otomatik renk, gelir türü migration + varsayılan; Pest: güncel dönem (en yeni onaylı ekstre + elle satır), süzgeçte tüm dönem, atama + aynı açıklamalı tüm işlemler (farklı kart, taslak, mevcut farklı kategori), kural kaydı / taşınması / kategorisiz kaldırma, sonraki ekstrede otomatik, uzun açıklama kısaltma, **başka hane göremez / değiştiremez** (işlem, kategori), "Ek ders" migration (var olan atlanır, sıra Maaş'ın ardından).
2. Ön yüz: gruplu akordiyon, çipler kaldırıldı, şerit, `useUzunBasma`, `KategoriSecDialog`, Ayarlar renk; Vitest (süzgeçsiz ilk grup açık / süzgeçli hepsi açık, çipler yok, uzun basma → dialog ve kısa dokunuş → düzenleme, uzun basma sonrası tıklama yutulur, seçim API'yi çağırır, şerit kategori renginde, yeni kategori).
3. Edge e2e (320 / 360 / 1280): akordiyonlar taşmıyor, uzun basma dokunmatik simülasyonuyla dialogu açıyor, seçim sonrası aynı açıklamalı satırların şeridi değişiyor, sayfa hatası yok.
4. Belgeler.

## Doğrulama
`DB_DATABASE=finans_test php artisan test`; `npm run type-check && npm run lint` ve vitest (PowerShell betiğiyle); Pint / prettier yalnız değişen dosyalara; Edge'de elle deneme. Canlıya yayın yalnız kullanıcı isterse (yeni migration: gelir türü "Ek ders").

## Kapsam dışı
Gelir türü hiyerarşisi (alt tür), kategori silme / yeniden adlandırma ekranı değişiklikleri, Faturalar kategorisinin özel kuralları, grafik değişiklikleri.
