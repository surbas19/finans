# Yatırım satırı: "toplama dahil" düğmesi + başka hesaba aktar; Gün'e "hangi ay bizde"

## Context
Kullanıcı (2026-11-15): "tüm yatırım enstrümanlarını kayıtlarının yanına genel toplama dahil etme butonu koy; ifade anlamlı kısa bir kelime ya da sadece buton. Dahil olmayan hem genel yatırım tutarına hem zekât hesabına dahil edilmesin."
Kararlar (kullanıcı): düğme **hesaptaki her varlık satırının** (hesap + varlık = pozisyon) yanında; kapanan satır yalnız o hesaptaki o varlığı çıkarır. Dahil olmayan satır listede **soluk ve "Hariç" etiketli** görünür (silinmez, geri açılır).
Ek istekler (aynı mesajda): (b) yatırımlar için **"başka hesaba aktar"** düğmesi; (c) **Altın/Döviz Günü**'ne **"hangi ay bizde"** alanı.
Kararlar: aktar = **kısmi miktar, maliyet taşınır**; bizde ayında **rozet "Sıra bizde" + alım beklenmez** (alım düğmesi çıkmaz, toplanan altın Tasarruf'tan elle girilir).
Önceki paket (Gelir/Gider, Gün, taksit) canlıda değil; bu iş ayrı commit'ler.

## Keşif özeti
- Portföy hesabı tek yerde: `backend/app/Services/Portfoy/PortfoyHesabi.php` `hesapla()` — pozisyonlar `hesap_id-varlik_id` anahtarlı, `degerle()` satırı, `topla()` toplam, `dagilim()`, `varlikBazinda()` (hesaplar arası toplam), `gecmis()` (performans serisi, ayrı döngü, pozisyonları kendi kurar).
- Tüketiciler: `OzetController`, `HesapController@index/show`, `VarlikController@show` (hepsi `hesapla()`), `PerformansController` (`gecmis()`), zekât `ZekatHesabi::hesapla` (`$portfoy['pozisyonlar']`; hesap bayrağı `hesaplar.zekata_dahil` + tür kuralı ile dışlama nedeni üretir).
- Ön yüz: `components/PozisyonListesi.vue` (satır = `RouterLink`), kullanan: `views/HesapDetayView.vue`, `views/OzetView.vue` (tüm hesaplar → `o.varliklar` toplanmış; hesap seçili → `o.pozisyonlar` süzgeçli), `views/VarlikDetayView.vue` (>1 hesapta). `Pozisyon` tipi `api/finans.ts:71`.
- Hesap erişimi: `Gate::authorize('update', $hesap)` / `Hesap::erisilebilir($user)` (hane); mevcut desen `HesapController`.

## Tasarım

### Backend
- **Migration** `pozisyon_ayarlari` (id, hesap_id FK cascade, varlik_id FK cascade, `dahil` bool default true, timestamps, **unique(hesap_id, varlik_id)**). Yalnızca `dahil=false` satırlar tutulur; "dahil"e çevrilince satır silinir (satır yok = dahil). Model `PozisyonAyari`.
- **`PortfoyHesabi::hesapla`**: `dahil=false` çiftler tek sorguda yüklenir; her `degerle()` satırına `'dahil' => bool` eklenir. **Toplamlar yalnızca dahil satırlardan**: `toplam`, her hesabın `topla()` (değer, maliyet, K/Z, günlük değişim, gerçekleşen K/Z, fiyatsız sayısı), `varlik_sayisi`, `dagilim`, `varliklar` (hesaplar arası toplam). `pozisyonlar` listesine **hariç olanlar da gelir** (`dahil:false`, sıralamada dahil olanlardan sonra) ki arayüz soluk gösterebilsin. `kalan` Bütçe formülü etkilenmez (portföy dışı).
- **`PortfoyHesabi::gecmis`** (performans grafiği): hariç çiftlerin işlemleri seriye alınmaz, böylece grafik genel toplamla tutarlı.
- **Zekât** (`ZekatHesabi::hesapla`): `dahil=false` pozisyon için dışlama nedeni **"Yatırım toplamına dahil değil"** (hesap bayrağı ve tür kuralından önce); `dahil_edilen=false`, `hesaba_giren=0`, fiyatsız uyarısı üretmez. Yıl kaydı kopyası bunu aynen taşır. Hesap düzeyi `zekata_dahil` ve tür kuralları değişmez (ayrı, ek süzgeç).
- **Uç**: `PUT /api/v1/hesaplar/{hesap}/pozisyonlar/{varlik}` `{dahil: bool}` — `HesapController@pozisyonDahil`; `Gate::authorize('update', $hesap)`; hesapta bu varlığa ait işlem yoksa 422; yanıt `{dahil}`. Hane dışı hesap → 403.
- Değişmeyenler: işlem kayıtları, portföy hesabı kuralları, Bütçe.

### Ön yüz
- `Pozisyon` tipine `dahil: boolean`. `api/finans.ts`: `pozisyonDahil(hesapId, varlikId, dahil)`.
- `PozisyonListesi.vue`: prop `degistirilebilir` (yalnız `hesap_id` dolu satırlarda) ve emit `dahilDegistir(p, dahil)`. Her satırın sağında link **dışında** küçük yuvarlak düğme (`pi-eye` / `pi-eye-slash`, `aria-label` "Genel toplama dahil" / "Genel toplamdan çıkar"; altında 11 px kısa yazı **Dahil / Hariç**), `data-test="dahil-dugmesi"`. Hariç satır: `opacity-60`, ad yanında `Tag` "Hariç", değer üstü çizili değil ama soluk; tıklanınca yine varlık sayfası.
- Sayfalar: `HesapDetayView` (liste + toplam kartı yenilenir), `OzetView` (tek hesapta ve hesap seçiliyken `o.pozisyonlar` listesi toggle'lı; **birden çok hesapta "Tüm hesaplar" toplu liste toggle'sız** ama altına "Toplama dahil olmayanlar" bölümü: `o.pozisyonlar.filter(!dahil)` toggle'lı; halka/dilimler yalnız dahil olanlar), `VarlikDetayView` (satırlar toggle'lı; toplam yalnız dahil olanlar). Düğme sonrası ilgili sayfa `yukle()` ile yenilenir, toast "Toplamdan çıkarıldı / Toplama eklendi".
- Zekât sonuç kartında nedeni zaten `dislanma_nedeni` olarak görünür; yeni neden metni orada çıkar.

### Başka hesaba aktar (çekirdek portföy kuralı değişmez)
- Yeni işlem türü yok: aktarım **iki kayıt**: kaynak hesapta **satış** ve hedef hesapta **alış**, ikisi de pozisyonun **ağırlıklı ortalama maliyet** fiyatıyla (satış fiyatı = ort. maliyet → gerçekleşen K/Z değişmez, toplam aynı kalır). Maliyeti bilinmeyen kısım için satış / hedefte `mevcut` **fiyatsız** (bilinmeyen sıfır maliyet sayılmaz); karışıkta bilinen + bilinmeyen oranla ayrı çifter kayıt. `islemler`e nullable `aktarim_kodu` (uuid) + not "Aktarım: A → B" ile eşleşik kayıtlar; işlem listesinde "Aktarım" rozeti, birini silme uyarısı eşini de söyler (silme yine `PortfoyHesabi::dogrula` kuralıyla).
- `AppServicesPortfoyPozisyonAktarimi` (miktar ≤ eldeki, kaynak ≠ hedef, ikisi de kullanıcının erişebildiği hane hesabı, BES / `deger` türü aktarılamaz, tarih ≤ bugün); tek DB transaction. Uç: `POST /api/v1/hesaplar/{hesap}/pozisyonlar/{varlik}/aktar` {hedef_hesap_id, miktar, tarih, not}. Kaynakta "toplama dahil" bayrağı hariçse hedefte de hariç kopyalanır (hedefte bayrak yoksa).
- Ön yüz: `PozisyonListesi` satırında dahil düğmesinin yanına **Aktar** düğmesi (`pi-arrow-right-arrow-left`, `data-test="aktar-dugmesi"`) → `PozisyonAktarDialog.vue` (hedef hesap, miktar [varsayılan tümü, en çok eldeki], tarih, not). Hesap tek ise düğme kapalı + ipucu.
- Testler: toplam değişmez, gerçekleşen K/Z değişmez, kısmi aktarımda iki hesabın miktar / maliyeti, bilinmeyen maliyet taşınır, fazla miktar / aynı hesap / başka hane hesabı / BES reddedilir, başka hane aktaramaz.

### Gün: "hangi ay bizde"
- `tasarruf_gunleri.bizde_ay` (nullable `YYYY-AA`; yeni migration, mevcut tablo). `TasarrufGunuController` doğrulama (`Donem::DESEN`, başlangıç ayı..süre içinde), `TasarrufGunu::ozet`, `GunTakvimi::ayinGunleri`: ayı `bizde_ay` olan günde `durum = sira_bizde` (alım beklenmez, `vadesi_geldi=false`); o ay yine de `gun_id` ile tasarruf girilirse engellenmez.
- Ön yüz: `TasarrufGunuFormu` "Hangi ay bizde" ay seçici (boş olabilir, ilk alım ayı + süre aralığında); `AileOzetView` gün satırında "Sıra bizde" rozeti, alım düğmesi yok. Testler: `TasarrufGunuTest`, `butceOzeti.spec`.

### Küçük düzeltmeler (aynı pakette, önce yapılır)
- **Özet dönem seçici 100 px:** `AySecici`'ye yükseklik değişkeni (`genis` modu `yukseklik` prop'u: Gelir/Gider 150 px, Özet 100 px); `AileOzetView` seçiciyi tam genişlik + 100 px kullanır (diğer sayfalardaki küçük hap aynen).
- **Telefonda "Sonraki taksitler" açıkken ekran taşıyor:** Edge 320/360'ta açık hâlde `scrollWidth - innerWidth` ve taşan eleman ölçülerek bulunur (şüphe: `AccordionContent` içindeki `ul`/`li` flex satırlarında `min-w-0`/`break-words` eksikliği, kalem satırındaki uzun açıklama + "× tutar" metni, `border-l-4` + `pl-3`). Düzeltme: satırlar `min-w-0`, açıklama `break-words`/`truncate`, kalem satırı dar ekranda iki satıra iner; e2e'ye "akordiyon açıkken taşma 0" kontrolü eklenir (320 / 360).

