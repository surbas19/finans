<?php

namespace App\Services\AIStatement;

use App\Models\Ekstre;
use App\Models\User;
use App\Services\Ekstre\EkstreIceAktarici;
use App\Services\Ekstre\EkstreMutabakati;
use App\Support\Kurus;
use Illuminate\Support\Facades\DB;

/**
 * Yapay zekâ sonucunu İNCELEMEDEKİ (taslak) ekstreye uygular: satırlar taslak olarak yenilenir, boş dönem alanları
 * doldurulur (kaynağı "ai"), mutabakat yeniden hesaplanır, sayfa denetimi ve reddedilen satırlar kaydedilir. Hiçbir
 * zaman doğrudan finans hareketi yazmaz; aktarım yine kullanıcının onayıyla, mevcut onay akışından yapılır.
 */
class StatementImporter
{
    public function __construct(private EkstreIceAktarici $aktarici, private EkstreMutabakati $mutabakat) {}

    /**
     * @param  array{ai: array<string, mixed>, kabul: list<array<string, mixed>>, reddedilen: list<array<string, mixed>>, sayfa_denetimi: array<string, mixed>, model: string}  $analiz
     */
    public function uygula(Ekstre $ekstre, User $user, array $analiz): Ekstre
    {
        DB::transaction(function () use ($ekstre, $user, $analiz): void {
            $this->aktarici->satirlariYenile($ekstre, $user, $analiz['kabul']);
            $ai = $analiz['ai'];

            $alanlar = [
                'kesim_tarihi' => self::tarih($ai['period']['cut_date'] ?? null),
                'son_odeme_tarihi' => self::tarih($ai['period']['due_date'] ?? null),
                'donem_baslangic' => self::tarih($ai['period']['start_date'] ?? null),
                'donem_borcu' => self::tutar($ai['summary']['total_due'] ?? null),
                'asgari_odeme' => self::tutar($ai['summary']['min_payment'] ?? null),
            ];
            $kaynaklar = $ekstre->alan_kaynaklari ?? [];
            $okunamayan = $ekstre->okunamayan ?? [];
            foreach ($alanlar as $alan => $deger) {
                $mevcut = $ekstre->getAttribute($alan);
                $bos = $mevcut === null || ($alan === 'asgari_odeme' && ($kaynaklar[$alan] ?? null) === 'hesaplandi');
                if ($deger !== null && $bos) {
                    $ekstre->setAttribute($alan, $deger);
                    $kaynaklar[$alan] = 'ai';
                    $okunamayan = array_values(array_diff($okunamayan, [$alan]));
                }
            }

            $satirlar = $analiz['kabul'];
            $denetim = $this->mutabakat->denetle(
                ['donem_borcu' => $ekstre->donem_borcu !== null ? (string) $ekstre->donem_borcu : null],
                ['onceki_borc' => self::tutar($ai['summary']['previous_balance'] ?? null, eksiOlabilir: true)],
                $satirlar,
            );
            $ekstre->forceFill([
                'alan_kaynaklari' => $kaynaklar,
                'okunamayan' => $okunamayan,
                'banka_profili' => 'ai',
                'kalan_taksit' => $this->aktarici->kalanTaksit($satirlar),
                'denetim' => [
                    ...$denetim,
                    'ai' => [
                        'model' => $analiz['model'],
                        'banka' => mb_substr((string) $ai['bank_name'], 0, 60),
                        'tur' => $ai['statement_type'],
                        'inceleme_gerekli' => (bool) $ai['review_required'],
                        'uyarilar' => array_map(fn ($u): string => mb_substr((string) $u, 0, 200), array_slice($ai['warnings'], 0, 20)),
                        'sayfa_denetimi' => $analiz['sayfa_denetimi'],
                        'reddedilen' => array_slice($analiz['reddedilen'], 0, 50),
                        'bonus_hareketi' => count($ai['bonus_transactions']),
                    ],
                ],
            ])->save();
        });

        return $ekstre->fresh();
    }

    private static function tarih(mixed $deger): ?string
    {
        return is_string($deger) && preg_match('/^(20\d{2})-(\d{2})-(\d{2})$/', $deger, $e) === 1 && checkdate((int) $e[2], (int) $e[3], (int) $e[1]) ? $deger : null;
    }

    /** Önceki borç fazla ödemede eksi olabilir; diğer tutarlar değil */
    private static function tutar(mixed $deger, bool $eksiOlabilir = false): ?string
    {
        return is_numeric($deger) && ($eksiOlabilir || (float) $deger >= 0) && abs((float) $deger) <= 1e10 ? Kurus::decimal((int) round(((float) $deger) * 100)) : null;
    }
}
