# Nakit harcamada Ev / Banka + ev nakdi takibi + Borç / Alacak defteri

## Context
Bugün nakit harcama (`NakitHarcamaFormu` → `duzenli_giderler`) hiçbir paradan düşmüyor: yalnızca Bütçe'de o ayın kartsız gideri sayılıyor, Hesaplar'daki Nakit (TL) bakiyesi değişmiyor. Kullanıcı: harcama anında **Ev / Banka** seçilsin; **ev nakdi ayrı takip edilsin** (kasa: başlangıç bakiyesi + eklenenler − ev harcamaları, Bütçe'de görünsün; banka yalnız etiket); ayrıca **borç / alacak basit defteri** (Bütçe sekmesi; Bütçe gider / gelir toplamlarına karışmaz). Cevaplar: ev nakdi ayrı takip, basit defter, Bütçe sekmesi.

## İş 1 — Ödeme yeri (Ev / Banka) + Ev nakdi
**Arka uç**
- Migration (yeni, `2026_11_19_…`): `duzenli_giderler.odeme_yeri` `string(10)` nullable (`ev` | `banka`; eski kayıtlar null = belirtilmemiş, kasaya dokunmaz); yeni tablo `ev_nakdi_hareketleri` (id, hane_id → haneler cascade, `tur` `ekleme`|`cikarma`, `tutar` decimal(15,2), `tarih`, `not` nullable, `olusturan_id`, timestamps, index hane_id).
- `HaneYonetimi::AILE_TABLOLARI`'na `ev_nakdi_hareketleri` (+ `borclar`, `borc_odemeleri`) eklenir (hane taşıma / silme tutarlılığı).
- `DuzenliGider`: `fillable` + `ozet()` `odeme_yeri`; `DuzenliGiderController::dogrula`: `odeme_yeri` `nullable|in:ev,banka`.
- Yeni `App\Models\EvNakdiHareketi` (`HaneyeAit`) + `App\Services\Aile\EvNakdi` (tek kaynak, `Kurus` ile): `bakiye(haneId)` = Σ ekleme − Σ cikarma − Σ(`duzenli_giderler` `odeme_yeri='ev'` tutarı); `ozet()` bakiye, giren, çıkan, ev harcaması toplamı, son hareketler (hareket + ev harcamaları tarih sırasıyla, en çok 20).
- `EvNakdiController` + rotalar: `GET aile/ev-nakdi`, `POST aile/ev-nakdi/hareketler` (tür, tutar > 0, tarih ≤ bugün, not), `DELETE aile/ev-nakdi/hareketler/{hareket}` (`Gate hane-kaydi`). Bakiye eksiye düşebilir (engellenmez; ekranda kırmızı uyarı).
- `ButceRaporu`: gider toplamı **değişmez** (ev / banka ayrımı yalnız etiket + kasa); `duzenli.kalemler` `ozet()` ile `odeme_yeri`'ni zaten taşır; Özet yanıtına `ev_nakdi` bakiyesi eklenmez (ayrı uç yeterli).

**Ön yüz**
- `NakitHarcamaFormu`: tutarın altına "Ödeme yeri" `SelectButton` (Ev | Banka; varsayılan Ev), payload `odeme_yeri`; Ev seçiliyse küçük not "Ev nakdinden düşer". Kategori çipleri / diğer alanlar aynı.
- `GiderFormu` (tam form) ödeme yeri alanı: aynı seçim (isteğe bağlı).
- Bütçe Özet'te **"Ev nakdi" kartı** (`EvNakdiKarti.vue`): bakiye (büyük; eksiyse kırmızı), "Para ekle" / "Para çıkar" düğmeleri → küçük form (`EvNakdiHareketFormu.vue`: tür, tutar, tarih, not), altta son hareketler (silme düğmesi). Hızlı işlemler menüsüne ayrı satır eklenmez (kart yeterli).
- `api/aile.ts`: `evNakdi`, `evNakdiHareketEkle`, `evNakdiHareketSil`, `DuzenliGider.odeme_yeri` tipi.
- Gider listelerinde (Gelir/Gider ekranı) ev harcamasına küçük "Ev" / "Banka" etiketi.

## İş 2 — Borç / Alacak defteri (Bütçe → yeni sekme)
**Arka uç** (Bütçe toplamlarına, kasaya, zekâta karışmaz)
- Migration: `borclar` (id, hane_id, `yon` `borc`|`alacak`, `kisi` string(80), `tutar` decimal(15,2), `tarih`, `vade` nullable date, `not` string(500) nullable, `olusturan_id`, timestamps, index [hane_id, yon]); `borc_odemeleri` (id, borc_id cascade, hane_id, `tutar`, `tarih`, `not`, timestamps).
- `Borc` + `BorcOdemesi` modelleri (`HaneyeAit`), `ozet()`: `odenen`, `kalan` (kuruşla), `durum` (`acik` | `kapali` kalan 0) , `vade_gecti`.
- `BorcController` (`apiResource borclar` index/store/update/destroy; index yanıtı: kalemler + `toplam_borc` (açık borçlarım kalanı) + `toplam_alacak`); `BorcOdemesiController`: `POST aile/borclar/{borc}/odemeleri` (tutar > 0 ve ≤ kalan, tarih ≤ bugün), `DELETE aile/borclar/{borc}/odemeleri/{odeme}`. Hepsi `Gate hane-kaydi`, borç / ödeme farklı hanenin id'siyle erişilemez.

**Ön yüz**
- Rota `aile-borclar` (`/aile/borclar`), `AileDuzeni.vue` sekmesi "Borç / Alacak".
- `BorclarView.vue`: üstte iki özet (Borcum / Alacağım kalan toplamı), "Borç ekle / Alacak ekle" `SelectButton` + form dialog (`BorcFormu.vue`: yön, kişi, tutar, tarih, vade, not), liste kişi bazında (açık olanlar önce, kapalılar soluk), satırda kalan / ödenen ilerleme çubuğu, vadesi geçen için uyarı etiketi, satır dokununca ödeme/tahsilat dialogu (kalan tutarı önerir, geçmiş ödemeler + silme), düzenle / sil.
- Hızlı işlemler menüsüne "Borç / alacak ekle" satırı (`HIZLI_ISLEMLER` + `BorcFormu`).
- `api/aile.ts` uçları ve tipleri.

## Testler
- Arka uç (`tests/Feature/Aile/`): `EvNakdiTest` (bakiye formülü: ekleme, çıkarma, ev harcaması düşer, banka ve null düşmez; harcama silinince bakiye geri; hane izolasyonu: başka hane görmez / silemez; doğrulama), `DuzenliGiderTest` (odeme_yeri geçerli / geçersiz), `BorcTest` (oluştur, kısmi ödeme, kalan, kapanış, fazla ödeme reddi, silme, başka hane göremez / ödeme ekleyemez).
- Ön yüz: `nakitHarcama.spec.ts` (Ev/Banka seçimi payload), `evNakdi.spec.ts`, `borclar.spec.ts`, `hizliIslemler.spec.ts` güncelle.

## Aşamalar (her biri commit + push; plan `docs/planlar/ev-nakdi-borc.md`, DEVAM.md, ILERLEME.md, CLAUDE.md)
1. Arka uç ev nakdi + `odeme_yeri` + testler. 2. Ön yüz ev nakdi (form seçimi + Özet kartı) + testler. 3. Arka uç borç/alacak + testler. 4. Ön yüz borç/alacak + hızlı işlem. 5. Edge e2e (320/360/1280): ev seçilen harcama bakiyeyi düşürür, banka düşürmez, para ekleme, borç ekle → kısmi ödeme → kapanış (deneme kayıtları silinir). 6. Belgeler.

## Doğrulama
`DB_DATABASE=finans_test php artisan test`; `npm run type-check && npm run lint && npx vitest run`; Pint / prettier yalnız değişen dosyalara. Migration var (2 yeni): canlı yayında `deploy/KUR` migration'ı çalıştırır; yayın yalnız kullanıcı "Yayınla" derse.

## Kapsam dışı
Banka bakiyesi takibi (banka yalnız etiket), borç faizi / taksit planı, borcun Bütçe gider / gelirine yansıması, kişi rehberi, hatırlatma bildirimi.
