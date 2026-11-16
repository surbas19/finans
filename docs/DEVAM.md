# Devam noktası (yarım işler)

> Kural: CLAUDE.md → "Kaldığın yeri kaydet". Oturum başında `git pull`'dan sonra burayı oku; dolu bir iş varsa oradan devam et.


## Yayın bekleyenler
Yok: canlıda yayın bekleyen yok (son yayın 2026-11-15: dış yapay zekâ ekstre girişi, zekât).

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

## MySQL'de doğrulama bekleyenler (2026-11-16)
Laragon MySQL kapalıyken arka uç testleri bellek içi SQLite ile çalıştırıldı: `DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test` → 258 testten 251 geçti. Kalan 7 test değişiklik öncesi kodda da yalnız SQLite'ta kırılıyor (tarih / benzersizlik / harf duyarlılığı farkı):
- EkstreTest ×2
- ElleEkstreTest
- FiyatGuncelleyiciTest
- GelirTest
- KartIslemiTest `test_tarih_araligi`
- VarlikTest

Kapsanan işler: temettü (TemettuTest), şirket geçmişi (SirketGecmisiTest), ekstre ayı = son ödeme ayı (EkstreAyiTest, FaturaTest; yeni migration `2026_11_21_000100_ekstre_ayi_son_odeme_ayi`).
- **Sıradaki adım:** MySQL açılınca `php artisan migrate` + `php artisan test` (hepsi geçmeli); sonra bu kaydı sil. Canlıya alınırsa migration cron'da çalışır (taksit rapor ayı ve kaynağı "kesim" faturaların dönemi yeniden yazılır).

## Temettü ve bedelsiz ([planlar/temettu.md](planlar/temettu.md))
- Durum: başladı (2026-11-16); TemettuTest SQLite'ta geçti
- Yapılanlar: migration `bag_turu`, `IslemTuru` temettu / bedelsiz, `Pozisyon`, `PortfoyHesabi` (temettu alanı, gecmis), `TemettuKaydi`, `IslemController`, `Islem::ozet`, demo verisi, `TemettuTest` (yazıldı, **çalıştırılmadı: MySQL kapalıydı**); arayüz (tipler, form, liste, pozisyon / özet / hesap başlığı, `temettu.spec.ts`); type-check, lint, vitest 134/134 geçti (bu makinede paralelde Bütçe testleri 5 sn zaman aşımına düşüyor; `--testTimeout=30000` ile hepsi geçiyor)
- **Sıradaki adım:** Laragon MySQL açılınca `php artisan migrate:fresh --seed` + `php artisan test`; geçerse CLAUDE.md / ILERLEME, bu kaydı sil, yayın
- Komutlar: `cd backend && php artisan migrate && php artisan test`; frontend `npm run type-check && npm run lint`, vitest PowerShell'den `D:\`
