<?php

namespace App\Services;

use App\Models\KurtarmaKodu;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Yönetici kurtarma kodları (tek yer): 8 adet tek kullanımlık `XXXX-XXXX-XXXX-XXXX` kodu (karışmayan 32 karakterlik
 * alfabe, 80 bit). Yeniden üretmek eskilerini geçersiz kılar. Düz kod yalnız üretildiği anda döner; saklanan şey
 * `APP_KEY` ile HMAC-SHA256 özetidir. Kod değeri hiçbir yerde loglanmaz.
 */
class KurtarmaKodlari
{
    public const ADET = 8;

    private const ALFABE = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * @return list<string> yeni kodlar (yalnız bu çağrıda görülür)
     */
    public function uret(User $user): array
    {
        $kodlar = [];
        for ($i = 0; $i < self::ADET; $i++) {
            $kodlar[] = $this->yeniKod();
        }
        DB::transaction(function () use ($user, $kodlar): void {
            KurtarmaKodu::where('user_id', $user->id)->delete();
            foreach ($kodlar as $kod) {
                KurtarmaKodu::create(['user_id' => $user->id, 'ozet' => $this->ozet($kod), 'created_at' => now()]);
            }
        });

        return $kodlar;
    }

    public function kalan(User $user): int
    {
        return KurtarmaKodu::where('user_id', $user->id)->whereNull('kullanildi_at')->count();
    }

    /** Kod kullanılmamışsa yakar ve true döner; yanlış / kullanılmış kodda false (zamanlama kullanıcıdan bağımsız) */
    public function kullan(User $user, string $kod): bool
    {
        $ozet = $this->ozet($kod);
        $kayit = KurtarmaKodu::where('user_id', $user->id)->whereNull('kullanildi_at')->where('ozet', $ozet)->first();
        if (! $kayit || ! hash_equals($kayit->ozet, $ozet)) {
            return false;
        }

        return KurtarmaKodu::whereKey($kayit->id)->whereNull('kullanildi_at')->update(['kullanildi_at' => now()]) === 1;
    }

    /** Karşılaştırma için kodun özeti (biçim ve harf büyüklüğü önemsiz) */
    public function ozet(string $kod): string
    {
        $sade = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $kod));

        return hash_hmac('sha256', $sade, (string) config('app.key'));
    }

    private function yeniKod(): string
    {
        $harfler = '';
        for ($i = 0; $i < 16; $i++) {
            $harfler .= self::ALFABE[random_int(0, strlen(self::ALFABE) - 1)];
        }

        return implode('-', str_split($harfler, 4));
    }
}
