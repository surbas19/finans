<?php

namespace App\Services\Aile;

use App\Enums\KartIslemDurumu;
use App\Enums\KartIslemTuru;
use App\Models\Ekstre;
use App\Models\KartIslemi;
use App\Support\Donem;
use App\Support\Kurus;
use App\Support\Metin;
use Illuminate\Validation\ValidationException;

/**
 * Kart satırı kuralları (tek yer):
 *
 * - Rapor ayı: normal satırda işlem tarihinin ayı; taksit satırında (n/N) ekstrenin kesim ayı. Ekstreler taksit
 *   satırında çoğunlukla asıl alış tarihini yazar; "aya düşen taksit" o ekstrenin ayına yazılır.
 * - Parmak izi: kart + tarih + tutar + tür + sade açıklama + taksit + aynı satırın kaçıncısı olduğu. Yalnızca onaylı
 *   satırda dolu ve kart içinde tekil: aynı işlem iki kez onaylanamaz (ikinci ekstre, elle girilmiş aynı satır).
 * - İade: aynı kartta, açıklaması aynı, tutarı iadeden az olmayan ve 180 gün içindeki tek bir harcama varsa ona bağlanır.
 */
class KartIslemKurallari
{
    public function raporDonemi(KartIslemi $islem, ?Ekstre $ekstre): ?string
    {
        if (($islem->taksit_sayisi ?? 0) > 1 && $ekstre?->kesim_tarihi) {
            return Donem::tarihten($ekstre->kesim_tarihi);
        }

        return $islem->islem_tarihi ? Donem::tarihten($islem->islem_tarihi) : null;
    }

    /** Aynı satırlar için ortak taban (kaçıncı olduğu hariç) */
    public function taban(KartIslemi $islem): string
    {
        return implode('|', [
            $islem->kart_id,
            $islem->islem_tarihi?->toDateString(),
            Kurus::kurus($islem->tutar),
            $islem->tur->value,
            Metin::sade($islem->aciklama),
            ($islem->taksit_no ?? '').'/'.($islem->taksit_sayisi ?? ''),
        ]);
    }

    public function parmakIzi(string $taban, int $kacinci): string
    {
        return hash('sha256', $taban.'#'.$kacinci);
    }

    /** Bu tabanla onaylı aynı satır (`$kacinci`.) varsa onu döndürür */
    public function onayliAynisi(KartIslemi $islem, int $kacinci): ?KartIslemi
    {
        return KartIslemi::where('kart_id', $islem->kart_id)
            ->where('parmak_izi', $this->parmakIzi($this->taban($islem), $kacinci))
            ->when($islem->exists, fn ($q) => $q->whereKeyNot($islem->id))
            ->first();
    }

    /**
     * Satırı onaylı yapar: rapor ayı ve parmak izi yazılır. `$kacinci`: ekstrede aynı satırın kaçıncısı (ekstre
     * içi sıra); null ise (elle giriş) ilk boş sıra alınır, yani elle aynı satır iki kez girilebilir.
     *
     * @throws ValidationException tarih / tutar eksikse ya da aynı satır onaylıysa
     */
    public function onayla(KartIslemi $islem, ?Ekstre $ekstre, ?int $kacinci): void
    {
        if ($islem->islem_tarihi === null || $islem->tutar === null || Kurus::kurus($islem->tutar) <= 0) {
            throw ValidationException::withMessages(['islem' => "\"{$islem->aciklama}\" satırının tarihi ya da tutarı yok; düzeltin ya da dışarıda bırakın."]);
        }
        $taban = $this->taban($islem);
        if ($kacinci === null) {
            $kacinci = 1;
            while (KartIslemi::where('kart_id', $islem->kart_id)->where('parmak_izi', $this->parmakIzi($taban, $kacinci))
                ->when($islem->exists, fn ($q) => $q->whereKeyNot($islem->id))->exists()) {
                $kacinci++;
            }
        } elseif ($aynisi = $this->onayliAynisi($islem, $kacinci)) {
            throw ValidationException::withMessages(['islem' => "\"{$islem->aciklama}\" ({$islem->islem_tarihi->format('d.m.Y')}) zaten kayıtlı".($aynisi->ekstre ? ' ('.$aynisi->ekstre->donemEtiketi().' ekstresi)' : '').'.']);
        }

        $islem->durum = KartIslemDurumu::Onayli;
        $islem->rapor_donemi = $this->raporDonemi($islem, $ekstre);
        $islem->parmak_izi = $this->parmakIzi($taban, $kacinci);
        $islem->tekrar_islem_id = null;
        if ($islem->tur === KartIslemTuru::Iade && $islem->iade_islem_id === null) {
            $islem->iade_islem_id = $this->iadeAsliniBul($islem)?->id;
        }
        $islem->save();
    }

    public function iadeAsliniBul(KartIslemi $iade): ?KartIslemi
    {
        if ($iade->islem_tarihi === null || $iade->tutar === null) {
            return null;
        }
        // İade satırı çoğunlukla işyeri adına "IADE / IPTAL" ekler
        $temiz = fn (string $s): string => trim((string) preg_replace(['/(^|\s)(IADESI|IADE|IPTALI|IPTAL|ALACAK)(?=\s|$)/', '/\s+/'], [' ', ' '], Metin::sade($s)));
        $sade = $temiz($iade->aciklama);
        $adaylar = KartIslemi::where('kart_id', $iade->kart_id)
            ->where('durum', KartIslemDurumu::Onayli->value)
            ->where('tur', KartIslemTuru::Harcama->value)
            ->whereBetween('islem_tarihi', [$iade->islem_tarihi->copy()->subDays(180)->toDateString(), $iade->islem_tarihi->toDateString()])
            ->where('tutar', '>=', $iade->tutar)
            ->get()
            ->filter(fn (KartIslemi $h): bool => $temiz($h->aciklama) === $sade);

        return $adaylar->count() === 1 ? $adaylar->first() : null;
    }
}
