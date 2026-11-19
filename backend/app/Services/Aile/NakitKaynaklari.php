<?php

namespace App\Services\Aile;

use App\Enums\IslemTuru;
use App\Models\EvNakdiHareketi;
use App\Models\Hesap;
use App\Models\Islem;
use App\Models\User;
use App\Models\Varlik;
use App\Services\Portfoy\PortfoyHesabi;
use App\Services\Portfoy\YetersizMiktar;
use App\Support\Kurus;
use Illuminate\Validation\ValidationException;

/**
 * Kartsız giderin düşüldüğü kaynaklar (tek yer). Ev: seçilen ev hesabının Nakit (TL)'si ya da ayrı ev nakdi kasası (`EvNakdi`). Banka: seçilen portföy hesabının
 * Nakit (TL) bakiyesi; harcama o hesaba `bag_turu = harcama` Nakit satışı olarak yazılır (Hesaplar, toplam ve zekât
 * otomatik doğru kalır). Yetmeyen bakiye hiçbir yerde eksiye düşmez, kayıt reddedilir.
 */
class NakitKaynaklari
{
    public function __construct(private EvNakdi $evNakdi, private PortfoyHesabi $portfoy) {}

    public function nakitVarligi(): ?Varlik
    {
        return $this->evNakdi->nakitVarligi();
    }

    /** Hesaptaki Nakit (TL) miktarı (EvNakdi::hesapNakit) */
    public function hesapNakit(Hesap $hesap): float
    {
        return $this->evNakdi->hesapNakit($hesap);
    }

    /** Ev nakdi olarak seçilmiş portföy hesabı (yoksa ayrı kasa) */
    public function evHesabi(int $haneId): ?Hesap
    {
        return $this->evNakdi->evHesabi($haneId);
    }

    /**
     * Formlar için: ev bakiyesi ve kullanıcının erişebildiği hesapların nakitleri.
     *
     * @return array<string, mixed>
     */
    public function kaynaklar(User $user): array
    {
        return [
            'ev' => [
                'bakiye' => Kurus::tl(max(0, $this->evNakdi->bakiyeKurus((int) $user->hane_id))),
                'hesap' => ($h = $this->evHesabi((int) $user->hane_id)) ? ['id' => $h->id, 'ad' => $h->ad] : null,
            ],
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
    public function bankaSatisiYaz(User $user, Hesap $hesap, int $tutarKurus, string $tarih, string $aciklama, string $ipucu = 'Önce hesaba nakit ekleyin ya da Ev seçin.'): Islem
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
            throw ValidationException::withMessages(['tutar' => '"'.$hesap->ad.'" hesabında '.self::tl((int) round(max(0, $e->eldeki) * 100)).' nakit var; bu harcama için yetmiyor. '.$ipucu]);
        }
        $islem->save();

        return $islem;
    }

    /**
     * Hesap kipinde ev nakdine para ekleme / çıkarma: ev hesabına Nakit (TL) alışı / satışı (sıradan portföy işlemi;
     * Portföy'den düzenlenir). Çıkarma, o tarihte hesaptaki nakitten fazla olamaz.
     */
    public function evHareketiYaz(User $user, Hesap $hesap, string $tur, int $tutarKurus, string $tarih, ?string $not): Islem
    {
        $nakit = $this->nakitVarligi();
        if (! $nakit) {
            throw ValidationException::withMessages(['tutar' => 'Katalogda nakit (TL) varlığı yok; yönetici katalog eşitlemesini çalıştırmalı.']);
        }
        $cikarma = $tur === EvNakdiHareketi::CIKARMA;
        $islem = new Islem([
            'user_id' => $user->id, 'hesap_id' => $hesap->id, 'varlik_id' => $nakit->id,
            'tur' => $cikarma ? IslemTuru::Satis : IslemTuru::Alis, 'miktar' => $tutarKurus / 100, 'birim_fiyat' => 1.0,
            'komisyon' => 0, 'tarih' => $tarih, 'not' => 'Ev nakdi: '.(filled($not) ? trim((string) $not) : ($cikarma ? 'para çıkarıldı' : 'para eklendi')),
        ]);
        if ($cikarma) {
            try {
                $this->portfoy->dogrula(Islem::where('hesap_id', $hesap->id)->where('varlik_id', $nakit->id)->get()->push($islem));
            } catch (YetersizMiktar $e) {
                throw ValidationException::withMessages(['tutar' => '"'.$hesap->ad.'" hesabında '.self::tl((int) round(max(0, $e->eldeki) * 100)).' nakit var; daha fazlası çıkarılamaz.']);
            }
        }
        $islem->save();

        return $islem;
    }

    private static function tl(int $kurus): string
    {
        return '₺'.number_format($kurus / 100, 2, ',', '.');
    }
}
