# Bütçe: Gelir/Gider tam ekran, sabit kart boyu, Altın/Döviz "Gün", sonraki taksitler akordiyonu

## Context
Önceki Bütçe arayüzü paketi (0f5bbe8) ve Halkbank JSON düzeltmesi (df1b553) `main`'de, canlıda değil. Kullanıcı yeni istekler verdi (2026-11-15):
1. **Gelir/Gider sayfası:** "Gelir" / "Gider" yazıları kalksın; her panel ekranı tam kaplasın; yalnızca ekranın **sağ kenarında ince dikey çizgi** (gelir yeşil, gider kırmızı).
2. Üstteki **dönem seçici ekranı yatay kaplasın, 150 px yüksekliğinde** olsun.
3. **Kredi kartı kartları her durumda aynı yükseklikte** (şimdi "ekstre yok / var" içeriğe göre boy değişiyor).
4. **Altın/döviz "gün" sistemi:** her ay belli bir alım yapılır; alımlar **tasarruf** olarak kaydedilir; ayın kime verildiğini kullanıcı açıklamaya yazar.
5. Bütçe Özet'te **her kredi kartı için sonraki taksit toplamları**; **Taksitler ve Sonraki taksitler akordiyon** olsun.

Kararlar (kullanıcı): gün alımı **sabit miktar** (örn. 5 gr altın / 100 USD; tutar güncel fiyattan), kayıt **ay gelince kullanıcı onaylar** (otomatik yazılmaz).

## Keşif özeti
- `views/aile/GelirGiderView.vue`: ortak `AySecici`, üstte Gelir/Gider hızlı sekme düğmeleri (yazılı), `KaydirmaliPaneller` (`sutun=2`), her panelde 3 px yatay renkli çizgi (`data-test="panel-cizgi-*"`).
- `components/KaydirmaliPaneller.vue`: panel `w-[88%]` (dar), noktalar, oklar; `items-start` (içerik kadar boy).
- `components/aile/AySecici.vue`: `inline-flex` küçük hap; kullanan: GelirGiderView, AileOzetView, AileIslemlerView, FaturalarView (diğerleri değişmemeli).
- `views/aile/KartlarView.vue` slayt: `RouterLink` kart (`mt-8`/`mt-4` ile içerik boyu belirler; "Son ekstre borcu" bloğu yoksa daha kısa).
- Tasarruf: `TasarrufController@store` (portföy alışı `Islem` + `Tasarruf` satırı, `not` alanı var), `TasarrufFormu.vue` (hesap, varlık, miktar, fiyat, not), `ButceRaporu::tasarrufOzeti`. Düzenli gider şablonu desenı: `GiderKaynagi` + `GiderTakvimi::beklenenler` + gerçekleşen kayıt.
- Taksit: `ButceRaporu` `gider.taksitler` (rapor ayındaki taksitli harcama satırları; `taksit_no/taksit_sayisi/taksit_kalan`), `AileOzetView.vue:466` "Taksitler" bölümü.

## Tasarım

