<?php

namespace App\Services\Aile;

use App\Enums\FaturaDurumu;
use App\Enums\KartIslemDurumu;
use App\Enums\KartIslemTuru;
use App\Models\Fatura;
use App\Models\FaturaSaglayicisi;
use App\Models\FaturaTuru;
use App\Models\HarcamaKategorisi;
use App\Models\KartIslemi;
use App\Support\Donem;
use App\Support\Kurus;
use App\Support\Metin;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Kart satırı → fatura eşleştirmesi (tek yer).
 *
 * Kanıtlar: sağlayıcının deseni / adı açıklamada geçiyor (güçlü, 60), tutar önceki faturalarına yakın (20), ödeme
 * günü önceki ödemelerine yakın (10), açıklamada hizmet dönemi yazıyor (10), fatura türünün kelimesi geçiyor (10).
 * Güven: tek sağlayıcı + desen + en az bir kanıt daha → yüksek; yalnızca desen → orta; birden çok sağlayıcı →
 * belirsiz; yalnızca tür kelimesi (sağlayıcı tanımsız) → düşük. Otomatik kayıt yalnızca sağlayıcıda açıksa ve güven
 * yüksekse. Hizmet dönemi açıklamada açıkça yazmıyorsa tahmin edilmez (boş = bilinmiyor).
 */
class FaturaEslestirici
{
    /** @var array<int, array{saglayicilar: Collection<int, FaturaSaglayicisi>, turler: Collection<int, FaturaTuru>}> */
    private array $onbellek = [];

    /**
     * @return array<string, mixed>|null
     */
    public function oner(KartIslemi $islem): ?array
    {
        if ($islem->tur !== KartIslemTuru::Harcama) {
            return null;
        }
        ['saglayicilar' => $saglayicilar, 'turler' => $turler] = $this->tanimlar((int) $islem->hane_id);
        $sade = Metin::sade($islem->aciklama);
        $donem = $this->aciklamadakiDonem($sade);

        $eslesenler = [];
        foreach ($saglayicilar as $s) {
            $desenler = [...$s->desenler->pluck('desen')->all(), Metin::sade($s->ad)];
            $gecen = array_values(array_filter(array_unique($desenler), fn (string $d): bool => Metin::geciyor($sade, $d)));
            if ($gecen !== []) {
                $eslesenler[] = [$s, $gecen[0]];
            }
        }
        $turKelimesi = $this->turKelimesi($sade, $turler);

        if (count($eslesenler) > 1) {
            return [
                'guven' => 'belirsiz',
                'puan' => 0,
                'saglayici_id' => null,
                'fatura_turu_id' => null,
                'adaylar' => array_map(fn (array $e): int => $e[0]->id, $eslesenler),
                'gerekce' => ['Birden çok sağlayıcı eşleşti: '.implode(', ', array_map(fn (array $e): string => $e[0]->ad, $eslesenler)).'. Doğru olanı seçin.'],
                ...$this->donemAlanlari($donem),
                'otomatik' => false,
                'secildi' => false,
            ];
        }
        if ($eslesenler === []) {
            if ($turKelimesi === null) {
                return null;
            }
            [$tur, $kelime] = $turKelimesi;

            return [
                'guven' => 'dusuk',
                'puan' => 10,
                'saglayici_id' => null,
                'fatura_turu_id' => $tur->id,
                'adaylar' => $saglayicilar->where('fatura_turu_id', $tur->id)->pluck('id')->values()->all(),
                'gerekce' => ["Açıklamada \"{$kelime}\" geçiyor ({$tur->ad}); bu yazım bir sağlayıcıya bağlı değil."],
                ...$this->donemAlanlari($donem),
                'otomatik' => false,
                'secildi' => false,
            ];
        }

        [$saglayici, $desen] = $eslesenler[0];
        $puan = 60;
        $gerekce = ["Açıklamada \"{$desen}\" geçiyor ({$saglayici->ad})."];
        $oncekiler = Fatura::where('saglayici_id', $saglayici->id)->orderByDesc('id')->limit(6)->get();
        if ($oncekiler->isNotEmpty() && $islem->tutar !== null) {
            $ortalama = intdiv($oncekiler->sum(fn (Fatura $f): int => Kurus::kurus($f->tutar)), $oncekiler->count());
            if ($ortalama > 0 && abs(Kurus::kurus($islem->tutar) - $ortalama) * 2 <= $ortalama) {
                $puan += 20;
                $gerekce[] = 'Tutar önceki faturalara yakın (ortalama ₺'.number_format(Kurus::tl($ortalama), 2, ',', '.').').';
            }
        }
        $gunler = $oncekiler->pluck('odeme_tarihi')->filter()->map(fn ($t): int => (int) $t->day);
        if ($gunler->isNotEmpty() && $islem->islem_tarihi !== null
            && $gunler->contains(fn (int $g): bool => abs($g - $islem->islem_tarihi->day) <= 5)) {
            $puan += 10;
            $gerekce[] = 'Önceki ödemelerle ayın aynı günlerinde.';
        }
        if ($donem !== null) {
            $puan += 10;
            $gerekce[] = 'Açıklamada hizmet dönemi yazıyor ('.Donem::etiket($donem).').';
        }
        // Tür kelimesi ayrı bir kanıt olmalı: sağlayıcının deseni çıkarılınca kalan metinde aranır ("IGDAS" hem desen
        // hem doğalgaz kelimesi; aynı kanıt iki kez sayılmaz)
        $kalanKelime = $this->turKelimesi(trim((string) preg_replace('/\s+/', ' ', str_replace($desen, ' ', $sade))), $turler);
        if ($kalanKelime !== null && $kalanKelime[0]->id === $saglayici->fatura_turu_id) {
            $puan += 10;
            $gerekce[] = "Açıklamada \"{$kalanKelime[1]}\" geçiyor ({$kalanKelime[0]->ad}).";
        }
        $guven = $puan >= 70 ? 'yuksek' : 'orta';
        $otomatik = $guven === 'yuksek' && $saglayici->otomatik_kayit;
        if ($donem === null) {
            $gerekce[] = 'Hizmet dönemi açıklamada yok: dönemi seçin ya da bilinmiyor bırakın.';
        }

        return [
            'guven' => $guven,
            'puan' => $puan,
            'saglayici_id' => $saglayici->id,
            'fatura_turu_id' => $saglayici->fatura_turu_id,
            'adaylar' => [$saglayici->id],
            'gerekce' => $gerekce,
            ...$this->donemAlanlari($donem),
            'otomatik' => $otomatik,
            'secildi' => $otomatik,
        ];
    }

