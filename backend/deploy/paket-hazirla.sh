#!/bin/bash
# YERELDE çalışır (Git Bash): canlıya yüklenecek tek paketi hazırlar → <proje>/canli-paket/finans-app.zip
#
#   bash backend/deploy/paket-hazirla.sh
#
# finans-app.zip: Laravel (vendor dahil, geliştirme paketleri, .env ve testler hariç, deploy/KUR işaretli) + derlenmiş
# arayüz (public/assets, resources/spa/index.html). Sunucuda ev klasörüne açılır: ~/finans-app
# (finans.yoklm.com'un kökü ~/finans-app/public). Yalnızca commit'lenmiş kod paketlenir. Gönderme: yayinla.sh.
set -euo pipefail

KOK="$(cd "$(dirname "$0")/../.." && pwd)"
CIKTI="$KOK/canli-paket"
GECICI="$CIKTI/.gecici"
TAR=/c/Windows/System32/tar.exe
[ -x "$TAR" ] || TAR=tar

# Composer: PATH'te yoksa Laragon'daki composer.phar
if command -v composer >/dev/null 2>&1; then
    COMPOSER=(composer)
elif [ -f /c/laragon/bin/composer/composer.phar ]; then
    COMPOSER=(php /c/laragon/bin/composer/composer.phar)
else
    echo "HATA: composer bulunamadı." >&2
    exit 1
fi

cd "$KOK"
if [ -n "$(git status --porcelain -- backend frontend)" ]; then
    echo "HATA: backend/ veya frontend/ içinde commit'lenmemiş değişiklik var. Önce commit'leyin." >&2
    git status --short -- backend frontend >&2
    exit 1
fi
SURUM="$(git rev-parse --short HEAD) $(git log -1 --format=%cs)"

rm -rf "$GECICI"
mkdir -p "$GECICI"

echo "==> Uygulama ($SURUM)"
git archive HEAD backend | tar -x -C "$GECICI"
mv "$GECICI/backend" "$GECICI/finans-app"
(
    cd "$GECICI/finans-app"
    "${COMPOSER[@]}" install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
    rm -rf tests phpunit.xml .env.example AGENTS.md CLAUDE.md
    echo "$SURUM" > deploy/SURUM
    touch deploy/KUR
)

echo "==> Arayüz derleniyor"
(
    cd "$KOK/frontend"
    npx vite build --outDir "$GECICI/dist" --emptyOutDir --logLevel warn
)
mkdir -p "$GECICI/finans-app/public/assets" "$GECICI/finans-app/resources/spa"
cp -r "$GECICI/dist/assets/." "$GECICI/finans-app/public/assets/"
cp "$GECICI/dist/index.html" "$GECICI/finans-app/resources/spa/index.html"
for dosya in "$GECICI/dist"/*; do
    ad="$(basename "$dosya")"
    [ "$ad" = assets ] || [ "$ad" = index.html ] || cp -r "$dosya" "$GECICI/finans-app/public/"
done
rm -rf "$GECICI/dist"

rm -f "$CIKTI/finans-app.zip"
(cd "$GECICI" && "$TAR" -a -c -f ../finans-app.zip finans-app)
rm -rf "$GECICI"

ls -lh "$CIKTI"/finans-app.zip
echo "Hazır: $CIKTI/finans-app.zip (sürüm $SURUM)"
