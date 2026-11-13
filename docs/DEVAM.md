# Devam noktası (yarım işler)

> Kural: CLAUDE.md → "Kaldığın yeri kaydet". Oturum başında `git pull`'dan sonra burayı oku; dolu bir iş varsa oradan devam et.

## Elde olan varlıkları toplu ekleme (`;` ile)
- **Plan:** [planlar/toplu-ekleme.md](planlar/toplu-ekleme.md)
- **Durum:** başladı (2026-11-13)
- **Yapılanlar:** plan; arka uç `POST /api/v1/islemler/toplu` + `TopluIslemTest` (39 test geçiyor); ayrıştırıcı `utils/topluIslem.ts` + `topluIslem.spec.ts`; `TopluEkleDialog.vue`, HesapDetay ve İşlemler sayfasında "Toplu ekle"
- **Sıradaki adım:** elle deneme (yerelde), `npm run build`, ILERLEME.md + DEVAM.md kaydını kapat
- **Komutlar:** `cd backend && php artisan test`; `cd frontend && npm run type-check && npm run lint && npx vitest run`


