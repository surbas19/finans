> Durum: Aşama 1-3 tamamlandı (arka uç + arayüz + testler). Aşama 4 kapsam dışı. Sapmalar: hicrî yedek algoritma Ümmülkura'dan en çok 2 gün ayrışır; ek uçlar GET zekat/odemeler, PUT zekat/yillar/{yil} (taslak notu); hesap bayrağı zekat/ayar PUT 'hesaplar' ile de değişir.

﻿# BÖLÜM B — Zekât hesabı ve takibi (portföydeki hesapların toplam değeri üzerinden)

## Context
Kullanıcı, hesaplardaki toplam değer üzerinden zekât hesaplayıp zekât takibi yapan bir sistem istiyor. Zekât, portföyün bir **görünümü**dür: değerleme tek kaynak olan `App\Services\Portfoy\PortfoyHesabi::hesapla($user)` çıktısından (`pozisyonlar`: `hesap_id`, `varlik{tur,kod}`, `miktar`, `deger` TL; USD fiyatlılar USD/TRY ile) okunur, ayrı değerleme yazılmaz. Her ekranda ve çıktıda sabit uyarı: "Bilgilendirme amaçlıdır, dini hüküm değildir; din görevlisine danışın."

## Tasarım kararları
- Kurallar sabit kodlanmaz: hane başına `zekat_ayarlari`; varsayılanlar `ZekatVarsayilanlari`. Para **kuruş** (`Kurus`) ile hesaplanır; oran `intdiv(x * oran_binde, 1000)` (yarım kuruş yukarı).
- **Basitleştirilmiş hawl:** nisab yalnızca zekât gününde kontrol edilir (arayüzde açıkça yazılır). Yıl içi nisab altı uyarısı kapsam dışı (aşama 4).
- Anlık görüntü (snapshot) **değişmez kayıt**; hesap / varlık adları ve fiyatlar kopyalanır (sonradan silme / fiyat değişikliği geçmişi bozmaz).
- Fiyatı olmayan kalem sıfır sayılmaz: `eksikler` + "eksik veri, kesin değil" (CLAUDE.md maliyeti bilinmeyen ilkesinin karşılığı). Nisab fiyatı bulunamazsa hesap hata verir, uydurulmaz. Nisab ve değerleme `GRAM` varlığının güncel `Fiyat.fiyat` (alış) fiyatıyla; gümüş `GUMUS`.

## Veri (migration'lar geri alınabilir; hepsi `hane_id`, cascade; `HaneYonetimi::AILE_TABLOLARI`'na eklenir)
1. `…_add_zekat_to_hesaplar_table`: `hesaplar.zekata_dahil` (bool, varsayılan true), `zekat_notu` (nullable). `HesapController`, `Hesap::ozet()`, `HesapFormu.vue` ("Zekât hesabına dahil et").
2. `…_create_zekat_tables`:
   - `zekat_ayarlari` (hane başına tek): `nisab_olcutu` (altin|gumus; varsayılan **altin**), `nisab_altin_gram` 80.180, `nisab_gumus_gram` 561.000, `oran_binde` 25, `tur_kurallari` json (tür → dahil + kapsam %; varsayılan **hepsi dahil %100, BES tam**), `borc_dus` (varsayılan **false**), `borc_kaynaklari` json `{kart, fatura, elle}`, `yalniz_gunde_nisab` true, `zekat_gunu_turu` (hicri|miladi), `hicri_ay/gun`, `miladi_ay/gun`, `sonraki_zekat_gunu`, `hatirlatma_gun` 30.
   - `zekat_yillari`: `zekat_gunu` (unique hane+gün), `durum` taslak|kesinlesti, `ayar_kopyasi` json, `brut`, `borc`, `net`, `nisab` (decimal 15,2), `nisab_ustu`, `zekat`, `usd_kuru`, `nisab_birim_fiyat`, `eksik_veri`, `hesaplandi_at`, `not`.
   - `zekat_yili_kalemleri` (kopya alanlı: `hesap_ad`, `varlik_kod/ad/tur`, `miktar`, `birim_fiyat_tl`, `deger`, `dahil_edilen`; `hesap_id` nullOnDelete), `zekat_yili_borclari`, `zekat_borclari` (elle borç), `zekat_odemeleri` (`zekat_yili_id` nullable, `tarih` ≤ bugün, `tutar`, `alici` serbest ad [IBAN / hesap no istenmez], `not`, `uye_id`, `olusturan_id`).
   - Modeller `ZekatAyari`, `ZekatYili`, `ZekatYiliKalemi`, `ZekatYiliBorcu`, `ZekatBorcu`, `ZekatOdemesi` (`HaneyeAit`, `ozet()` + `Kurus::tl`).

