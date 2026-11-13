# Hesaplarda nakit ve bireysel emeklilik (BES)

## Bağlam
Hesaplar şu an yalnızca piyasa fiyatlı varlık tutuyor (altın, döviz, hisse, kripto, fon). Kullanıcı iki eksik bildirdi:
1. **Nakit yok**: hesapta duran TL görünmüyor. Seçim: **elle girilen nakit** (yatırma / çekme / elde olan); alım-satım nakde dokunmaz.
2. **Bireysel emeklilik yok**. Seçim: **toplam tutar ve yüzde kâr elle girilir** (şirket uygulamasındaki / e-Devlet'teki sayılar). Fon, pay, katkı payı dökümü yok; her giriş BES'in o tarihteki durumudur, öncekinin yerine geçer.

Döviz nakdi (USD, EUR) zaten "Döviz" olarak giriliyor; nakit yalnızca TL. Devlet katkısı ayrı tutulmaz, toplam tutara dahil girilir.

## Tasarım (migration yok)
- **İki yeni varlık türü**, katalogda `kaynak = 'sabit'`, `birim = 'TL'`, fiyat satırı yok:
  - `nakit`: `TL` "Nakit"
  - `bes`: `BES` "Bireysel emeklilik"
- **Birim değeri 1 TL**: miktar = TL tutarı. `PortfoyHesabi::tlFiyat` ve `kapanisTl` sabit türde 1.0 döner. Özet, dağılım, hesap toplamları ve performans grafiği ek kod olmadan çalışır. `FiyatGuncelleyici` `sabit` kaynağına dokunmaz (yalnızca truncgil / yahoo / binance'i dolaşır).
- **Nakit**: alış = yatırma, satış = çekme (eldekinden fazla çekilemez, mevcut denetim), elde olan = başlangıç bakiyesi. Birim fiyat 1, komisyon 0 → maliyet = tutar, K/Z 0 (nakit satırında K/Z gösterilmez). Toplam K/Z yüzdesine nakit de girer (grafikteki maliyet çizgisiyle tutarlı kalsın).
- **BES**: yalnızca yeni işlem türü **`deger` ("Güncel tutar")**. İstekte `miktar` = toplam tutar T, `kar_yuzde` = p (boş olabilir). Sunucu `birim_fiyat = 1 / (1 + p/100)` saklar (maliyet / tutar oranı; p boşsa null). `Pozisyon::uygula` bu türde pozisyonu **sıfırlayıp yeniden kurar**: p varsa bilinen miktar T, maliyet T × birim_fiyat; yoksa T maliyeti bilinmeyen kısma (K/Z "—", sıfır maliyet sayılmaz kuralı). Sonuç: değer = T, K/Z = T − maliyet, K/Z yüzdesi = p. Geçmiş girişler grafikte basamak olarak kalır.
- BES varlığına yalnızca `deger`, `deger` yalnızca BES varlığına girilir.
- `HesapTuru::Bes` ("Bireysel emeklilik"): ikon ve kısayol için.

## Arka uç
- `app/Enums/VarlikTuru.php`: `Nakit`, `Bes` + `sabitFiyatli(): bool`. `IslemTuru.php`: `Deger` ("Güncel tutar"). `HesapTuru.php`: `Bes`.
- `app/Fiyat/Katalog.php`: iki kalem sona eklenir (var olanların `sira`'sı değişmez); `kaynak_kodu` null (docblock `?string`). Canlıda her yayında `katalog:esitle` çalıştığı için (`deploy/cron.sh`, KUR) ek adım gerekmez.
- `app/Services/Portfoy/Pozisyon.php`: `Deger` dalı (sıfırla + yeniden kur).
- `app/Services/Portfoy/PortfoyHesabi.php`: `tlFiyat` / `kapanisTl` sabit türde 1.0.
- `app/Http/Controllers/Api/V1/IslemController.php` (`dogrula`): varlık önceden bulunur.
  - Nakit: `birim_fiyat` zorunlu değil, 1'e; `komisyon` 0'a çekilir.
  - BES: `tur` yalnızca `deger` ("Bireysel emeklilikte toplam tutarı ve kâr yüzdesini girin."); `kar_yuzde` nullable, numeric, `gt:-100`; `birim_fiyat` bundan hesaplanır, komisyon 0.
  - `deger` BES dışında 422 ("Güncel tutar yalnızca bireysel emeklilikte girilir.").
  - `toplu`: nakit satırında birim fiyat 1; BES satırı reddedilir ("Bireysel emekliliği 'İşlem ekle' ile girin.").
- `app/Models/Islem.php` `ozet()`: `deger` işleminde `kar_yuzde` (birim fiyattan, 2 hane; null olabilir), `tutar` = miktar.
- `VarlikController::elleFiyat`: sabit türde 422. `IzlemeController::store`: sabit tür eklenemez.
- `database/seeders/DemoSeeder.php`: örnek fiyat / geçmiş üretiminde `sabit` atlanır; yatırım hesabına nakit (elde olan + yatırma + çekme); yeni "Bireysel emeklilik" hesabına birkaç ay arayla kâr yüzdeli üç güncel tutar kaydı.

## Arayüz
- `src/api/finans.ts`: `VarlikTuru` + `nakit` (pi-money-bill, teal) ve `bes` (pi-shield, rose); `HesapTuru` + `bes`; `IslemTuru` + `deger`; `IslemGirdisi` + `kar_yuzde`; `Islem` + `kar_yuzde`; `sabitFiyatli(tur)` ve `islemEtiketi(tur, varlikTuru)` (nakit: Yatırma / Çekme / Elde olan; BES: Güncel tutar). İkonlar primeicons 7 CSS'inde doğrulanır.
- `components/IslemFormu.vue`:
  - Nakit seçilince tür seçenekleri Yatırma / Çekme / Elde olan; birim fiyat, komisyon, tutar kutusu gizli; miktar "Tutar (TL)".
  - BES seçilince tür seçici gizli (`deger`); alanlar "Toplam tutar (TL)" ve "Kâr (%)" (boş bırakılabilir, eksi olabilir; ipucu: "Şirket uygulamasındaki getiri yüzdesi"), altta hesaplanan "Ödenen ≈ ₺… · K/Z ₺…" kutusu.
  - Varlık değişince geçersiz kalan tür ilk seçeneğe döner; `varsayilan.varlik_tur` eklenir (katalog yüklenince o türün varlığı seçilir, kısayollar için). Düzenlemede `kar_yuzde` geri doldurulur.
- `views/HesapDetayView.vue`: BES dışı hesapta "Nakit" düğmesi (hesapta nakit yoksa "Elde olan", varsa "Yatırma" ile açılır). BES hesabında "Toplu ekle" ve "İşlem ekle" yerine "Tutarı güncelle"; boş durum metni de BES'e göre.
- `components/PozisyonListesi.vue`: sabit türde alt satırda "miktar × fiyat" yok; nakitte K/Z yok; kâr yüzdesi girilmemiş BES'te "Kâr yüzdesi girilmedi".
- `components/IslemListesi.vue`: etiket `islemEtiketi` ile; `deger` için renk; BES satırında "₺T · %p kâr".
- `views/VarlikDetayView.vue`: sabit türde fiyat bloğu, "Elle fiyat" ve fiyat grafiği gizli.
- `views/IzlemeView.vue`: sabit türler eklenebilir listede yok.
- `utils/topluIslem.ts`: BES eşleşen satır hatalı (aynı mesaj); nakit "TL" / "Nakit" ile eşleşir (kod / ad), birim fiyat yok sayılır.
- Hesap formu tür listesi `HESAP_TURLERI`'nden geldiği için BES kendiliğinden çıkar.

## Testler
- `PortfoyHesabiTest`: nakit değeri ve dağılımda "Nakit"; BES güncel tutar + kâr → değer, maliyet, K/Z, K/Z yüzdesi = p; ikinci giriş öncekinin yerine geçer; kâr yüzdesiz giriş → K/Z null, maliyeti eksik; eksi yüzde (zarar).
- `PortfoyGecmisiTest`: nakit ve BES noktaları `eksik` değil; BES değeri giriş tarihlerinde basamak.
- `IslemVeHesapTest`: nakit alışı fiyatsız kabul, 1 / 0 saklanır; eldekinden fazla çekme 422; BES'te `deger` dışı tür 422; `deger` BES dışında 422; `kar_yuzde` −100 ve altı 422; `ozet()` `kar_yuzde` geri veriyor; başka kullanıcının hesabına giriş yok.
- `TopluIslemTest`: nakit satırı, BES satırı 422. `VarlikTest`: sabit türde elle fiyat 422. `YonetimVeIzlemeTest`: izlemeye sabit tür 422. Katalog eşitleme / `FiyatGuncelleyiciTest`: iki kalem var, `sabit` için istek atılmaz.
- Vitest: `islemEtiketi`, `topluIslem` (BES hatası, "TL" eşleşmesi), `IslemFormu` nakit / BES modu (tür seçenekleri, fiyat alanı gizli, kâr yüzdesi alanı ve hesaplanan ödenen tutar).

## Doğrulama
1. `php artisan test`; `npm run type-check && npm run lint && npx vitest run`; Pint / prettier yalnızca bu işin dosyalarına.
2. `php artisan migrate:fresh --seed`, `php artisan serve --port=8001` + `npm run dev`; `ben@finans.test` ile 360 ve 1280 px:
   - Yatırım hesabı: "Nakit" düğmesi, nakit satırı, yatırma / fazla çekme hatası.
   - Yeni BES hesabı: "Tutarı güncelle" ile 150.000 TL, %25 → değer 150.000, K/Z +30.000 (%25); yüzdesiz giriş → K/Z "—".
   - Özet: dağılımda "Nakit" ve "Bireysel emeklilik", hesap halkaları ve performans grafiği bozulmuyor; taşma yok.
3. `npm run build`, commit + push, canlıya yayın (`yayinla.bat`); canlıda katalogda TL ve BES görünüyor.

## Kayıt (CLAUDE.md kuralı)
Plan `docs/planlar/nakit-bes.md`'ye kopyalanır, `docs/DEVAM.md`'ye kayıt; adım adım commit + push ("Yarım: …"). Bitince ILERLEME "Son tamamlananlar"a tek satır, CLAUDE.md'deki portföy kuralına nakit / BES (birim 1 TL, BES güncel tutarı pozisyonu yeniden kurar) cümlesi.
