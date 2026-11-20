# Silinenler: silinen her kayıt saklanır (kim yaptı, ne zaman, kim sildi, ne zaman sildi), üstü çizili listelenir

## Context
Bugün silinen kayıtlar tablodan tamamen gider; kimin, ne zaman oluşturduğu / sildiği kalmaz. Kullanıcı: silinen her işlem saklansın, tüm işlemlerin ne zaman ve kim tarafından yapıldığı "Silinenler" kısmında kaydedilsin, kayıtların üstü çizili olsun. Kararlar (kullanıcı): **kapsam = para kayıtları + kart/hesap** (kart işlemi, gider [nakit harcama dahil], gelir, tasarruf, borç + borç ödemesi, ev nakdi hareketi, fatura, ekstre, portföy işlemi, kart, hesap; kategori / kaynak / tür / sağlayıcı / üye / zekât tanımları hariç); **saklama = arşiv tablosu, anlık görüntü** (gerçek kayıt gider, raporlar / toplamlar / benzersizlik kuralları bozulmaz); **yalnız görüntüleme** (geri yükleme yok); **yer = Bütçe ⚙ ayarlarından açılan "Silinenler" sayfası, aynı haneden herkes görür**.

## Arka uç
**Migration `silinen_kayitlar`**: `id`, `hane_id` (→ haneler, cascade), `tur` (kart_islemi | gider | gelir | tasarruf | borc | borc_odemesi | ev_nakdi | fatura | ekstre | portfoy_islemi | kart | hesap), `kayit_id`, `baslik` (okunur özet: "Market · 12.11.2026"), `tutar` decimal nullable, `icerik` JSON (silinen satırın tüm alanları), `alt` JSON nullable (bağlı kayıtların özeti: ekstre satırları, hesap işlemleri, tasarrufun alışı; en çok 500), `neden` nullable ("Ekstre silindi"), `olusturan_id` + `olusturan_ad` (kaydı kim yaptı; ad kopyalanır, kullanıcı silinse de kalır), `olusturuldu_at` (özgün kayıt zamanı), `silen_id` + `silen_ad`, `silindi_at`; indeks `[hane_id, silindi_at]`, `[hane_id, tur]`. **Tablodan hiçbir yolla silinmez** (silme ucu yok; hane silinirse cascade).

