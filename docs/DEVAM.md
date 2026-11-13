# Devam noktası (yarım işler)

> Kural: CLAUDE.md → "Kaldığın yeri kaydet". Oturum başında `git pull`'dan sonra burayı oku; dolu bir iş varsa oradan devam et.

## İlk sürüm
- **Plan:** [planlar/ilk-surum.md](planlar/ilk-surum.md) (1. adım sonucu planın sonunda)
- **Durum:** 2.–4. adım backend sürüyor (modeller, kaynaklar, portföy servisi yazıldı; uçlar / testler yok).
- **Yapılanlar:**
  - 1. adım: kaynak denemesi bitti (Truncgil, Yahoo, Binance, TCMB çalışıyor; TEFAS engelli → fon fiyatı elle).
  - Laravel 13 + Sanctum kuruldu, `.env` MySQL `finans` (+ `finans_test`), oturum çerezi `finans_oturum`.
- **Sıradaki adım:** giriş / çıkış / ben, hesap, işlem, varlık, izleme, yönetim uçları + Policy; CSRF köken denetimi (bootstrap/app.php); SPA rotası; zamanlanmış komutlar (routes/console.php); testler; sonra frontend.
- **Dikkat:** GitHub deposu henüz yok (kullanıcı açacak, `gh` kurulu değil); şimdilik yalnızca yerel commit.
- **Komutlar:** `cd backend && php artisan migrate:fresh --seed`
