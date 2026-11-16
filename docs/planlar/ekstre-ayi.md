# Ekstre ayı = son ödeme ayı (2026-11-16)

## Karar
Kullanıcı ekstrenin dönemini son ödeme tarihinin ayından otomatik almak istedi: 20.11.2026 son ödeme tarihli ekstre "Kasım 2026 ekstresi" olur. Son ödeme tarihi okunamadıysa kesim tarihinin ayı kullanılır.

Kullanıcı ayrıca şu iki kararı verdi:
- **Bütçe:** kart gideri son ödeme ayına yazılır.
- **Fatura:** kartla ödenen faturanın hizmet dönemi, açıklamada yoksa ekstrenin ayıdır.

## Değişenler
- Tek kaynak `Ekstre::ay()` / `ayTarihi()` / `scopeAyinda()`:
  - `ozet().donem`, `donemEtiketi()`
  - ButceRaporu kart gideri
  - İşlemler `donem_turu=ekstre` süzgeci
  - taksit rapor ayı (`KartIslemKurallari::raporDonemi`)
  - fatura hizmet dönemi önerisi (`FaturaEslestirici::guncelDonem` / `bosDonemiTamamla`; `donem_kaynagi` değeri `kesim` kalır, etiket "ekstrenin ayı" olur)
  - tekrar uyarısı
- Değişmeyenler:
  - Ekstreye girmemiş elle satırın "35 gün" kuralı ve aynı ekstrenin ikinci kez onaylanmaması kesim tarihine bakmaya devam eder.
  - Sıralama ve "güncel ekstre" de kesim tarihine bakar.
- Veri (migration):
  - Onaylı taksit satırlarının `rapor_donemi` değeri ekstre ayıyla yeniden yazılır.
  - Kaynağı `kesim` olan faturaların `hizmet_donemi` değeri ekstre ayına alınır; aynı sağlayıcının o ayda başka faturası varsa değişmez.

## Doğrulama
- `php artisan test`: ekstre / bütçe / fatura testleri yeni kurala göre güncellenir, yeni test eklenir (son ödeme bir sonraki aya düşen ekstre).
- Ön yüz testleri.
