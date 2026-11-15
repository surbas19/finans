# Zekât ödemeleri: İşlemler'den Yönetim → Zekât'a, Ramazan–Ramazan yıl bazında; verilen ve kalan

## Context
Kullanıcı (2026-11-15): "zekât ödemesi kısmını İşlemler'den alıp Yönetim'deki zekât sayfasına alalım. Zekât ödemelerini **yıl bazında** toplayalım; **Ramazan'dan Ramazan'a** toplanıp verilmesi gerekir; **verilen ve kalanları göster**."
Önceki paket canlıda (`eb04742`).

## Keşif özeti
- Veri: `zekat_odemeleri` (`zekat_yili_id` nullable, tarih, tutar, alıcı, not); İşlemler'den girilen ödemelerin hepsi **yıla bağsız** (`yilId=null`). Yıl kaydı `ZekatYili` (`zekat_gunu`, `zekat`, `odenen` = yalnız bağlı ödemeler, `kalan`), sadece **kesinleşmiş** yıla bağlanabilir.
- Zekât günü ayarda (varsayılan hicrî **1 Ramazan**; miladî seçenek de var): `ZekatTakvimi::ilk/sonrasi`, `HicriTakvim`. Yıl = bir zekât gününden sonrakine kadar.
- UI: `IslemlerView` (Zekât ödemesi düğmesi, `zekatYukle`, `zekatSil`, `ZekatOdemeFormu`), `IslemListesi` (`zekatOdemeleri` prop, "Zekât" rozetli satır), Yönetim → Zekât `ZekatView` (yıl listesi + yıl ayrıntısında ödemeler / ödeme ekle), `ZekatOdemeFormu`.
- Bağımlılar: `ZekatHatirlatici` ("ödenmemiş zekât" uyarısı) ve Özet bilgi satırı `ZekatYili` odenen / kalanını kullanır.

## Tasarım

### A. İşlemler'den kaldırma (ön yüz)
`IslemlerView`: "Zekât ödemesi" düğmesi, zekât yükleme / silme, `ZekatOdemeFormu` kalkar; boş durum yalnız işlemlere bakar. `IslemListesi`: `zekatOdemeleri` prop'u, `zekat` satır türü, `zekatSil` emit'i, "Zekât" rozeti kalkar (liste yalnız işlem). İlgili Vitest'ler güncellenir. CLAUDE.md: "verilen zekât ödemeleri ana İşlemler listesinde…" cümlesi "Yönetim → Zekât'ta yıl bazında" olur. API ucu `zekat/odemeler` aynen kalır.

### B. Ramazan–Ramazan yıl bazında gruplama (backend)
- Yeni servis `App\Services\Zekat\ZekatDonemleri`: bir **zekât yılı** (dönem) = zekât gününden bir sonraki zekât gününe kadar `[başlangıç, bitiş)`. Sınırlar: kayıtlı (`zekat_yillari`) `zekat_gunu` değerleri varsa onlar (bir kayıttan sonraki kayda ya da ayardan hesaplanan sonraki zekât gününe kadar), kayıt olmayan tarihler için `ZekatTakvimi` ile ayardan (geriye doğru bulunur: `ilk(gün − 400)` ardından `sonrasi` zinciri). Hicrî gün tahminî olduğundan eşleşme ±7 gün toleranslıdır.
- **Otomatik bağlama**: `ZekatOdemeController@store/update` — `zekat_yili_id` boşsa ödeme tarihini kapsayan **kesinleşmiş** yıl bulunur ve bağlanır (kalan doğru çıksın); bulunamazsa bağsız kalır. **Geriye dönük**: yeni migration mevcut bağsız ödemeleri aynı kuralla (yalnız kesinleşmiş yıla) bağlar (veri kaybı yok, geri alınabilir `down`).
- **Uç** `GET aile/zekat/donemler` (`ZekatController` ya da `ZekatDonemController`, hane süzgeci): en yeniden eskiye dönem listesi — ödemesi ya da kaydı olan her yıl + **içinde bulunulan yıl** (ödeme olmasa da). Her dönem: `baslangic`, `bitis`, `etiket` ("Ramazan 1447 → Ramazan 1448" + miladî tarihler, tahminî işareti), `guncel` bayrağı, `yil` (varsa kayıt: id, durum, nisab_ustu), **`gereken`** (kesinleşmiş / taslak kaydın zekâtı; kayıt yoksa `null` = "henüz hesaplanmadı"), **`verilen`** (dönemdeki bütün ödemelerin toplamı: bağlı + tarihle düşen bağsız), **`kalan`** = `max(0, gereken − verilen)` (gereken yoksa `null`), `fazla` (verilen > gereken ise fark), `odemeler[]` (alıcı, tarih, not). Para kuruşla (`Kurus`).
- Hane izolasyonu + "başka hane göremez / değiştiremez" testi; zekât uyarısı metinleri aynı.

