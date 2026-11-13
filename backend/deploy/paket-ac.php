<?php

/**
 * SUNUCUDA cron.sh çağırır: FTP ile gelen paketi (backend/deploy/yayinla.sh) açar. Sunucuda unzip olmayabilir,
 * bu yüzden PHP'nin ZipArchive'i kullanılır.
 *
 *   php paket-ac.php <zip> <ev klasörü>   → <ev>/finans-app üzerine açar (.env ve storage/ pakette yok);
 *                                           pakette olmayan eski public/assets dosyalarını siler
 */
function dur(string $mesaj): never
{
    fwrite(STDERR, "HATA: $mesaj\n");
    exit(1);
}

[, $zipYolu, $hedef] = $argv + [null, null, null];
if (! $zipYolu || ! $hedef) {
    dur('Kullanım: php paket-ac.php <zip> <ev klasörü>');
}
if (! class_exists(ZipArchive::class)) {
    dur('PHP zip eklentisi kapalı (cPanel → Select PHP Version → Extensions → zip).');
}

// deploy → finans-app → ev klasörü
$ev = realpath(dirname(__DIR__, 2));
$hedef = realpath($hedef);
if ($ev === false || $hedef === false || $hedef !== $ev) {
    dur('Hedef ev klasörü olmalı.');
}

$zip = new ZipArchive;
if ($zip->open($zipYolu) !== true) {
    dur('Zip açılamadı (eksik yüklenmiş olabilir).');
}

$girdiler = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $ad = (string) $zip->getNameIndex($i);
    if ($ad === '' || str_starts_with($ad, '/') || str_contains($ad, '\\') || in_array('..', explode('/', $ad), true)) {
        dur("Zip içinde geçersiz yol: $ad");
    }
    $ad = preg_replace('#^(\./)+#', '', $ad);
    if (! str_starts_with($ad, 'finans-app/')) {
        dur("Paket dışı yol: $ad");
    }
    $girdiler[] = $ad;
}
if (! in_array('finans-app/artisan', $girdiler, true)) {
    dur('Bu bir finans paketi değil (finans-app/artisan yok).');
}

if (! $zip->extractTo($hedef)) {
    dur('Zip açılırken hata oluştu.');
}
$zip->close();

// Derlenmiş arayüz dosyalarının adları her sürümde değişir; eskileri birikmesin.
$assets = "$hedef/finans-app/public/assets";
if (is_dir($assets)) {
    $yeni = array_flip($girdiler);
    $silinen = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($assets, FilesystemIterator::SKIP_DOTS)) as $dosya) {
        $goreli = str_replace(DIRECTORY_SEPARATOR, '/', substr($dosya->getPathname(), strlen($hedef) + 1));
        if (! isset($yeni[$goreli]) && unlink($dosya->getPathname())) {
            $silinen++;
        }
    }
    echo "Eski $silinen arayüz dosyası silindi.\n";
}

echo count($girdiler)." girdi açıldı → $hedef/finans-app\n";
