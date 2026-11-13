# Devam noktası (yarım işler)

> Kural: CLAUDE.md → "Kaldığın yeri kaydet". Oturum başında `git pull`'dan sonra burayı oku; dolu bir iş varsa oradan devam et.

## İlk sürüm
- **Plan:** [planlar/ilk-surum.md](planlar/ilk-surum.md) (1. adım sonucu planın sonunda)
- **Durum:** backend bitti (uçlar, Policy, zamanlayıcı, seeder, 29 test geçiyor). Frontend başlamadı.
- **Yapılanlar:**
  - 1. adım: kaynak denemesi bitti (Truncgil, Yahoo, Binance, TCMB çalışıyor; TEFAS engelli → fon fiyatı elle).
  - Laravel 13 + Sanctum kuruldu, `.env` MySQL `finans` (+ `finans_test`), oturum çerezi `finans_oturum`.
- **Sıradaki adım:** frontend iskeleti (Vue 3 + PrimeVue 4.5 + Tailwind 4, port 5174, /api proxy → 8001, build → backend/public/assets + backend/resources/spa); ekranlar: giriş, özet, hesaplar, hesap detayı + işlem formu, izleme, varlık detayı + grafik, yönetim; sonra yayın betikleri + CANLI.md.
- **Dikkat:** GitHub deposu henüz yok (kullanıcı açacak, `gh` kurulu değil); şimdilik yalnızca yerel commit.
- **Komutlar:** `cd backend && php artisan migrate:fresh --seed`
