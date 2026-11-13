#!/bin/bash
# YERELDE çalışır (Git Bash): paketi hazırlar, FTP (TLS zorunlu) ile sunucuya gönderir ve sonucu bekler.
#
#   bash backend/deploy/yayinla.sh            # paketle + gönder
#   bash backend/deploy/yayinla.sh denetle    # yalnızca sürüm denetimi (hiçbir şey göndermez)
#
# Koruma: yalnızca 'main' dalı yayınlanır; canlıdaki sürüm (kurulum.log'daki son "paketi açıldı (<commit>)")
# gönderilecek kodun atası değilse yayın durur. Bilerek geri dönmek için: YAYIN_ZORLA=1 bash backend/deploy/yayinla.sh
#
# Paket ~/finans-yukleme/ klasörüne gider; sunucudaki cron.sh bir-iki dakika içinde açar, sonucu
# finans-app/deploy/kurulum.log'a yazar. Bağlantı bilgileri canli-paket/ftp.env'de (git'e girmez). Ayrıntı: docs/CANLI.md
set -euo pipefail

ISLEM="${1:-yayinla}"
case "$ISLEM" in yayinla|denetle) ;; *) echo "Kullanım: $0 [denetle]" >&2; exit 1 ;; esac

KOK="$(cd "$(dirname "$0")/../.." && pwd)"
CIKTI="$KOK/canli-paket"
AYAR="$CIKTI/ftp.env"
UZAK="finans-yukleme"
UYGULAMA="finans-app"

if [ ! -f "$AYAR" ]; then
    mkdir -p "$CIKTI"
    cat > "$AYAR" <<'SABLON'
# FTP bilgileri: backend/deploy/yayinla.sh okur. Bu dosya git'e girmez; kimseyle paylaşmayın.
# Okul sisteminin yayın hesabı kullanılabilir (canli-paket/ftp.env'deki aynı bilgiler): "Directory" ev klasörü olmalı.
# Şifrede ' karakteri kullanmayın.
FTP_SUNUCU='ftp.yoklm.com'
# Sertifika sunucunun kendi adına kayıtlıysa (Turhost: *.trwww.com) o ad; bağlantı yine FTP_SUNUCU'ya kurulur.
FTP_SERTIFIKA_ADI='srvc32.trwww.com'
FTP_KULLANICI='deploy@yoklm.com'
FTP_SIFRE=''
SABLON
    echo "canli-paket/ftp.env oluşturuldu. İçindeki FTP bilgilerini doldurup yeniden çalıştırın." >&2
    exit 1
fi

FTP_SUNUCU="" FTP_KULLANICI="" FTP_SIFRE="" FTP_SERTIFIKA_ADI=""
# shellcheck source=/dev/null
. "$AYAR"
if [ -z "$FTP_SUNUCU" ] || [ -z "$FTP_KULLANICI" ] || [ -z "$FTP_SIFRE" ]; then
    echo "HATA: canli-paket/ftp.env içinde FTP_SUNUCU, FTP_KULLANICI ve FTP_SIFRE dolu olmalı." >&2
    exit 1
fi

# Kimlik bilgisi komut satırında (süreç listesinde) görünmesin: curl ayarı standart girdiden okunur.
kimlik() {
    local k="${FTP_KULLANICI//\\/\\\\}" s="${FTP_SIFRE//\\/\\\\}"
    printf 'user = "%s:%s"\n' "${k//\"/\\\"}" "${s//\"/\\\"}"
}
URL_SUNUCU="${FTP_SERTIFIKA_ADI:-$FTP_SUNUCU}"
BAGLANTI=()
[ -n "$FTP_SERTIFIKA_ADI" ] && BAGLANTI=(--connect-to "$FTP_SERTIFIKA_ADI:21:$FTP_SUNUCU:21")

ftp() {
    kimlik | curl --config - --silent --show-error --fail --ssl-reqd --ftp-create-dirs "${BAGLANTI[@]}" "$@"
}

echo "==> Bağlantı deneniyor ($FTP_SUNUCU)"
ftp --list-only "ftp://$URL_SUNUCU/" > /dev/null

