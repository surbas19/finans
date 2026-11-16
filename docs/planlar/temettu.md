# Temettü ve bedelsiz sermaye artırımı

## Bağlam
ILERLEME'de açık madde: "Temettü / faiz / kira geliri". Şu an hisse temettüsü girilemiyor (yalnızca alış / satış / elde olan); nakit elle "yatırma" diye girilirse portföy getirisinde görünmüyor, hisse fiyatı temettü sonrası düştüğü için K/Z olduğundan kötü görünüyor. Bedelsiz sermaye artırımı da girilemiyor: adet eksik, birim maliyet yanlış kalıyor.

Kullanıcı kararları:
- Temettü **aynı hesaba TL nakit** olarak eklenir (hesap değeri artar) ve hissenin getirisinde "temettü" olarak görünür.
- **Bütçe gelirlerine yazılmaz** (yalnızca portföy; çift sayım yok).
- **Yalnızca elle** girilir (dış kaynaktan otomatik öneri yok).
- **Bedelsiz de bu işe dahil.**

Kapsam dışı (ILERLEME'de kalır): faiz, kira geliri, bedelli sermaye artırımı.

İş başında: plan `docs/planlar/temettu.md`'ye, DEVAM.md'ye kayıt; adımlarda commit + push ("Yarım: …").

## Veri modeli
Yeni tablo yok; `islemler`'e iki yeni tür ve bir sütun:
- `IslemTuru::Temettu` (`temettu`): hisse satırında. `miktar` = hesaba geçen **net** TL tutarı, `komisyon` = **stopaj** TL (isteğe bağlı; brüt = net + stopaj), `birim_fiyat` null. Adet ve maliyeti değiştirmez.
- `IslemTuru::Bedelsiz` (`bedelsiz`): hisse satırında. `miktar` = bedelsiz **oranı yüzde** (ör. 100 = her hisseye 1 yeni hisse). O tarihteki adet `× (1 + oran/100)` olur, toplam maliyet aynı kalır (birim maliyet düşer). Maliyeti bilinen / bilinmeyen kısımlar aynı oranla büyür.
- Temettünün nakdi: aynı hesaba **Nakit (`nakit` / `TL`) varlığına alış** kaydı (birim 1 TL, miktar = net tutar). İki kayıt mevcut **`aktarim_kodu`** ile eşlenir; bu kalıp zaten "birlikte yazılır, düzenlenmez, birini silmek ikisini siler, dahil/hariç birlikte değişir" demek ([IslemController](backend/app/Http/Controllers/Api/V1/IslemController.php) `update` / `dahil` / `destroy`). Ayrım için migration ile **`islemler.bag_turu`** (string 10, nullable: `aktarim` | `temettu`); mevcut aktarım satırlarına `aktarim` yazılır.
- Kurallar: temettü ve bedelsiz yalnızca **hisse** türünde; o tarihte hesapta o hisseden **adet > 0** olmalı (yoksa 422: "Bu tarihte hesapta bu hisse yok").

## Backend
1. **Migration** `…_temettu_bedelsiz.php`: `bag_turu` sütunu + `aktarim_kodu` dolu satırlara `aktarim`. `IslemTuru` enum'una iki case + etiketler ("Temettü", "Bedelsiz").
2. **`Pozisyon::uygula`** ([Pozisyon.php](backend/app/Services/Portfoy/Pozisyon.php)): yeni `public float $temettu`.
   - Temettü: eldeki ≤ EPS ise `YetersizMiktar`; değilse `$temettu += miktar`.
   - Bedelsiz: eldeki ≤ EPS ise `YetersizMiktar`; değilse `miktarBilinen` ve `miktarBilinmeyen` `× (1 + miktar/100)`, maliyet aynı.
3. **`PortfoyHesabi`** ([PortfoyHesabi.php](backend/app/Services/Portfoy/PortfoyHesabi.php)):
   - `degerle()`: satıra `temettu` (yoksa null), `varlikBazinda()`'da toplanır, `topla()`'da `temettu` toplamı (hesap / genel).
   - `gecmis()`: noktanın `gerceklesen` değeri `p->gerceklesen + p->temettu` (temettü ödenince hisse fiyatı düşer, nakit eklenir; dönem K/Z doğru kalır). Doc güncellenir.
   - `dogrula()` değişmez (yeni türler `uygula` üzerinden denetlenir; bedelsizi silmek sonraki bir satışı geçersiz kılıyorsa mevcut silme denetimi yakalar).
4. **`TemettuKaydi`** servisi (`backend/app/Services/Portfoy/TemettuKaydi.php`, [PozisyonAktarimi](backend/app/Services/Portfoy/PozisyonAktarimi.php) kalıbı): o tarihte adet kontrolü, `DB::transaction` içinde temettü satırı + Nakit alış satırı (aynı `aktarim_kodu`, `bag_turu=temettu`, nakit notu "THYAO temettüsü").
5. **`IslemController`**:
   - `dogrula()`: `tur` temettü / bedelsiz ise varlık hisse olmalı; temettüde `miktar` net TL, `komisyon` stopaj; bedelsizde `miktar` yüzde (0 < oran ≤ 10000), birim fiyat ve komisyon yok.
   - `store()`: temettüyse `TemettuKaydi`; bedelsiz normal yol (`miktarYeterliMi`).
   - `update()`: bağlı kayıt mesajı türe göre ("Temettü kaydı düzenlenemez; silip yeniden girin." / mevcut aktarım mesajı). Bedelsiz düzenlenebilir.
   - `miktarYeterliMi` mesajı: temettü / bedelsizde "Bu tarihte hesapta bu hisse yok."
6. **`Islem::ozet()`**: `bag_turu`; temettüde `tutar` = net, `stopaj`, `brut`; bedelsizde `oran_yuzde`, `tutar` null.
7. **Demo verisi** ([DemoSeeder.php](backend/database/seeders/DemoSeeder.php)): demo hisselerinden birine bir temettü (yerelde görmek için).

## Frontend
8. **[finans.ts](frontend/src/api/finans.ts)**: `IslemTuru`'na `temettu` | `bedelsiz`, `ISLEM_TURLERI` etiketleri, `islemTurleri('hisse')` → alış, satış, elde olan, temettü, bedelsiz; `Islem`'e `bag_turu`, `stopaj`, `brut`, `oran_yuzde`; `Pozisyon` ve `Toplam`'a `temettu`.
9. **[IslemFormu.vue](frontend/src/components/IslemFormu.vue)**: tür seçicide hissede iki yeni tür.
   - Temettü: "Hesaba geçen net tutar (TL)" + "Stopaj (TL, isteğe bağlı)", altında "Brüt: …" ve "Tutar bu hesaba nakit olarak eklenir." Birim fiyat / komisyon alanları gizli.
   - Bedelsiz: "Bedelsiz oranı (%)", altında "Örn. %100: her hisseye 1 yeni hisse; toplam maliyet değişmez."
10. **[IslemListesi.vue](frontend/src/components/IslemListesi.vue)**: rozet `bag_turu`'na göre "Temettü" / "Aktarım"; temettü satırında net tutar (ve stopaj), bedelsizde "%100"; tür renkleri (`TUR_RENGI`).
11. **Pozisyon ve özet gösterimi**: [PozisyonListesi.vue](frontend/src/components/PozisyonListesi.vue) hisse satırında temettü > 0 ise "Temettü ₺…" küçük satırı; [OzetView.vue](frontend/src/views/OzetView.vue) toplam kartındaki "Gerçekleşen (satışlar)" yanına "Temettü" kutusu (toplam > 0 ise); hesap detayı başlığında da aynı bilgi.

## Testler
- `backend/tests/Feature/TemettuTest.php`:
  - Temettü iki kayıt yazar; hisse adedi / maliyeti değişmez, pozisyon `temettu` = net, hesap nakdi artar, toplam `temettu` doğru; stopajla brüt.
  - Elde olmayan hisseye (ya da hisse olmayan varlığa) temettü / bedelsiz → 422.
  - Silme ikisini birden siler; nakit sonradan çekildiyse silme reddedilir; düzenleme reddedilir; dahil/hariç ikisini birlikte değiştirir.
  - `gecmis()`: temettü günü `gerceklesen` artar, dönem K/Z temettüyü sayar.
  - Bedelsiz: 100 adet / 1.000 TL + %50 → 150 adet, maliyet 1.000, ortalama 6,6667; sonra 150 satış geçerli; bedelsizi silmek bu satışı bozuyorsa 422; maliyeti bilinmeyen kısım da oranla büyür.
  - Kullanıcı izolasyonu: başka hanenin hesabına temettü girilemez, başkasının temettüsü silinemez.
- `IslemVeHesapTest` / `PozisyonAktarimTest`: mevcut aktarım satırları `bag_turu=aktarim` ile aynen çalışıyor.
- vitest: `islemTurleri('hisse')` yeni türleri içerir, nakitte / BES'te içermez.

## Belgeler
CLAUDE.md "Portföy hesabı tek yerde" maddesine temettü / bedelsiz kuralı (bir cümle); ILERLEME: "Temettü / faiz / kira geliri" → "Faiz / kira geliri; bedelli sermaye artırımı" kalır, temettü "Son tamamlananlar"a.

## Doğrulama
1. Laragon MySQL açık olmalı; `php artisan migrate:fresh --seed`, `php artisan test` (tamamı), Pint yalnız işin dosyalarına.
2. `npm run type-check && npm run lint`; vitest PowerShell'den (`D:\`).
3. Yerelde (8001 + 5174, `ben@finans.test`): bir hisseye temettü gir → hesapta Nakit artıyor, hisse satırında "Temettü ₺…", İşlemler'de "Temettü" rozetli iki kayıt, silince ikisi gidiyor; %100 bedelsiz → adet iki katı, birim maliyet yarısı; telefon genişliğinde form taşmıyor.
4. Yayın `bash backend/deploy/yayinla.sh` (migration cron'da çalışır), canlıda kısa deneme.
