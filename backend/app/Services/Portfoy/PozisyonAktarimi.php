<?php

namespace App\Services\Portfoy;

use App\Enums\IslemTuru;
use App\Enums\VarlikTuru;
use App\Models\Hesap;
use App\Models\Islem;
use App\Models\PozisyonAyari;
use App\Models\User;
use App\Models\Varlik;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Yatırımı bir hesaptan öbürüne taşır. Yeni işlem türü yoktur: kaynakta SATIŞ, hedefte ALIŞ yazılır; ikisi de
 * pozisyonun ağırlıklı ortalama maliyetiyle işlenir, bu yüzden gerçekleşen kâr / zarar ve toplam değişmez, maliyet
 * hedefe taşınır. Maliyeti bilinmeyen kısım hedefe de "elde olan, fiyatsız" olarak gider (sıfır maliyet sayılmaz).
 * Eşleşik kayıtlar aynı `aktarim_kodu`nu taşır.
 */
class PozisyonAktarimi
{
    public function __construct(private PortfoyHesabi $portfoy) {}

    /**
     * @return array{kod: string, kaynak_miktar: float, bilinen: float, bilinmeyen: float}
     *
     * @throws ValidationException
     */
    public function aktar(User $user, Hesap $kaynak, Hesap $hedef, Varlik $varlik, float $miktar, CarbonInterface $tarih, ?string $not): array
    {
        if ($kaynak->id === $hedef->id) {
            throw ValidationException::withMessages(['hedef_hesap_id' => 'Kaynak ve hedef hesap aynı olamaz.']);
        }
        if ($varlik->tur === VarlikTuru::Bes) {
            throw ValidationException::withMessages(['miktar' => 'Bireysel emeklilik başka hesaba aktarılamaz.']);
        }

        $islemler = Islem::where('hesap_id', $kaynak->id)->where('varlik_id', $varlik->id)->get();
        if ($islemler->contains(fn (Islem $i): bool => $i->tur === IslemTuru::Deger)) {
            throw ValidationException::withMessages(['miktar' => 'Bu varlık aktarılamaz.']);
        }

        // Aktarım gününün sonundaki pozisyon (o güne kadarki bütün işlemler)
        $p = new Pozisyon($kaynak->id, $varlik->id);
        foreach (PortfoyHesabi::sirala($islemler) as $islem) {
            if ($islem->tarih->toDateString() > $tarih->toDateString()) {
                break;
            }
            try {
                $p->uygula($islem);
            } catch (YetersizMiktar) {
                // kayıtlar girişte doğrulanır
            }
        }
        $eldeki = $p->miktar();
        if ($miktar > $eldeki + Pozisyon::EPS) {
            throw ValidationException::withMessages(['miktar' => 'Bu tarihte hesapta yeterli miktar yok (elde olan: '.rtrim(rtrim(number_format($eldeki, 8, ',', '.'), '0'), ',').').']);
        }
        $miktar = min($miktar, $eldeki);

        $oran = $eldeki > 0 ? $miktar / $eldeki : 0.0;
        $bilinen = round($p->miktarBilinen * $oran, 8);
        $bilinmeyen = round($miktar - $bilinen, 8);
        $ortalama = $p->ortalamaMaliyet();
        $kod = (string) Str::uuid();
        $aciklama = 'Aktarım: '.$kaynak->ad.' → '.$hedef->ad;
        $notMetni = Str::limit($not !== null && trim($not) !== '' ? $aciklama.' · '.trim($not) : $aciklama, 255, '');
        $ortak = ['user_id' => $user->id, 'varlik_id' => $varlik->id, 'komisyon' => 0, 'tarih' => $tarih->toDateString(), 'not' => $notMetni, 'aktarim_kodu' => $kod, 'bag_turu' => 'aktarim'];

        // Kaynakta satış sonrası da hesap tutarlı mı (sonraki tarihli satışlar elde olandan fazla kalmasın)
        $cikis = new Islem([...$ortak, 'hesap_id' => $kaynak->id, 'tur' => IslemTuru::Satis, 'miktar' => $miktar, 'birim_fiyat' => $ortalama !== null ? round($ortalama, 8) : null]);
        try {
            $this->portfoy->dogrula($islemler->push($cikis));
        } catch (YetersizMiktar $e) {
            throw ValidationException::withMessages(['miktar' => 'Bu aktarım '.$e->islem->tarih->format('d.m.Y').' tarihli bir satışı elde olandan fazla bırakır.']);
        }

        DB::transaction(function () use ($cikis, $ortak, $hedef, $kaynak, $varlik, $bilinen, $bilinmeyen, $ortalama): void {
            $cikis->save();
            if ($bilinen > Pozisyon::EPS && $ortalama !== null) {
                Islem::create([...$ortak, 'hesap_id' => $hedef->id, 'tur' => IslemTuru::Alis, 'miktar' => $bilinen, 'birim_fiyat' => round($ortalama, 8)]);
            }
            if ($bilinmeyen > Pozisyon::EPS) {
                Islem::create([...$ortak, 'hesap_id' => $hedef->id, 'tur' => IslemTuru::Mevcut, 'miktar' => $bilinmeyen, 'birim_fiyat' => null]);
            }
            // Kaynakta genel toplamdan çıkarılmışsa hedefte de çıkarılmış sayılır (hedefte ayar yoksa)
            $haric = PozisyonAyari::where('hesap_id', $kaynak->id)->where('varlik_id', $varlik->id)->where('dahil', false)->exists();
            if ($haric) {
                PozisyonAyari::firstOrCreate(['hesap_id' => $hedef->id, 'varlik_id' => $varlik->id], ['dahil' => false]);
            }
        });

        return ['kod' => $kod, 'kaynak_miktar' => $miktar, 'bilinen' => $bilinen, 'bilinmeyen' => $bilinmeyen];
    }
}
