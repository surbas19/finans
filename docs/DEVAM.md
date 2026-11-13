# Devam noktası (yarım işler)

> Kural: CLAUDE.md → "Kaldığın yeri kaydet". Oturum başında `git pull`'dan sonra burayı oku; dolu bir iş varsa oradan devam et.

## Toplu eklemede yeni hisse / kripto kodları ([planlar/toplu-yeni-kod.md](planlar/toplu-yeni-kod.md))
Sorun: toplu eklemede katalogda olmayan hisse kodu "önce Kodla ekle" hatası veriyor. Çözüm: "Ekle"de yeni kodlar `POST /api/v1/varliklar/toplu` ile önce BIST'te, sonra kriptoda aranıp kataloğa eklenir, sonra kayıt yapılır.

- [x] Arka uç: `YahooKaynagi` / `BinanceKaynagi::dogrulaHepsi` (Http::pool), `VarlikController::toplu` + ortak `olustur()`, rota, `TopluVarlikTest`
- [x] Ön yüz: `topluIslem.ts` (`yeniKod`, `bulunamayan`), `finansApi.varliklariKodlaBul`, `TopluEkleDialog` (önce kod çöz, sonra kaydet), testler
- [ ] Tüm testler, uçtan uca deneme (`toplu-dene.mjs`)
- [ ] ILERLEME satırı, bu kaydı sil, commit + push, canlıya yayın (`yayinla.bat yayinla`)

**Sıradaki adım:** uçtan uca deneme (scratchpad `toplu-dene.mjs`: GRAM;5, ASELS;100, SOL;2, YOKBUKOD;1), sonra tüm testler ve yayın.
