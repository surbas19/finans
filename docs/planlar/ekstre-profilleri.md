# Farklı bankaların ekstrelerini okuyan sistem

## Bağlam
Canlıda Garanti BBVA ve Yapı Kredi ekstreleri yükleniyor ama 0 satır çıkıyor. Gerçek PDF'lerle (yalnızca bu bilgisayarda; depoya girmez) teşhis:
- Tarihler ay adıyla (`22 Ekim 2026`); ayrıştırıcı yalnızca `gg.aa.yyyy` biliyor → işlem satırı yok.
- Garanti metni CP1254 → Latin-1 karışık geliyor (`Ýþlem`, `Kasým`); etiketler eşleşmiyor.
- Etiketler, taksit yazımı, alacak işareti (`+8.085,68`), puan sütunu, ara kategori satırları bankaya göre değişiyor.
- Enpara (`gg.aa.yyyy`) 8 satır okudu; asgari ödeme etiketi bulunamadı.

Mevcut "genel ayrıştırıcı" tek şekle yazılmış. Hedef: her bankaya **profil**, ortak **normalizasyon**, finansçı gözüyle **mutabakat denetimi** ve okunamayınca **neden** bilgisi.

## Mimari (hepsi `app/Services/Ekstre/`)
1. **Metin normalizasyonu** (`MetinNormalizer`, okuyucudan sonra, her profilden önce)
   - Yanlış kod sayfası düzeltme: `Ý→İ ý→ı þ→ş Þ→Ş ð→ğ Ð→Ğ` (metinde Türkçe sözcük sayısı artıyorsa uygula).
   - Ay adları → sayı: `22 Ekim 2026` → `22.10.2026` (Ocak…Aralık, kısaltmalar, büyük/küçük).
   - Boşluk / sekme sadeleştirme; birleşik yazılmış metin (`yapmamanýzveya…`) işlem sayılmaz.
2. **Banka tanıma** (`BankaTanici`): ilk 2 sayfadaki parmak izlerinden (`T. Garanti Bankası`, `Yapı ve Kredi Bankası` / `Worldpuan`, `Enpara` / `QNB`, `Akbank` / `Axess`, `İş Bankası` / `Maximum`). Tanınmazsa `genel` profili + uyarı "Banka tanınamadı, genel kurallarla okundu".
3. **Banka profili** (`Profil/{Garanti,YapiKredi,Enpara,Genel}.php`, ortak `EkstreProfili` arayüzü; mantık değil, **bildirimsel kurallar**):
   - `alanEtiketleri`: kesim / son ödeme / dönem borcu / asgari / önceki borç için bankanın yazımları (Garanti: "Toplam Borcunuz", kesim tarihi "Bir önceki hesap kesim tarihiniz" + 1 dönem ya da ekstre no `YYYYAAGG`; Yapı Kredi: "Ödenmesi Gereken Asgari Tutar/Oran").
   - `satirDeseni`: tarih biçimi, tutar sütunu konumu (sekmeyle ayrılmış son / ilk sayısal), alacak işareti (`+` önde, `-`, `CR`), yok sayılacak sondaki sütunlar (puan).
   - `taksitDesenleri`: `A x N = T n.Taksit` (Garanti), `kalan / N` + alt satır `T TL'lik işlemin n / N taksidi` (Yapı Kredi).
   - `devamSatiri`: bir sonraki satır önceki işleme aittir (taksit açıklaması); `atlanacak`: kategori başlıkları, "DİĞER HARCAMALARINIZ", sayfa altı metinleri, "TOPLAM".
   - `tabloBaslangici` / `tabloSonu`: "İşlem Tarihi … Tutar" başlığından "TOPLAM" / "PUAN ÖZETİ"'ne kadar; dışarıdaki tarihli satırlar (limit, sözleşme metni) işlem değil.
4. **Ortak boru hattı** (`EkstreAyristirici` sadeleşir): normalize → banka → başlık alanları → tablo satırları → devam satırlarını birleştir → tür / taksit / alacak → kuruşa çevir.
5. **Mutabakat denetimi** (`EkstreMutabakati`, finansçının yaptığı kontroller; sonuç `ekstreler.denetim` JSON, incelemede gösterilir):
   - Önceki dönem borcu − ödemeler + harcamalar + faiz / ücret − iadeler = dönem borcu (kuruş farkı toleransı 0).
   - Satır toplamı = tablodaki "TOPLAM" satırı.
   - Taksit: `A × N = T` tutuyor mu; kalan taksit tutarı = gelecek dönem yükümlülüğü (bütçe tahmini için `ekstreler.kalan_taksit`).
   - İşlem tarihleri dönem içinde mi (taksitlerde ilk işlem tarihi eski olabilir, uyarı değil).
   - Uyuşmazlıkta ekstre yine taslak olur, "mutabakat tutmuyor: X TL fark" uyarısıyla; kullanıcı onayı engellenmez ama neden görünür.
