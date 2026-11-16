# Şirket geçmişi + renkli tarih kutusu (2026-11-16)

Kullanıcı Bütçe → İşlemler'deki satır rengini (sağa sönen kategori gradient'i) beğenmedi. Görsel seçeneklerden şunları seçti:
- **D: renkli tarih kutusu.** Gün ve kısa ay, kategori renginin açık zemininde koyu yazıyla.
- **1: harcama satırında, tutarın sağında grafik düğmesi.**
- **C: tam ekran pencere.** Üstte toplam / aylık ortalama / bu ay ve aylık eğri; altında her ayın başlığı toplamıyla, altında o ayın harcamaları.

## Yapılanlar
- Arka uç:
  - `App\Services\Aile\SirketGecmisi` ve `GET aile/kart-islemleri/{islem}/gecmis` (`KartIslemiController::gecmis`, `hane-kaydi`).
  - Aynı şirket, `Metin::sade` eşitliğiyle bulunur (kategori kuralıyla aynı ölçüt). Hanenin bütün kartlarındaki onaylı satırlar ile onlara bağlı iadeler alınır.
  - Satırlar işlem ayına göre gruplanır. Tutar etkisi `ButceRaporu::giderEtkisi`'dir. Para kuruşla hesaplanır.
  - Test: `SirketGecmisiTest`.
- Ön yüz:
  - `KartIslemListesi` (tarih kutusu, düğme) ve `SirketGecmisiDialog`.
  - `kategoriKutusu`, `aileApi.kartIslemiGecmisi`.
  - Test: `islemKategori.spec.ts`.

## Bilinen sınır
Banka açıklamaya şube adı ya da numarası ekliyorsa ("MARKET 1234 İSTANBUL" ile "MARKET 5678 ANKARA") bunlar ayrı şirket sayılır. Gerekirse eşleştirme gevşetilir.

## Doğrulama
- Ön yüz: type-check, lint ve vitest 146/146 geçti.
- Arka uç: `php artisan test`, MySQL açılınca çalıştırılacak.
