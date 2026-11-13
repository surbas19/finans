<?php

namespace App\Services\Portfoy;

use App\Enums\VarlikTuru;
use App\Models\Hesap;
use App\Models\Islem;
use App\Models\User;
use App\Models\Varlik;
use Illuminate\Support\Collection;

/**
 * Portföy hesabının TEK yeri: miktar, ağırlıklı ortalama maliyet, gerçekleşen / gerçekleşmemiş kâr-zarar,
 * TL değerleme (USD fiyatlılar USD/TRY alışıyla). Maliyeti bilinmeyen kalem sıfır maliyet sayılmaz.
 */
class PortfoyHesabi
{
    /**
     * @return array<string, mixed>
     */
    public function hesapla(User $user, ?int $hesapId = null): array
    {
        $hesaplar = $user->hesaplar()->when($hesapId, fn ($q) => $q->whereKey($hesapId))->get();
        $islemler = self::sirala(
            Islem::where('user_id', $user->id)
                ->whereIn('hesap_id', $hesaplar->modelKeys())
                ->with('varlik.fiyat')
                ->get()
        );
        $usd = $this->usdKuru();

        /** @var array<string, Pozisyon> $pozisyonlar */
        $pozisyonlar = [];
        /** @var array<int, Varlik> $varliklar */
        $varliklar = [];
        foreach ($islemler as $islem) {
            $anahtar = $islem->hesap_id.'-'.$islem->varlik_id;
            $pozisyonlar[$anahtar] ??= new Pozisyon($islem->hesap_id, $islem->varlik_id);
            $varliklar[$islem->varlik_id] = $islem->varlik;
            try {
                $pozisyonlar[$anahtar]->uygula($islem);
            } catch (YetersizMiktar) {
                // Kayıtlar girişte doğrulanır; buraya düşen bozuk satış hesaba katılmaz.
            }
        }

        $satirlar = collect($pozisyonlar)->map(fn (Pozisyon $p): array => $this->degerle($p, $varliklar[$p->varlikId], $usd))->values();
        $acik = $satirlar->filter(fn (array $s): bool => $s['miktar'] > Pozisyon::EPS)->values();

        return [
            'usd_kuru' => $usd,
            'toplam' => self::topla($satirlar),
            'hesaplar' => $hesaplar->map(fn (Hesap $h): array => [
                ...$h->ozet(),
                ...self::topla($satirlar->where('hesap_id', $h->id)),
                'varlik_sayisi' => $acik->where('hesap_id', $h->id)->count(),
            ])->values(),
            'dagilim' => $this->dagilim($acik),
            'varliklar' => $this->varlikBazinda($pozisyonlar, $varliklar, $usd),
            'pozisyonlar' => $acik->sortByDesc(fn (array $s): float => $s['deger'] ?? -1)->values(),
        ];
    }

    /**
     * Bir hesap + varlığın işlemleri tarih sırasıyla uygulanabiliyor mu (satış eldekinden fazla değil mi).
     *
     * @param  Collection<int, Islem>  $islemler
     *
     * @throws YetersizMiktar
     */
    public function dogrula(Collection $islemler): void
    {
        $pozisyon = new Pozisyon(0, 0);
        foreach (self::sirala($islemler) as $islem) {
            $pozisyon->uygula($islem);
        }
    }

    /**
     * Tarih, aynı günde kayıt sırası (yeni kayıt — id'siz — günün sonunda).
     *
     * @param  Collection<int, Islem>  $islemler
     * @return Collection<int, Islem>
     */
    public static function sirala(Collection $islemler): Collection
    {
        return $islemler->sortBy([
            fn (Islem $a, Islem $b): int => $a->tarih->toDateString() <=> $b->tarih->toDateString(),
            fn (Islem $a, Islem $b): int => ($a->id ?? PHP_INT_MAX) <=> ($b->id ?? PHP_INT_MAX),
        ])->values();
    }

    public function usdKuru(): ?float
    {
        $usd = Varlik::where('tur', VarlikTuru::Doviz->value)->where('kod', 'USD')->with('fiyat')->first();

        return $usd?->fiyat?->fiyat;
    }

