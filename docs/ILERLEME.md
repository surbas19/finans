# İlerleme

> Açık işler ve son tamamlananlar. Yarım işler: [DEVAM.md](DEVAM.md).

## Kullanıcının yapacakları
- [ ] Canlıda: geçici şifreyle girip şifreyi değiştir, `finans-app/deploy/kurulum.log`'u sil; boş kalan `~/finans`, `~/finans-app.yoklm.com`, `~/finans.yoklm.com` ve `~/finans-yukleme/home` klasörlerini temizle.

## Sıradaki
- [ ] Fiyat alarmı ve bildirim (Web Push)
- [ ] Hesabı başka kullanıcıyla paylaşma (eşin kendi girişiyle görmesi)
- [ ] Hesaplar arası aktarma (kasadan yatırım hesabına)
- [ ] Temettü / faiz / kira geliri
- [ ] Excel'e aktarma; PWA (ana ekrana ekleme)
- [ ] Fonlar için resmî fiyat kaynağı (TEFAS otomatik isteği engelliyor; şimdilik elle)
- [ ] TCMB döviz yedeği

## Son tamamlananlar
- (2026-11-13) Canlı kurulum: https://finans.yoklm.com çalışıyor (paket `adabc9a`). Düzeltmeler: tablo motoru InnoDB'ye sabitlendi (sunucu varsayılanı MyISAM), boş `resources/views` pakete eklendi, CANLI.md'ye ilk paketin elle açılması ve Document Root notu.
- (2026-11-13) Hesap performans grafikleri ([planlar/hesap-performansi.md](planlar/hesap-performansi.md)): Özet ve hesap detayında günlük değer + maliyet grafiği (1A / 3A / 1Y / Tümü, işlemler ve fiyat geçmişinden geriye dönük, `GET /api/v1/ozet/gecmis`), dönem K/Z; hesap detayında Özet düzeninde varlıklar + dağılım.
- (2026-11-13) İlk sürüm ([planlar/ilk-surum.md](planlar/ilk-surum.md)): altın / döviz (Truncgil), BIST ve ons (Yahoo, gecikmeli), kripto (Binance), fon (elle) fiyatları 15 dakikada bir; hesaplar / konumlar, alış-satış-elde olan işlemleri, ağırlıklı ortalama maliyet, kâr / zarar (maliyeti bilinmeyen sıfır sayılmaz), özet ve dağılım, izleme listesi, fiyat grafiği, yönetim (kullanıcılar, kaynak durumu); yayın betikleri. Canlıya kurulmadı.
