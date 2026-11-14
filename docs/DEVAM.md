# Devam noktası (yarım işler)

> Kural: CLAUDE.md → "Kaldığın yeri kaydet". Oturum başında `git pull`'dan sonra burayı oku; dolu bir iş varsa oradan devam et.

## Gelir türleri hanenin listesi ([planlar/gelir-turleri.md](planlar/gelir-turleri.md))
İstek: "ek ders" gibi gelir türünü kullanıcı kendisi ekleyebilsin. Sabit enum (`GelirTuru`) → `gelir_turleri` tablosu (hane başına, varsayılan 8 tür), `gelir_turu_id`.

- [x] 1. Arka uç: migration `…001000` (veri taşıma; yerelde ileri / geri / ileri denendi, sayılar tuttu), model, `/aile/gelir-turleri`, rapor (`turler[].gelir_turu_id`, `etiket`), varsayılanlar, demo, testler (118)
- [ ] 2. Arayüz: store, `GelirTuruSecici` (formda "Yeni tür"), Ayarlar → Gelir türleri, listeler; vitest
- [ ] 3. Tüm testler, uçtan uca 360, CLAUDE.md / ILERLEME, bu kaydı sil

**Sıradaki adım:** 2 (arayüz şu an eski `tur` alanını gönderiyor: gelir formu bozuk). Ayrıca yayımlanmamış: `4c6413c` (fatura dönemi = kesim ayı düzeltmesi; kullanıcı onayı bekleniyor).

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
