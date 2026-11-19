# Grafiklerde "Hiçbiri", ekstreden kart sayfasına düğme, kart detayında kartlar arası gezinme

## Bağlam
Kullanıcının üç isteği var:
1. **Çizgi grafikler:** seri düğmelerinin başına "Hiçbiri" konacak. Bugün tek seriyi görmek için bütün serileri tek tek kapatmak gerekiyor.
2. **Ekstre yükleme sonrası:** ekstre yüklenince uygulama ekstre ekranına (`aile-ekstre`) gidiyor, ama oradan ilgili kartın sayfasına giden bir düğme yok. Geri tuşu Ekstreler listesine dönüyor.
3. **Kart detayı:** kartlar arasında geçmek için şimdi Kartlar listesine dönmek gerekiyor.
   - Kullanıcı seçimi **"Şerit + kaydırma"**: üstte yana kayan renkli kart çipleri olacak, başlık kartında parmakla sola / sağa kaydırınca önceki / sonraki karta geçilecek.
   - Geçiş yönlü kayacak; geri tuşu Kartlar'a dönecek.

## 1. Çizgi grafiklerde "Hiçbiri"
Değişecek dosya: `frontend/src/components/grafik/ZamanCizgiGrafigi.vue`. Fatura, Gelir ve Kart grafiklerinin hepsi bunu kullanıyor. Performans ve fiyat grafiklerinde seri düğmesi yok; onlara dokunulmaz.
- Seri çiplerinin başına bir düğme gelir (`data-test="seri-hicbiri"`):
  - Görünen seri varsa "Hiçbiri" yazar ve hepsini kapatır.
  - Hepsi kapalıysa "Hepsi" yazar ve hepsini açar.
- Kesik çerçeveli küçük çip olur; seri çiplerinden ayırt edilir.
- `kapali` kümesi `props.seriler`'in bütün anahtarlarıyla doldurulur ya da boşaltılır.
- Hepsi kapalıyken:
  - Sayaç (`ozetHedef`) zaten `null` olur.
  - Grafik alanı boş eksenle kalır.
  - Grafiğin üstüne "Seri seçin" yazısı gelir (`data-test="grafik-seri-yok"`).

## 2. Ekstre ekranından kart sayfasına
Değişecek dosya: `frontend/src/views/aile/EkstreView.vue`.
- Başlık kartındaki eylem grubuna "PDF'i aç" bağlantısı biçiminde bir `RouterLink` eklenir: "Kart sayfası" (`pi pi-credit-card`).
  - Hedef: `{ name: 'aile-kart', params: { id: ekstre.kart_id } }`, `data-test="kart-sayfasi"`.
  - Her durumda görünür (taslak ve onaylı).
- Onaylı ekstrede alttaki eylem alanında, "Onayla" düğmesinin yerinde birincil "Kart sayfasına git" düğmesi (`data-test="kart-sayfasina-git"`) çıkar. Onaydan sonra doğal sonraki adım budur.

## 3. Kart detayında kartlar arası gezinme
- **Kart sırası:** Kartlar sayfasındaki sıra (`KartlarView.vue:88`: `banka_id`, sonra `id`), pasif kartlar dahil.
  - Ortak bir yardımcıya çıkarılır: `stores/aile.ts` → `siraliKartlar` (computed).
  - `KartlarView` da bunu kullanır.
- **Şerit:** yeni `components/aile/KartSeridi.vue`, `KartDetayView` başlık kartının üstünde, yalnız birden çok kart varsa görünür.
  - Yana kayan çipler; her çipte kartın rengi (`kartRengiCss`) ve adı yazar.
  - Seçili çip `kartDegradesi` ile dolu, beyaz yazılıdır. Pasif kart soluk görünür.
  - Seçili çip açılışta görünür alana kaydırılır (`scrollIntoView({ inline: 'center' })`).
  - Çipe dokununca `router.replace` çalışır. Geçmiş kartlarla dolmaz; geri tuşu Kartlar'a döner.
  - Kaydırma çubuğu gizlenir (`[scrollbar-width:none]`).
- **Kaydırma hareketi:** yeni `composables/useYanaKaydirma.ts`.
  - Pointer olaylarıyla çalışır, yalnız dokunmatik ve kalemde (`pointerType !== 'mouse'`).
  - Yatay hareket 60 px'i ve dikey hareketin 1,5 katını geçerse sola / sağa geri çağrısı yapılır.
  - `touch-action: pan-y` ile dikey sayfa kaydırması bozulmaz.
  - `KartDetayView`'da başlık kartına bağlanır: sola kaydırınca sonraki, sağa kaydırınca önceki kart. Uçlarda bir şey olmaz.
- **Yönlü geçiş:**
  - Bugün `AnaDuzen.vue:76-81` yalnız `route.name` değişince yön hesaplıyor. Kart → kart geçişinde ad aynı kaldığı için yön eski değerde kalıyor.
  - İzleme `route.fullPath`'e çevrilir. Ad aynıysa yön `history.state.sayfaYonu`'ndan alınır (yoksa 0, yalnız solma).
  - Şerit ve kaydırma, `router.replace({ name: 'aile-kart', params: { id }, state: { sayfaYonu: ±1 } })` ile yön verir.
  - `RouterView` anahtarı yola bağlı olduğundan sayfa yeniden kurulur ve veri yüklenir; `KartDetayView`'da ek izleme gerekmez.
- **Klavye / erişilebilirlik:**
  - Şerit `nav` olur, `aria-label="Kartlar"`.
  - Seçili çipte `aria-current="page"` bulunur.

## Testler (ön yüz)
- `__tests__/donemGrafikleri.spec.ts` ya da yeni `zamanGrafigi.spec.ts`:
  - "Hiçbiri" bütün serileri kapatır ve düğme "Hepsi" olur.
  - "Hepsi" bütün serileri açar.
- `__tests__/kartGezinme.spec.ts` (yeni):
  - Şerit kartları sırayla gösterir ve seçili çipte `aria-current` vardır.
  - Çipe tıklayınca `router.replace` doğru id ve yönle çağrılır.
  - Kaydırma geri çağrısı (composable'a pointer olayları) sonraki / önceki karta gider; uçta gitmez.
  - Tek kartta şerit görünmez.
- `EkstreView` için:
  - "Kart sayfası" bağlantısının hedefi doğrudur.
  - Onaylı ekstrede "Kart sayfasına git" görünür.
  - Mevcut ekstre testleri varsa onlara eklenir; yoksa küçük bir mount testi yazılır.
- `useSayfaYonu` ya da `AnaDuzen`: aynı sayfa adı ve `history.state.sayfaYonu` ile yön.

## Doğrulama
- `npm run type-check && npm run lint && npx vitest run --testTimeout=30000`. Arka uç değişmez; yine de `php artisan test` çalıştırılır.
- Edge headless ile 390 px ekran görüntüleri, açık ve koyu (demo `demo.sqlite`, sunucu `backend/public`'ten `php -S`):
  - Fatura grafiği açık ve "Hiçbiri" basılmış hali.
  - Ekstre ekranı başlığı.
  - Kart detayı şeridi.
- Ardından CLAUDE.md'ye kısa notlar:
  - "Hiçbiri / Hepsi".
  - Ekstre → kart.
  - Kart şeridi ve kaydırma, `history.state.sayfaYonu`.
- ILERLEME'ye bir satır, DEVAM'da yayın bekleyenlere ekleme yapılır; commit + push.
