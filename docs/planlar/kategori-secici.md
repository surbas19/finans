# Kategori seçici: ikonlu ızgara (A) + ortak işlevler

Kullanıcı seçimi (2026-11-19), görsel öneriler artifact'ında **A**, ortak işlevlerin hepsiyle.

## Arka uç
- `KategoriAtayici::ata($islem, $kategori, $tek)`: kapsam `hepsi` (aynı açıklamalı hepsi + kural) | `tek` (yalnız bu satır, kural değişmez). Dönüş: sayı + `geri_al` (satırların önceki kategorileri gruplu, kuralın önceki hâli).
- `KategoriAtayici::geriAl`: satırları ve kuralı eski hâline döndürür; satırlar ve kategoriler hane içinde mi denetlenir.
- `KategoriAtayici::oneri`: önce kural eşleşmesi, yoksa açıklamanın ilk kelimesi aynı (3+ harf) kategorili satırlarda en çok kullanılan kategori; mevcut kategoriyle aynıysa yok. Uydurulmaz.
- Uçlar:
  - `GET aile/kart-islemleri/{islem}/kategori-onerisi`: `ayni_aciklamali`, `oneri`.
  - `PUT .../kategori`: `kapsam` alır.
  - `POST .../kategori-geri-al`.
- Test: kapsam tek, geri al (kural dahil), öneri (kural / benzer / yok), başka hane.

## Ön yüz
- `KategoriSecDialog` yeniden: ızgara düzeni.
  - Arama (Türkçe harf duyarsız) ve eşleşme yoksa "X oluştur ve ata".
  - Öneri kartı.
  - 3 sütun karo: ikon (yoksa pi-tag), ad, kullanım sayısı; seçili işaretli; fatura kategorisi sonda; "Yeni" karosu aramaya odaklar.
  - "Hepsi (N) / Yalnız bu işlem" kapsamı ve kategorisiz yap.
- Geri al bildirimi:
  - `useGeriAl` + `GeriAlBildirimi` (AnaDuzen'de); 5 sn.
  - `KategorisizBolumu` çipleri de kullanır.
