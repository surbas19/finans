# Gelir türleri hanenin kendi listesi

İstek (2026-11-14): "Gelir türlerini elle ekleyebilmeliyim; örneğin ek ders yok, kendim ekleyebilmeliyim."

Bugün tür koda sabit bir enum (`App\Enums\GelirTuru`: maaş, kira, serbest, ek iş, prim, faiz / temettü, emekli, diğer). Harcama kategorileri gibi **hanenin kendi listesi** olur.

## Veri
- Yeni tablo `gelir_turleri`: `hane_id`, `ad` (hanede tekil), `sira`.
- `gelirler.tur` ve `gelir_kaynaklari.tur` yerine `gelir_turu_id` (FK, `restrictOnDelete`: kullanılan tür silinmez).
- **Migration (veriyi taşır, geri alınabilir):**
  - Her haneye eski 8 tür aynı adlarla yazılır.
  - Var olan gelir ve kaynakların türü bu satırlara bağlanır, `tur` sütunu kalkar.
  - `down`: `tur` geri gelir. Eski adlardan birine uyan tür anahtarına döner, hanenin eklediği türler `diger` olur. `gelir_turu_id` ve tablo kalkar.
- **Yeni hane:** `VarsayilanAileKayitlari` aynı 8 türü yazar. Kategoriler ve türler ayrı ayrı denetlenir, ikisi de bir kez yazılır.
- Gelir türleri "hanede kayıt var" sayılmaz (`HaneYonetimi::AILE_TABLOLARI`'na girmez; varsayılan kategoriler gibi).

## API
- `GET / POST /api/v1/aile/gelir-turleri`.
- `PUT /aile/gelir-turleri/{tur}`: ad değiştirme.
- `DELETE /aile/gelir-turleri/{tur}`: türde gelir ya da kaynak varsa 422 ("önce başka türe alın").
- Gelir ve kaynak formları `gelir_turu_id` gönderir; yalnızca hanenin türü kabul edilir. Liste süzgeci de `gelir_turu_id`.
- Yetki her zamanki gibi: `hanenin()`, `Gate::authorize('hane-kaydi')`. "Başka hane göremez / değiştiremez" testi yazılır.
- `ButceRaporu` kişi bazında tür toplamlarını `gelir_turu_id` ve adıyla verir.

## Arayüz
- Store'a gelir türleri eklenir; sabit `GELIR_TURLERI` haritası kalkar.
- **`GelirTuruSecici`:** tür seçimi ve yanında "Yeni tür". Türü yazıp eklersiniz, eklenen tür seçili gelir. Gelir formunda ve düzenli gelir formunda kullanılır.
- **Ayarlar → "Gelir türleri":** çipler, silme (×) ve ekleme (fatura türleri gibi).
- Gelirler, Özet ve kaynak listesi tür adını store'dan okur.

## Testler ve doğrulama
- **Arka uç:**
  - tür ekleme, tekil ad, silme (kullanılıyorsa 422), başka hane;
  - gelir ve kaynakta başka hanenin türü reddedilir;
  - raporda tür adı;
  - migration'ın eski veriyi taşıması.
- **Ön yüz:** vitest.
- **Uçtan uca (360 px):** gelir formunda "Ek ders" ekle, kaydet, Gelirler ve Özet'te görün.
- Bütün testler, Pint ve prettier (yalnızca değişen dosyalar).
