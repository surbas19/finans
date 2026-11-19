# Ev nakdi = "Ev" hesabı, Portföy sırası, varlık akordiyonu, tasarruf düzeltme (2026-11-19)

Görseller: https://claude.ai/artifact/LKwryR8VW6nbY1p6hgzqaJ

## Kullanıcı kararları
- **Ev nakdi:** "Ev" adlı portföy hesabı evdeki nakittir. Ev nakdi kartında bir hesap seçilir (`haneler.ev_hesap_id`).
  - Seçiliyken ev bakiyesi bu hesabın Nakit (TL) tutarıdır.
  - Ödeme yeri "ev" olan yeni harcama bu hesaptan Nakit satışı olarak düşer (banka yoluyla aynı, `bag_turu=harcama`).
  - "Para ekle / çıkar" bu hesaba Nakit alış / satış yazar.
  - **Eski kasa:** "Ev hesabının nakdi esas". Kasaya girilmiş para ekle / çıkar kayıtları yok sayılır. Geçmiş ev harcamaları Bütçe'de gider olarak kalır, hesaptan geriye dönük düşülmez.
  - Hesap seçilmemişse bugünkü kasa davranışı sürer.
- **Portföy Özet sırası:** üst kart → hesap çipleri → Dağılım → Varlıklarım → Performans.
- **Varlıklarım:** varlık türüne göre akordiyon, başlık **B**.
  - Başlıkta ikon, tür adı, varlık sayısı ve pay; sağda değer ve K/Z; altında dağılım renginde pay çubuğu.
  - **Hepsi kapalı** açılır.
  - Hesap detayında da kullanılır.
- **Tasarruf düzeltme:** Bütçe Özet ve Tasarruf sekmesinde kaleme dokununca düzeltme penceresi açılır (`PUT aile/tasarruflar/{tasarruf}`).

## Adımlar
1. Portföy sırası ve `VarlikAkordiyonu`.
2. Tasarruf düzeltme: arka uç ucu, form düzeltme kipi, kalem tıklama.
3. Ev nakdi → hesap: migration, `EvNakdi` / `NakitKaynaklari`, `EvNakdiController`, `DuzenliGiderController`, kart ve form.
4. Testler, belgeler, ekran görüntüsüyle denetim.
