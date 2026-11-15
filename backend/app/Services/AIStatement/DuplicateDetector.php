<?php

namespace App\Services\AIStatement;

use App\Enums\KartIslemDurumu;
use App\Models\Ekstre;
use App\Models\KartIslemi;
use Illuminate\Support\Carbon;

/**
 * Mükerrer kayıt denetimi.
 *
 * - Yapay zekâ çıktısı içinde: aynı sayfadaki aynı kaynak satır, sayfa metninde kaç kez geçiyorsa en çok o kadar
 *   kabul edilir. Böylece aynı gün ve aynı tutarlı farklı işlemler (ekstrede iki ayrı satır) korunur, modelin aynı
 *   satırı iki kez yazması ayıklanır. Metni olmayan (taranmış) sayfada sayılamaz: aynı tarih / tutar / açıklama ikinci
 *   kez geçerse inceleme gerekir (DuplicateDetector satırı reddetmez, TransactionValidator işaretler).
 * - Ekstreler arası: kesin tekrar parmak iziyle (EkstreIceAktarici::tekrarlariIsaretle); ayrıca açıklama farkına
 *   bakmayan "olası tekrar" (aynı kart, tarih, tutar, tür, taksit; başka ekstrede ya da elle girilmiş onaylı satır).
 */
class DuplicateDetector
{
    /**
     * @param  list<array<string, mixed>>  $islemler
     * @param  list<string>  $sayfaSade  boşluksuz sade sayfa metinleri (0 tabanlı)
     * @return list<array{0: array<string, mixed>, 1: string|null}> her işlem ve fazlaysa ret nedeni
     */
    public function ayikla(array $islemler, array $sayfaSade): array
    {
        $gorulen = [];
        $sonuc = [];
        foreach ($islemler as $i) {
            $sayfa = is_int($i['source_page'] ?? null) ? $i['source_page'] : 0;
            $kaynak = TransactionValidator::bosluksuz((string) ($i['source_text'] ?? ''));
            $metin = $sayfaSade[$sayfa - 1] ?? '';
            if ($kaynak === '' || $metin === '' || ! str_contains($metin, $kaynak)) {
                $sonuc[] = [$i, null];

                continue;
            }
            $anahtar = $sayfa.'|'.$kaynak;
            $gorulen[$anahtar] = ($gorulen[$anahtar] ?? 0) + 1;
            $sonuc[] = [$i, $gorulen[$anahtar] > substr_count($metin, $kaynak)
                ? 'Aynı kaynak satır sayfada '.substr_count($metin, $kaynak).' kez geçiyor; fazla tekrar alınmadı (mükerrer).'
                : null];
        }

        return $sonuc;
    }

    /**
     * Taslak ekstrenin satırlarında olası tekrarları işaretler (inceleme gerekir); işaretlenen satır sayısı.
     */
    public function olasiTekrarlar(Ekstre $ekstre): int
    {
        $sayi = 0;
        foreach ($ekstre->islemler()->where('durum', '!=', KartIslemDurumu::Atlandi->value)->whereNull('tekrar_islem_id')->get() as $islem) {
            if ($islem->islem_tarihi === null || $islem->tutar === null) {
                continue;
            }
            $aynisi = KartIslemi::where('kart_id', $islem->kart_id)->where('durum', KartIslemDurumu::Onayli->value)
                ->where(fn ($q) => $q->whereNull('ekstre_id')->orWhere('ekstre_id', '!=', $ekstre->id))
                ->whereDate('islem_tarihi', $islem->islem_tarihi)->where('tutar', $islem->tutar)->where('tur', $islem->tur->value)
                ->where('taksit_no', $islem->taksit_no)->where('taksit_sayisi', $islem->taksit_sayisi)
                ->with('ekstre:id,kesim_tarihi')->first();
            if ($aynisi === null) {
                continue;
            }
            $okuma = $islem->okuma ?? ['alanlar' => [], 'uyarilar' => [], 'tutar_adaylari' => []];
            $yer = $aynisi->ekstre?->kesim_tarihi ? Carbon::parse($aynisi->ekstre->kesim_tarihi)->format('m.Y').' ekstresinde' : 'elle girilen işlemlerde';
            $okuma['uyarilar'][] = "Olası tekrar: {$yer} aynı tarih ve tutarlı işlem var.";
            $okuma['inceleme'] = true;
            $okuma['incelendi'] = false;
            $islem->forceFill(['okuma' => $okuma])->save();
            $sayi++;
        }

        return $sayi;
    }
}
