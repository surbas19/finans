# Bütçe → "Tasarruf" sekmesi + başkasına verilen gün alımı hesaplara girmez

## Context
Kullanıcı (2026-11-15): (1) Bütçe içine **Tasarruf** bölümü: aylık **gelir, gider ve tasarruf oranları**; ayrıca **dönem dönem tasarruf kalemleri ve toplamları**. (2) **Gün** için yapılan tasarruflarda **"kime" notu doluysa** alım **hiçbir hesaba eklenmesin** (başkasına verilmiştir), **yalnızca aylık tasarruf toplamına** girsin.
Önceki işler (Gelir/Gider, taksit, Gün, Dahil/Aktar) canlıda değil; bu iş ayrı commit'ler.

## Keşif özeti
- `Tasarruf` modeli (`tasarruflar`): `islem_id` zorunlu + `unique`, `hesap_id` zorunlu; kalem özeti `ozet()` varlık adını `islem.varlik`'tan alır. `TasarrufController@store` her zaman portföye alış `Islem` yazar; `@destroy` işlemi siler (cascade).
- `ButceRaporu::tasarrufOzeti(haneId, donem)` (ay toplamı + kalemler) ve `aylikOzet` (`gelir`, `gider`, `tasarruf`, `fark`, `kalan`); `kalan = gelir − gider − tasarruf`. Tasarruf Özet'te küçük bölüm (`AileOzetView` "Tasarruf" + Günler).
- Gün alımı: `TasarrufFormu` (`gun` prop → hesap/varlık/miktar hazır, not "Kime verildi / açıklama"), `POST aile/tasarruflar` + `gun_id`.
- Bütçe sekmeleri `layouts/AileDuzeni.vue` `SEKMELER`; rotalar `router/index.ts` (`aile` çocukları).

## Tasarım

### A. Kime notu doluysa hesaba eklenmez (backend)
- **Migration**: `tasarruflar.islem_id` ve `hesap_id` **nullable** (unique `islem_id` kalır; NULL'lar çakışmaz); yeni `varlik_id` (nullable FK) ve `miktar` decimal(20,8) nullable — işlemsiz kayıtta ne alındığı bunlardan gösterilir (eski kayıtlar `islem`'den okunmaya devam eder).
- **`TasarrufController@store`**: `gun_id` dolu **ve** `not` boş değilse "verilen alım" dalı: **`Islem` yazılmaz, portföy / hesap toplamı / zekât etkilenmez**; `hesap_id` zorunlu olmaktan çıkar; `Tasarruf` satırı `islem_id=null, hesap_id=null, varlik_id, miktar, tutar_tl (miktar × birim fiyat; nakitte miktar), gun_id, not` ile yazılır. Not boşsa mevcut davranış aynen (hesaba alış). BES / ileri tarih kuralları aynen.
- **`@destroy`**: `islem_id` null ise yalnızca tasarruf satırı silinir (portföy denetimi gerekmez). `Tasarruf::ozet()`: `hesap_ad` null, `varlik_*` `islem`'den yoksa kendi `varlik_id`'sinden, yeni alan **`hesaba_eklendi: bool`**.
- Kural yalnızca Gün alımına uygulanır; normal "Tasarruf yap" etkilenmez.
- **Ön yüz** (`TasarrufFormu`): gün alımında not yazılınca "Hesap" alanı gizlenir ve "Kime verildiği yazıldığı için bu alım hiçbir hesaba eklenmez; yalnız aylık tasarruf toplamına girer." uyarısı çıkar; hesap yalnız not boşken zorunlu. Listelerde bu satırlar "Verildi" rozetli, hesap yerine "hesaba eklenmedi".

