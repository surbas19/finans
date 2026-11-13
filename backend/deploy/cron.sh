#!/bin/bash
# cPanel cron (her dakika): terminal/SSH olmayan paylaşımlı hostingde sunucu komutlarının yerine geçer.
#
#   * * * * * /bin/bash /home/yoklmcom/finans-app/deploy/cron.sh >/dev/null 2>&1
#
# 1) ~/finans-yukleme/ içine FTP ile gelen paketi açar (backend/deploy/yayinla.sh).
# 2) deploy/KUR dosyası varsa (her pakette gelir) kurulum/güncelleme adımlarını bir kez çalıştırır, dosyayı siler.
# 3) Zamanlanmış işleri (fiyat güncelleme) işletir.
# Çıktı deploy/kurulum.log'a yazılır (public dışında, web'den okunamaz). Ayrıntı: docs/CANLI.md
# Okul sisteminin cron.sh'sinden uyarlandı; ikisi ayrı klasörlerde, ayrı cron satırlarıyla çalışır.

set -u
# Gövde { } içinde: bash dosyanın tamamını baştan okur; paket bu dosyanın üzerine yazsa da çalışan kopya bozulmaz.
{
KOK="$(cd "$(dirname "$0")/.." && pwd)"
cd "$KOK" || exit 1
LOG="$KOK/deploy/kurulum.log"
KILIT="$KOK/deploy/.calisiyor"

# Aynı anda iki kopya çalışmasın.
if ! mkdir "$KILIT" 2>/dev/null; then
    if [ -n "$(find "$KILIT" -maxdepth 0 -mmin +15 2>/dev/null)" ]; then
        rmdir "$KILIT" && mkdir "$KILIT" || exit 0
    else
        exit 0
    fi
fi
trap 'rmdir "$KILIT" 2>/dev/null' EXIT

# PHP 8.3+ komut satırı sürümünü bul (cPanel MultiPHP / CloudLinux alt-php; varsayılan php eski olabilir).
PHP=""
for aday in /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php \
            /opt/alt/php84/usr/bin/php /opt/alt/php83/usr/bin/php /usr/local/bin/php /usr/bin/php "$(command -v php)"; do
    if [ -n "$aday" ] && [ -x "$aday" ] && "$aday" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' 2>/dev/null; then
        PHP="$aday"
        break
    fi
done

yaz() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG"; }

if [ -z "$PHP" ]; then
    [ -f "$KOK/deploy/KUR" ] && yaz "HATA: PHP 8.3 veya üstü bulunamadı. cPanel → MultiPHP Manager'dan PHP 8.3 seçin."
    exit 1
fi

artisan() {
    yaz "> php artisan $*"
    "$PHP" artisan "$@" --no-ansi >> "$LOG" 2>&1
    local kod=$?
    [ $kod -ne 0 ] && yaz "HATA ($kod): php artisan $*"
    return $kod
}

# Hata olursa her dakika yeniden denemesin: işaret KUR.hata olur. Sorun giderilince adını yeniden KUR yapın.
durdur() {
    yaz "DURDU: $1"
    yaz "Sorunu giderince deploy/KUR.hata dosyasının adını KUR yapın; bir dakika içinde yeniden denenir."
    "$PHP" artisan up --no-ansi >> "$LOG" 2>&1
    mv -f "$KOK/deploy/KUR" "$KOK/deploy/KUR.hata"
    exit 1
}

# FTP ile gelen paket. yayinla.sh önce .part adıyla yükleyip sonra adını değiştirir; yarım dosya açılmaz.
EV="$(dirname "$KOK")"
YUKLEME="$EV/finans-yukleme"

if [ -f "$YUKLEME/finans-app.zip" ]; then
    yaz "===== Paket açılıyor"
    "$PHP" artisan down --retry=30 --no-ansi >> "$LOG" 2>&1
    if "$PHP" "$KOK/deploy/paket-ac.php" "$YUKLEME/finans-app.zip" "$EV" >> "$LOG" 2>&1; then
        rm -f "$YUKLEME/finans-app.zip"
        yaz "Uygulama paketi açıldı ($(cat "$KOK/deploy/SURUM" 2>/dev/null))."
    else
        mv -f "$YUKLEME/finans-app.zip" "$YUKLEME/finans-app.zip.hata"
        yaz "HATA: Paket açılamadı (finans-yukleme/finans-app.zip.hata). Yukarıdaki satıra bakın."
        "$PHP" artisan up --no-ansi >> "$LOG" 2>&1
    fi
fi

if [ -f "$KOK/deploy/KUR" ]; then
    yaz "===== Kurulum/güncelleme başladı ($("$PHP" -r 'echo PHP_VERSION;'), $PHP)"

    [ -f "$KOK/.env" ] || durdur ".env yok. backend/.env.production.example içeriğiyle finans-app/.env oluşturun (docs/CANLI.md)."
    grep -qE '^DB_PASSWORD=\s*$' "$KOK/.env" \
        && durdur ".env içinde DB_PASSWORD boş. Dosya Yöneticisi'nde .env'i açıp veritabanı şifresini yazın."

    # APP_KEY yalnızca boşsa üretilir; dolu anahtar ASLA değiştirilmez (oturumlar onunla şifreli).
    if grep -qE '^APP_KEY=\s*$' "$KOK/.env"; then
        artisan key:generate --force || durdur "Uygulama anahtarı üretilemedi."
    fi

    artisan down --retry=30
    artisan optimize:clear
    artisan migrate --force || durdur "Veritabanı güncellenemedi (bağlantı bilgilerini ve yukarıdaki hatayı kontrol edin)."
    artisan katalog:esitle || durdur "Varlık kataloğu yazılamadı."
    # İlk kurulum: hiç kullanıcı yoksa .env'deki KURULUM_YONETICI ile yönetici açılır (geçici şifre bu log'a yazılır).
    artisan kurulum:yonetici || durdur "İlk yönetici açılamadı (.env KURULUM_YONETICI=\"e-posta|Ad|Soyad\")."
    artisan optimize || durdur "Ayarlar önbelleğe alınamadı."
    artisan up
    rm -f "$KOK/deploy/KUR"
    yaz "===== KURULUM TAMAM. Geçici şifre bu dosyada yazıyorsa giriş yapıp şifrenizi değiştirin, sonra bu dosyayı silin."
fi

# Yönetici şifresi unutulursa: deploy/SIFRE-SIFIRLA adlı boş dosya oluşturulur; KURULUM_YONETICI e-postasındaki
# yöneticiye yeni geçici şifre verilir ve bu log'a yazılır (giriş yapınca log silinmeli).
if [ -f "$KOK/deploy/SIFRE-SIFIRLA" ]; then
    rm -f "$KOK/deploy/SIFRE-SIFIRLA"
    yaz "===== Yönetici şifresi sıfırlanıyor"
    artisan kurulum:yonetici --sifirla
fi

# Zamanlanmış işler (15 dakikada bir fiyat güncelleme). Son çalışmanın çıktısı (her dakika üzerine yazılır).
"$PHP" artisan schedule:run --no-ansi > "$KOK/storage/logs/zamanlayici.log" 2>&1
exit
}