## Servisler (`app/Services/Zekat/`)
- `ZekatHesabi::hesapla(User, ?CarbonInterface)`: ayar → `PortfoyHesabi::hesapla` → bayrağı kapalı hesapları ve tür kuralıyla dışlananları ele → kalemler (kuruş) → brüt → borç düşme açıksa (onaylı `odendi=false` ekstre `donem_borcu`, vadesi gelmiş bekleyen `Fatura`, elle `zekat_borclari`; hane süzgeciyle) → net = max(0, brüt − borç) → nisab = gram × güncel TL fiyat → `net ≥ nisab` ise zekât = net × oran. Çıktı: `kalemler, brut, borc, net, nisab{olcut,gram,birim_fiyat,tl}, nisab_ustu, zekat, eksikler, fiyat_zamanlari, usd_kuru, uyari`. Yazmaz (önizleme).
- `ZekatYilKaydi` (önizlemeden taslak snapshot, kesinleştirme = salt okunur, silme; çift gün `unique` + işlem içi), `ZekatTakvimi` + `App\Support\HicriTakvim` (PHP `intl` `IntlCalendar` islamic-umalqura; yoksa saf PHP fallback ±1 gün, arayüzde "tahminî"; hicrî yıl ~354 gün → "+354 sabiti" kullanılmaz, her kapanışta yeniden hesaplanır), `ZekatHatirlatici` (okunurken hesaplanır; zamanlanmış komut gerekmez), `ZekatVarsayilanlari`.
- Bütçe bağı: `ButceRaporu::uyarilar($haneId)` çıktısına `zekat` (zekât gününe N gün / ödenmemiş zekât / gün geçti kayıt yok); özet yanıtına **yalnızca bilgi** satırı `zekat{verilen}` (ödeme tarihinin ayı); `kalan` formülü **değişmez**.

## Uçlar (`routes/api.php`, `aile` grubu; controller'lar `Api\V1\Aile\ZekatAyarController`, `ZekatController`, `ZekatOdemeController`, `ZekatBorcController`; `Gate 'hane-kaydi'`, `->hanenin($user)`, `Rule::exists(...)->where('hane_id')`)
`GET/PUT aile/zekat/ayar` · `POST aile/zekat/hesapla` · `GET/POST aile/zekat/yillar` · `GET/DELETE aile/zekat/yillar/{yil}` · `POST .../kesinlestir` · `GET/POST/DELETE aile/zekat/borclar` · `POST aile/zekat/odemeler`, `PUT/DELETE .../{odeme}`. Hesap bayrağı mevcut hesap uçlarıyla.

## Arayüz (mobil öncelikli; PrimeVue 4.x, yeni paket yok)
`AileDuzeni` sekmesi **Zekât** (`aile-zekat`, gerekirse `aile-zekat-yil`); `views/aile/ZekatView.vue`, `components/zekat/ZekatAyarlari.vue` (nisab ölçütü, oran, zekât günü hicrî / miladî, tür kuralları, borç düşme, hesap dahil anahtarları; ön ayar düğmeleri "Altın nisabı / Hanefî gümüş" yalnızca alanları doldurur), `ZekatSonucKarti.vue` (brüt, borç, net, nisab, zekât, kalem kartları hesaba / türe göre, eksik fiyat uyarısı, "zekât günü dışında tahmini"), `ZekatOdemeFormu.vue`, `ZekatYilListesi.vue`. Akış: ayarlar → hesapla → anlık görüntüyü kaydet → kesinleştir → ödeme ekle → yıl geçmişi (tutar, ödenen, kalan). `api/aile.ts` tipleri, Bütçe Özet'te zekât uyarı kartı ve "verilen zekât" bilgi satırı.

## Aşamalar (her aşama: testler + commit + push dalında; DEVAM / ILERLEME; `docs/planlar/zekat.md`)
1. **Çekirdek:** hesap bayrağı + ayar + `ZekatHesabi` (borçsuz) + ayar / hesapla uçları + `ZekatView` (ayar + hesapla).
2. **Takip:** snapshot, kesinleştirme, yıl geçmişi, ödemeler, kalan borç, `HicriTakvim` + `ZekatTakvimi`.
3. **Bütçe bağı:** borç düşme (ekstre, fatura, elle borç), `ZekatHatirlatici` uyarısı, "verilen zekât" bilgi satırı.
4. (İsteğe bağlı, kapsam dışı bu turda) tarihli değerleme, yıl içi nisab altı uyarısı, yazdırılabilir özet.

## Doğrulama
`ZekatTest` (fiyatlar elle `Fiyat` ile, gerçek kaynak yok): nisab altı / üstü + kuruş yuvarlama; USD / altın değerleme, fiyatsız kalem `eksikler`; gümüş ölçütü; tür kuralları (hisse kapalı, BES tam); hesap bayrağı + eşin hesabı dahil; borç düşme açık / kapalı (ödenmiş ekstre düşmez, net < 0 olmaz); snapshot sonradan fiyat değişse de değişmez, aynı gün ikinci kayıt reddedilir, kesinleşmiş düzenlenemez; kısmi ödeme / kalan / ileri tarih reddi; hicrî sonraki gün (~11 gün erken) ve `intl` yoksa fallback (`HicriTakvimTest`); hatırlatma eşiği; **her uç için hane izolasyonu** (başka hane ayar / yıl / ödeme / borç görmez, değiştiremez; başka hanenin hesap / ekstre / faturası hesaba karışmaz). Vitest: sonuç kartı + ödeme formu doğrulaması. `php artisan test`; `npm run type-check && npm run lint && npx vitest run`; Edge uçtan uca (320 / 360 / 1280).

## Riskler
Fıkhî yorum farkı (kurallar ayarlı, varsayılan "başlangıç değeri" etiketli, uyarı zorunlu); fiyat doğruluğu (kaynaklar resmî değil → snapshot'a fiyat zamanı, elle fiyat zaten mümkün); `intl` yokluğu (fallback ±1 gün + elle tarih); float → kuruş yuvarlaması tek yerde; BES maliyeti bilinmiyorsa tam tutar + uyarı; Bütçe `kalan` sözleşmesine dokunulmaz.

## Kapsam dışı (bu tur)
Kişi bazlı ayrı zekât hesabı, tarihli geçmiş gün değerlemesi, yıl içi nisab altına inme takibi, verilen zekâtın gidere / kalandan düşülmesi.
