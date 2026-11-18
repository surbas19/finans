# Arayüz iyileştirmesi (2026-11-18)

Kullanıcı ön yüz incelemesindeki (artifact "Finans arayüz incelemesi") bütün önerileri onayladı ("hepsini uygula").

## Sıra
1. **K1:** Bütçe Özet açıklama metni (kart gideri = son ödeme ayı).
2. **Öneri 7:** yazı ölçeği. `bolum-etiketi` normal yazımla 15 px kalın koyu başlık olur; 10–11 px yazılar en az 12 px'e çıkar.
3. **Öneri 2:** üst çubukta sayfa adı.
   - Detay sayfalarında ‹ geri düğmesi.
   - Dinamik başlık `useSayfaBasligi`.
   - Çıkış Profil'e taşınır.
4. **Öneri 3:** Bütçe üst bloğu.
   - Alt çizgili, yapışkan sekmeler; Ayarlar ⚙ simgesi olur.
   - Ay çarkı sekmelerin altında yapışkan durur.
   - Hane adı satırı kalkar.
5. **Öneri 4:** Bütçe Özet üst kartı. Kalan büyük yazılır; gelir çubuğu gider, tasarruf ve kalana bölünür.
6. **Öneri 5:** uyarılar tek satırda sayaç olur; dokununca liste pencerede açılır.
7. **Küçük bulgular:**
   - K2: dokunma alanı 44 px.
   - K4: zemin ışığı `body::before` katmanına taşınır.
   - K5: bilgi iletileri kapatılabilir.
   - K6: hesap rengi tek yardımcıdan gelir.
   - K7: sayfa başlığı tekrarı kalkar.
   - K8: Bütçe Özet sırası.
   - K9: iskelet eksikleri.
8. **Öneri 1:** alt menü 4 sekme ve ortada + düğmesi.
   - Sekmeler: Portföy (Özet · Hesaplar · İşlemler alt sekmeleri), Bütçe, İzleme, Menü (Profil, Yönetim, Çıkış).
   - K3 böylece çözülür.
9. **Öneri 6:** Portföy Özet üst kartı.
   - Tek kartta toplam, kâr / zarar ve küçük halka.
   - Hesap çipleri yana kayar.
   - Ayrıntı tek satırda.
10. **Öneri 8:** koyu tema.
    - PrimeVue ve Tailwind palet basamakları koyu temada ters çevrilir (`html.koyu`).
    - Profil'de Açık / Koyu / Sistem seçimi.

Her adımdan sonra type-check, lint, vitest çalışır; ardından commit + push.
