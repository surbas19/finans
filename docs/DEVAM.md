# Devam noktası (yarım işler)

> Kural: CLAUDE.md → "Kaldığın yeri kaydet". Oturum başında `git pull`'dan sonra burayı oku; dolu bir iş varsa oradan devam et.

## Hesap performans grafikleri + hesap detayında dağılım
- Plan: [planlar/hesap-performansi.md](planlar/hesap-performansi.md)
- Durum: başladı (2026-11-13)
- Yapılanlar: backend tamam (`PortfoyHesabi::gecmis`, `GET /api/v1/ozet/gecmis`, hesap detayında `dagilim`, testler geçiyor)
- **Sıradaki adım:** frontend (`PerformansGrafigi.vue`, `DagilimKarti.vue`, OzetView + HesapDetayView)
- Komutlar: `cd backend && php artisan test`; `cd frontend && npm run type-check && npm run lint && npx vitest run`
