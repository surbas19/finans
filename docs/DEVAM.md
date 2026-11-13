# Devam noktası (yarım işler)

> Kural: CLAUDE.md → "Kaldığın yeri kaydet". Oturum başında `git pull`'dan sonra burayı oku; dolu bir iş varsa oradan devam et.

## Elde olan varlıkları toplu ekleme (`;` ile)
- **Plan:** [planlar/toplu-ekleme.md](planlar/toplu-ekleme.md)
- **Durum:** başladı (2026-11-13)
- **Yapılanlar:** plan; arka uç `POST /api/v1/islemler/toplu` + `TopluIslemTest` (39 test geçiyor)
- **Sıradaki adım:** 2. `frontend/src/utils/topluIslem.ts` ayrıştırıcı + `src/__tests__/topluIslem.spec.ts`
- **Komutlar:** `cd backend && php artisan test`; `cd frontend && npm run type-check && npm run lint && npx vitest run`