    /**
     * Onaylı kart satırını faturaya bağlar: aynı sağlayıcı ve dönemde bağlanmamış bekleyen fatura varsa ona (ödendi
     * olur), yoksa yeni fatura açılır. Kart satırı Faturalar kategorisine girer.
     *
     * @param  array<string, mixed>|null  $eslesme  güven ve gerekçe (otomatik eşleşmeden)
     */
    public function bagla(KartIslemi $islem, FaturaSaglayicisi $saglayici, ?string $donem, ?string $donemKaynagi, ?array $eslesme, ?int $kullaniciId): Fatura
    {
        if ($islem->durum !== KartIslemDurumu::Onayli) {
            throw ValidationException::withMessages(['kart_islemi_id' => 'Yalnızca onaylı kart satırı faturaya bağlanır.']);
        }
        if (Fatura::where('kart_islemi_id', $islem->id)->exists()) {
            throw ValidationException::withMessages(['kart_islemi_id' => "\"{$islem->aciklama}\" zaten bir faturaya bağlı."]);
        }
        $bekleyen = $donem !== null
            ? Fatura::where('saglayici_id', $saglayici->id)->where('hizmet_donemi', $donem)
                ->where('durum', FaturaDurumu::Bekliyor->value)->whereNull('kart_islemi_id')->first()
            : null;
        $fatura = $bekleyen ?? new Fatura([
            'hane_id' => $islem->hane_id,
            'saglayici_id' => $saglayici->id,
            'fatura_turu_id' => $saglayici->fatura_turu_id,
            'hizmet_donemi' => $donem,
            'donem_kaynagi' => $donem !== null ? $donemKaynagi : null,
            'tutar' => $islem->tutar,
            'kaynak' => 'ekstre',
            'olusturan_id' => $kullaniciId,
        ]);
        $fatura->fill([
            'durum' => FaturaDurumu::Odendi,
            'odeme_tarihi' => $islem->islem_tarihi,
            'kart_islemi_id' => $islem->id,
            'eslesme' => $eslesme,
        ])->save();

        $faturaKategorisi = HarcamaKategorisi::where('hane_id', $islem->hane_id)->where('fatura', true)->value('id');
        if ($faturaKategorisi !== null && $islem->kategori_id !== $faturaKategorisi) {
            $islem->forceFill(['kategori_id' => $faturaKategorisi])->save();
        }

        return $fatura;
    }