### B. Bütçe → Tasarruf sekmesi
- **Backend**: `ButceRaporu::tasarrufDonemleri(haneId, yil)` (12 ay): her ay `gelir`, `gider`, `tasarruf` toplamları (mevcut `gelirOzeti` / `giderOzeti` / `tasarrufOzeti` yeniden kullanılır, kuruş), **oranlar** `tasarruf_orani = tasarruf / gelir`, `gider_orani`, `kalan_orani` (gelir 0 ise `null` → "—"), ayın tasarruf **kalemleri**; yıllık toplam + ortalama oranlar. Uç `GET aile/tasarruf-ozeti?yil=2026` (`TasarrufOzetController`, hane süzgeci).
- **Ön yüz**: yeni rota `aile-tasarruf` (`aile/tasarruf`), `AileDuzeni` sekmesi "Tasarruf", `views/aile/TasarrufView.vue`:
  - Yıl seçici (‹ 2026 ›) + **yıl özet kartı**: toplam gelir, gider, tasarruf, ortalama tasarruf oranı.
  - **Aylık oran satırları**: her ay Gelir / Gider / Tasarruf tutarı ve yığılmış CSS çubuğu (gider kırmızı, tasarruf sky, kalan yeşil, yüzdeler yazılı).
  - **Dönem dönem akordiyon** (PrimeVue `Accordion`, Özet'teki desen): ay başlığında "Kasım 2026 · ₺X tasarruf (%Y)"; açılınca o ayın tasarruf kalemleri (varlık, miktar, tutar, hesap ya da "Verildi · kime", gün adı) ve ay toplamı; silme (mevcut `aileApi.tasarrufSil`). Boş ay "Bu ay tasarruf yok".
  - "Tasarruf yap" / "Gün ekle" düğmeleri mevcut `TasarrufFormu` / `TasarrufGunuFormu`'yu açar; Özet'teki küçük bölüm kalır, "Tüm dönemler" bağlantısı eklenir.
- CLAUDE.md tasarruf satırı güncellenir (nullable `islem_id`, verilen alım kuralı, Tasarruf sekmesi).

### C. Kart son ödeme hatırlatıcıları → Kartlar sayfası (akordiyon)
Kullanıcı: "kartların son ödeme hatırlatıcıları akordiyon olarak Kartlar sayfasında kartların altına alınsın"; karar: **yalnız Kartlar sayfasında** (Özet'ten kalkar; fatura ve zekât uyarıları Özet'te kalır).
- `KartlarView`: kart kaydırıcısının altına PrimeVue `Accordion` (tek panel "Son ödeme hatırlatıcıları (N)", varsayılan açık): zaten yüklü `ekstreler`den **onaylı + ödenmemiş + son ödeme tarihi dolu** ekstreler, tarihe göre; satır = `KartEtiketi` (kart rengi), son ödeme tarihi, kalan gün ("3 gün kaldı" / "bugün" / "2 gün geçti"; geçen kırmızı, ≤3 gün amber), dönem borcu, `aile-ekstre` bağlantısı. Liste boşsa panel gizli. 320 px taşma için Özet akordiyonundaki `.p-accordioncontent-wrapper` `min-w-0 overflow-hidden` çözümü + `min-w-0`/`break-words`.
- `AileOzetView`: uyarı bloğundaki `son_odemeler` satırları ve `uyariVar` koşulundaki `son_odemeler` terimi kaldırılır. Backend `uyarilar.son_odemeler` alanı API uyumu için kalır (değişiklik yok).
- Vitest: Kartlar akordiyonu (satırlar, kalan gün metni, ödenmiş / taslak yok), Özet'te kart son ödeme satırı yok.

### D. İşlem numarası, adet ve gün açıklaması (ek istekler)
Kullanıcı: "her işleme bir işlem numarası ve küçük harflerle bir yere yaz, kırmızı renkte; tasarruflarda alınan malzemenin adet bilgisi de olsun; gün için alındıysa açıklaması olsun; aylık tasarruflarda gün için alınan fakat bizde olmayan varlıklar da gözüksün." Karar: işlem numarası **yalnız portföy işlemleri ve tasarruf kalemleri**.
- **İşlem no**: yeni sütun yok; mevcut kimlikten türetilir (`ozet()` yanıtına `no`: portföy işlemi `İ-{id}`, tasarruf `T-{id}`; kalıcı, değişmez, silinince yeniden kullanılmaz). Ortak küçük bileşen `components/IslemNo.vue` (`text-[10px] text-red-600 font-medium lowercase`, `data-test="islem-no"`, `aria-label="İşlem numarası"`): `IslemListesi` satırında (telefon + masaüstü, tarih / tür satırının yanında) ve tasarruf kalemlerinde (Özet "Tasarruf", Tasarruf sekmesi akordiyon kalemleri). Aktarım çifti iki ayrı `İ-` numarası taşır (kayıtlar ayrı).
- **Adet**: tasarruf kalemi yanıtı `miktar` + `birim` (işlem varsa `islem.miktar`, verilen alımda kendi `miktar`/`varlik`) — satırda "5 gr Gram altın · ₺15.000" biçimi; nakitte miktar yazılmaz (tutar yeterli).
- **Gün açıklaması**: gün alımı kalemi `gun_ad` + not ("Kime verildi / açıklama") satırın altında gösterilir ("Mahalle altın günü — Ayşe teyze"); işlemsiz (verilen) kalemler "Verildi" rozetiyle, ayın tasarruf toplamında ve Tasarruf sekmesi dönem akordiyonunda **adet + açıklama ile görünür** (hesapta görünmeyen, yalnız tasarruf toplamında olan kalemler: istek gereği).
- Testler: `ozet()` `no` alanları, `TasarrufOzetTest` verilen alımın `miktar` / `gun_ad` / `not` ile ay kalemlerinde çıkması; Vitest `IslemNo` (kırmızı, küçük, doğru önek), `IslemListesi` / tasarruf satırında görünürlük.

### E. Her işleme "Dahil / Hariç" + alım-satım tarihi ve işlem (kayıt) tarihi
Kullanıcı: "her varlık **işlemi** için dahil / değil işareti olacak, sadece varlık (satır) için değil; her işlem için alım-satım tarihi ile birlikte bir de işlem tarihi". Karar: **işlem tarihi = kaydın uygulamaya girildiği zaman (otomatik, değişmez)**; alım-satım tarihi mevcut `tarih`.
- **İşlem düzeyinde dahil**: `islemler.dahil` boolean (default true; mevcut satır düzeyi `pozisyon_ayarlari` bayrağı **kalır**, ikisi birlikte çalışır: satır hariçse hepsi hariç; satır dahilse yalnız hariç işaretli işlemler yok sayılır). `PortfoyHesabi::hesapla` / `gecmis` pozisyonu **yalnız dahil işlemlerle** kurar (hariç işlem hesapta yokmuş gibi; satış > eldeki ise mevcut koruma gibi sessizce atlanır), böylece genel toplam, hesap / varlık toplamı, dağılım, performans ve **zekât** otomatik uyar. Hariç işlem İşlemler listesinde soluk + "Hariç" etiketiyle görünür.
- **Uç**: `PUT /api/v1/islemler/{islem}/dahil` {dahil}: `Gate::authorize('update', $islem)`; kapatırken kalan **dahil** işlemler `PortfoyHesabi::dogrula`'dan geçmezse (örn. alışı kapatınca sonraki dahil satış açığa düşer) 422 ve açıklayıcı mesaj; aktarım kaydı (`aktarim_kodu`) için eşleşik tüm kayıtlar birlikte değişir. Hane izolasyonu + "başka hane değiştiremez" testi.
- **Ön yüz**: `IslemListesi` satırında (telefon + masaüstü) küçük Dahil / Hariç düğmesi (`data-test="islem-dahil"`, ikon + kısa yazı, telefonda yalnız ikon — Pozisyon düğmeleri gibi taşma yok); tasarruf kalemi satırında portföy işlemi varsa aynı düğme.
- **Tarihler**: `ozet()` yanıtına `islem_zamani` (= `created_at`, ISO) eklenir; `IslemListesi` ve tasarruf kalemlerinde "Alım/Satım: 10.11.2026 · İşlem: 15.11.2026 14:32" biçiminde, işlem numarasıyla (kırmızı, küçük) aynı satırda küçük gri yazı (`bicim.ts` `tarihSaat()`). Tür etiketine göre "Alım tarihi" / "Satım tarihi" / "Elde olan" yazılır. Düzenleme formu etkilenmez (`tarih` düzenlenebilir, işlem zamanı değil).
- Testler: işlem hariç → toplam / zekât değişir, geri açınca döner; alışı kapatma satışı bozuyorsa 422; aktarım çifti birlikte; başka hane 403; `islem_zamani` dolu ve düzenlemeyle değişmez; Vitest `IslemListesi` (dahil düğmesi, hariç satır, tarih satırı).

## Aşamalar (commit + push; plan `docs/planlar/butce-tasarruf-sekmesi.md`; DEVAM/ILERLEME)
0. Kart son ödeme hatırlatıcıları (Kartlar akordiyonu, Özet'ten kaldır) + Vitest + e2e.
1. Backend: migration, `store/destroy/ozet` verilen alım kuralı + testler.
2. Backend: `tasarrufDonemleri` + uç + testler.
3. Ön yüz: `TasarrufFormu` verilen alım davranışı + Özet rozetleri + Vitest.
4. Ön yüz: Tasarruf sekmesi (rota, sekme, `TasarrufView`, API) + Vitest.
5. Tüm testler, Pint / prettier, Edge e2e (320 / 360 / 1280), belgeler. Yayın ayrı onayla.

## Doğrulama
- `TasarrufTest` / `TasarrufGunuTest`: `gun_id` + dolu not → **`Islem` yok**, hesap / portföy toplamı değişmez, `tasarruf.toplam` artar, `hesaba_eklendi=false`; boş not → eskisi gibi alış; silme işlemsiz kaydı siler; not dolu ama `gun_id` yok → eski davranış; BES / ileri tarih / başka hanenin günü reddedilir; **başka hane tasarruf özetini göremez**.
- Yeni `TasarrufOzetTest`: 12 ay, oranlar (gelir 0 → null), verilen alım ay toplamında, hane izolasyonu, kuruş doğruluğu.
- Vitest: `TasarrufView`, `TasarrufFormu` (not doluyken hesap gizli + uyarı).
- Tarayıcı: Gün alımında "Kime verildi" yaz → Portföy toplamı değişmez, Tasarruf sekmesinde ay toplamına girer; not boşken portföye yazılır; 320/360'ta taşma yok.
- `php artisan test`, `npm run type-check && npm run lint && npx vitest run`.

## Kapsam dışı
Geçmiş tasarruf kayıtlarını toplu dönüştürme, hedef / limit, yeni grafik kütüphanesi (çubuklar CSS), canlı yayın.
