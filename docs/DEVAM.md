# Devam noktası (yarım işler)

> Kural: CLAUDE.md → "Kaldığın yeri kaydet". Oturum başında `git pull`'dan sonra burayı oku; dolu bir iş varsa oradan devam et.

Sıra: otomatik güncelleme bitti (ILERLEME.md); şimdi mobil uyum, en son canlıya yayın (`yayinla.bat`).

## Mobil uyum (kalanı)
- **Plan:** [planlar/mobil-uyum.md](planlar/mobil-uyum.md)
- **Durum:** başladı (2026-11-13)
- **Yapılanlar:** ilk parça alt çubuk düzeltmesinde (ILERLEME.md); ölçüm; `useDarEkran` + `UygulamaPenceresi` (8 pencere telefonda alttan), onay penceresi genişliği; işlem satırları telefonda kart, silme pencerede (`useIslemSil`); kodla ekle türleri kısa, tür seçicileri tam genişlik
- **Sıradaki adım:** küçükler (büyük tutar yazı boyu, grafik ekseni, liste satır yüksekliği, Yönetim satırı, Özet yenile düğmesi), testler (useDarEkran, UygulamaPenceresi, IslemListesi), son ölçüm
- **Komutlar:** `cd backend && php artisan test`; `cd frontend && npm run type-check && npm run lint && npx vitest run`