### A. Gelir/Gider (yalnız ön yüz)
- `KaydirmaliPaneller`: yeni props `tamGenislik` (panel `w-full`, kenar görünmez), `noktalar` (varsayılan true; Gelir/Gider'de false). Diğer kullanımlar (Kartlar vb.) aynen.
- `GelirGiderView`: hızlı sekme düğmeleri ve "Gelir/Gider" metinleri kalkar (aria-label'lar yerinde kalır: erişilebilirlik; ekranda yazı yok). Panelin yatay üst çizgisi kalkar; yerine panelin sağ kenarında `absolute inset-y-0 right-0 w-[3px]` dikey çizgi (`var(--p-emerald-500)` / `var(--p-red-500)`, `data-test` aynı kalır). Sayfa içindeki iç başlıklar ("Gelirler"/"Giderler" `<h2>`) `GelirlerView`/`GiderlerView`'de gözden geçirilip gizlenir.
- `AySecici`: yeni prop `genis`: `flex w-full h-[150px]` (kutu), büyük ay yazısı, büyük ok düğmeleri (en az 44 px). Yalnızca GelirGiderView `genis` kullanır.
- Panellerde sekme/ok yok: telefonda kaydırma, geniş ekranda oklar kalır.

### B. Kart boyları
- `KartlarView` slayt kartı sabit yükseklik (`h-52`, içerik `flex flex-col justify-between`; ekstre yoksa "Henüz ekstre yok" aynı alanı doldurur) ve şerit `items-stretch`; kart altındaki düğme satırı da aynı yükseklik. Test: farklı içerikli iki kartta `offsetHeight` eşit.

### C. Altın/Döviz "Gün" (backend + ön yüz)
- **Migration** (yeni, ekleyen): `tasarruf_gunleri` (id, hane_id, ad, hesap_id (portföy hesabı), varlik_id, miktar decimal(20,8), ay_gunu 1–28, baslangic_ay `YYYY-AA`, ay_sayisi nullable/ bitis_ay, aktif, olusturan_id) ve `tasarruflar.gun_id` nullable (FK, `nullOnDelete`). Tasarruf = alış `Islem` + `Tasarruf` satırı kuralı aynen; `not` = kullanıcının "kime verildi" açıklaması.
- **Model** `TasarrufGunu` (`HaneyeAit`, `ozet()`), **`GunTakvimi`** (GiderTakvimi deseni): verilen ayda bu gün için alım beklenir mi (başlangıç ≤ ay ≤ bitiş, aktif, o ay `gun_id`'li tasarruf yoksa "bekliyor"); durum: bekliyor / yapıldı.
- **Uçlar** (`TasarrufGunuController`, `hane-kaydi` Gate + hane süzgeci): `GET aile/tasarruf-gunleri?donem`, `POST` (oluştur), `PATCH` (ad, miktar, aktif, bitiş), `DELETE` (gün silinir; geçmiş tasarruflar kalır, `gun_id` null). Alım kaydı yeni uç açmaz: `POST aile/tasarruf`'a opsiyonel `gun_id` (aynı hane doğrulaması, o ay tekrar kaydı uyarısı) eklenir; ay içinde aynı gün için ikinci kez kayıt engellenmez ama uyarı verilir.
- **ButceRaporu**: `tasarrufOzeti` dönem satırlarına `gun_id` / gün adı eklenir; yeni `gunler` alanı (`GunTakvimi::beklenenler`) özete eklenir. `kalan` formülü değişmez (tasarruf zaten düşülüyor).
- **Ön yüz**: Bütçe Özet'te Tasarruf bölümünün altında **"Günler"** bölümü (akordiyon değil): gün kartı (ad, "5 gr Gram Altın · her ayın 15'i", bu ay durumu rozeti), bekleyen günde **"Bu ayın alımını yap"** → `TasarrufFormu` önceden doldurulmuş (hesap, varlık, miktar, `gun_id`; fiyat o günkü piyasa fiyatından önerilir, "Not" alanı "Kime verildi" etiketiyle öne çıkar). "Gün ekle" `TasarrufGunuFormu.vue` (ad, hesap, varlık, miktar, ay günü, başlangıç ay, kaç ay). Gün kaydı silme onaylı. Hatırlatma: bekleyen gün Özet uyarılarında görünür (okunurken hesaplanır, zamanlanmış iş yok).
- Kişisel veri/IBAN yok; TC/hesap no alanı eklenmez. Para tasarruf için mevcut Kurus/decimal kuralları.

### D. Sonraki taksitler + akordiyon
- **ButceRaporu** `gider.sonraki_taksitler`: rapor ayındaki taksitli harcama satırlarından kart başına: `kart_id`, `toplam` (kalan taksit tutarlarının toplamı: `taksit_kalan` varsa o, yoksa `tutar × (taksit_sayisi − taksit_no)`, yaklaşık işareti), `kalan_adet`, `kalemler` (islem_id, açıklama, kalan taksit sayısı, kalan toplam). Para kuruşla (`Kurus`).
- **AileOzetView**: "Taksitler" bölümü PrimeVue `Accordion` (`Accordion`/`AccordionPanel`, çoklu açık): panel 1 **Taksitler** (mevcut liste), panel 2 **Sonraki taksitler**: kart başına satır (`KartEtiketi`, "N taksit kaldı", toplam kalan tutar) ve genişleyince o kartın kalemleri. Varsayılan kapalı ya da yalnızca Taksitler açık; data-test'ler korunur (`taksit-satiri`).

## Aşamalar (her biri commit + push; plan `docs/planlar/butce-gun-taksit.md`, DEVAM/ILERLEME güncellenir)
1. Gelir/Gider tam ekran + dikey çizgi + 150 px dönem seçici + sabit kart boyu (yalnız ön yüz) + Vitest.
2. Sonraki taksitler (backend rapor + akordiyon) + testler.
3. Gün sistemi backend (migration, model, takvim, uçlar, `gun_id`) + Pest/PHPUnit testleri (hane izolasyonu "başka hane göremez/değiştiremez", kuruş doğruluğu, ay durumu).
4. Gün sistemi ön yüz (Günler bölümü, form, `TasarrufFormu` ön doldurma) + Vitest.
5. Tüm testler (`php artisan test`; `npm run type-check && npm run lint && npx vitest run`), Pint/prettier yalnızca değişen dosyalar, CLAUDE.md/ILERLEME, Edge e2e (320/360/1280). Yayın ayrı onayla.

## Doğrulama
- Vitest: `KaydirmaliPaneller` (`tamGenislik`, `noktalar=false`), GelirGiderView (yazı yok, dikey çizgi renkleri, AySecici `genis` yüksekliği 150), kart yüksekliği eşitliği, AileOzetView akordiyon (iki panel, kart başına toplam), Gün formu/Günler bölümü.
- Backend: `GunTest` (CRUD, hane izolasyonu, `gun_id` ile tasarruf, ay durumu bekliyor→yapıldı, başka hane günü ile tasarruf reddi), `ButceRaporuSonrakiTaksitTest` (kart başına toplam, `taksit_kalan` varken/yokken).
- Edge e2e: Gelir/Gider telefonda tam ekran + sağ kenar çizgisi, dönem seçici 150 px tam genişlik, kartlar eşit boy, Özet akordiyon, Gün ekle → bu ay alımı yap → Tasarruf toplamına yazılır; yatay taşma yok.

## Kapsam dışı
Gün alımının otomatik yazılması, gün alacağı/borç takibi (kimden ne alındı), kullanıcının kendi "alma" ayının ayrı modellenmesi (elle tasarruf/portföyden girilir), canlı yayın (ayrı onay).
