# Devam noktası (yarım işler)

> Kural: CLAUDE.md → "Kaldığın yeri kaydet". Oturum başında `git pull`'dan sonra burayı oku; dolu bir iş varsa oradan devam et.


## Yayın bekleyenler
`main`'de yayımlanmamış, kullanıcı onayı bekleniyor: `4c6413c` (fatura dönemi = kesim ayı; dönemsiz faturaları tamamlayan migration) ve gelir türleri (migration `…001000`, var olan gelirleri taşır).

## Ekstre profilleri ([planlar/ekstre-profilleri.md](planlar/ekstre-profilleri.md))
İstek: Garanti BBVA ve Yapı Kredi ekstreleri 0 satır okuyor (tarih ay adıyla, CP1254 karakterler, banka etiketleri). Plan: normalizasyon → banka tanıma → banka profilleri → mutabakat → teşhis; ayrıca fatura dönemi = kesim ayı, asgari = borç × kart oranı (varsayılan %40).

- [x] 1. `MetinNormalizer` (kod sayfası, ay adları) + genel profilde `+` alacak / puan sütunu
- [x] 2. `BankaTanici` + Garanti / Yapı Kredi / Enpara profilleri
- [x] 3. Fatura dönemi = kesim ayı; asgari = borç × `kartlar.asgari_oran` (migration, kart formu)
- [x] 4. `EkstreMutabakati` + migration (`banka_profili`, `denetim`, `kalan_taksit`, `taksit_kalan`) + inceleme şeridi
- [x] 5. `EkstreTeshisi` + "0 satır — neden?" kutusu
- [x] 6. Tüm testler, CLAUDE.md, ILERLEME, bu kaydı sil, yayın

- [x] 7. Yayın: canlıda `b37ad94` (2026-11-14; 4 migration çalıştı; testler arka uç 114, ön yüz 68)

**Sıradaki adım:** canlıda Garanti / Yapı Kredi ekstrelerini yeniden yükleyip deneme; AI için canlı `.env`'e `OPENAI_API_KEY` (isteğe bağlı; yoksa kapalı). Sonra bu kaydı sil, ILERLEME'ye satır. Açık: bonusSureyya.pdf mutabakatı 1.305,73 TL tutmuyor (Garanti "-" ekli bonus satırları; PDF ile satır satır karşılaştırılmalı).

Gerçek PDF'ler yalnızca bu bilgisayarda (`C:\Users\surbas\Downloads`: Garanti `bonusSureyya.pdf`, Yapı Kredi `Kredi_Karti_Hesap_Ozeti_…20261015…pdf`, Enpara `10.11.2026 tarihli Enpara…pdf`); doğrulama betiği scratchpad'de (`e2e/ekstre-altin.php`), depoya girmez. Komutlar: `cd backend && php artisan migrate && php artisan test`; `cd frontend && npm run type-check && npm run lint && npx vitest run`.

## Bütçe: Giderler, Tasarruf, elle ekstre ([planlar/butce-giderler-tasarruf.md](planlar/butce-giderler-tasarruf.md))
İstek (2026-11-15): düzenli giderler (eğitim, bakıcı…) + kartlar aylık dönemde; "Tasarruf yap" (altın / hisse / döviz → portföy hesabı, ay içinde görünür, Gelir − Gider − Tasarruf = Kalan); ekstreyi dosyasız, yalnızca tutarla girme.

- [x] 1. Düzenli giderler (arka uç): migration `2026_11_15_000100`, `GiderKaynagi`, `DuzenliGider`, `GiderTakvimi`, controller'lar, `ButceRaporu` (gider.duzenli, gider.beklenenler), `GiderTest`
- [x] 2. Tasarruf (arka uç): `tasarruflar`, `TasarrufController`, rapor (`tasarruf`, `kalan`), test
- [x] 3. Elle ekstre (arka uç): `EkstreIceAktarici::elleOlustur`, `POST ekstreler/elle`, test
- [ ] 4. Arayüz: Giderler sekmesi, Özet'te Gelir/Gider/Tasarruf/Kalan + beklenen giderler + "Tasarruf yap", "Elle ekstre gir"
- [ ] 5. CLAUDE.md, ILERLEME, e2e, yayın onayı

**Sıradaki adım:** 4. aşama (arayüz); API: gider-kaynaklari, duzenli-giderler, tasarruflar, ekstreler/elle, ozet (gider.duzenli, gider.beklenenler, tasarruf, kalan). PHP: `C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64` PATH'e eklenir; MySQL elle başlatılır (açılışı ~1 dk).
