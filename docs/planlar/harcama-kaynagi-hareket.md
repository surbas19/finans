# Oturum planı (19 Kasım 2026): bu oturumda planlanan her şey tek seferde uygulanacak

> Kullanıcı kuralı: "Tamam" denmeden plan modundan çıkılmaz (ExitPlanMode çağrılmaz). Maddeler biriktirilir, onaydan sonra hepsi tek seferde yapılır.

## Madde 1 — Harcama kaynağından gerçekten düşsün (Ev nakdi kasası / Banka = portföy hesabının Nakit TL'si)

### Context
Nakit harcama bugün hiçbir bakiyeden düşmüyor ya da yalnız Ev kasasını eksiye düşürüyor; Banka yalnız etiket. Karar (kullanıcı):
- **Banka harcaması** seçilen **portföy hesabındaki Nakit (TL)** bakiyesinden düşer (Hesaplar, toplam değer, zekât otomatik doğru kalır).
- **Ev harcaması** Ev nakdi kasasından düşer (mevcut `ev_nakdi_hareketleri` kasası).
- **Yetersiz bakiye: engelle, ekleme öner** (kayıt reddedilir; bakiye hiçbir zaman eksiye düşmez).
- **Başlangıç bakiyesini kullanıcı girer** ("Para ekle"); geçmiş harcamalar düşülmüş kalır.

### Arka uç
- Migration: `duzenli_giderler` → `hesap_id` (nullable, `hesaplar` → nullOnDelete), `islem_id` (nullable, `islemler` → nullOnDelete). `odeme_yeri='banka'` kayıtlarında ikisi de dolu olur (eski banka kayıtları null kalır = düşülmemiş).
- Yeni `App\Services\Aile\GiderKaynagiBakiyesi` (ya da `EvNakdi` yanına): 
  - Ev: `EvNakdi::bakiye(hane)` kullanılır; yeni gider eklenirken `tutar > bakiye` ise `ValidationException` (alan `tutar`, mesaj: "Ev nakdinde ₺X var, ₺Y eksik. Önce para ekleyin ya da Banka seçin."). Güncellemede eski tutar bakiyeye geri sayılır.
  - Banka: seçilen hesabın Nakit (TL) elde miktarı `PortfoyHesabi::hesapla` pozisyonlarından (`App\Services\Portfoy\PortfoyHesabi`) okunur; harcama `Islem` kaydıdır: `tur=Satis`, `varlik=Nakit(TL)` (`Varlik::where('tur','nakit')`), `miktar=tutar`, `birim_fiyat=1`, `komisyon=0`, `tarih`, `not="Nakit harcama: …"`, `bag_turu='harcama'`. Yetersizse `PortfoyHesabi::dogrula` (`YetersizMiktar`) → `ValidationException` ("Hesapta ₺X nakit var").
- `DuzenliGiderController`: `store` / `update` / `destroy` işlemleri `DB::transaction` içinde; banka satışı oluşturma / tutarını-tarihini güncelleme / silme (gider silinince `islem` de silinir; ödeme yeri banka→ev değişirse işlem silinir, ev→banka olursa oluşturulur). `odeme_yeri='banka'` iken `hesap_id` zorunlu ve hanenin hesaplarından olmalı (`Rule::exists('hesaplar')->whereIn('user_id', haneKullaniciIdleri())`).
- `IslemController`: `bag_turu='harcama'` işlem portföy ekranından düzenlenmez / tek başına silinmez (temettü çifti gibi; mesaj "Bu satış bir nakit harcamasından geldi; harcamayı Bütçe'den düzenleyin / silin").
- Yeni uç `GET aile/odeme-kaynaklari`: `{ ev: { bakiye }, hesaplar: [{ id, ad, nakit }] }` (formlar bakiyeyi gösterip yetmiyorsa uyarsın; hane hesapları, Nakit TL elde olanla).
- `HaneYonetimi` / `ozet()`: `DuzenliGider::ozet()` `hesap_id`, `islem_id` döndürür.