## Aşamalar (commit + push, ILERLEME/DEVAM güncel; plan `docs/planlar/yatirim-toplam-dahil.md`)
0. Küçük düzeltmeler (Özet seçici 100 px, taksit akordiyonu taşması) + test/e2e.
1. Backend: migration, model, `hesapla`/`gecmis` süzgeci, uç, zekât nedeni + testler.
2. Ön yüz: tip/API, `PozisyonListesi`, üç sayfa + Vitest.
3. Başka hesaba aktar (backend + ön yüz + testler).
4. Gün "hangi ay bizde" (migration + backend + ön yüz + testler).
5. Tüm testler, Pint/prettier, CLAUDE.md satırı, Edge e2e (320/360/1280). Yayın ayrı onayla.

## Doğrulama
- `PortfoyHesabiTest`/yeni `PozisyonDahilTest`: hariç satır `toplam`, hesap toplamı, `dagilim`, `varliklar`dan düşer ve `pozisyonlar`da `dahil:false` gelir; geri açınca eski toplam; satır yoksa 422; **başka hane değiştiremez / listede göremez**; `PortfoyGecmisiTest`: hariç çiftin işlemleri seride yok; `ZekatTest`: hariç satır `dahil_edilen=false`, neden "Yatırım toplamına dahil değil", brüt düşer, hesap bayrağı ve tür kuralı davranışı bozulmaz.
- Vitest: `PozisyonListesi` (düğme yalnız `hesap_id` dolu satırda, hariç satır soluk + "Hariç", emit), OzetView hariç bölümü.
- Tarayıcı: hesap detayında düğmeye bas → Özet toplamı düşer, Zekât ekranında kalem "dahil değil"; geri aç → eski rakamlar; yatay taşma yok.
- `php artisan test`, `npm run type-check && npm run lint && npx vitest run`.

## Kapsam dışı
Varlığı tüm hesaplarda topluca kapatma, tasarruf/Bütçe etkisi, geçmiş zekât yıl kayıtlarını geriye dönük değiştirme (kesinleşmiş kayıt değişmez), canlı yayın.
