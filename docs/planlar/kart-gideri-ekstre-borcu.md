# Kart gideri = ekstre dönem borcu

## Context
Giderler'de kartın ekstre borcu ile ayın kart gideri farklı çıkıyor: gider bugün ekstre **satırlarından** (işlem ayı; taksit kesim ayı; ödeme / bonus / nakit avans hariç) hesaplanıyor. Kullanıcı (2026-11-15): "gider ile ekstre borcunu ayırmaya gerek yok, tüm ekstre borcu gider olarak hesap edilsin." Kararlar: **tüm dönem borcu aynen gider** (devreden borç dahil, çıkarılmaz); kategori grafiği satırlardan, toplam ekstre borcu; ekstresi gelmemiş elle kart işlemleri ay giderine eklenir.

## Yeni kural (tek yer: `App\Services\Aile\ButceRaporu::giderOzeti`)
- **Kart gideri (ay `d`)** = o kartın **onaylı** ekstrelerinden kesim ayı `d` olanların `donem_borcu` toplamı (kuruş, `Kurus`) + **henüz ekstreye girmemiş elle kart işlemleri** (aşağıda). `donem_borcu` boşsa (okunamadı) ekstrenin satırlarından eski hesap (`giderEtkisi`) yedek olur.
- **Ekstresiz elle işlem** (`ekstre_id` null, onaylı, `rapor_donemi = d`, gider yönü ≠ 0): kartın, işlem tarihinden sonraki ilk onaylı ekstresi işlem tarihinden en çok 35 gün sonra kesiliyorsa ekstreye dahil sayılır (gidere eklenmez); aksi halde ay giderine eklenir. Böylece ekstre gelince çift sayım olmaz.
- **Kategori dağılımı**: kesim ayı `d` olan onaylı ekstrelerin satırlarının `giderEtkisi` toplamı kategoriye göre (+ ekstresiz elle işlemler); `donem_borcu − Σ satırlar` farkı **"Ekstredeki diğer (devreden borç, ödeme, bonus…)"** dilimi olarak eklenir (negatif olabilir), böylece kategori toplamı = gider toplamı.
- **Taksitler** bölümü: kesim ayı `d` ekstrelerinin taksitli harcama satırları (`rapor_donemi` zaten kesim ayı).
- **`haric`** ("gidere sayılmayanlar"): artık ödeme / bonus / nakit avans ekstre borcunun içindedir; bölüm "ekstre borcuna dahil, ayrıca eklenmedi" bilgisine döner (ekstre satırı türlerinden).
- `gider.kartlar[]`: kart başına `toplam` = o kartın ekstre borcu (+ elle); o ay yalnızca ekstresi kesilmiş kartlar da listelenir; `ekstre {id, donem_borcu, son_odeme_tarihi}` eklenir (GiderlerView ekstreleri ayrıca çekmez).
- Değişmeyenler: kartsız ödenen faturalar, düzenli giderler, tasarruf, `kalan`. Kartla ödenen fatura zaten ekstre borcunun içindedir.
- `KartIslemKurallari::raporDonemi` (satır ayı) ve İşlemler sayfası satır bazlı kalır; açıklama metni (Özet "Toplamlar nasıl hesaplanıyor?") ve CLAUDE.md "Aile finansı" kuralı güncellenir.

## Arayüz
- `api/aile.ts`: `AylikOzet.gider.kartlar[].ekstre`, kategori "Ekstredeki diğer" satırı (anahtar `kategori_id: null`, etiket sunucudan).
- `GiderlerView` kart satırı: ekstre borcu (= gider) + son ödeme; "Ekstre borcu bilgi içindir" notu kalkar, yerine "Kart gideri ekstre borcudur".
- `AileOzetView`: kategori listesinde "Ekstredeki diğer" tıklanamaz; "gidere sayılmayanlar" metni ve "Toplamlar nasıl hesaplanıyor?" maddeleri yeni kurala göre.

## Aşamalar (commit + push; DEVAM / ILERLEME; yayın ayrı onayla)
1. Arka uç: `ButceRaporu` yeni kural + testlerin güncellenmesi (`EkstreTest`, `KartIslemiTest`, `FaturaTest`, `GarantiReferansEkstreTest`, `ElleEkstreTest`'te `gider.*` beklentileri) + yeni `KartGideriTest` (aşağıda).
2. Arayüz: GiderlerView, AileOzetView metin / tip, `butceOzeti.spec.ts`.
3. CLAUDE.md, ILERLEME, uçtan uca (demo Bonus kartı, Kasım 2026: kart gideri ekstre borcuna eşit; 320 / 360 / 1280).

## Doğrulama
- `KartGideriTest`: (1) onaylı ekstre → ayın kart gideri = `donem_borcu`, satır toplamından bağımsız; kategori toplamı = gider (diğer dilimi dahil); (2) iki ekstre / iki kart toplanır; ekstresi başka ayda olan kart bu ayda yok; (3) taslak ekstre giderde yok, onaylanınca girer; (4) elle işlem ekstreden önce ay giderine girer, ilgili ekstre onaylanınca çift sayılmaz; çok eski elle işlem (ekstresi yok) sayılır; (5) tutarla girilen ekstre = borç; (6) `donem_borcu` boşsa satır yedek; (7) başka hane göremez (mevcut `ozet` yetkisi); kalan = gelir − gider − tasarruf hâlâ tutar.
- `php artisan test`; `npm run type-check && npm run lint && npx vitest run` (PowerShell); Edge e2e.

## Kapsam dışı
Devreden borcun ayrıştırılması / çift sayımı önleme (kullanıcı kararı: tüm borç gider), ekstre satırlarını başka aya taşıma, banka hesabı ekstresi.
