# Bütçe → İşlemler: arama kutusu + tarih / tarih aralığı seçici (ay ve "Özet ayı / İşlem tarihi / Ekstre dönemi" düğmesi kalkar)

## Context
Kullanıcı (2026-11-15): Bütçe → İşlemler (kart harcamaları) sayfasına arama filtresi; "işlem tarihi ve özet ayını kaldır. arama kutusunun en sağına bir datepicker koy, tarih veya tarih aralığı seçilebilsin, buna göre süzme yapsın."
Sayfa: `views/aile/AileIslemlerView.vue` (şu an `AySecici` + 3'lü `SelectButton` Özet ayı / İşlem tarihi / Ekstre dönemi + kişi, banka, kart, kategori, tür seçicileri + toplam kutusu + `KartIslemListesi`). Uç: `GET /api/v1/aile/kart-islemleri` → `Api\V1\Aile\KartIslemiController::index`.

## Tasarım
**Karar:** üçlü düğme tamamen kalkar (Ekstre dönemi de; yalnız "işlem tarihi ve özet ayı" denmişti ama tek başına kalan Ekstre dönemi seçeneği anlamsız; belirli ekstrenin satırları `ekstre_id` ile zaten açılıyor). `AySecici` de kalkar, yerini tarih seçici alır. Süzme **işlem tarihine** (`islem_tarihi`) göre yapılır.

**Arka uç** (`KartIslemiController::index`, geriye dönük uyumlu — `FaturaBagla` ve Özet bağlantıları `ay`/`donem_turu` kullanıyor, bunlar korunur):
- Yeni `tarih_bas` ve `tarih_bit` (nullable, `date_format:Y-m-d`, `tarih_bit >= tarih_bas`): `islem_tarihi` bu aralıkta. Tek gün seçilirse ikisi aynı. Biri boşsa açık uçlu. `ay` verilmişse (eski çağrılar) önceki davranış aynen.
- Yeni `ara` (nullable, max 100): kelimelere bölünür, her kelime AND; bir kelime `aciklama`, kategori adı veya kart adında geçerse (LIKE, `% _ \` kaçışlı, turkish_ci duyarsız) ya da kelime tutar biçimindeyse (`1.250,50`) `Kurus` ile tutar eşitliğinde eşleşir.
- `toplam` (gider / gidere girmeyen / satır) aynı koleksiyondan, yani seçilen tarih + aramaya göre hesaplanır. Not: tarih aralığı **işlem tarihine** göre olduğundan toplam artık Bütçe Özet'in aylık (rapor ayı) gider toplamıyla birebir aynı olmayabilir (kart gideri Özet'te ekstre kesim ayına yazılır); sayfada kutunun altına tek satır not konur.
- Hane kapsamı (`KartIslemi::hanenin`) aynen; 1000 limit aynen.

**Ön yüz** (`AileIslemlerView.vue`, `api/aile.ts` `KartIslemSuzgeci`: `tarih_bas`, `tarih_bit`, `ara`):
- **"İşlem ekle" düğmesi bu sayfadan kalkar** (kullanıcı isteği). Elle kart satırı ekleme özelliği, ekstresi henüz gelmemiş / ekstresi olmayan harcamayı rapora yansıtmak içindi (`KartIslemFormu`, `POST aile/kart-islemleri`, parmak izi ile tekrar engeli, 35 günden uzak ekstre kuralı). Düğme kalkınca ekleme için ön yüzde yol kalmaz; arka uç ucu ve form bileşeni **silinmez** (satıra dokununca düzeltme penceresi `sec(i)` ile açılmaya devam eder, `KartDetayView` de formu kullanıyor, `ButceRaporu` elle satırları saymaya devam eder). Sayfa başlığı satırında artık yalnız arama + tarih seçici bulunur; `aile.kartlar` boşken "kart yok" uyarısı gerekmez.
- Üst satır (tek satır) (mobilde alt alta, `sm:` ve üstünde yan yana): **arama kutusu** (`IconField` + `pi-search` + `InputText`, temizle ×, `flex-1`) ve en sağda **PrimeVue `DatePicker`** (`selection-mode="range"`, `show-button-bar`, Türkçe yerel ayar, `date-format="dd.mm.yy"`, `show-icon`, temizleme; tek tarihe tıklayıp bitişi seçmezse tek gün sayılır). Hızlı seçim çipleri (Bu ay, Geçen ay, Son 30 gün) tarih seçicinin altında küçük `Chip`'ler olarak (mobilde yatay kaydırma). Varsayılan: **bu ayın başı – sonu**.
- Eski bağlantılar: `?ay=2026-11` (Özet'ten) gelince aralık o ayın ilk-son günü olur; `?kart_id=` aynen çalışır. Adreste `tarih_bas`, `tarih_bit`, `ara` tutulur (mevcut `watch` + `router.replace` deseni). 300 ms debounce, `useSonIstek`; arama yazarken iskelet yok (kutu odağı kaybolmaz).
- Boş sonuç: "Bu süzgeçte işlem yok" yerine arama/tarih varken "Eşleşen işlem yok" + "Süzgeçleri temizle".
- Kaldırılan: `donemTuru`, `DONEM_TURLERI`, `SelectButton` ve `AySecici` importları (bu sayfadan).

## Aşamalar (tek commit + push; ILERLEME.md; CLAUDE.md'de kart işlemleri cümlesi: tarih/aralık + arama, "Özet ayı" düğmesi yok)
1. Arka uç: `tarih_bas/tarih_bit/ara` + Pest testleri (aralık dahil uçlar, tek gün, açık uçlu, bit<bas → 422; ara: açıklama / kategori / kart / tutar / çok kelime / `%_` kaçışı; toplam arama+tarihe göre; **başka hane göremez**; eski `ay`+`donem_turu` testleri değişmeden geçer).
2. Ön yüz: arama + DatePicker + çipler + adres eşitleme + Vitest (debounce'lu tek istek, tarih aralığı parametreleri, tek gün, `?ay=` → aralık, temizle, boş sonuç).
3. Edge e2e 320 / 360 / 1280 px: DatePicker mobilde taşmıyor (panel `append-to="body"`), yazınca/tarih seçince liste süzülüyor.

## Doğrulama
`DB_DATABASE=finans_test php artisan test`; `npm run type-check && npm run lint && npx vitest run`; Pint / prettier yalnız değişen dosyalara; Edge'de elle deneme. Canlıya yayın yalnız kullanıcı onayıyla.

## Kapsam dışı
Yatırım İşlemler sayfası, `FaturaBagla` ve Özet'teki ay mantığı, sayfalama.

---

# İŞ 2 (İş 1'den hemen sonra, kullanıcı: "grafik işleri ile birlikte hepsini sırayla yap"): Faturalar, Kartlar, Gelirler dönem çizgi grafikleri

## Context
Kullanıcı: Faturalar ve Kartlar sayfalarında dönemler halinde çizgi grafik. Faturalarda veri: toplam + her faturanın (türün) aylık kazanca oranı. Kredi kartlarında: toplam + her kartın dönem sonu ödeme tutarı. Maaş ve ek derste hem toplam hem bireysel grafik. Mobil öncelikli; grafik monoton değil, hareketli ve modern.

## Keşif
- Chart.js 4.5 + PrimeVue `Chart`; `components/VeriGrafigi.vue` (tur, `seriler[{ad,veri,renk}]`, `cssRengi`, `GRAFIK_RENKLERI`, `degerBicimi`) yeniden kullanılır; animasyon ayarı yok.
- Dönemli uç yalnız `GET aile/tasarruf-ozeti` (`ButceRaporu::tasarrufDonemleri`). `ButceRaporu::gelirOzeti` (kişi + tür kırılımı, tek ay), `faturaOzeti` (tek ay, `turler[]`), kart gideri = onaylı ekstre `donem_borcu` (kesim ayına göre).
- "Ek ders" varsayılan gelir türünde yok (hane ekler) → ada göre sabit değil, grafikte **tür seçici**; varsayılan seçili: "Maaş" ve adında "ek ders" geçenler.

## Tasarım
**Arka uç:** `GET aile/grafikler?yil=YYYY` → `App\Services\Aile\DonemGrafikleri` (ButceRaporu yöntemleri + `Kurus`/`Donem` yeniden kullanılır). 12 dönem için: `gelir` {toplam[], kisiler[{uye,toplam[]}], turler[{gelir_turu_id,ad,toplam[],kisiler[{uye,toplam[]}]}]}; `faturalar` {toplam[], turler[{fatura_turu,tutar[],gelire_oran[]}], toplam_oran[] (gelir 0 → null, uydurulmaz)}; `kartlar` {toplam[], kartlar[{kart_id,ad,renk,tutar[]}]} (onaylı ekstre, kesim ayı, `donem_borcu`). Para kuruş (int). Hane izolasyonlu.
**Ön yüz:** `components/grafik/ZamanCizgiGrafigi.vue` (VeriGrafigi'ni genişletir): yumuşak eğri, degrade alan dolgusu, soldan sağa çizilme + seri gecikmesi, vurgulu son nokta, `interaction: index` ipucu, dokunmatik renkli seri açma/kapama çipleri, `prefers-reduced-motion`'da animasyon kapalı, koyu/açık tema; yükseklik ~220 px, kısa ay etiketleri, yıl önceki/sonraki düğmeleri. Yerleşim: `FaturalarView` üstü (Tutar / Gelire oran % anahtarı; toplam + tür çizgileri), `KartlarView` üstü (kalın toplam + her kart kendi `renk`inde, dönem sonu ödeme), Gelir sekmesinde "Maaş / Ek ders" akordiyonu (tür seçici + Toplam / Kişiler görünümü). Boş veri metni. `api/aile.ts` `grafikler(yil)`.

## Aşamalar (her biri ayrı commit + push; plan `docs/planlar/donem-grafikleri.md`, ILERLEME / CLAUDE.md)
1. Arka uç `DonemGrafikleri` + uç + Pest (kırılımlar, oran, kart dönem sonu, **başka hane göremez**, gelir 0 → oran null, taslak ekstre sayılmaz).
2. Ön yüz bileşeni + api + Vitest (dönüşüm, boş durum, seri açma/kapama).
3. Üç sayfaya yerleştirme + Edge e2e (320/360/1280, taşma yok, animasyon çalışıyor).
4. Belgeler.

## Genel sıra ve doğrulama
İş 1 (arka uç → ön yüz → e2e) bitip commit/push edilir, sonra İş 2. Her iş sonunda tüm testler (`php artisan test`; `npm run type-check && npm run lint && npx vitest run`), Pint/prettier yalnız değişen dosyalara. Canlıya yayın / bilgisayar kapatma yalnız kullanıcı açıkça isterse.