    /** Yanlış eşleşmeyi düzeltme: bağ çözülür, fatura "bekliyor"a döner (kart harcaması giderde kalır) */
    public function coz(Fatura $fatura): void
    {
        $fatura->forceFill(['kart_islemi_id' => null, 'durum' => FaturaDurumu::Bekliyor, 'odeme_tarihi' => null, 'eslesme' => null])->save();
    }

    /** Kart satırı silinir / dışarıda bırakılırsa bağlı faturası bekliyor'a döner */
    public function islemCikti(KartIslemi $islem): void
    {
        Fatura::where('kart_islemi_id', $islem->id)->get()->each(fn (Fatura $f) => $this->coz($f));
    }

    /**
     * Açıklamadaki açık hizmet dönemi: "OCAK 2026", "01/2026", "2026/01", "2026-01", "DONEM 202601".
     */
    public function aciklamadakiDonem(string $sade): ?string
    {
        $aylar = 'OCAK|SUBAT|MART|NISAN|MAYIS|HAZIRAN|TEMMUZ|AGUSTOS|EYLUL|EKIM|KASIM|ARALIK';
        if (preg_match('/(?:^|[^A-Z])('.$aylar.')\s*\/?\s*(20\d{2})(?!\d)/', $sade, $e)) {
            return sprintf('%s-%02d', $e[2], Donem::ayNumarasi($e[1]));
        }
        if (preg_match('/(?<![\d\/.])(0?[1-9]|1[0-2])\s*[\/.-]\s*(20\d{2})(?![\d\/.])/', $sade, $e)) {
            return sprintf('%s-%02d', $e[2], (int) $e[1]);
        }
        if (preg_match('/(?<![\d\/.])(20\d{2})\s*[\/-]\s*(0[1-9]|1[0-2])(?![\d\/.])/', $sade, $e)) {
            return "{$e[1]}-{$e[2]}";
        }
        if (preg_match('/DONEM\w*\s*:?\s*(20\d{2})(0[1-9]|1[0-2])(?!\d)/', $sade, $e)) {
            return "{$e[1]}-{$e[2]}";
        }

        return null;
    }

    /**
     * @return array{hizmet_donemi: string|null, donem_kaynagi: string|null}
     */
    private function donemAlanlari(?string $donem): array
    {
        return ['hizmet_donemi' => $donem, 'donem_kaynagi' => $donem !== null ? 'aciklama' : null];
    }

    /**
     * @param  Collection<int, FaturaTuru>  $turler
     * @return array{0: FaturaTuru, 1: string}|null
     */
    private function turKelimesi(string $sade, Collection $turler): ?array
    {
        foreach ($turler as $tur) {
            foreach ($tur->anahtar_kelimeler ?? [] as $kelime) {
                if (Metin::geciyor($sade, $kelime)) {
                    return [$tur, $kelime];
                }
            }
        }

        return null;
    }

    /**
     * @return array{saglayicilar: Collection<int, FaturaSaglayicisi>, turler: Collection<int, FaturaTuru>}
     */
    private function tanimlar(int $haneId): array
    {
        return $this->onbellek[$haneId] ??= [
            'saglayicilar' => FaturaSaglayicisi::where('hane_id', $haneId)->where('aktif', true)->with('desenler')->get(),
            'turler' => FaturaTuru::haneIcin($haneId)->orderBy('sira')->get(),
        ];
    }

    public function unut(int $haneId): void
    {
        unset($this->onbellek[$haneId]);
    }
}
