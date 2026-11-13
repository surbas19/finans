# Devam noktası (yarım işler)

> Kural: CLAUDE.md → "Kaldığın yeri kaydet". Oturum başında `git pull`'dan sonra burayı oku; dolu bir iş varsa oradan devam et.

## Aile finansı ([planlar/aile-finansi.md](planlar/aile-finansi.md)) — dal: `aile-finansi`
**Bu iş `aile-finansi` dalında.** Ana dala yalnızca kullanıcı onayıyla birleştirilir; canlıya yayın da onayla. Devam etmek için: `git fetch && git switch aile-finansi && git pull`.

İstek: hane (ben + eşim ayrı girişle aynı veriyi görür; mevcut yatırım hesapları da hanede ortak), gelirler (maaş + ek gelir, düzenli / düzensiz, geçmiş ay), bankalar / kartlar, PDF ekstre yükleme → inceleme → onay, faturaların (elektrik / su / doğalgaz…) ekstreden bulunup hizmet ayına bağlanması, aylık özet. PDF saklanır (özel klasör). Genel ayrıştırıcı (örnek PDF yok).

- [x] 1. Hane + mevcut hesapların hanede paylaşımı (erişim tek yerde) + Yönetim'de hane + testler
- [x] 2. Gelirler (kaynaklar, kayıtlar, beklenen) — arka uç + testler
- [x] 3. Bankalar, kartlar, kategoriler, kart işlemleri, rapor kuralları (ButceRaporu) — arka uç + testler
- [ ] 4. PDF ekstre (smalot): yükleme, ayrıştırma, inceleme, onay, tekrar, dosya erişimi — arka uç + testler
- [ ] 5. Faturalar: türler, sağlayıcılar, desenler, eşleştirme, otomatik kayıt, bağla / çöz — arka uç + testler
- [ ] 6. Arayüz: Bütçe bölümü (özet, gelirler, kartlar, ekstre inceleme, işlemler, faturalar, ayarlar), Hesaplar'da sahip, Yönetim'de hane
- [ ] 7. Demo verisi, belgeler (CLAUDE.md, CANLI.md), uçtan uca (360 / 320 / 1280), son gözden geçirme

**Sıradaki adım:** 4. PDF ekstre: `composer require smalot/pdfparser`, PdfMetinOkuyucu, EkstreAyristirici, EkstreIceAktarici (yükle / incele / onayla / sil), dosya ucu, testler (OrnekPdf).

Komutlar: `cd backend && php artisan migrate && php artisan test`; `cd frontend && npm run type-check && npm run lint && npx vitest run && npm run build`.