6. **Teşhis** (`EkstreTeshisi`, 0 satırda ya da yöneticiye): banka tanındı mı, sayfa / metin satırı sayısı, tablo başlığı bulundu mu, tanınmayan tarihli satır sayısı, **maskelenmiş** 3 örnek satır (rakamlar `#`, harfler korunur). Loga yazılmaz; yalnızca yanıt içinde. Arayüzde yükleme sonucu "0 satır okundu — neden?" kutusu.

## Kullanıcının istediği iki otomatik kural
7. **Fatura dönemi = ekstre kesim ayı** (`FaturaEslestirici`): kart satırından oluşan faturada hizmet dönemi açıklamadan okunamazsa ekstrenin kesim tarihinin ayı yazılır; `donem_kaynagi = 'kesim'` ("ekstre kesim ayından"). Açıklamada dönem varsa o öncelikli, kullanıcı seçimi her zaman üstün. Aynı sağlayıcı + aynı dönem tekrar denetimi bu dönemle çalışır. CLAUDE.md'deki "hizmet dönemi uydurulmaz" cümlesi "açıklamada yoksa kesim ayı sayılır (kaynağı belli)" olarak güncellenir.
8. **Asgari ödeme = dönem borcu × oran** (`EkstreIceAktarici::taslakYaz` ve `alanlariGuncelle`): PDF'ten asgari okunamazsa `dönem borcu × kart.asgari_oran / 100`, kuruşa yuvarlanır; `alan_kaynaklari.asgari_odeme = 'hesaplandi'`. Dönem borcu da değişirse hesaplanan asgari yeniden hesaplanır (kullanıcı elle girdiyse dokunulmaz). Kartta `asgari_oran` (unsignedTinyInteger, varsayılan **40**, 1–100; kart formunda "Asgari ödeme oranı (%)"). PDF'te asgari okunduysa o kullanılır ve orandan farklıysa uyarı ("ekstrede %…").

## Veri ve arayüz
- `kartlar`: `asgari_oran` (varsayılan 40); `KartFormu`'na alan, `Kart::ozet`'e. Ekstre incelemede asgari yanında "hesaplandı (%40)" etiketi; faturalarda "(kesim ayından)".
- `ekstreler`: `banka_profili` (string), `denetim` (json), `kalan_taksit` (decimal) — tek migration.
- `KartIslemi`: `taksit_toplam` zaten var; Yapı Kredi'nin "kalan / N" bilgisi `taksit_kalan` (decimal, null).
- İnceleme ekranı (`EkstreIncelemeView`): üstte "Garanti BBVA · mutabakat ✓ / ✗ (fark)" şeridi; satırda taksit `3/9`, kalan.
- Kart düzenlemede banka seçimi zaten var (`bankalar`); profil yine PDF'ten tanınır, banka alanı yalnızca ipucu.

## Testler
- Her profil için `Tests\Support\OrnekPdf` ile **uydurma** ama aynı düzende fixture (gerçek PDF'teki yazım kalıpları: ay adı, `+` alacak, puan sütunu, `x5=`, `/ 6`, kategori ara satırı, CP1254 karakterler). Gerçek PDF depoya girmez.
- `FaturaTest`: açıklamasız faturada dönem kesim ayı ve kaynağı `kesim`; açıklama / kullanıcı seçimi üstün. `EkstreTest`: asgari okunamayınca borç × %40 ve kaynağı `hesaplandi`; kart oranı %30 ise ona göre; borç düzeltilince yeniden hesap; elle girilen bozulmaz.
- `MetinNormalizerTest` (kod sayfası, ay adları), `BankaTaniciTest`, profil başına `…ProfiliTest` (alanlar, satır sayısı, taksit, alacak, tutar), `EkstreMutabakatiTest` (tutan / tutmayan), `EkstreTeshisiTest` (maskeleme: rakam sızmıyor).
- Yerel "altın" doğrulama betiği (scratchpad, depoya girmez): üç gerçek PDF → beklenen satır sayısı ve dönem borcu; her değişiklikte koşulur.

## Sıra (her adım ayrı commit, DEVAM.md kaydı)
1. Normalizasyon + genel profilde ay adı / `+` alacak / puan sütunu → gerçek üç PDF'te satırlar okunmalı (en büyük kazanç).
2. Banka tanıma + Garanti ve Yapı Kredi profilleri (etiketler, taksit, devam satırı, tablo sınırları); Enpara'ya asgari etiketi.
3. Fatura dönemi = kesim ayı; asgari = borç × kart oranı (migration `asgari_oran`, kart formu).
4. Mutabakat denetimi + migration + inceleme şeridi.
5. Teşhis kutusu.
6. Tüm testler, canlıya yayın; CLAUDE.md "Aile finansı" kuralına profil / mutabakat cümlesi; ILERLEME.

## Doğrulama
`php artisan test`; `npm run type-check && npm run lint && npx vitest run`; gerçek PDF betiği: Garanti ≥ 60 satır ve dönem borcu 100.632,22; Yapı Kredi 14 satır, borç 7.736,49, mutabakat tutuyor; Enpara 8 satır + asgari. Yerelde yükle → incele → onayla (360 / 1280). Geçici PDF kopyası tutulmaz.
