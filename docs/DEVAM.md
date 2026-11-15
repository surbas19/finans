# Devam noktası (yarım işler)

> Kural: CLAUDE.md → "Kaldığın yeri kaydet". Oturum başında `git pull`'dan sonra burayı oku; dolu bir iş varsa oradan devam et.

## Yapay zekâ ile ekstre analizi ([planlar/ai-ekstre.md](planlar/ai-ekstre.md))
İstek: PDF → OpenAI Responses API (input_file) → standart JSON → PHP doğrulama → önizleme → onaylı aktarım. Kararlar: PDF gider (her seferinde onay, store:false); yapay zekâ ana yol, PHP okuyucu yedek + çapraz kontrol; banka hesabı yalnız önizleme (hesap_ekstreleri); bonusSureyya.pdf anonim fixture depoya (PDF girmez). Bonus gider sayılmaz (puanla ödenen).

- [x] 1. Mimari rapor (planda) + CLAUDE.md / CANLI.md gizlilik metni
- [x] 2. Yeni türler bonus (gider 0) / faiz / vergi: enum, mutabakat, okuyucu, AI eşlemesi, ön yüz; testler (arka uç 119, ön yüz 70)
- [x] 3. Referans fixture (`ekstre:fixture`, tests/Fixtures/ekstre/garanti_bonus) + GarantiReferansEkstreTest (75 satır tek tek; mutabakat 100.632,22 tutuyor). Fark nedeni: Garanti "BONUS …" satırlarının rakamı Bonus (TL) sütununda (TL değil) → bonus_hareketleri; özet kontrolü birleşik (dönem hareketi); yazılı "Hesap Kesim Tarihi" türetilenden önce (10 Kasım)
- [x] 4. JSON şeması v2 (direction, 13 tür, döviz, needs_review, ayrıntılı özet + banka hesabı alanları, bonus earned/used, page_audit page_number/found_count/status) + istem v2 (PDF + [SAYFA n] metni, bonus sütunu TL değil); TransactionValidator / StatementAuditService / StatementImporter v2 alan adlarına uyarlandı; EkstreSemasiTest (strict kuralları)
- [x] 5. OpenAIClient: govde (input_file base64 "ekstre.pdf" + detail + temizlenmiş [SAYFA n] metni + max_output_tokens), baslat / sor / iptal (arka plan), senkron yol; hata kodları + arka_plan_yok / dosya_buyuk / iptal / model_hatasi; config/ekstre.php + .env örnekleri; StatementAnalyzer hazirla / coz / analiz; okuyucu metinsizKabul; ekstre:ai-dene (yerel canlı deneme). **Yerel anahtar yok: canlı deneme yapılmadı.**
- [x] 6. Analiz motoru: migration'lar …001100 hesap_ekstreleri, …001200 ekstreler.ai_cikti + çalışma alanları (yerelde ileri / geri / ileri); PdfUploadService (finfo MIME + imza + EKSTRE_MAX_MB + SHA-256 + özel disk); EkstreAiIsleyici (baslat / defer / ilerlet / cron / iptal; tek etkin çalışma, koşullu UPDATE, kilit, 5 sn yoklama, 15 dk zaman aşımı, arka_plan_yok → cron senkron); EkstreAnalizController (başlat 202 / durum / iptal / json); HesapEkstresiController (yalnız önizleme, PDF analizden sonra silinir); yüklemede yapay_zeka onayı + metinsiz PDF; analiz sürerken düzeltme / onay kapalı; testler 132
- [x] 7. Doğrulama motoru: TransactionValidator v2 (taranmış sayfa / benzer satır / çoklu tutar / tür-yön / needs_review / düşük güven → inceleme; özet satırı ve uydurma → ret), DuplicateDetector (sayfadaki geçiş sayısı kadar; olası tekrar), StatementAuditService v2 (sayfa durumu, php_islem), StatementImporter v2 (denetim.php + denetim.capraz; PHP tutuyor / AI tutmuyorsa PHP satırları kalır; kesim farkı → kesim_teyit_gerekli), HesapEkstresiOnizleme (satır + yürüyen bakiye + açılış / kapanış), inceleme kapısı (EKSTRE_AI_AKTARIM, kart dışı belge, kesim teyidi, "Kontrol ettim"); testler 139
- [x] 8. Arayüz: useAnalizTakibi (3 sn yoklama; bitince / kapanınca durur, gizli sekmede bekler), AiAnalizPaneli (onay, başlat, aşamalar, süre, iptal, yeniden analiz uyarısı, son hata), AiDenetimPaneli (banka / tür, PDF okuyucu satırları korundu, çapraz farklar, kesim teyidi, sayfa denetimi, reddedilenler, maskeli JSON + kopyala), EkstreSatiri "İnceleme gerekli" / "Kontrol ettim", EkstreView "İnceleme" süzgeci + analiz sürerken / önizlemede onay kapalı, EkstreYukleDialog tür seçimi + onay, EkstrelerView (/aile/ekstreler), HesapEkstresiView; hane yanıtında yetenekler; vitest 73
- [ ] 9. Testler + uçtan uca; EKSTRE_AI_AKTARIM varsayılanı açılır
- [ ] 10. Rapor, ILERLEME, bu kaydı sil

**Sıradaki adım:** 9 (referansın yapay zekâ yarısı: tests/Fixtures/ekstre/garanti_bonus/ai_yaniti.json — beklenen + sentetik PDF'in sayfa metninden source_text; GarantiReferansEkstreTest'e Http::fake ile tam akış: 75 satır, mutabakat, çapraz uyumlu; ardından EKSTRE_AI_AKTARIM varsayılanı true (.env örnekleri, CANLI.md); uçtan uca 360 / 1280: sahte OpenAI uç noktası (OPENAI_URL yerel) ile yükle + onay → ilerleme → inceleme → onay, banka hesabı önizlemesi, taşma).

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
