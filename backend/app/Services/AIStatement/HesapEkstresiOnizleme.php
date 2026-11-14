<?php

namespace App\Services\AIStatement;

use App\Models\HesapEkstresi;
use App\Services\Ekstre\EkstreIceAktarici;
use App\Support\Kurus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Banka hesabı ekstresinin yapay zekâ sonucunu önizleme olarak yazar (Bütçe'ye aktarılmaz): şema denetimi, maskeli
 * standart JSON, dönem ve açılış / kapanış bakiyesi, sayfa denetimi. PDF, işlem kesinleştikten sonra silinir.
 */
class HesapEkstresiOnizleme
{
    public function __construct(
        private JsonValidator $json,
        private StatementAuditService $denetim,
        private KisiselVeriTemizleyici $temizleyici,
    ) {}

    /**
     * @param  array<string, mixed>  $veri
     * @param  list<string>  $sayfaMetinleri
     * @return array{0: int, 1: int} kabul edilen, reddedilen satır
     *
     * @throws YapayZekaHatasi
     */
    public function uygula(HesapEkstresi $hesap, array $veri, array $sayfaMetinleri, int $sayfaSayisi): array
    {
        $sema = json_decode((string) file_get_contents(app_path('Schemas/bank_statement_schema.json')), true, flags: JSON_THROW_ON_ERROR);
        if ($this->json->dogrula($veri, $sema) !== []) {
            throw YapayZekaHatasi::semaHatasi();
        }
        $maskeli = $this->temizleyici->maskeleHepsi($veri);
        $yol = $hesap->dosya_yolu;
        $hesap->forceFill([
            'durum' => 'onizleme',
            'banka_adi' => mb_substr((string) ($maskeli['bank_name'] ?? ''), 0, 60) ?: null,
            'donem_baslangic' => self::tarih($veri['period']['start_date'] ?? null),
            'donem_bitis' => self::tarih($veri['period']['end_date'] ?? null),
            'acilis_bakiyesi' => self::tutar($veri['summary']['opening_balance'] ?? null),
            'kapanis_bakiyesi' => self::tutar($veri['summary']['closing_balance'] ?? null),
            'ai_cikti' => $maskeli,
            'denetim' => [
                'tur' => $veri['statement_type'],
                'sayfa_denetimi' => $this->denetim->denetle($sayfaSayisi, $veri['page_audit'], [], []),
            ],
            'dosya_yolu' => null,
        ])->save();
        // PDF analizden sonra tutulmaz (yalnızca önizleme); geri alınan işlemde dosya kalır
        DB::afterCommit(fn () => $yol !== null && Storage::disk(EkstreIceAktarici::DISK)->delete($yol));

        return [count($veri['transactions']), 0];
    }

    private static function tarih(mixed $deger): ?string
    {
        return is_string($deger) && preg_match('/^(20\d{2})-(\d{2})-(\d{2})$/', $deger, $e) === 1 && checkdate((int) $e[2], (int) $e[3], (int) $e[1]) ? $deger : null;
    }

    private static function tutar(mixed $deger): ?string
    {
        return is_numeric($deger) && abs((float) $deger) <= 1e10 ? Kurus::decimal((int) round(((float) $deger) * 100)) : null;
    }
}
