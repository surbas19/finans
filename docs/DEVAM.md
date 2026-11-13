# Devam noktası (yarım işler)

> Kural: CLAUDE.md → "Kaldığın yeri kaydet". Oturum başında `git pull`'dan sonra burayı oku; dolu bir iş varsa oradan devam et.

## Hesaplarda nakit ve bireysel emeklilik ([planlar/nakit-bes.md](planlar/nakit-bes.md))
İstek: hesaplarda TL nakit ve BES yok. Seçimler: nakit elle (yatırma / çekme / elde olan, alım-satım nakde dokunmaz); BES'te toplam tutar + kâr yüzdesi elle (her giriş öncekinin yerine geçer). Migration yok: `nakit` (TL) ve `bes` (BES) katalogda `kaynak = sabit`, birim 1 TL; BES için işlem türü `deger`, `birim_fiyat = 1 / (1 + kâr/100)`.

- [x] Arka uç: enumlar, Katalog, Pozisyon / PortfoyHesabi, IslemController (dogrula, toplu), Islem::ozet, elle fiyat / izleme engeli, DemoSeeder + testler
- [x] Arayüz: finans.ts, IslemFormu, HesapDetayView, PozisyonListesi, IslemListesi, VarlikDetayView, IzlemeView, topluIslem + testler
- [ ] Tüm testler, uçtan uca (360 / 1280)
- [ ] CLAUDE.md portföy kuralı, ILERLEME satırı, bu kaydı sil, commit + push, canlıya yayın

**Sıradaki adım:** uçtan uca deneme (`migrate:fresh --seed`, 360 / 1280), sonra kayıt ve yayın.

Komutlar: `cd backend && php artisan test`; `cd frontend && npm run type-check && npm run lint && npx vitest run`.
