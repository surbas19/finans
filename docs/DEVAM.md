# Devam noktası (yarım işler)

> Kural: CLAUDE.md → "Kaldığın yeri kaydet". Oturum başında `git pull`'dan sonra burayı oku; dolu bir iş varsa oradan devam et.

## Profil resmi ([planlar/profil-resmi.md](planlar/profil-resmi.md))
İstek: kullanıcı profil resmini değiştirebilsin. Seçimler: otomatik kare kırpma (tarayıcıda 256×256), görünüm üst çubuk + Profil + Yönetim + Hesaplar sahibi + Bütçe üyeleri. Özel diskte, yalnızca kendisi / hane / yönetici görür.

- [x] Arka uç: migration, User::resimAdresi, ProfilResmiController, Hesap / HaneUyesi alanları, testler
- [ ] Arayüz: KisiResmi, profilResmi.ts, ProfilView, üst çubuk, Yönetim, Hesaplar, Bütçe + testler
- [ ] Tüm testler, uçtan uca (360 / 1280)
- [ ] ILERLEME satırı, bu kaydı sil, commit + push, canlıya yayın

**Sıradaki adım:** arayüz (KisiResmi, profilResmi.ts, ProfilView).

Komutlar: `cd backend && php artisan migrate && php artisan test`; `cd frontend && npm run type-check && npm run lint && npx vitest run`.
