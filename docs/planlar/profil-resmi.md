# Profil resmi

## Bağlam
İstek: "kullanıcı profil resmini değiştirebilsin". Şu an hiçbir yerde resim yok; üst çubukta, Profil'de ve Yönetim listesinde kişi simgesi var.

Seçimler:
- **Kırpma otomatik:** seçilen fotoğrafın ortasından kare, 256×256; kaydetmeden önce yuvarlak önizleme.
- **Görünüm:** üst çubuk ve Profil; ayrıca Yönetim → Kullanıcılar, Hesaplar'daki sahip, Bütçe'de üye adları (özet, gelirler, kartlar, kart detayı, ayarlar → üyeler).

## Tasarım
- **Küçültme tarayıcıda** (`createImageBitmap` + canvas, EXIF yönü düzeltilir; WebP, desteklenmezse JPEG). Sunucuda GD gerekmez (canlıda GD eklentisi listede yok); sunucu yalnızca doğrular (`image`, `mimes:jpeg,png,webp`, en çok 1 MB, 32–2048 px) ve saklar.
- **Özel disk** (`local`, ekstre PDF'leriyle aynı): `profil/{user_id}-{rastgele}.{uzantı}`. Açık adres yok; `GET /api/v1/kullanicilar/{id}/resim` yalnızca **kendisi, aynı hanedekiler ve yönetici** için (öbürlerine 404). Yeni resimde dosya adı değiştiği için adreste sürüm var (`?v=`), tarayıcı önbelleği `private, max-age=1 yıl, immutable`. Eski dosya silinir.
- Resim yoksa ya da yüklenemezse **baş harfler** (hane üyesinde üyenin rengiyle). Girişi olmayan üyede (ör. çocuk) hep baş harf.

## Arka uç
- Migration `2026_11_14_000500_add_profil_resmi_to_users_table`: `users.profil_resmi` (string 100, null).
- `User`: `resimAdresi(): ?string`; `oturumBilgisi()`'ne `resim` (üst çubuk, Profil, Yönetim listesi bundan).
- `ProfilResmiController`: `yukle` (`POST /ben/resim`), `sil` (`DELETE /ben/resim`), `goster` (`GET /kullanicilar/{kullanici}/resim`).
- `Hesap::ozet` → `sahip_resim`; `HaneUyesi::ozet` → `resim` (`HaneController` üyeleri `user` ile yükler).
- Test `ProfilResmiTest`: yükle / değiştir (eski dosya silinir) / sil; svg, pdf, büyük ve küçük resim reddi; görme yetkisi (kendisi, aynı hane, yönetici 200; başka kullanıcı 404; resimsiz 404; girişsiz 401); `sahip_resim` ve üye `resim` alanları.

## Arayüz
- `components/KisiResmi.vue` (`ad`, `resim`, `renk`, `boyut`): yuvarlak resim ya da baş harfler; resim açılamazsa baş harfe düşer.
- `utils/profilResmi.ts`: `kareAlan(g, y)` (ortadan kare) ve `kareKucult(dosya, 256)`.
- `ProfilView`: büyük resim, "Resmi değiştir" (dosya seç → önizleme penceresi → Kaydet), "Resmi kaldır" (onaylı).
- `AnaDuzen` üst çubuk, `YonetimView`, `HesaplarView` (sahip), Bütçe: `AileOzetView`, `GelirlerView`, `KartlarView`, `KartDetayView`, `AileAyarlarView` (aile store'da `uye(id)`).
- Vitest: `kareAlan`, `KisiResmi` (resim / baş harf / hata → baş harf).

## Doğrulama
1. `php artisan test`; `npm run type-check && npm run lint && npx vitest run`; Pint / prettier yalnızca bu işin dosyalarına.
2. Uçtan uca (Edge + playwright-core, 360 / 1280): Profil'de yatay ve dikey fotoğraf yükle → yuvarlak önizleme, kaydet, üst çubukta görünür; kaldır → baş harf; eşin oturumunda Bütçe'de ve Hesaplar'da resim; başka hanedeki kullanıcı adresi açamaz; taşma yok.
3. Commit + push, canlıya yayın (migration cron'da çalışır).
