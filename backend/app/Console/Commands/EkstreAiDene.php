<?php

namespace App\Console\Commands;

use App\Services\AIStatement\OpenAIClient;
use App\Services\AIStatement\StatementAnalyzer;
use App\Services\AIStatement\YapayZekaHatasi;
use Illuminate\Console\Command;

/**
 * Yalnızca yerel, elle: gerçek bir ekstre PDF'ini (sizin anahtarınızla) OpenAI'ye gönderir, yanıtı uygulamanın
 * doğrulamasından geçirir ve isterseniz referans beklentisiyle (tests/Fixtures/ekstre/<ad>/beklenen.json; açıklamalar
 * anonim olduğu için tarih, tutar, tür, sayfa ve taksitle) satır satır karşılaştırır. Süre ve token kullanımını yazar.
 * CI'da çalışmaz; testler gerçek API'ye istek atmaz. İçerik loglanmaz.
 */
class EkstreAiDene extends Command
{
    protected $signature = 'ekstre:ai-dene {pdf : Gerçek ekstre PDF dosyası} {--beklenen= : Referans beklenen.json} {--senkron : Arka plan modu yerine bekleyerek}';

    protected $description = 'Gerçek ekstreyi yapay zekâyla dener ve referansla karşılaştırır (yalnızca yerel)';

    public function handle(StatementAnalyzer $analizci, OpenAIClient $istemci): int
    {
        if (! app()->environment('local') || ! $istemci->acikMi()) {
            $this->error('Yalnızca yerelde ve OPENAI_API_KEY tanımlıyken çalışır.');

            return self::FAILURE;
        }
        $pdf = @file_get_contents((string) $this->argument('pdf'));
        if ($pdf === false) {
            $this->error('PDF okunamadı.');

            return self::FAILURE;
        }

        $bas = hrtime(true);
        try {
            $arkaPlan = ! $this->option('senkron');
            $hazir = $analizci->hazirla($pdf, $arkaPlan);
            if ($arkaPlan) {
                $durum = $istemci->baslat($hazir['govde']);
                $this->line('Arka plan yanıtı: '.$durum['durum']);
                while ($durum['sonuc'] === null) {
                    if ((hrtime(true) - $bas) / 1e9 > 600) {
                        $istemci->iptal($durum['id']);
                        $this->error('10 dakikada bitmedi; iptal edildi.');

                        return self::FAILURE;
                    }
                    sleep(5);
                    $durum = ['id' => $durum['id'], ...$istemci->sor($durum['id'])];
                    $this->line('… '.$durum['durum']);
                }
                $veri = $durum['sonuc'];
            } else {
                $veri = $istemci->yapilandirilmisCevap($hazir['govde']);
            }
            $sonuc = $analizci->coz($veri, $hazir['sayfa_metinleri'], $hazir['sayfa_sayisi']);
        } catch (YapayZekaHatasi $e) {
            $this->error("Hata ({$e->kod}): {$e->getMessage()}");

            return self::FAILURE;
        }
        $sure = round((hrtime(true) - $bas) / 1e9, 1);

        $this->info(sprintf('%s · %s · %d sayfa · %d satır kabul, %d reddedildi · %s sn · token: %s',
            $sonuc['ai']['bank_name'] ?? '?', $sonuc['ai']['statement_type'], $hazir['sayfa_sayisi'], count($sonuc['kabul']), count($sonuc['reddedilen']), $sure,
            $istemci->sonKullanim ? "{$istemci->sonKullanim['girdi']} girdi / {$istemci->sonKullanim['cikti']} çıktı" : '?'));
        foreach ($sonuc['reddedilen'] as $r) {
            $this->line("  reddedildi s{$r['sayfa']}: {$r['neden']}");
        }

        $beklenenYol = $this->option('beklenen');
        if (! $beklenenYol) {
            return self::SUCCESS;
        }
        $beklenen = json_decode((string) file_get_contents($beklenenYol), true, flags: JSON_THROW_ON_ERROR);
        $anahtar = fn (array $s): string => implode('|', [$s['sayfa'], $s['islem_tarihi'], $s['tutar'], $s['tur'], $s['taksit_no'] ?? '', $s['taksit_sayisi'] ?? '']);
        $b = array_count_values(array_map($anahtar, $beklenen['satirlar']));
        $g = array_count_values(array_map($anahtar, $sonuc['kabul']));
        $eksik = $fazla = [];
        foreach ($b as $k => $n) {
            if (($g[$k] ?? 0) < $n) {
                $eksik[] = $k.' ×'.($n - ($g[$k] ?? 0));
            }
        }
        foreach ($g as $k => $n) {
            if (($b[$k] ?? 0) < $n) {
                $fazla[] = $k.' ×'.($n - ($b[$k] ?? 0));
            }
        }
        $eslesen = count($beklenen['satirlar']) - array_sum(array_map(fn (string $e): int => (int) substr($e, strrpos($e, '×') + 2), $eksik));
        $this->info("Referans: {$eslesen} / ".count($beklenen['satirlar']).' satır eşleşti (sayfa|tarih|tutar|tür|taksit).');
        foreach ($eksik as $e) {
            $this->line("  eksik: {$e}");
        }
        foreach ($fazla as $f) {
            $this->line("  fazla: {$f}");
        }

        return $eksik === [] && $fazla === [] ? self::SUCCESS : self::FAILURE;
    }
}
