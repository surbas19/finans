# Hesapların geçmiş performans grafikleri

## Bağlam
Özet ve hesap detayı yalnızca bugünkü değeri ve K/Z'yi gösteriyor. Kullanıcı hesabının (ve toplam portföyün) zaman içinde nasıl değiştiğini görmek istiyor. Kararlar (kullanıcıyla): grafik **Özet** (süzgeç seçiliyse o hesap) ve **hesap detayı** sayfalarında; **değer + maliyet** iki çizgi, aradaki fark K/Z. Ayrıca hesap açıldığında Özet'teki gibi **varlıklar + dağılım grafiği** görünür.

Değerin anlık görüntüsü tutulmuyor; gerek de yok: işlemler (`islemler`) + günlük kapanışlar (`fiyat_gecmisi`, kaynaklardan ~365 gün + her güncellemede bugünün satırı) ile geçmiş geriye dönük hesaplanır. Yeni tablo / migration / cron yok. Demo seeder zaten `fiyat_gecmisi` dolduruyor.

İş başında (CLAUDE.md kuralı): bu plan `docs/planlar/hesap-performansi.md`'ye kopyalanır, `docs/DEVAM.md`'ye kayıt açılır, adımlarda commit + push ("Yarım: …").

## Backend

### 1. `PortfoyHesabi::gecmis()` — `backend/app/Services/Portfoy/PortfoyHesabi.php`
Portföy hesabı tek yerde kuralı gereği aynı sınıfa eklenir; `Pozisyon::uygula`, `self::sirala` yeniden kullanılır.

`gecmis(User $user, ?int $hesapId, CarbonInterface $baslangic, CarbonInterface $bitis): array` → `list<{tarih, deger, maliyet, kz, eksik}>`
1. Kullanıcının hesapları (isteğe bağlı tek hesap, `hesapla()` ile aynı sorgu) → işlemler `self::sirala`.
2. İlgili varlık id'leri (+ USD fiyatlı varlık varsa USD/TRY varlığı). `fiyat_gecmisi`: `[baslangic, bitis]` satırları + her varlık için `baslangic`tan önceki son satır (ileri taşıma için).
3. `baslangic`tan önceki işlemler uygulanır; sonra gün gün: o günün işlemleri `Pozisyon`lara uygulanır, varlığın gün fiyatı güncellenir.
4. Gün fiyatı: son bilinen kapanış (hafta sonu / tatil ileri taşınır); hiç yoksa o güne kadarki son işlemin `birim_fiyat`ı (TL) yedek; o da yoksa pozisyon o gün değere katılmaz ve nokta `eksik: true`. USD fiyatlılar o günün USD/TRY kapanışıyla (yoksa `usdKuru()`) çevrilir.
5. Nokta: `deger` = Σ miktar × TL fiyat; `maliyet` / `kz` yalnızca maliyeti bilinen kısımdan (`topla()` ile aynı kural: bilinmeyen sıfır maliyet sayılmaz): maliyet = Σ maliyetBilinen, kz = Σ (miktarBilinen × fiyat − maliyetBilinen). TL 2 hane.
6. Son gün bugünse güncel `fiyatlar` kullanılır → son nokta özet başlığındaki `toplam.deger` ile birebir aynı.
7. 400 günden uzun aralıkta haftalık örnekleme (haftanın son günü + son gün).

### 2. Uç — `backend/app/Http/Controllers/Api/V1/PerformansController.php` (invokable)
`GET /api/v1/ozet/gecmis?aralik=1A|3A|1Y|T&hesap_id=` (`routes/api.php`, `auth:sanctum` grubu, ad `v1.ozet.gecmis`).
- `hesap_id`: `OzetController`'daki gibi `Rule::exists('hesaplar','id')->where('user_id', …)` (izolasyon).
- Aralık eşlemesi `VarlikController::gecmis` ile aynı (1A 31, 3A 92, 1Y 366 gün); `T` = seçili hesaplardaki ilk işlem tarihi. Başlangıç, ilk işlemden önceyse ilk işleme çekilir (boş başlangıç yok). İşlem yoksa `[]`.
- Yanıt: `{ gecmis: [...] }`.

