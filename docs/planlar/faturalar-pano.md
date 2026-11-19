# Faturalar: ödeme panosu + sağlayıcı geçmişi

Kullanıcı seçimi (2026-11-19): görsel öneriler artifact'ında **A (ödeme panosu)**, B'nin sağlayıcı geçmişi penceresi A'daki detay penceresine "Geçmiş" bağlantısı olarak.

## Arka uç
- `App\Services\Aile\FaturaOzeti`:
  - `ay(haneId, donem)`: toplam / ödendi / bekliyor / gecikti (kuruşla), adetler, önceki ay toplamı, dönemi bilinmeyen sayısı.
  - "Gelmedi": aktif sağlayıcı, son 3 ayda faturası var, bu ayda yok; son faturanın dönemi ve tutarıyla.
  - `saglayici(saglayici)`: adet, aylık ortalama, en yüksek (dönemiyle), son 12 ay toplamı, son 12 ayın serisi (boş ay null), bütün faturalar (yeniden eskiye).
- Uçlar `GET aile/faturalar/ozet?donem=` ve `GET aile/saglayicilar/{saglayici}/gecmis` (hane-kaydi). Testlerde başka hane göremez.

## Ön yüz
- `FaturalarView` yeniden:
  - Ay çarkı.
  - Özet kartı: N / M geldi, toplam, önceki aya göre %, ödendi / bekliyor / gecikti çubuğu, "Fatura" ekle düğmesi.
  - "Dönemi bilinmeyen" uyarı şeridi.
  - Tür çipleri.
  - Gruplar:
    - Gecikmiş ve Bekleyen (son ödemesi yakın önce; "Gelmedi" soluk satırları, dokununca o sağlayıcı + ay ile fatura formu).
    - Ödenen.
  - Satır tek katlı; bekleyende "Ödendi ✓".
  - Grafik en altta kapalı.
- `FaturaDetayi` (alt pencere): alanlar, kart bağı (bağla / bağı çöz), Ödendi işaretle, Düzenle, Geçmiş.
- `SaglayiciGecmisiDialog`: ortalama / en yüksek / 12 ay, eğri, ay ay liste (dokununca detay).
- `DonemBilinmeyenDialog`: liste, satırda öneri ayı (ödeme ya da son ödeme tarihinin ayı) "Uygula", "Önerileri uygula".
- `DonemGrafikKarti` `kapali` özelliği.

## Testler
- Arka uç `FaturaOzetiTest`.
- Ön yüz `faturalarPano.spec`: gruplar, gelmedi satırı, detay, geçmiş, öneri.
