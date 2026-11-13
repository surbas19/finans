# Devam noktası (yarım işler)

> Kural: CLAUDE.md → "Kaldığın yeri kaydet". Oturum başında `git pull`'dan sonra burayı oku; dolu bir iş varsa oradan devam et.

Sıra: önce otomatik güncelleme, sonra mobil uyum, en son ikisiyle birlikte canlıya yayın (`yayinla.bat`).

## Otomatik güncelleme (açık uygulama yeni sürümü kendisi yükler)
- **Plan:** [planlar/otomatik-guncelleme.md](planlar/otomatik-guncelleme.md)
- **Durum:** başladı (2026-11-13)
- **Yapılanlar:** plan
- **Sıradaki adım:** 1. `GET /api/v1/surum` + `SurumTest`
- **Komutlar:** `cd backend && php artisan test`; `cd frontend && npm run type-check && npm run lint && npx vitest run`

## Mobil uyum (kalanı)
- **Plan:** [planlar/mobil-uyum.md](planlar/mobil-uyum.md)
- **Durum:** bekliyor (otomatik güncellemeden sonra)
- **Yapılanlar:** ilk parça alt çubuk düzeltmesinde (ILERLEME.md)
- **Sıradaki adım:** 1. ölçüm (pencereler dahil, 320 / 360 / 390)