### Ön yüz
- `NakitHarcamaFormu`: Ev | Banka seçimi; Ev'de "Ev nakdi ₺X" satırı, Banka'da hesap seçici (varsayılan: son kullanılan hesap, localStorage) + "Nakit ₺X"; tutar bakiyeyi aşarsa kırmızı uyarı + "Para ekle" kısayolu (Ev için `EvNakdiHareketFormu`) ve Kaydet pasif; sunucu 422 mesajı da gösterilir. Kaynaklar açılırken `GET aile/odeme-kaynaklari` ile yüklenir.
- `GiderFormu`: aynı ödeme yeri + hesap alanları (banka seçilince hesap zorunlu).
- `EvNakdiKarti`: negatif durum uyarısı kaldırılır (artık oluşmaz); Banka tarafı için Bütçe Özet'te hesap nakitlerini gösteren kısa satır (isteğe bağlı: "Banka nakit ₺X" toplamı).
- Testler: arka uç `EvNakdiTest` güncellenir (yetersiz bakiye 422, bakiye hiç eksiye düşmez), yeni `BankaHarcamaTest` (satış işlemi oluşur, nakit düşer, düzenleme tutarı günceller, silme işlemi siler, yetersiz nakitte 422, başka hane hesabı reddedilir, `bag_turu=harcama` işlemi IslemController'dan silinemez); ön yüz `nakitHarcama.spec.ts` (kaynak bakiyesi, yetersizde uyarı, hesap seçimi payload'da).

### Doğrulama
Arka uç + ön yüz tüm testler, Pint / prettier yalnız değişen dosyalara, Edge e2e (360 px): hesaba nakit yoksa banka harcaması reddedilir; nakit eklenince geçer ve Hesaplar'da düşer; Ev kasası yetersizse engeller, para ekleyince geçer; deneme kayıtları temizlenir. CLAUDE.md + ILERLEME güncellenir.

## Madde 2 — Grafik animasyonu (SEÇİLDİ: G3 sayaçlı belirme)
Artifact: https://claude.ai/artifact/Tr5Wu6M9SdWmbSJMdk4MHd. Uygulama: `components/grafik/ZamanCizgiGrafigi.vue` (Chart.js) animasyon ayarları; Faturalar / Kartlar / Gelir grafikleri; `prefers-reduced-motion`'da kapalı. Seçilen seçeneğe göre Chart.js `animation` (G1: soldan sağa `x` ilerleme + alan solma, G2: `y` yaylanma `easeOutBack`, G3: nokta nokta uzama + üst toplamda sayaç). Test: reduced-motion'da animasyonsuz.

## Madde 3 — Sayfa geçiş animasyonu (SEÇİLDİ: T2 yönlü kayma)
`layouts/AnaDuzen.vue` `RouterView` + `<Transition>` (şu an `:key="yol:yenileme"` ile yeniden kuruluyor, `sayfa-giris` sınıfı var). Yönlü seçenekte (T2) alt sekme sırasına göre yön `router.afterEach` ile hesaplanır; aynı sayfada `yenileme` sayacı artınca geçiş oynamaz. `prefers-reduced-motion`'da kapalı.

## Madde 4 — Açılış ekranı (splash) (SEÇİLDİ: S1 çizen logo)
`index.html` içinde Vue yüklenmeden önce görünen satır içi splash (logo SVG + CSS animasyonu), uygulama hazır olunca (`auth` denetimi bitince) solarak kalkar; yalnız soğuk açılışta (sessionStorage işareti), en çok ~1,2 sn; koyu tema (`finans-tema`) ve `prefers-reduced-motion` uyumlu; PWA `manifest` arka plan rengiyle aynı zemin.

## Sıradaki maddeler
(Kullanıcı yeni istek ilettikçe buraya eklenecek.)
