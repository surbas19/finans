# İlerleme

> Açık işler ve son tamamlananlar. Yarım işler: [DEVAM.md](DEVAM.md).

## Kullanıcının yapacakları
- [ ] Canlı kurulum: [CANLI.md](CANLI.md) → "İlk kurulum" (alt alan adı, PHP 8.3, SSL, veritabanı, `.env`, cron).

## Sıradaki
- [ ] Fiyat alarmı ve bildirim (Web Push)
- [ ] Hesabı başka kullanıcıyla paylaşma (eşin kendi girişiyle görmesi)
- [ ] Hesaplar arası aktarma (kasadan yatırım hesabına)
- [ ] Temettü / faiz / kira geliri
- [ ] Excel'e aktarma; PWA (ana ekrana ekleme)
- [ ] Fonlar için resmî fiyat kaynağı (TEFAS otomatik isteği engelliyor; şimdilik elle)
- [ ] TCMB döviz yedeği

## Son tamamlananlar
- (2026-11-13) Hesap performans grafikleri ([planlar/hesap-performansi.md](planlar/hesap-performansi.md)): Özet ve hesap detayında günlük değer + maliyet grafiği (1A / 3A / 1Y / Tümü, işlemler ve fiyat geçmişinden geriye dönük, `GET /api/v1/ozet/gecmis`), dönem K/Z; hesap detayında Özet düzeninde varlıklar + dağılım.
- (2026-11-13) İlk sürüm ([planlar/ilk-surum.md](planlar/ilk-surum.md)): altın / döviz (Truncgil), BIST ve ons (Yahoo, gecikmeli), kripto (Binance), fon (elle) fiyatları 15 dakikada bir; hesaplar / konumlar, alış-satış-elde olan işlemleri, ağırlıklı ortalama maliyet, kâr / zarar (maliyeti bilinmeyen sıfır sayılmaz), özet ve dağılım, izleme listesi, fiyat grafiği, yönetim (kullanıcılar, kaynak durumu); yayın betikleri. Canlıya kurulmadı.