surum_denetle() {
    local canli dal
    canli="$(ftp "ftp://$URL_SUNUCU/$UYGULAMA/deploy/kurulum.log" 2>/dev/null \
        | grep -oE 'paketi açıldı \([0-9a-f]{7,40}' | tail -n 1 | grep -oE '[0-9a-f]{7,40}$' || true)"
    dal="$(git -C "$KOK" rev-parse --abbrev-ref HEAD)"
    if [ "$dal" != "main" ] && [ "${YAYIN_ZORLA:-0}" != "1" ]; then
        echo "HATA: Yalnızca 'main' dalı yayınlanır; şu an '$dal' dalındasınız." >&2
        return 1
    fi
    if [ -z "$canli" ]; then
        echo "==> Canlıdaki sürüm okunamadı (ilk kurulum ya da kurulum.log silinmiş); denetim atlandı."
        return 0
    fi
    echo "==> Canlıdaki sürüm: $canli · gönderilecek: $(git -C "$KOK" rev-parse --short HEAD) ($dal)"
    git -C "$KOK" fetch --quiet --all 2>/dev/null || true
    if ! git -C "$KOK" cat-file -e "$canli^{commit}" 2>/dev/null; then
        echo "HATA: Canlıdaki sürüm ($canli) bu bilgisayarda yok. Öbür bilgisayarda 'git push', burada 'git pull' yapın." >&2
        return 1
    fi
    if ! git -C "$KOK" merge-base --is-ancestor "$canli" HEAD; then
        echo "HATA: Canlıdaki sürüm ($canli) '$dal' dalında yok; yayınlarsanız canlıdaki yeni iş kaybolur." >&2
        echo "      Bilerek eski sürüme dönmek için: YAYIN_ZORLA=1 bash backend/deploy/yayinla.sh" >&2
        return 1
    fi
    echo "==> Sürüm denetimi tamam."
}

if ! surum_denetle; then
    [ "$ISLEM" = denetle ] && exit 1
    if [ "${YAYIN_ZORLA:-}" = 1 ]; then
        echo "UYARI: YAYIN_ZORLA=1 ile denetim geçildi." >&2
    else
        exit 1
    fi
fi
[ "$ISLEM" = denetle ] && exit 0

bash "$KOK/backend/deploy/paket-hazirla.sh"

AD=finans-app.zip
echo "==> Gönderiliyor: $AD ($(du -h "$CIKTI/$AD" | cut -f1))"
# .part adıyla yüklenir, bitince adı değişir: cron yarım dosyayı açmaz. Önceki başarısız denemenin .hata dosyası silinir.
ftp --upload-file "$CIKTI/$AD" "ftp://$URL_SUNUCU/$UZAK/$AD.part" \
    -Q "*DELE $UZAK/$AD.hata" -Q "-RNFR $AD.part" -Q "-RNTO $AD"

echo "==> Sunucunun paketi açması bekleniyor (en çok 5 dakika)"
BITTI=""
bekleyen=""
kur=""
for _ in $(seq 1 30); do
    sleep 10
    bekleyen="$(ftp --list-only "ftp://$URL_SUNUCU/$UZAK/" | tr -d '\r' || true)"
    kur="$(ftp --list-only "ftp://$URL_SUNUCU/$UYGULAMA/deploy/" 2>/dev/null | tr -d '\r' | grep -xE 'KUR(\.hata)?' || true)"
    if ! grep -qx "$AD" <<< "$bekleyen" && [ "$kur" != KUR ]; then
        BITTI=1
        break
    fi
    printf '.'
done
echo

echo "==> kurulum.log (son satırlar)"
ftp "ftp://$URL_SUNUCU/$UYGULAMA/deploy/kurulum.log" | tail -n 12 || true

if [ -z "$BITTI" ]; then
    echo "UYARI: Paket 5 dakikada açılmadı. Cron çalışıyor mu? (cPanel → Cron Jobs)" >&2
    exit 1
fi
if grep -qE '\.hata$' <<< "$bekleyen" || [ "$kur" = KUR.hata ]; then
    echo "HATA: Sunucuda açma/kurulum hatası var; yukarıdaki log satırlarına bakın." >&2
    exit 1
fi
echo "Yayınlandı: $(git -C "$KOK" rev-parse --short HEAD)"
