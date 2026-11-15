<?php

namespace App\Services\Zekat;

use App\Models\ZekatAyari;
use App\Models\ZekatOdemesi;
use App\Models\ZekatYili;
use App\Support\Kurus;
use Illuminate\Support\Carbon;

/**
 * Zekât hatırlatmaları (Bütçe uyarıları): zekât gününe N gün kaldı, zekât günü geçti ama kayıt yok, kesinleşmiş
 * zekâtın ödenmemiş kısmı var. Okunurken hesaplanır; zamanlanmış komut gerekmez. Zekât hiç kurulmamış haneye uyarı çıkmaz.
 */
class ZekatHatirlatici
{
    public function __construct(private ZekatYilKaydi $kayit) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function uyarilar(int $haneId): array
    {
        $ayar = ZekatAyari::where('hane_id', $haneId)->first();
        if ($ayar === null) {
            return [];
        }
        $bugun = now()->startOfDay();
        $uyarilar = [];

        $hedef = $this->kayit->hedefGun($ayar);
        $fark = (int) $bugun->diffInDays($hedef, false);
        if ($fark < 0) {
            $uyarilar[] = ['tur' => 'gecti', 'tarih' => $hedef->toDateString(), 'gun' => $fark, 'mesaj' => 'Zekât günü ('.$hedef->format('d.m.Y').') geçti, bu yılın zekât kaydı yok.'];
        } elseif ($fark <= (int) $ayar->hatirlatma_gun) {
            $uyarilar[] = [
                'tur' => 'yaklasiyor', 'tarih' => $hedef->toDateString(), 'gun' => $fark,
                'tahmini' => $ayar->zekat_gunu_turu === 'hicri',
                'mesaj' => $fark === 0 ? 'Bugün zekât günü.' : "Zekât gününe {$fark} gün kaldı ({$hedef->format('d.m.Y')}).",
            ];
        }

        ZekatYili::where('hane_id', $haneId)->where('durum', ZekatYili::KESINLESTI)->where('zekat', '>', 0)
            ->with('odemeler')->orderBy('zekat_gunu')->get()
            ->each(function (ZekatYili $yil) use (&$uyarilar): void {
                $kalan = max(0, Kurus::kurus($yil->zekat) - $yil->odenenKurus());
                if ($kalan > 0) {
                    $uyarilar[] = [
                        'tur' => 'odenmemis', 'yil_id' => $yil->id, 'tarih' => $yil->zekat_gunu->toDateString(), 'kalan' => Kurus::tl($kalan),
                        'mesaj' => $yil->zekat_gunu->format('d.m.Y').' zekâtından ödenmemiş tutar var.',
                    ];
                }
            });

        return $uyarilar;
    }

    /**
     * Bütçe ayı içinde verilen zekât (ödeme tarihinin ayı). Yalnızca bilgi; gider ya da kalan hesabına girmez.
     *
     * @return array{verilen: float, adet: int}
     */
    public function verilen(int $haneId, string $donem): array
    {
        $odemeler = ZekatOdemesi::where('hane_id', $haneId)->whereBetween('tarih', [
            Carbon::createFromFormat('!Y-m', $donem)->startOfMonth()->toDateString(),
            Carbon::createFromFormat('!Y-m', $donem)->endOfMonth()->toDateString(),
        ])->get();

        return [
            'verilen' => Kurus::tl($odemeler->reduce(fn (int $t, ZekatOdemesi $o): int => $t + Kurus::kurus($o->tutar), 0)),
            'adet' => $odemeler->count(),
        ];
    }
}
