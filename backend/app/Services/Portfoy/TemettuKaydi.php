<?php

namespace App\Services\Portfoy;

use App\Enums\IslemTuru;
use App\Enums\VarlikTuru;
use App\Models\Islem;
use App\Models\Varlik;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Hisse temettüsünü yazar: hisse satırında TEMETTÜ (net tutar, stopaj) ve aynı hesaba o tutar kadar NAKİT alışı.
 * İki kayıt aynı `aktarim_kodu`nu ve `bag_turu = temettu`yu taşır: birlikte yazılır, düzenlenmez, birini silmek
 * ikisini siler, genel toplama dahil / hariç birlikte değişir (IslemController). Bütçe gelirlerine yazılmaz.
 */
class TemettuKaydi
{
    public function __construct(private PortfoyHesabi $portfoy) {}

    /**
     * @param  Islem  $temettu  kaydedilmemiş temettü satırı (hesap, hisse, net tutar `miktar`, stopaj `komisyon`, tarih)
     *
     * @throws ValidationException
     */
    public function kaydet(Islem $temettu): Islem
    {
        // O tarihte hesapta bu hisseden olmalı
        $liste = Islem::where('hesap_id', $temettu->hesap_id)->where('varlik_id', $temettu->varlik_id)->get()->push($temettu);
        try {
            $this->portfoy->dogrula($liste);
        } catch (YetersizMiktar $e) {
            throw ValidationException::withMessages([
                'tarih' => $e->islem === $temettu
                    ? 'Bu tarihte hesapta bu hisse yok; temettü elde hisse varken girilir.'
                    : 'Bu temettüyle '.$e->islem->tarih->format('d.m.Y').' tarihli bir kayıt geçersiz kalır.',
            ]);
        }

        $nakit = Varlik::where('tur', VarlikTuru::Nakit->value)->orderBy('id')->first();
        if (! $nakit) {
            throw ValidationException::withMessages(['varlik_id' => 'Katalogda nakit (TL) varlığı yok; yönetici katalog eşitlemesini çalıştırmalı.']);
        }

        $hisse = Varlik::find($temettu->varlik_id);
        $kod = (string) Str::uuid();
        $temettu->fill(['aktarim_kodu' => $kod, 'bag_turu' => 'temettu', 'birim_fiyat' => null]);
        $not = Str::limit(trim(($hisse?->kod ?? 'Hisse').' temettüsü'.($temettu->not ? ' · '.$temettu->not : '')), 255, '');

        DB::transaction(function () use ($temettu, $nakit, $kod, $not): void {
            $temettu->save();
            Islem::create([
                'user_id' => $temettu->user_id,
                'hesap_id' => $temettu->hesap_id,
                'varlik_id' => $nakit->id,
                'tur' => IslemTuru::Alis,
                'miktar' => $temettu->miktar,
                'birim_fiyat' => 1.0,
                'komisyon' => 0,
                'tarih' => $temettu->tarih->toDateString(),
                'not' => $not,
                'aktarim_kodu' => $kod,
                'bag_turu' => 'temettu',
                'dahil' => $temettu->dahil ?? true,
            ]);
        });

        return $temettu;
    }
}
