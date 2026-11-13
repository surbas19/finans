# İlerleme

> Açık işler ve son tamamlananlar. Yarım işler: [DEVAM.md](DEVAM.md).

## Kullanıcının yapacakları
- [ ] Canlıda: geçici şifreyle girip şifreyi değiştir, `finans-app/deploy/kurulum.log`'u sil; boş kalan `~/finans`, `~/finans-app.yoklm.com`, `~/finans.yoklm.com` ve `~/finans-yukleme/home` klasörlerini temizle.

## Sıradaki
- [ ] Fiyat alarmı ve bildirim (Web Push)
- [ ] Hesabı başka kullanıcıyla paylaşma (eşin kendi girişiyle görmesi)
- [ ] Hesaplar arası aktarma (kasadan yatırım hesabına)
- [ ] Temettü / faiz / kira geliri
- [ ] Excel'e aktarma
- [ ] Fonlar için resmî fiyat kaynağı (TEFAS otomatik isteği engelliyor; şimdilik elle)
- [ ] TCMB döviz yedeği

## Son tamamlananlar
- (2026-11-13) Otomatik güncelleme ([planlar/otomatik-guncelleme.md](planlar/otomatik-guncelleme.md)): açık uygulama yeni sürümü kendisi yükler. `GET /api/v1/surum` (paketteki `deploy/SURUM`) ile derleme sürümü (`__SURUM__`) öne gelince / sayfa geçişinde karşılaştırılır; açık pencere ya da dolu form yoksa sessizce yenilenir, varsa sonraki sayfa geçişinde tam yükleme; yayında silinmiş eski dosyada hedef sayfa tam yüklenir; aynı sürüm için saatte en çok bir yenileme (döngü yok).
- (2026-11-13) Telefonda alt çubuk sabit kalıyor: Özet, hesap ve varlık detayı ekrandan genişti (taban sütunsuz `grid` uzun varlık adıyla genişliyordu, K/Z etiketi sarmıyordu); tarayıcı sayfayı genişletince sabit çubuk ekranın altından kayıyordu. Gridlere `grid-cols-1`, `DegisimEtiketi` yüzdeyi alt satıra indirebiliyor, `main`'de `overflow-x-clip` güvencesi ve çentik paylı alt boşluk.
- (2026-11-13) Elde olanları toplu ekleme ([planlar/toplu-ekleme.md](planlar/toplu-ekleme.md)): her satır `kod;miktar;birim fiyat;tarih;not` (yalnızca ilk ikisi zorunlu; kod ya da ad, Türkçe harf farkı gözetilmez; sekmeyle de ayrılabilir, Excel'den yapıştırılır). Yazdıkça önizleme, hatalı satırda kayıt kapalı, belirsiz `1.500` için uyarı. `POST /api/v1/islemler/toplu` hepsini ya da hiçbirini ekler (en çok 200 satır). Hesap detayında ve İşlemler sayfasında "Toplu ekle".
- (2026-11-13) Telefona kurulum / PWA ([planlar/pwa.md](planlar/pwa.md)): manifest, ikonlar (`frontend/scripts/ikon-uret.ps1`), service worker (veri önbelleğe alınmaz, yalnızca çevrimdışı sayfası), iOS etiketleri, Profil'de ve Özet'te "ana ekrana ekle" kartı, çentik payı, Özet'te yenile düğmesi.
- (2026-11-13) Canlı kurulum: https://finans.yoklm.com çalışıyor (paket `adabc9a`). Düzeltmeler: tablo motoru InnoDB'ye sabitlendi (sunucu varsayılanı MyISAM), boş `resources/views` pakete eklendi, CANLI.md'ye ilk paketin elle açılması ve Document Root notu.
- (2026-11-13) Hesap performans grafikleri ([planlar/hesap-performansi.md](planlar/hesap-performansi.md)): Özet ve hesap detayında günlük değer + maliyet grafiği (1A / 3A / 1Y / Tümü, işlemler ve fiyat geçmişinden geriye dönük, `GET /api/v1/ozet/gecmis`), dönem K/Z; hesap detayında Özet düzeninde varlıklar + dağılım.
- (2026-11-13) İlk sürüm ([planlar/ilk-surum.md](planlar/ilk-surum.md)): altın / döviz (Truncgil), BIST ve ons (Yahoo, gecikmeli), kripto (Binance), fon (elle) fiyatları 15 dakikada bir; hesaplar / konumlar, alış-satış-elde olan işlemleri, ağırlıklı ortalama maliyet, kâr / zarar (maliyeti bilinmeyen sıfır sayılmaz), özet ve dağılım, izleme listesi, fiyat grafiği, yönetim (kullanıcılar, kaynak durumu); yayın betikleri. Canlıya kurulmadı.
