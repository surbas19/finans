# Zekât ekranı: otomatik anlık hesap (Hesapla düğmesi kalkar), varlık ayrıntısı gösterilmez

## Context
Kullanıcı (2026-11-15): "zekât ekranında ödenen zekâtların üstünde **anlık hesap** gözüksün; **Hesapla düğmesini kaldır, otomatik hesapla ve göster**; varlık ayrıntılarını göstermene gerek yok."
Önceki işler canlıda değil (yalnız son zekât yıl bazlı değişiklik commit'li: `83da59d`); bu küçük bir ön yüz işi, backend değişmez (`POST aile/zekat/hesapla` aynen).

## Keşif özeti
- `views/aile/ZekatView.vue`: zekât günü kartında "Ayarlar" + **"Hesapla"** (`data-test="zekat-hesapla"`, `hesapla()` → `aileApi.zekatHesapla()`), sonuç `sonuc` varsa `ZekatSonucKarti` + "Anlık görüntüyü kaydet"; altında yıl bazlı "Verilen zekât" bölümü ve "Yıl geçmişi". `ayarKaydedildi` sonucu sıfırlıyor.
- `components/zekat/ZekatSonucKarti.vue`: özet kutuları (zekâta tabi toplam, borç, net, nisab), ödenecek zekât kutusu, düşülen borçlar, **`zekat-kalemler` (hesaba göre varlık ayrıntıları)**, notlar, sabit uyarı; hem canlı önizleme hem kayıtlı yıl penceresi (`detaySonucu`) kullanır.
- Hesap hatası (nisab fiyatı yok) sunucudan 422 gelir ve `zekat-hata` mesajında gösterilir (uydurulmaz).

## Tasarım (yalnız ön yüz)
- **Otomatik hesap:** `ZekatView` yüklenince (`onMounted`, ayar + yıllar + dönemler yüklendikten sonra) `hesapla()` çağrılır; "Hesapla" düğmesi ve `data-test="zekat-hesapla"` kalkar. Ayarlar kaydedilince (`ayarKaydedildi`) yeniden otomatik hesaplanır. Hesaplama sürerken küçük iskelet, hata olursa mevcut `zekat-hata` mesajı (nisab fiyatı yoksa uydurma yok). Zekât ödemesi / silme sonrası zekât tutarı değişmediği için yeniden hesap gerekmez.
- **Yerleşim:** zekât günü / ayarlar kartının altında **anlık hesap kartı** (başlık "Anlık zekât hesabı", portföyün şu anki değeri), onun altında "Anlık görüntüyü kaydet" düğmesi, **ardından** "Verilen zekât (Ramazan'dan Ramazan'a)" ve "Yıl geçmişi" — yani anlık hesap ödenen zekâtların üstünde.
- **Ayrıntısız:** `ZekatSonucKarti`'ya `kalemlerGoster` prop'u (varsayılan `true`); anlık hesapta `false` → hesaba göre varlık listesi gösterilmez (özet kutuları, ödenecek zekât, borçlar, "eksik veri" uyarısı, notlar ve sabit uyarı kalır; eksik veri uyarısı doğruluk için zorunludur). Kayıtlı yıl ayrıntı penceresi (değişmez anlık görüntü) kalemleriyle aynen kalır.
- Sabit uyarı "Bilgilendirme amaçlıdır, dini hüküm değildir; din görevlisine danışın." sayfada zaten üstte; kartta tekrarı kalır.

## Aşamalar (commit + push; DEVAM / ILERLEME satırı)
1. `ZekatSonucKarti` `kalemlerGoster`, `ZekatView` otomatik hesap + yerleşim, Hesapla düğmesi kaldırma.
2. Vitest + Edge e2e; belgeler (CLAUDE.md zekât cümlesi: "ekran açılınca otomatik hesaplar").

## Doğrulama
- Vitest: `ZekatSonucKarti` `kalemlerGoster=false` iken `zekat-kalemler` yok, özet kutuları ve eksik veri uyarısı var; `true` iken (kayıtlı yıl) kalemler var. `ZekatView` mount testi: yüklenince `zekatHesapla` bir kez çağrılır, `zekat-hesapla` düğmesi yok, anlık hesap "Verilen zekât" bölümünden önce gelir, hata mesajı gösterilir.
- Tarayıcı (Edge 320 / 360 / 1280): Yönetim → Zekât açılınca düğmeye basmadan hesap görünür, varlık listesi yok, sırayla: ayar kartı → anlık hesap → kaydet → verilen zekât → yıl geçmişi; ayar değişince hesap yenilenir; taşma yok.
- `php artisan test` (değişmez), `npm run type-check && npm run lint && npx vitest run`.

## Kapsam dışı
Zekât hesabı kuralları, API, kayıtlı yıl penceresinin içeriği, canlı yayın.
