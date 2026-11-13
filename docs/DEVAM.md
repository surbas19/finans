# Devam noktası (yarım işler)

> Kural: CLAUDE.md → "Kaldığın yeri kaydet". Oturum başında `git pull`'dan sonra burayı oku; dolu bir iş varsa oradan devam et.

## İlk sürüm
- **Plan:** [planlar/ilk-surum.md](planlar/ilk-surum.md) (1. adım sonucu planın sonunda)
- **Durum:** backend + frontend çalışıyor (29 backend, 6 frontend testi; type-check / lint temiz; curl ile giriş / CSRF / özet denendi). Yayın betikleri yok.
- **Yapılanlar:**
  - 1. adım: kaynak denemesi bitti (Truncgil, Yahoo, Binance, TCMB çalışıyor; TEFAS engelli → fon fiyatı elle).
  - Laravel 13 + Sanctum kuruldu, `.env` MySQL `finans` (+ `finans_test`), oturum çerezi `finans_oturum`.
- **Sıradaki adım:** yayın betikleri (backend/deploy: yayinla.sh, paket-hazirla.sh, paket-ac.php, cron.sh; okuldakinden uyarlanır), .env.production.example, docs/CANLI.md (cPanel adımları); sonra kullanıcıya GitHub deposu ve cPanel kurulumu anlatılır.
- **Dikkat:** GitHub deposu henüz yok (kullanıcı açacak, `gh` kurulu değil); şimdilik yalnızca yerel commit.
- **Komutlar:** `cd backend && php artisan migrate:fresh --seed`