    /**
     * Varlığın TL fiyatı (USD fiyatlıysa kurla çevrilir); fiyat ya da kur yoksa null.
     */
    public static function tlFiyat(Varlik $varlik, ?float $usd): ?float
    {
        $fiyat = $varlik->fiyat?->fiyat;
        if ($fiyat === null) {
            return null;
        }

        return match ($varlik->para_birimi) {
            'TRY' => $fiyat,
            'USD' => $usd ? $fiyat * $usd : null,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function degerle(Pozisyon $p, Varlik $varlik, ?float $usd): array
    {
        $fiyatTl = self::tlFiyat($varlik, $usd);
        $kur = $varlik->para_birimi === 'USD' ? $usd : 1.0;
        $miktar = $p->miktar();
        $deger = $fiyatTl !== null ? $miktar * $fiyatTl : null;
        $kz = $fiyatTl !== null && $p->miktarBilinen > Pozisyon::EPS ? $p->miktarBilinen * $fiyatTl - $p->maliyetBilinen : null;
        $dunku = $varlik->fiyat?->dunkuFiyat();
        $gunluk = $dunku !== null && $kur ? $miktar * ($varlik->fiyat->fiyat - $dunku) * $kur : null;

        return [
            'hesap_id' => $p->hesapId ?: null,
            'varlik' => $varlik->ozet(),
            'miktar' => round($miktar, 8),
            'maliyeti_bilinmeyen_miktar' => round($p->miktarBilinmeyen, 8),
            'ortalama_maliyet' => self::yuvarla($p->ortalamaMaliyet(), 4),
            'maliyet' => $kz !== null ? round($p->maliyetBilinen, 2) : null,
            'fiyat_tl' => self::yuvarla($fiyatTl, 4),
            'deger' => self::yuvarla($deger, 2),
            'kz' => self::yuvarla($kz, 2),
            'kz_yuzde' => $kz !== null && $p->maliyetBilinen > 0 ? round($kz / $p->maliyetBilinen * 100, 2) : null,
            'gunluk_degisim' => self::yuvarla($gunluk, 2),
            'gerceklesen_kz' => $p->satisVar ? round($p->gerceklesen, 2) : null,
            'gerceklesen_kz_eksik' => $p->gerceklesenEksik,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $satirlar
     * @return array<string, mixed>
     */
    private static function topla(Collection $satirlar): array
    {
        $acik = $satirlar->filter(fn (array $s): bool => $s['miktar'] > Pozisyon::EPS);
        $maliyet = (float) $acik->whereNotNull('kz')->sum('maliyet');
        $kz = (float) $acik->whereNotNull('kz')->sum('kz');

        return [
            'deger' => round((float) $acik->sum(fn (array $s): float => $s['deger'] ?? 0), 2),
            'maliyet' => round($maliyet, 2),
            'kz' => round($kz, 2),
            'kz_yuzde' => $maliyet > 0 ? round($kz / $maliyet * 100, 2) : null,
            'gunluk_degisim' => round((float) $acik->sum(fn (array $s): float => $s['gunluk_degisim'] ?? 0), 2),
            'gerceklesen_kz' => round((float) $satirlar->sum(fn (array $s): float => $s['gerceklesen_kz'] ?? 0), 2),
            'fiyatsiz' => $acik->whereNull('deger')->count(),
            'maliyeti_eksik' => $acik->contains(fn (array $s): bool => $s['maliyeti_bilinmeyen_miktar'] > 0),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $acik
     * @return list<array{tur: string, etiket: string, deger: float}>
     */
    private function dagilim(Collection $acik): array
    {
        return $acik->groupBy(fn (array $s): string => $s['varlik']['tur'])
            ->map(fn (Collection $grup, string $tur): array => [
                'tur' => $tur,
                'etiket' => VarlikTuru::from($tur)->etiket(),
                'deger' => round((float) $grup->sum(fn (array $s): float => $s['deger'] ?? 0), 2),
            ])
            ->filter(fn (array $d): bool => $d['deger'] > 0)
            ->sortByDesc('deger')->values()->all();
    }

    /**
     * Aynı varlık bütün hesaplarda toplanır (ör. toplam gram altın).
     *
     * @param  array<string, Pozisyon>  $pozisyonlar
     * @param  array<int, Varlik>  $varliklar
     * @return list<array<string, mixed>>
     */
    private function varlikBazinda(array $pozisyonlar, array $varliklar, ?float $usd): array
    {
        $toplam = [];
        foreach ($pozisyonlar as $p) {
            $t = $toplam[$p->varlikId] ??= new Pozisyon(0, $p->varlikId);
            $t->miktarBilinen += $p->miktarBilinen;
            $t->maliyetBilinen += $p->maliyetBilinen;
            $t->miktarBilinmeyen += $p->miktarBilinmeyen;
            $t->gerceklesen += $p->gerceklesen;
            $t->gerceklesenEksik = $t->gerceklesenEksik || $p->gerceklesenEksik;
            $t->satisVar = $t->satisVar || $p->satisVar;
        }

        return collect($toplam)
            ->map(fn (Pozisyon $p): array => [
                ...$this->degerle($p, $varliklar[$p->varlikId], $usd),
                'hesap_sayisi' => collect($pozisyonlar)->filter(fn (Pozisyon $x): bool => $x->varlikId === $p->varlikId && $x->miktar() > Pozisyon::EPS)->count(),
            ])
            ->filter(fn (array $s): bool => $s['miktar'] > Pozisyon::EPS)
            ->sortByDesc(fn (array $s): float => $s['deger'] ?? -1)
            ->values()->all();
    }

    private static function yuvarla(?float $deger, int $hane): ?float
    {
        return $deger === null ? null : round($deger, $hane);
    }
}