### 3. Testler — `backend/tests/Feature/PortfoyGecmisiTest.php` (`VarlikYardimcisi`, `Islem::factory`, `FiyatGecmisi::insert`)
- Alış → değer / maliyet / kz belirli günlerde doğru; sonraki satış maliyeti ve değeri düşürür.
- Kapanışı olmayan gün önceki kapanışla dolar.
- USD fiyatlı varlık o günün USD/TRY kapanışıyla çevrilir.
- Geçmişi olmayan varlık işlem fiyatıyla değerlenir; fiyatı hiç olmayan → `eksik`.
- Maliyeti bilinmeyen kalem değere girer, maliyete / kz'ye girmez.
- Son nokta = `hesapla()['toplam']['deger']`.
- Uç: başka kullanıcının `hesap_id`'si 422, başkasının işlemleri seriye girmez; oturumsuz 401; geçersiz `aralik` 422; `T` ilk işlemden başlar.

## Frontend

### 4. API — `frontend/src/api/finans.ts`
`PerformansNoktasi { tarih; deger; maliyet; kz; eksik }`, `finansApi.performans(aralik, hesapId | null)`.

### 5. `frontend/src/components/PerformansGrafigi.vue`
`FiyatGrafigi.vue` kalıbı (SelectButton aralık, Skeleton, `useSonIstek`, CSS değişkeninden renk, chart.js `Chart`).
- Props: `hesapId: number | null`, `yenile: number` (değişince yeniden yükler; işlem eklenince sayfa artırır).
- Aralıklar 1A / 3A / 1Y / Tümü, varsayılan 3A.
- İki çizgi: **Değer** (primary, dolgulu), **Maliyet** (gri, kesikli). Tooltip: değer, maliyet, K/Z (`tl()`).
- Üstte "Dönemde K/Z" = son.kz − ilk.kz, `DegisimEtiketi` ile.
- Herhangi bir nokta `eksik` → küçük not: "Fiyatı bilinmeyen günlerde bazı varlıklar değere katılmadı."
- < 2 nokta → boş durum metni.
- Dönem K/Z hesabı `utils/bicim.ts` yanına küçük saf fonksiyon (`donemKz(noktalar)`) → vitest testi.

### 6. Dağılım kartı — `frontend/src/components/DagilimKarti.vue`
`OzetView.vue`'daki dağılım bölümü (doughnut `VeriGrafigi` + tür / tutar / % listesi, `VARLIK_TURLERI` renkleri) bileşene taşınır. Props: `dagilim`, `toplamDeger`. Özet ve hesap detayı aynı bileşeni kullanır.

### 7. Hesap detayında dağılım (backend)
`HesapController::show` yanıtına `'dagilim' => $portfoy['dagilim']` eklenir (`hesapla()` zaten hesaplıyor). `IslemVeHesapTest`'te hesap detayının dağılımı yalnızca o hesabın varlıklarını içeriyor testi. `finans.ts`'te hesap yanıtı tipine `dagilim` eklenir.

### 8. Sayfalar
- `frontend/src/views/OzetView.vue`: Toplam kartının altına "Performans" kartı (`:hesap-id="secilenHesap"`, `yukle()` sonrası `yenile++`); dağılım bölümü `DagilimKarti` ile.
- `frontend/src/views/HesapDetayView.vue`, Özet düzeninde:
  1. Başlık kartı (değişmez)
  2. Performans kartı (`:hesap-id="id"`, işlem kaydı / silme sonrası `yenile++`)
  3. `grid lg:grid-cols-5`: solda (`lg:col-span-3`) mevcut Varlıklar / İşlemler sekmeleri, sağda (`lg:col-span-2`) `DagilimKarti` (dağılım boşsa gizli). Mobilde alt alta.

## Belgeler
`docs/ILERLEME.md` → "Son tamamlananlar"; `docs/DEVAM.md` kaydı silinir; plan `docs/planlar/hesap-performansi.md`'de kalır.

## Doğrulama
1. `php artisan test` (tamamı), Pint yalnızca işin dosyalarına.
2. `npm run type-check && npm run lint && npx vitest run`, prettier yalnızca işin dosyalarına.
3. Yerelde (sunucular açık: 8001 + 5174): `ben@finans.test` / `password` ile giriş → Özet'te grafik, süzgeçle hesap seçince değişiyor; hesap detayında performans grafiği + varlıklar + dağılım (Özet'teki görünümle aynı); aralıklar çalışıyor; son nokta başlıktaki toplamla aynı; işlem ekleyince grafik yenileniyor; mobil genişlikte taşma yok.
4. Commit + push.
