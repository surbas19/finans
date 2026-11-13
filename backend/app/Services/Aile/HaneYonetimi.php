<?php

namespace App\Services\Aile;

use App\Models\Hane;
use App\Models\HaneUyesi;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Hane kurma ve kullanıcıyı haneler arasında taşıma kuralları (tek yer).
 *
 * - Bütçe'ye ilk girişte hanesi olmayan kullanıcıya tek kişilik hane kurulur.
 * - Yönetici kullanıcıyı başka haneye bağlar. Kullanıcı hanede tek girişliyse ve hanede aile kaydı varsa taşıma
 *   reddedilir: kayıtlar sahipsiz kalmaz. Başka girişli üye varsa kayıtlar hanede kalır, kişinin üye kaydı adıyla
 *   (girişsiz) durur. Boş kalan hane silinir.
 */
class HaneYonetimi
{
    /** `hane_id` taşıyan aile tabloları (üyeler hariç); yeni tablo eklenince buraya da yazılır */
    public const AILE_TABLOLARI = ['gelir_kaynaklari', 'gelirler', 'bankalar', 'kartlar', 'ekstreler', 'kart_islemleri'];

    /** Üyeye bağlı kayıtlar (tablo => sütun); kaydı olan üye silinmez, pasif olur */
    public const UYE_BAGLARI = ['gelir_kaynaklari' => 'uye_id', 'gelirler' => 'uye_id', 'kartlar' => 'sahip_uye_id'];

    public function __construct(private VarsayilanAileKayitlari $varsayilanlar) {}

    public function hazirla(User $user): Hane
    {
        if ($user->hane_id !== null && $user->hane) {
            // Bu özellikten önce kurulmuş hanede eksik başlangıç kayıtları (kategoriler) bir kez yazılır
            $this->varsayilanlar->yaz($user->hane);

            return $user->hane;
        }

        return DB::transaction(function () use ($user): Hane {
            $hane = Hane::create(['ad' => trim($user->soyad) !== '' ? trim($user->soyad).' ailesi' : 'Hanem']);
            $this->varsayilanlar->yaz($hane);
            $this->uyeYap($user, $hane);

            return $hane;
        });
    }

    /**
     * Kullanıcıyı `$hedef` haneye taşır; `null` ise kendi başına yeni bir haneye.
     */
    public function tasi(User $user, ?Hane $hedef): Hane
    {
        if ($hedef !== null && $user->hane_id === $hedef->id) {
            return $hedef;
        }

        return DB::transaction(function () use ($user, $hedef): Hane {
            $eski = $user->hane_id !== null ? Hane::lockForUpdate()->find($user->hane_id) : null;
            if ($eski) {
                $digerGirisler = User::where('hane_id', $eski->id)->whereKeyNot($user->id)->exists();
                if (! $digerGirisler && $this->kayitVarMi($eski)) {
                    throw ValidationException::withMessages([
                        'hane_id' => "{$user->ad} {$user->soyad} hanesindeki tek kullanıcı ve hanede gelir / kart / fatura kaydı var; taşınırsa bu kayıtlara kimse erişemez. Önce başka bir kullanıcıyı bu haneye bağlayın.",
                    ]);
                }
                // Gelir / kart kayıtları üyeye bağlı kalsın: üye adıyla, girişsiz durur
                HaneUyesi::where('user_id', $user->id)->update(['user_id' => null]);
                $user->forceFill(['hane_id' => null])->save();
                if (! $digerGirisler) {
                    $eski->delete();
                }
            }

            if ($hedef === null) {
                $hedef = Hane::create(['ad' => trim($user->soyad) !== '' ? trim($user->soyad).' ailesi' : 'Hanem']);
                $this->varsayilanlar->yaz($hedef);
            }
            $this->uyeYap($user, $hedef);

            return $hedef;
        });
    }

    /** Hanede gelir, kart, ekstre, fatura gibi kayıt var mı (girişsiz üyeler de kayıt sayılır) */
    public function kayitVarMi(Hane $hane): bool
    {
        if (HaneUyesi::where('hane_id', $hane->id)->whereNull('user_id')->exists()) {
            return true;
        }
        foreach (self::AILE_TABLOLARI as $tablo) {
            if (Schema::hasTable($tablo) && DB::table($tablo)->where('hane_id', $hane->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    public function uyeninKaydiVarMi(HaneUyesi $uye): bool
    {
        foreach (self::UYE_BAGLARI as $tablo => $sutun) {
            if (Schema::hasTable($tablo) && DB::table($tablo)->where($sutun, $uye->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function uyeYap(User $user, Hane $hane): void
    {
        $user->forceFill(['hane_id' => $hane->id])->save();
        $user->haneOnbeleginiBirak();
        $user->unsetRelation('hane');
        $ad = trim($user->ad);
        if (HaneUyesi::where('hane_id', $hane->id)->where('ad', $ad)->exists()) {
            $ad = trim($user->ad.' '.$user->soyad);
        }
        HaneUyesi::create([
            'hane_id' => $hane->id,
            'user_id' => $user->id,
            'ad' => $ad,
            'sira' => (int) HaneUyesi::where('hane_id', $hane->id)->max('sira') + 1,
            'aktif' => true,
        ]);
    }
}
