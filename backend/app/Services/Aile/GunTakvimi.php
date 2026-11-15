<?php

namespace App\Services\Aile;

use App\Models\Tasarruf;
use App\Models\TasarrufGunu;
use App\Support\Donem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Altın / döviz günleri: verilen ayda hangi gün için alım beklenir, hangisi yapıldı. Beklenen kayıt değildir (toplama
 * katılmaz, otomatik yazılmaz); alım kullanıcı onayıyla `tasarruflar` kaydı olur.
 */
class GunTakvimi
{
    public function ayiVarMi(TasarrufGunu $gun, string $donem): bool
    {
        if (! $gun->aktif) {
            return false;
        }
        $fark = Donem::fark(Donem::tarihten($gun->baslangic), $donem);

        return $fark >= 0 && ($gun->ay_sayisi === null || $fark < $gun->ay_sayisi);
    }

    /**
     * Ayın günleri: durum `yapildi` (o ay bu güne bağlı tasarruf var) ya da `bekliyor`; `vadesi_geldi` planlanan gün
     * bugün ya da geçmişte ise.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function ayinGunleri(int $haneId, string $donem): Collection
    {
        $gunler = TasarrufGunu::where('hane_id', $haneId)->where('aktif', true)->with(['varlik', 'hesap'])->orderBy('ay_gunu')->orderBy('id')->get()
            ->filter(fn (TasarrufGunu $g): bool => $this->ayiVarMi($g, $donem));
        $yapilan = Tasarruf::where('hane_id', $haneId)->where('donem', $donem)->whereIn('gun_id', $gunler->modelKeys())
            ->get()->groupBy('gun_id');
        $bugun = Carbon::today();

        return $gunler->map(function (TasarrufGunu $g) use ($donem, $yapilan, $bugun): array {
            $planlanan = Donem::ilkGun($donem)->day(min($g->ay_gunu, Donem::sonGun($donem)->day));
            $kayitlar = $yapilan->get($g->id, collect());

            // Sıra bizde: o ay alım beklenmez (yine de tasarruf girilmişse yapıldı sayılır)
            $siraBizde = $g->bizde_ay === $donem && $kayitlar->isEmpty();

            return [
                'gun' => $g->ozet(),
                'donem' => $donem,
                'tarih' => $planlanan->toDateString(),
                'durum' => $kayitlar->isNotEmpty() ? 'yapildi' : ($siraBizde ? 'sira_bizde' : 'bekliyor'),
                'vadesi_geldi' => $kayitlar->isEmpty() && ! $siraBizde && $planlanan->lte($bugun),
                'tasarruf_id' => $kayitlar->first()?->id,
            ];
        })->values();
    }
}
