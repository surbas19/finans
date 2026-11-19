<?php

namespace App\Services\Aile;

use App\Enums\IslemTuru;
use App\Enums\VarlikTuru;
use App\Models\Hesap;
use App\Models\Islem;
use App\Models\User;
use App\Models\Varlik;
use App\Services\Portfoy\PortfoyHesabi;
use App\Services\Portfoy\Pozisyon;
use App\Services\Portfoy\YetersizMiktar;
use App\Support\Kurus;
use Illuminate\Validation\ValidationException;

/**
 * Kartsız giderin düşüldüğü kaynaklar (tek yer). Ev: ev nakdi kasası (`EvNakdi`). Banka: seçilen portföy hesabının
 * Nakit (TL) bakiyesi; harcama o hesaba `bag_turu = harcama` Nakit satışı olarak yazılır (Hesaplar, toplam ve zekât
 * otomatik doğru kalır). Yetmeyen bakiye hiçbir yerde eksiye düşmez, kayıt reddedilir.
 */
class NakitKaynaklari
{
    public function __construct(private EvNakdi $evNakdi, private PortfoyHesabi $portfoy) {}

    public function nakitVarligi(): ?Varlik
    {
        return Varlik::where('tur', VarlikTuru::Nakit->value)->orderBy('id')->first();
    }

    /** Hesaptaki Nakit (TL) miktarı (tüm işlemler, tarih sırasıyla) */
    public function hesapNakit(Hesap $hesap): float
    {
        $nakit = $this->nakitVarligi();
        if (! $nakit) {
            return 0.0;
        }
        $p = new Pozisyon($hesap->id, $nakit->id);
        foreach (PortfoyHesabi::sirala(Islem::where('hesap_id', $hesap->id)->where('varlik_id', $nakit->id)->get()) as $islem) {
            try {
                $p->uygula($islem);
            } catch (YetersizMiktar) {
                // Bozuk eski satış hesaba katılmaz (PortfoyHesabi::hesapla ile aynı)
            }
        }

        return max(0.0, $p->miktar());
    }

    /**
     * Formlar için: ev bakiyesi ve kullanıcının erişebildiği hesapların nakitleri.
     *
     * @return array<string, mixed>
     */
    public function kaynaklar(User $user): array
    {
        return [
            'ev' => ['bakiye' => Kurus::tl(max(0, $this->evNakdi->bakiyeKurus((int) $user->hane_id)))],
            'hesaplar' => Hesap::erisilebilir($user)->orderBy('sira')->orderBy('id')->get()
                ->map(fn (Hesap $h): array => ['id' => $h->id, 'ad' => $h->ad, 'nakit' => round($this->hesapNakit($h), 2)])->values(),
        ];
    }

    /** Ev harcaması: tutar ev nakdi bakiyesini aşıyorsa reddedilir (güncellemede kendi eski tutarı hariç sayılır) */
    public function evYeterliMi(int $haneId, int $tutarKurus, ?int $haricGiderId = null): void
    {
        $bakiye = $this->evNakdi->bakiyeKurus($haneId, $haricGiderId);
        if ($tutarKurus > $bakiye) {
            throw ValidationException::withMessages(['tutar' => 'Ev nakdinde '.self::tl($bakiye).' var, '.self::tl($tutarKurus - $bakiye).' eksik. Önce ev nakdine para ekleyin ya da Banka seçin.']);
        }
    }

    /**
     * Banka harcaması: hesaba Nakit (TL) satışı yazar. Hesapta o tarihte yeterli nakit yoksa reddedilir.
     */
    public function bankaSatisiYaz(User $user, Hesap $hesap, int $tutarKurus, string $tarih, string $aciklama): Islem
    {
        $nakit = $this->nakitVarligi();
        if (! $nakit) {
            throw ValidationException::withMessages(['hesap_id' => 'Katalogda nakit (TL) varlığı yok; yönetici katalog eşitlemesini çalıştırmalı.']);
        }
        $islem = new Islem([
            'user_id' => $user->id, 'hesap_id' => $hesap->id, 'varlik_id' => $nakit->id, 'tur' => IslemTuru::Satis,
            'miktar' => $tutarKurus / 100, 'birim_fiyat' => 1.0, 'komisyon' => 0, 'tarih' => $tarih,
            'not' => 'Nakit harcama: '.$aciklama, 'bag_turu' => 'harcama',
        ]);
        $mevcut = Islem::where('hesap_id', $hesap->id)->where('varlik_id', $nakit->id)->get();
        try {
            $this->portfoy->dogrula($mevcut->push($islem));
        } catch (YetersizMiktar $e) {
            throw ValidationException::withMessages(['tutar' => '"'.$hesap->ad.'" hesabında '.self::tl((int) round(max(0, $e->eldeki) * 100)).' nakit var; bu harcama için yetmiyor. Önce hesaba nakit ekleyin ya da Ev seçin.']);
        }
        $islem->save();

        return $islem;
    }

    private static function tl(int $kurus): string
    {
        return '₺'.number_format($kurus / 100, 2, ',', '.');
    }
}