### C. Yönetim → Zekât ekranı (ön yüz)
`ZekatView`'a yeni bölüm **"Verilen zekât (Ramazan'dan Ramazan'a)"** (`data-test="zekat-donemleri"`), yıl listesinin üstünde:
- PrimeVue `Accordion` (içinde bulunulan yıl açık): başlıkta dönem etiketi + `Gereken / Verilen / Kalan` üç küçük rakam (kalan 0 ise yeşil "Tamamlandı", fazla verildiyse "Fazla"); gereken yoksa "Zekât henüz hesaplanmadı" ve yalnız verilen.
- İçerik: o yılın ödemeleri (tarih, alıcı, not, tutar, sil düğmesi) ve dönem toplamı; **"Ödeme ekle"** (`ZekatOdemeFormu`, tutar varsayılan = kalan; ödeme tarihi dönemin içindeyse otomatik o yıla bağlanır).
- Üstte genel "Zekât ödemesi ekle" düğmesi (`data-test="zekat-odemesi-ekle"`, yıl bağsız; sunucu tarihe göre bağlar). Zekât uyarı metni ("Bilgilendirme amaçlıdır, dini hüküm değildir; din görevlisine danışın.") bölümde de görünür.
- Yıl ayrıntı penceresindeki mevcut ödeme listesi aynı bileşenle uyumlu kalır.

## Aşamalar (commit + push; plan `docs/planlar/zekat-odemeleri-yillik.md`; DEVAM / ILERLEME)
1. Backend: `ZekatDonemleri` + uç + otomatik bağlama + migration (geriye dönük bağlama) + testler.
2. Ön yüz: İşlemler'den kaldırma + testler güncellemesi.
3. Ön yüz: `ZekatView` yıl bazlı bölüm + Vitest.
4. Tüm testler, Pint / prettier, belgeler, Edge e2e (320 / 360 / 1280). Yayın ayrı onayla (şimdilik yalnız commit + push).

## Doğrulama
- `ZekatTest` / yeni `ZekatDonemTest`: ödemeler tarihe göre Ramazan–Ramazan dönemlerine düşer (sınır günü, geçen yıl / bu yıl); bağsız ödeme kesinleşmiş yıla otomatik bağlanır; kayıt yoksa dönem ayardan hesaplanır ve `gereken=null`; verilen toplamı, kalan (`max(0)`), fazla; içinde bulunulan yıl ödemesiz de listelenir; miladî ayar; kuruş doğruluğu; migration bağsızları doğru yıla bağlar; hane izolasyonu.
- Vitest: `ZekatView` dönemler bölümü (gereken / verilen / kalan, tamamlandı / fazla, ödeme ekle, sil); `IslemlerView` / `IslemListesi` artık zekât içermez.
- Tarayıcı: Yönetim → Zekât'ta ödeme ekle → ilgili Ramazan yılında görünür, kalan düşer; İşlemler'de "Zekât ödemesi" yok; 320 / 360'ta taşma yok.
- `php artisan test`, `npm run type-check && npm run lint && npx vitest run`.

## Kapsam dışı
Zekât hesabı kuralları (nisab, tür kuralları) değişmez; kesinleşmiş yıl kaydı düzenlenmez; ödemelerin başka yıla elle taşınması (tarih değişince yeniden bağlanır); canlı yayın.
