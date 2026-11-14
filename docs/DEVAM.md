# Devam noktası (yarım işler)

> Kural: CLAUDE.md → "Kaldığın yeri kaydet". Oturum başında `git pull`'dan sonra burayı oku; dolu bir iş varsa oradan devam et.

## Yapay zekâ ile ekstre analizi ([planlar/ai-ekstre.md](planlar/ai-ekstre.md))
İstek: PDF → OpenAI Responses API (input_file) → standart JSON → PHP doğrulama → önizleme → onaylı aktarım. Kararlar: PDF gider (her seferinde onay, store:false); yapay zekâ ana yol, PHP okuyucu yedek + çapraz kontrol; banka hesabı yalnız önizleme (hesap_ekstreleri); bonusSureyya.pdf anonim fixture depoya (PDF girmez). Bonus gider sayılmaz (puanla ödenen).

- [ ] 1. Mimari rapor + CLAUDE.md / CANLI.md gizlilik metni
- [ ] 2. Yeni türler bonus / faiz / vergi
- [ ] 3. Referans fixture (metin) + PHP satır satır testi, 100.632,22 mutabakatı
- [ ] 4. JSON şeması v2 + istem v2
- [ ] 5. OpenAI: input_file, detail, arka plan modu, ayarlar; yerel canlı deneme (anahtar varsa)
- [ ] 6. Analiz motoru: migration'lar, PdfUploadService, EkstreAiIsleyici, defer + cron, uçlar, metinsiz PDF
- [ ] 7. Doğrulama motoru: TransactionValidator v2, DuplicateDetector, denetim, çapraz kontrol, maskeleme, inceleme kapısı
- [ ] 8. Arayüz: aktarım merkezi, ilerleme, JSON görünümü, inceleme, banka hesabı önizlemesi
- [ ] 9. Testler + uçtan uca; EKSTRE_AI_AKTARIM varsayılanı açılır
- [ ] 10. Rapor, ILERLEME, bu kaydı sil

**Sıradaki adım:** 1.

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
