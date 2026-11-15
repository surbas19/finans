<?php

namespace App\Services\Zekat;

use App\Models\User;
use App\Models\ZekatAyari;
use App\Models\ZekatYili;
use App\Models\ZekatYiliBorcu;
use App\Models\ZekatYiliKalemi;
use App\Support\HicriTakvim;
use App\Support\Kurus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Zekât yılı kaydı: önizlemeden değişmez anlık görüntü (taslak), kesinleştirme (salt okunur), taslağı silme.
 * Aynı hane ve zekât günü için tek kayıt vardır. Kayıt, zekât gününü bir sonraki zekât gününe taşır.
 */
class ZekatYilKaydi
{
    public function __construct(private ZekatHesabi $hesap, private ZekatTakvimi $takvim) {}

    public function taslakOlustur(User $user, ?Carbon $zekatGunu = null, ?string $not = null): ZekatYili
    {
        $haneId = (int) $user->hane_id;
        $ayar = ZekatAyari::haneIcin($haneId);
        $hedef = $this->hedefGun($ayar);
        $gun = ($zekatGunu ?? $hedef)->copy()->startOfDay();
        if (ZekatYili::where('hane_id', $haneId)->whereDate('zekat_gunu', $gun->toDateString())->exists()) {
            throw $this->cift();
        }
        $sonuc = $this->hesap->hesapla($user);

        try {
            $yil = DB::transaction(function () use ($haneId, $user, $gun, $not, $sonuc, $ayar, $hedef): ZekatYili {
                $yil = ZekatYili::create([
                    'hane_id' => $haneId,
                    'zekat_gunu' => $gun->toDateString(),
                    'durum' => ZekatYili::TASLAK,
                    'ayar_kopyasi' => $sonuc['ayar'],
                    'brut' => Kurus::decimal($this->kurus($sonuc['brut'])),
                    'borc' => Kurus::decimal($this->kurus($sonuc['borc'])),
                    'net' => Kurus::decimal($this->kurus($sonuc['net'])),
                    'nisab' => Kurus::decimal($this->kurus($sonuc['nisab']['tl'])),
                    'nisab_ustu' => $sonuc['nisab_ustu'],
                    'zekat' => Kurus::decimal($this->kurus($sonuc['zekat'])),
                    'usd_kuru' => $sonuc['usd_kuru'],
                    'nisab_birim_fiyat' => $sonuc['nisab']['birim_fiyat'],
                    'eksik_veri' => $sonuc['eksik_veri'],
                    'eksikler' => $sonuc['eksikler'],
                    'hesaplandi_at' => now(),
                    'not' => $not,
                    'olusturan_id' => $user->id,
                ]);
                foreach ($sonuc['kalemler'] as $k) {
                    ZekatYiliKalemi::create([
                        'zekat_yili_id' => $yil->id, 'hesap_id' => $k['hesap_id'], 'hesap_ad' => $k['hesap_ad'],
                        'varlik_kod' => $k['varlik_kod'], 'varlik_ad' => $k['varlik_ad'], 'varlik_tur' => $k['varlik_tur'],
                        'miktar' => $k['miktar'], 'birim_fiyat_tl' => $k['birim_fiyat_tl'],
                        'deger' => $k['deger'] !== null ? Kurus::decimal($this->kurus($k['deger'])) : null,
                        'dahil_edilen' => $k['dahil_edilen'], 'kapsam_yuzde' => $k['kapsam_yuzde'],
                        'hesaba_giren' => Kurus::decimal($this->kurus($k['hesaba_giren'])), 'dislanma_nedeni' => $k['dislanma_nedeni'],
                    ]);
                }
                foreach ($sonuc['borclar'] as $b) {
                    ZekatYiliBorcu::create(['zekat_yili_id' => $yil->id, 'kaynak' => $b['kaynak'], 'ad' => $b['ad'], 'tutar' => Kurus::decimal($this->kurus($b['tutar']))]);
                }
                // Bu zekât günü kapandı: ayardaki sıradaki gün bir sonrakine geçer
                if ($ayar->sonraki_zekat_gunu === null || $gun->gte($hedef)) {
                    $ayar->hane_id = $haneId;
                    $ayar->sonraki_zekat_gunu = $this->takvim->sonrasi($ayar, $gun)['tarih']->toDateString();
                    $ayar->save();
                }

                return $yil;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->cift();
        }

        return $yil->load(['kalemler', 'borclar']);
    }

    public function kesinlestir(ZekatYili $yil): ZekatYili
    {
        if ($yil->kesinlesmisMi()) {
            throw ValidationException::withMessages(['yil' => 'Bu zekât yılı zaten kesinleşmiş.']);
        }
        $yil->update(['durum' => ZekatYili::KESINLESTI, 'kesinlesti_at' => now()]);

        return $yil;
    }

    public function sil(ZekatYili $yil): void
    {
        if ($yil->kesinlesmisMi()) {
            throw ValidationException::withMessages(['yil' => 'Kesinleşmiş zekât yılı silinemez.']);
        }
        DB::transaction(function () use ($yil): void {
            $gun = $yil->zekat_gunu;
            $hane = $yil->hane_id;
            $yil->delete();
            // Silinen gün hâlâ sıradaki gün olsun ki "zekât günü geçti, kayıt yok" hatırlatması doğru çalışsın
            $ayar = ZekatAyari::where('hane_id', $hane)->first();
            if ($ayar && $ayar->sonraki_zekat_gunu?->gt($gun) && ! ZekatYili::where('hane_id', $hane)->whereDate('zekat_gunu', '>=', $gun->toDateString())->exists()) {
                $ayar->update(['sonraki_zekat_gunu' => $gun->toDateString()]);
            }
        });
    }

    /** Ayarda kayıtlı sıradaki zekât günü; yoksa takvimden hesaplanır (kaydedilmez) */
    public function hedefGun(ZekatAyari $ayar): Carbon
    {
        return $ayar->sonraki_zekat_gunu?->copy()->startOfDay() ?? $this->takvim->ilk($ayar, now())['tarih'];
    }

    /**
     * Sıradaki zekât günü bilgisi (arayüz için): tarih, tahminî mi, yöntem (intl / yedek / miladi), kalan gün.
     *
     * @return array{tarih: string, tahmini: bool, yontem: string, gun_kaldi: int}
     */
    public function gunBilgisi(ZekatAyari $ayar): array
    {
        $hedef = $this->hedefGun($ayar);
        $miladi = $ayar->zekat_gunu_turu === 'miladi';

        return [
            'tarih' => $hedef->toDateString(),
            'tahmini' => ! $miladi,
            'yontem' => $miladi ? 'miladi' : HicriTakvim::yontem(),
            'gun_kaldi' => (int) now()->startOfDay()->diffInDays($hedef, false),
        ];
    }

    private function cift(): ValidationException
    {
        return ValidationException::withMessages(['zekat_gunu' => 'Bu zekât günü için zaten bir kayıt var.']);
    }

    private function kurus(float $tl): int
    {
        return Kurus::kurus(number_format($tl, 2, '.', ''));
    }
}
