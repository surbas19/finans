# Devam noktası (yarım işler)

> Kural: CLAUDE.md → "Kaldığın yeri kaydet". Oturum başında `git pull`'dan sonra burayı oku; dolu bir iş varsa oradan devam et.

## Özet: yana kaydırmalı hesap halkaları ([planlar/ozet-kaydirici.md](planlar/ozet-kaydirici.md))
İstek: Özet açılınca "Tüm hesaplar" halkası, yana kaydırınca her hesabın halkası; seçilenin detayı altta. Seçimler: Tümü halkası hesaplara, hesap halkası varlıklara göre (ilk 6 renkli + gri Diğer, satırlarda şerit); alt detay Özet düzeni + "Hesabı aç".

- [ ] Arka uç: `PortfoyHesabi::hesapla` hesap satırına `dagilim` + test
- [ ] `utils/halka.ts` + test; `VeriGrafigi` (`gosterge`, `degerBicimi`, `kesim`); `PozisyonListesi` (`renkler` şeridi)
- [ ] `PortfoyKaydirici.vue` + test
- [ ] `OzetView.vue` yeniden düzen (tek istek, `?hesap=`)
- [ ] Tüm testler, uçtan uca (360 / 1280) ve taşma ölçümü
- [ ] ILERLEME satırı, bu kaydı sil, commit + push, canlıya yayın

**Sıradaki adım:** arka uç.
