# Defter (2026-11-22)

Kullanıcı isteği: aylık tüm işlemleri listeleyen, sonradan silinmeyen / değiştirilemeyen, kategorili, el yazısı, renkli yazılı defter sayfası. Seçim: sekmeli ajanda; en üstte kalem başına adet + değer değişimi ve ay sonu değerlendirmesi; temettü, borç / alacak; ay bittikten sonra yapılan değişiklikler "Sonradan değişiklikler" kaleminde (ay toplamlarına katılmaz).

## Yapılanlar
- Arka uç: `defter_aylari` (mühür), `defter_degisiklikleri`, `DefterHazirla` / `DefterKapanisi` / `DefterGecmisi`, `DefterIzler` trait'i (Gelir, DuzenliGider, Tasarruf, Borc, BorcOdemesi, Islem), `GET aile/defter`, `defter:kapat` (saatlik).
- Ön yüz: `DefterView` (Bütçe ⚙ Ayarlar → Defter), `@fontsource/caveat` + `patrick-hand` (OFL-1.1).
- Testler: `DefterTest` (9), `defter.spec.ts` (7).

## Sınırlar
Kart ekstresi onayı / satırları ve fatura değişiklikleri izlenmez. Özellikten önceki aylar ilk açıldıkları anda mühürlenir.
