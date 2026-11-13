# Elde olan varlıkları toplu ekleme (`;` ile ayrılmış satırlar)

## Context
Elde olan (başlangıç bakiyesi, `tur = mevcut`) varlıkları tek tek "İşlem ekle" ile girmek uzun sürüyor. Kullanıcı `;` ile ayrılmış satırlarla toplu ekleme istiyor. Seçilen biçim: **satır başına bir varlık**, Türkçe Excel'den yapıştırılabilir:

```
GRAM;25
CEYREK;4;3850
USD;1.500;32,40;01.03.2025
THYAO;100;245,5
```
Alanlar: `kod ya da ad ; miktar ; birim fiyat (isteğe bağlı, TL) ; tarih (isteğe bağlı) ; not (isteğe bağlı)`. Kaydetmeden önce önizleme gösterilir; hatalı satır varken kaydedilmez. Kayıt tek seferde ve atomiktir (biri geçersizse hiçbiri eklenmez).

## Adımlar

### 0. Kayıt (CLAUDE.md kuralı)
- Planı `docs/planlar/toplu-ekleme.md`'ye kopyala, [docs/DEVAM.md](docs/DEVAM.md)'ye iş kaydı; ilk commit "Yarım: toplu ekleme planı" (bekleyen `backend/tests/Unit/.gitkeep` de girer), push. Her adımdan sonra DEVAM.md + commit / push.

### 1. Arka uç: `POST /api/v1/islemler/toplu`
- [routes/api.php](backend/routes/api.php): `Route::post('islemler/toplu', [IslemController::class, 'toplu'])->name('islemler.toplu')`, `apiResource('islemler')`'ten önce.
- [IslemController.php](backend/app/Http/Controllers/Api/V1/IslemController.php) `toplu()`:
  - Girdi: `{ hesap_id, satirlar: [{ varlik_id, miktar, birim_fiyat?, tarih, not? }] }`.
  - Doğrulama, mevcut `dogrula()` kurallarıyla aynı: `hesap_id` kullanıcının hesabı (`Rule::exists(...)->where('user_id', ...)`, kullanıcı izolasyonu); `satirlar` 1–200; `satirlar.*.varlik_id` var olan varlık; `miktar` `gt:0`, `max:1000000000000`; `birim_fiyat` nullable `gt:0`; `tarih` `before_or_equal:today`; `not` en çok 255. Hata anahtarları `satirlar.N.alan` olur (arayüz satıra eşler).
  - `DB::transaction` içinde her satır `Islem` (`tur = Mevcut`, `komisyon = 0`, `user_id`). Satış denetimi gerekmez: elde olan miktarı yalnızca artırır, sonraki satışı geçersiz kılamaz (yorumla yazılır).
  - Yanıt 201: `{ eklenen: n, message: 'n varlık eklendi.' }`.
- Testler (yeni `tests/Feature/TopluIslemTest.php`, `Http::fake` gerekmez):
  - Başarılı: 3 satır eklenir, hepsi `mevcut`, fiyatsız satır `birim_fiyat` null, `user_id` doğru.
  - Başka kullanıcının hesabı → 422, hiçbir şey eklenmez.
  - Bir satır hatalı (miktar 0 / ileri tarih) → 422, `satirlar.1.miktar` anahtarı, hiçbir satır eklenmez (atomik).
  - 201 satır → 422; giriş yapmayan → 401.

### 2. Ön uç: ayrıştırıcı (saf fonksiyon)
- Yeni `frontend/src/utils/topluIslem.ts`: `satirlariCoz(metin, varliklar, varsayilanTarih, bugun)` → `{ satirNo, ham, varlik?, miktar?, birimFiyat, tarih, not, hata?, uyari? }[]`.
  - Boş satır ve `#` ile başlayan satır atlanır; ilk alanı "kod / varlık" olan başlık satırı atlanır.
  - Varlık: önce kod (`toLocaleUpperCase('tr-TR')`), sonra ad (`toLocaleLowerCase('tr-TR')`) eşleşmesi, yalnızca katalogdakiler ([stores/katalog.ts](frontend/src/stores/katalog.ts)). Bulunmazsa: "Katalogda yok: X. Hisse / kripto / fonsa önce Kodla ekle". Birden çok türde eşleşirse hata.
  - Sayı: virgül varsa Türkçe (`1.234,56`); virgül yoksa birden çok nokta binliktir; tek nokta + tam 3 hane (`1.500`, tam kısım 0 değil) binlik sayılır ve **uyarı** gösterilir; kalanında nokta ondalıktır (`12.5`, `0.015`). `₺`, `TL`, boşluklar atılır.
  - Tarih: `gg.aa.yyyy`, `gg/aa/yyyy`, `yyyy-aa-gg`; boşsa formdaki tarih; ileri tarih hata.
  - Eksik alan (kod + miktar yoksa), 5'ten fazla alan, miktar ≤ 0, fiyat ≤ 0 → satır hatası.
- `src/__tests__/topluIslem.spec.ts`: biçimler, sayı kuralları (uyarılı `1.500` dahil), kod / ad eşleşmesi, bilinmeyen kod, başlık ve yorum satırı, tarih varsayılanı ve ileri tarih.

### 3. Ön uç: pencere ve giriş noktaları
- [api/finans.ts](frontend/src/api/finans.ts): `topluMevcutEkle(veri)` → `POST /islemler/toplu`, `TopluSatir` tipi.
- Yeni `components/TopluEkleDialog.vue` (mevcut desen: [IslemFormu.vue](frontend/src/components/IslemFormu.vue), `FormAlani`, `useFormGonder`):
  - Hesap seçimi (detaydan açılınca seçili gelir), varsayılan tarih (`DatePicker`, bugün), eş aralıklı yazılı `Textarea` + örnekli açıklama.
  - Yazdıkça önizleme listesi: durum simgesi, varlık adı (kod), miktar + birim, fiyat ya da "maliyet bilinmiyor", tarih, hata / uyarı. Özet satırı: "5 hazır · 1 hatalı".
  - "N varlığı ekle" düğmesi hata yokken ve en az bir satır varken açık. Sunucu 422 dönerse `satirlar.N.*` hataları ilgili önizleme satırına yazılır. Başarıda toast + `kaydedildi` yayılır.
- Giriş noktaları: [HesapDetayView.vue](frontend/src/views/HesapDetayView.vue) ("İşlem ekle" yanında ikincil "Toplu ekle", hesap seçili) ve [IslemlerView.vue](frontend/src/views/IslemlerView.vue) (eylemlerde "Toplu ekle"). Kaydedilince sayfalar mevcut `islemDegisti` / `yukle` ile yenilenir.

## Doğrulama
1. `php artisan test` (yeni testler dahil hepsi), `npm run type-check && npm run lint && npx vitest run`; Pint / prettier yalnızca değişen dosyalara.
2. Elle (yerelde `php artisan serve --port=8001` + `npm run dev`, `ben@finans.test`): hesap detayında Toplu ekle → örnek metni yapıştır → önizleme doğru; bilinmeyen kod ve ileri tarih satırı kırmızı, kayıt kapalı; düzeltip kaydet → varlıklar hesapta "Elde olan" olarak görünür, fiyatsızlarda K/Z "—".
3. `npm run build`; ILERLEME.md "Son tamamlananlar"a satır, DEVAM.md kaydı silinir, commit + push.
