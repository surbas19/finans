# Devam noktası (yarım işler)

> Kural: CLAUDE.md → "Kaldığın yeri kaydet". Oturum başında `git pull`'dan sonra burayı oku; dolu bir iş varsa oradan devam et.


## PWA (ana ekrana ekleme) + mobil cilalama
- Plan: [planlar/pwa.md](planlar/pwa.md)
- Durum: başladı (2026-11-13)
- Yapılanlar: ikonlar, manifest, sw.js, çevrimdışı sayfası, index.html, .htaccess, kurulum kartı (Profil + Özet ipucu), üst çubuk çentik payı, Özet yenile düğmesi; testler geçiyor
- **Sıradaki adım:** canlıya yayın ve canlıda manifest / sw.js denetimi; belgeler
- Komutlar: `cd backend && php artisan test`; frontend `npm run type-check && npm run lint`, vitest PowerShell'den `D:\`; yayın `bash backend/deploy/yayinla.sh`
