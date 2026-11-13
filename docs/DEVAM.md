# Devam noktası (yarım işler)

> Kural: CLAUDE.md → "Kaldığın yeri kaydet". Oturum başında `git pull`'dan sonra burayı oku; dolu bir iş varsa oradan devam et.


## PWA (ana ekrana ekleme) + mobil cilalama
- Plan: [planlar/pwa.md](planlar/pwa.md)
- Durum: başladı (2026-11-13)
- Yapılanlar: —
- **Sıradaki adım:** ikonlar (`frontend/scripts/ikon-uret.ps1`), manifest, sw.js, index.html etiketleri
- Komutlar: `cd backend && php artisan test`; frontend `npm run type-check && npm run lint`, vitest PowerShell'den `D:\`; yayın `bash backend/deploy/yayinla.sh`
