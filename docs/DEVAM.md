# Devam noktası (yarım işler)

> Kural: CLAUDE.md → "Kaldığın yeri kaydet". Oturum başında `git pull`'dan sonra burayı oku; dolu bir iş varsa oradan devam et.

## İlk sürüm
- **Plan:** [planlar/ilk-surum.md](planlar/ilk-surum.md) (1. adım sonucu planın sonunda)
- **Durum:** 2. adım (iskelet) sürüyor.
- **Yapılanlar:**
  - 1. adım: kaynak denemesi bitti (Truncgil, Yahoo, Binance, TCMB çalışıyor; TEFAS engelli → fon fiyatı elle).
  - Laravel 13 + Sanctum kuruldu, `.env` MySQL `finans` (+ `finans_test`), oturum çerezi `finans_oturum`.
- **Sıradaki adım:** kullanıcı modeli (ad, soyad, yonetici) + giriş / çıkış / ben uçları, CSRF köken denetimi, SPA sunma rotası; sonra frontend iskeleti (Vue + PrimeVue 4.5, port 5174, proxy).
- **Dikkat:** GitHub deposu henüz yok (kullanıcı açacak, `gh` kurulu değil); şimdilik yalnızca yerel commit.
- **Komutlar:** `cd backend && php artisan migrate:fresh --seed`