**`App\Services\Aile\SilinenArsivi`** (tek yer): `yaz(Model, ?array $alt, ?string $neden)`; silen = oturumdaki kullanıcı (`auth()`; konsol / zamanlayıcıda null → "Sistem"); `sessiz(callable)` iç içe silmelerde ikinci arşiv yazmaz. Hane: modelin `hane_id`'si, yoksa (Islem / Hesap) sahibinin `users.hane_id`.
**`App\Models\Concerns\Arsivlenir`** (trait): `static::deleting` bağlı kayıtları (`arsivAlt()`) yakalar, `static::deleted` arşive yazar. Modeller `arsivBilgisi()` ile `tur`, `baslik`, `tutar`, `olusturan_id` döndürür. Uygulanacak modeller: `KartIslemi`, `DuzenliGider`, `Gelir`, `Tasarruf`, `Borc`, `BorcOdemesi`, `EvNakdiHareketi`, `Fatura`, `Ekstre`, `Islem`, `Kart`, `Hesap`.
- **Tek kayıt, tek silme**: `Tasarruf` silinince alışı `alt`'ta saklanır, bağlı `Islem` silmesi `sessiz()` içinde yapılır (çift kayıt olmaz); gider silinince bağlı banka satışı (`bag_turu=harcama`) zaten oluşturucuyla silindiği için ayrıca yazılmaz (gider kaydının `icerik`'inde `islem_id`).
- **Ekstre**: `deleting`'de satırlar (`kart_islemleri` özeti: tarih, açıklama, tutar) `alt`'a alınır; PDF dosyası saklanmaz (gizlilik kuralı: dosya silinince gider). **Hesap**: portföy işlemleri `alt`'ta. **Kart**: yalnız kayıt.
- Sistemin kendi iç silmeleri (ekstre taslağı yeniden okunurken satır yenileme, kural / tanım silmeleri, `->delete()` builder'ları) arşivlenmez; yalnızca kullanıcı eylemi olan model silmeleri.
- KartIslemi'nde ekstreden gelen satır "dışarıda bırak" (durum `atlandi`) silme sayılmaz; yalnız elle girilen satır silinince arşivlenir.
- Gizlilik: arşiv zaten tablolarda olan alanları kopyalar; kişisel veri alanı yok; içerik loglanmaz.

**Uç**: `GET aile/silinenler?tur=&sayfa=` (hane_id süzülü, `silindi_at` yeniden eskiye, sayfa başına 50, `toplam`); yanıt satırı: `id, tur, tur_etiketi, baslik, tutar, olusturan_ad, olusturuldu_at, silen_ad, silindi_at, neden, alt_sayisi`; ayrıntı `GET aile/silinenler/{id}` (`icerik` ana alanlar + `alt`). `Gate::authorize('hane-kaydi')`. Yazma ucu yok.

## Ön yüz
- Rota `aile-silinenler` (`/aile/silinenler`, `meta.geri: 'aile-ayarlar'`); Bütçe ⚙ ayarları sayfasına "Silinenler" bağlantısı (rozet: toplam kayıt sayısı).
- `SilinenlerView.vue`: üstte tür çipleri (Tümü, Kart işlemi, Gider, Gelir, Tasarruf, Borç, Ev nakdi, Fatura, Ekstre, Portföy işlemi, Kart, Hesap), altında yeniden eskiye liste; **satır metni üstü çizili** (`line-through`, soluk renk), başlık + tutar; alt satırlar: "Yapan: Ayşe · 12.11.2026 14:05", "Silen: Ben · 19.11.2026 09:30" (+ neden); satıra dokununca açılır ayrıntı (`icerik` alanları, `alt` satırlar da üstü çizili); "Daha fazla yükle"; boş durum ("Silinen kayıt yok"). Tarihler `bicim.ts` yardımcılarıyla.
- `api/aile.ts`: `silinenler`, `silinenAyrinti`, tipler.

## Testler
- Arka uç `SilinenlerTest`: gider silme (ev ve banka; banka satışı çift kayıt yazmaz) silen / yapan / zamanlar; elle kart satırı silme arşivlenir, ekstreden gelen satırın "dışarıda bırak"ı arşivlenmez; ekstre silme → `alt` satır özeti; hesap silme → `alt` işlemler; tasarruf silme tek kayıt (alt = alış); borç ödemesi / ev nakdi / gelir / fatura / kart; süzgeç + sayfalama + sıralama; **başka hane göremez, ayrıntıyı açamaz**; konsoldan silme "Sistem"; kullanıcı silinse de ad kalır; liste ucu dışında silme ucu yok. Mevcut silme testleri geçmeye devam eder.
- Ön yüz `silinenler.spec.ts`: üstü çizili satır sınıfı, tür süzgeci, yapan / silen satırları, ayrıntı açılır, boş durum.

## Doğrulama
`DB_DATABASE=finans_test php artisan test`; `npm run type-check && npm run lint && npx vitest run` (PowerShell); Pint / prettier yalnız değişen dosyalara. Edge e2e (360 px): bir nakit harcama + elle kart satırı + borç ekle → hepsini sil → Bütçe ⚙ → Silinenler'de üstü çizili, yapan / silen / zaman doğru; deneme kayıtları (arşiv satırları dahil) temizlenir. CLAUDE.md + ILERLEME güncellenir, plan `docs/planlar/silinenler.md`'ye kopyalanır; 1 migration; canlıya yayın yalnız "Yayınla" denirse. Not: bugüne kadar silinenler geri getirilemez, arşiv bu işten sonraki silmelerle başlar.

## Kapsam dışı
Geri yükleme, tanım (kategori, kaynak, tür, sağlayıcı, üye, zekât) silmeleri, düzenleme geçmişi (yalnız silme), arşivden kalıcı silme, ekstre PDF'inin saklanması.

---

# Madde 2 — Şifremi unuttum (e-postayla) + oturum sürekli açık (güvenli)

## Context
Giriş sayfasında şifre kurtarma yok (yalnız yönetici için sunucuda `SIFRE-SIFIRLA` dosyası); oturum: "Beni hatırla" kutusu işaretli gelir, işaretsizken oturum 2 saat (`SESSION_LIFETIME=120`). Kararlar (kullanıcı): **e-postayla sıfırlama bağlantısı**; **oturum sürekli açık kalsın, güvenli şekilde**; **yönetici şifresini unutursa**: yönetici de aynı e-posta akışını kullanır; e-posta ayarı yapılmamış / çalışmıyorsa mevcut `SIFRE-SIFIRLA` dosya yolu yedek olarak kalır (docs/CANLI.md'de ikisi birlikte anlatılır).

## Şifremi unuttum (arka uç)
- Laravel parola aracısı (`Password::broker()`), tablo `password_reset_tokens` (mevcut); jeton 60 dk geçerli, tek kullanımlık (`config/auth.php passwords.users.expire`), aynı kişiye 60 sn'de bir istek (`throttle`).
- `POST /api/v1/sifre-unuttum` (`email`): `throttle:giris` + hesap sayımını önleyen **her zaman aynı yanıt** ("Bu e-posta kayıtlıysa bağlantı gönderildi"); yalnız `aktif` kullanıcıya gönderilir. E-posta gönderimi ayarlı değilse (`MAIL_MAILER` = log / array) 503 ve "E-posta gönderimi ayarlı değil; yöneticiye başvurun" (sahte "gönderildi" denmez).
- `POST /api/v1/sifre-sifirla` (`email`, `token`, `yeni_sifre` + onay, `Password::defaults()`): başarıda şifre güncellenir, **kullanıcının tüm oturumları ve `remember_token`'ı kapanır** (`sessions` satırları silinir), jeton silinir, `son_giris` değişmez; hatalı / süresi geçmiş jeton için tek genel mesaj.
- E-posta: Türkçe `SifreSifirlamaBildirimi` (`ResetPassword::createUrlUsing` → `{APP_URL}/sifre-sifirla?token=…&email=…`, istek yapılan IP / saat bilgisi içermez, bağlantı dışında hiçbir sır yok); gönderen `MAIL_FROM_*`. `.env.example` ve `.env.production.example`: `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` örnek (boş) alanları; **gerçek SMTP bilgilerini kullanıcı canlı `finans-app/.env`'e kendisi yazar** (Turhost'ta bir e-posta hesabı, ör. `noreply@yoklm.com`); bilgiler bana verilmez, depoya girmez.
- Mevcut `SIFRE-SIFIRLA` dosya yolu korunur (yönetici için yedek); `cron.sh` sıfırlama sonrası oturumları da kapatır.

## Şifremi unuttum (ön yüz)
- `GirisView`: şifre alanının altında "Şifremi unuttum" bağlantısı. Yeni sayfalar `SifreUnuttumView` (e-posta → genel başarı mesajı, 503'te uyarı) ve `SifreSifirlaView` (adresteki `token` + `email`; yeni şifre + tekrar; başarıda girişe yönlenir, "Şifreniz değişti, giriş yapın" bildirimi). Rotalar giriş gibi herkese açık (`meta.acik`).

## Oturum sürekli açık (güvenli)
- Giriş **her zaman** "hatırla" ile yapılır (`Auth::attempt(..., true)`); ön yüzde "Beni hatırla" kutusu kalkar (`GirisView`, `auth.giris` imzası). Hatırlama çerezi 1 yıl (`Auth::guard('web')->setRememberDuration(525600)` / `AppServiceProvider`), oturum ömrü `SESSION_LIFETIME=525600` (config varsayılanı ve `.env.example`; canlı `.env`'de eski 120 satırı varsa `deploy/cron.sh` bunu 525600'e çeker), `expire_on_close=false`; her istekte çerez süresi uzar (kullandıkça sürer).
- Güvenlik: çerez `HttpOnly` + `Secure` + `SameSite=Lax` (mevcut `finans_oturum`, değiştirilmez); oturum şu durumlarda kapanır: Çıkış'a basınca, şifre değişince / sıfırlanınca (tüm cihazlar), yönetici kullanıcıyı pasif yapınca ya da şifresini değiştirince (mevcut kod `sessions` + `remember_token` temizliyor), yönetici pasif kullanıcının hatırla çerezi geçerli olsa da **girişi reddedilir** (`aktif` denetimi: her istekte `EnsureAktif` ara katmanı / `Auth::viaRemember` sonrası).
- Profil ekranına "Bu cihazdaki oturum açık kalır; ortak bilgisayarda Çıkış yapın" notu.

## Testler
- Arka uç `SifreSifirlamaTest`: bağlantı e-postası gider (`Notification::fake`), kayıtsız e-postada aynı yanıt, pasif kullanıcıya gitmez, `MAIL_MAILER=log` iken 503, geçerli jetonla şifre değişir ve eski oturum / `remember_token` kapanır, jeton tek kullanımlık ve süresi dolunca reddedilir, zayıf şifre reddedilir, istek sınırı; `GirisTest`: hatırla çerezi her girişte verilir, pasif kullanıcı girişi / hatırla çerezi reddedilir.
- Ön yüz: `giris.spec.ts` ("Şifremi unuttum" bağlantısı, kutu yok), `sifreUnuttum.spec.ts` (genel mesaj, 503 uyarısı), `sifreSifirla.spec.ts` (token + e-posta adresten, başarıda girişe gider, hatada mesaj).

## Doğrulama
Tüm testler; Pint / prettier yalnız değişen dosyalara; yerelde `MAIL_MAILER=log` ile bağlantının log'a düştüğünü ve sıfırlamanın çalıştığını Edge'de uçtan uca dene (jeton log'dan alınır, deneme kullanıcısı); CLAUDE.md, `docs/CANLI.md` (e-posta ayarı + yönetici için iki yol: e-posta ya da `SIFRE-SIFIRLA`), ILERLEME güncellenir. **Canlıda e-posta için sizin yapacağınız tek şey:** Turhost'ta e-posta hesabı açıp SMTP bilgilerini `finans-app/.env`'e yazmak (adımlar CANLI.md'de); yapılana kadar "Şifremi unuttum" 503 ile yöneticiye yönlendirir. Yeni migration yok.

## Kapsam dışı
SMS ile sıfırlama, iki adımlı doğrulama, "oturumlar" listesi (cihaz bazlı kapatma), parola geçmişi.

## Yönetici şifresini unutursa — üç yol (kullanıcı kararı: e-posta bağlantısı + SIFRE-SIFIRLA dosyası + kurtarma kodları)
1. **E-posta bağlantısı** (yukarıdaki akış; yönetici de aynı yoldan sıfırlar).
2. **Sunucuda `SIFRE-SIFIRLA` dosyası** (mevcut, kalır; e-posta çalışmasa da işler; `cron.sh` sıfırlamadan sonra o kullanıcının oturumlarını da kapatır).
3. **Kurtarma kodları (yeni, yalnızca yönetici hesapları)**:
   - Migration `kurtarma_kodlari` (`id`, `user_id` → users cascade, `ozet` string(64) [kodun `HMAC-SHA256` özeti, anahtar `APP_KEY`; düz kod saklanmaz], `kullanildi_at` nullable, `created_at`; indeks `user_id`).
   - Profil → Güvenlik (yalnız yönetici): "Kurtarma kodları üret" (mevcut şifre ister): 8 adet tek kullanımlık kod (`XXXX-XXXX-XXXX-XXXX`, 32 karakterlik karışmayan alfabe = 80 bit) **bir kez ekranda gösterilir** (kopyala / yazdır yok, "güvenli bir yere yazın" uyarısı); yeniden üretmek eskilerini geçersiz kılar; kalan kod sayısı görünür. `POST /api/v1/ben/kurtarma-kodlari` (şifre onaylı), `GET /api/v1/ben/kurtarma-kodlari` (yalnız kalan sayısı).
   - Giriş → "Şifremi unuttum" sayfasında iki sekme: "E-posta ile" / "Kurtarma kodu ile". `POST /api/v1/sifre-sifirla/kodla` (`email`, `kod`, `yeni_sifre` + onay): kod özeti `hash_equals` ile eşleşir ve kullanılmamışsa kod yakılır, şifre değişir, kullanıcının tüm oturumları ve `remember_token`'ı kapanır; hata her durumda tek genel mesaj ("E-posta ya da kod hatalı"), kullanıcı bulunamasa da aynı süre; `throttle:giris` (dakikada 5, saatte 20) + yanlış deneme sayacı; yalnız `yonetici` ve `aktif` hesaplar (diğer kullanıcıların kodu yoktur, onlar e-postayla ya da yönetici aracılığıyla sıfırlar).
   - Kod kullanıldıktan sonra ilk girişte "Kurtarma kodlarınızı yenileyin" uyarısı (kalan sayı ≤ 2 ise de); kod kullanımı `son_giris` gibi `kurtarma_kullanildi_at` ile yöneticinin Yönetim → Kullanıcılar satırında görünür (kim, ne zaman; kod değeri hiçbir yerde görünmez / loglanmaz).
   - Testler: üretim (8 kod, tekrar üretince eskisi geçersiz, şifre ister, düz kod DB'de yok), kodla sıfırlama (doğru kod tek kullanımlık, yanlış / kullanılmış kod, yönetici olmayan reddi, pasif yönetici reddi, istek sınırı, oturum + remember kapanır), ön yüz (sekmeler, kod biçimi, bir kez gösterilen kodlar).
