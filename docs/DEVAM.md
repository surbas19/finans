# Devam noktası (yarım işler)

> Kural: CLAUDE.md → "Kaldığın yeri kaydet". Oturum başında `git pull`'dan sonra burayı oku; dolu bir iş varsa oradan devam et.

## Ekstre profilleri ([planlar/ekstre-profilleri.md](planlar/ekstre-profilleri.md))
İstek: Garanti BBVA ve Yapı Kredi ekstreleri 0 satır okuyor (tarih ay adıyla, CP1254 karakterler, banka etiketleri). Plan: normalizasyon → banka tanıma → banka profilleri → mutabakat → teşhis; ayrıca fatura dönemi = kesim ayı, asgari = borç × kart oranı (varsayılan %40).

- [x] 1. `MetinNormalizer` (kod sayfası, ay adları) + genel profilde `+` alacak / puan sütunu
- [x] 2. `BankaTanici` + Garanti / Yapı Kredi / Enpara profilleri
- [x] 3. Fatura dönemi = kesim ayı; asgari = borç × `kartlar.asgari_oran` (migration, kart formu)
- [x] 4. `EkstreMutabakati` + migration (`banka_profili`, `denetim`, `kalan_taksit`, `taksit_kalan`) + inceleme şeridi
- [x] 5. `EkstreTeshisi` + "0 satır — neden?" kutusu
- [ ] 6. Tüm testler, CLAUDE.md, ILERLEME, bu kaydı sil, yayın

**Sıradaki adım:** 6 (onaylı AI yedeği: `OPENAI_API_KEY` / `OPENAI_MODEL`, kişisel veri temizleyici, `app/Services/AIStatement`), sonra 7 (yayın). Açık: bonusSureyya.pdf mutabakatı 1.305,73 TL tutmuyor (Garanti "-" ekli bonus satırları; PDF ile satır satır karşılaştırılmalı).

Gerçek PDF'ler yalnızca bu bilgisayarda (`C:\Users\surbas\Downloads`: Garanti `bonusSureyya.pdf`, Yapı Kredi `Kredi_Karti_Hesap_Ozeti_…20261015…pdf`, Enpara `10.11.2026 tarihli Enpara…pdf`); doğrulama betiği scratchpad'de (`e2e/ekstre-altin.php`), depoya girmez. Komutlar: `cd backend && php artisan migrate && php artisan test`; `cd frontend && npm run type-check && npm run lint && npx vitest run`.
