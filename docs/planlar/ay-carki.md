# Ay çarkı: tek tip dikey ay seçici (2026-11-16)

## Bağlam
"Kasım 2026" biçimindeki ay seçici (`components/aile/AySecici.vue`, ‹ Kasım 2026 ›) Bütçe'nin 5 sayfasında farklı boyut ve yerdeydi:
- Bütçe Özet ve Gelir / Gider: geniş, 100 px.
- Kartlar, İşlemler ve Faturalar: küçük hap, sayfanın ortasında.

Kullanıcı hepsinin aynı biçimde ve sayfanın en üstünde olmasını istedi:
- **Dikey tek çark** (wheel picker), **çerçevesiz** (yalnız yazılar), **50 px** yükseklik. Görsel önizlemede onaylandı.
- Yıl seçicileri ("2026") ve formlardaki ay alanları kapsam dışıdır.

## Tasarım
- Tam genişlik, 50 px. Satır yüksekliği 24 px. Seçili ay ortada, büyük, kalın ve turkuaz yazılır. Önceki / sonraki ay üstte ve altta yarım görünür, küçük ve soluk. Kenarlar `mask-image` ile söner. Kart zemini, kenar ve bant yoktur.
- Kaydırma `scroll-snap` ile yapılır; ortaya oturan ay seçilir. Soluk aya dokununca o ay seçilir.
- Sağda ˄ ˅ ("Önceki ay" / "Sonraki ay") düğmeleri bulunur. Klavyede Yukarı / Aşağı okları çalışır. `role="listbox"` kullanılır.
- `prefers-reduced-motion` açıksa kaydırma animasyonsuz olur.
- `ileriSinir`: sınırdan sonraki aylar listede yoktur.
- `bos`: İşlemler'de "Tümü" seçiliyken "Tüm dönemler" yazar. `pasif`: Faturalar'ın "Dönemi bilinmeyen" görünümünde soluk ve kapalıdır.
- `#ek` yuvası solda ortadadır: "Tümü" (İşlemler), "En yeni" (Kartlar).

## Adımlar
1. `AySecici.vue` baştan yazılır; `genis` / `yukseklik` kalkar.
2. Sayfalar: çark kök `div`'in ilk çocuğu olur (AileOzet, GelirGider, Kartlar, AileIslemler, Faturalar). İşlemler'deki "Ekstre dönemi seç" düğmesi kalkar; yerine `bos` gelir.
3. Testler:
   - Yeni: `aySecici.spec.ts`.
   - Güncellenen: `islemAra`, `kartDonem`, `kartKaydirici`.
4. Belgeler: CLAUDE.md, ILERLEME. DEVAM kaydı silinir.

## Doğrulama
`npm run type-check && npm run lint && npx vitest run --testTimeout=30000`, `php artisan test` (MySQL açıksa); telefonda 5 sayfa elle denenir.
