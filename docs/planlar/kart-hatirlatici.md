# Kart borç hatırlatıcısı: kendi sıram, Borç öde, Toplama ekle

Kullanıcı seçimi (2026-11-20): görsel öneriler artifact'ında 1A + 2A + 3A; saklama sunucuda (hane düzeyinde).

- **1A Sürükle-bırak:** hatırlatıcı başlığında "Düzenle" → tutamaklar (⋮⋮, parmak / fare; klavyede ↑ ↓). Sıra `kartlar.hatirlatici_sira`'da (hane); sırası olmayan kart sonda, tarihe göre. Uç `PUT aile/kartlar/hatirlatici-sirasi {kart_idler}`.
- **2A Borç öde:** satırda "Borç öde" → ekstre `odendi=true` (mevcut uç), satır listeden düşer, 5 sn "Geri al" (`useGeriAl`). Arka uç değişmez.
- **3A Toplama ekle:** satırda "+ Toplama ekle" / "✓ Toplamda" (`ekstreler.toplamda`, `PUT aile/ekstreler/{id}` alanı). Altta yapışkan çubuk: seçilen N kart, dönem borcu toplamı, asgari toplamı, "Temizle". Ödenen ekstre hatırlatıcıdan düştüğü için toplamdan da çıkar.
- Satırda asgari ödeme görünür; satıra dokununca ekstre açılır (eylemler satırın altında).
- Testler: arka uç sıra (hane izolasyonu), toplamda (onaylıda da); ön yüz sıra, sürükleme, öde + geri al, toplam.
