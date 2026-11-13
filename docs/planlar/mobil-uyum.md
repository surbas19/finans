# Mobil uyum: tüm ekranlar telefona sığsın + telefon düzeni

## Bağlam
Kullanıcı tüm ekranların telefona sığmasını ve telefon düzenini (alttan açılan pencereler, dokunması kolay satırlar) istedi. İlk parça "alt çubuk sabit kalmıyor" düzeltmesinde yapıldı (2026-11-13): taban sütunsuz gridlere `grid-cols-1`, `DegisimEtiketi` yüzdeyi alt satıra indirebiliyor, varlık listesinde ada en az yer, `main`'de `overflow-x-clip`, çentik paylı alt boşluk. Bu plan kalanıdır.

## Adımlar
1. **Ölçüm önce / sonra:** 320 / 360 / 390 px'te bütün sayfalar ve pencereler (işlem ekle / düzenle, toplu ekle, hesap ekle, kodla ekle, izleme listesi, elle fiyat, kullanıcı ekle, şifre, silme onayı): yatay taşma, taşan öğeler, ekran görüntüsü (Edge + playwright-core, betik scratchpad'de).
2. **Alttan açılan pencere:** `composables/useDarEkran.ts` (tek `matchMedia('(max-width: 639px)')`) + `components/Pencere.vue` (PrimeVue `Dialog` sarmalı). Telefonda `position="bottom"`, tam genişlik, üst köşeler yuvarlak, `max-height: 90dvh`, çentik payı, tutamak çizgisi, alttan kayarak açılış; masaüstü değişmez. 8 pencere buna geçer.
3. **İşlem satırları** (`IslemListesi`) telefonda kart: satıra dokununca düzenleme; silme düzenleme penceresinde ("Sil"). Ortak mantık `composables/useIslemSil.ts`. Masaüstü aynı.
4. **Küçükler:** kodla ekle türleri "Hisse / Kripto / Fon"; tür seçicileri telefonda tam genişlik; `ConfirmDialog` genişliği; büyük tutarlar dar ekranda `text-2xl`; fiyat grafiği y ekseni en çok 2 ondalık; liste satırları telefonda daha yüksek; Yönetim kullanıcı satırında düğmeler alt satırda; yalnız simgeli düğmeler telefonda en az 40 px.
5. **Testler:** `useDarEkran`, `Pencere`, `IslemListesi`.

## Doğrulama
Ölçüm betiği 320 / 360 / 390'da bütün sayfa ve pencerelerde taşma 0, alt çubuk yerinde; ekran görüntüleri; 1280 px'te masaüstü değişmemiş; tüm testler.
