<?php

namespace App\Fiyat;

use App\Fiyat\Kaynaklar\BinanceKaynagi;
use App\Fiyat\Kaynaklar\TruncgilKaynagi;
use App\Fiyat\Kaynaklar\YahooKaynagi;
use App\Models\Fiyat;
use App\Models\FiyatGecmisi;
use App\Models\KaynakDurumu;
use App\Models\Varlik;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fiyatları kaynaklardan çeker. Bir kaynak bozulursa öbürleri sürer; durum kaynak_durumlari'na yazılır.
 * Her güncelleme günün kapanışını (fiyat_gecmisi, bugünün satırı) da son fiyatla yazar.
 */
class FiyatGuncelleyici
{
    /** Geçmiş fiyatlar varlık eklendiğinde bir kez, bu kadar geriye çekilir */
    public const GECMIS_GUN = 365;

    /** Bir çalışmada geçmişi çekilecek en çok varlık (paylaşımlı sunucuda uzun sürmesin) */
    public const GECMIS_PARTI = 10;

    public function __construct(
        private TruncgilKaynagi $truncgil,
        private YahooKaynagi $yahoo,
        private BinanceKaynagi $binance,
    ) {}

    /**
     * @return array<string, FiyatKaynagi>
     */
    public function kaynaklar(): array
    {
        return [
            'truncgil' => $this->truncgil,
            'yahoo' => $this->yahoo,
            'binance' => $this->binance,
        ];
    }

    public function kaynak(string $ad): ?FiyatKaynagi
    {
        return $this->kaynaklar()[$ad] ?? null;
    }

    /**
     * @return array<string, int|string> kaynak => güncellenen sayı ya da hata metni
     */
    public function guncelle(?string $yalnizca = null): array
    {
        $rapor = [];
        foreach ($this->kaynaklar() as $ad => $kaynak) {
            if ($yalnizca !== null && $yalnizca !== $ad) {
                continue;
            }
            $varliklar = Varlik::where('aktif', true)->where('kaynak', $ad)->get();
            if ($varliklar->isEmpty()) {
                continue;
            }
            $rapor[$ad] = $this->kaynakGuncelle($ad, $kaynak, $varliklar);
        }

        return $rapor;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Varlik>  $varliklar
     */
    private function kaynakGuncelle(string $ad, FiyatKaynagi $kaynak, $varliklar): int|string
    {
        $durum = KaynakDurumu::firstOrNew(['kaynak' => $ad]);
        $durum->son_deneme = now();
        try {
            $veriler = $kaynak->guncel($varliklar);
        } catch (Throwable $e) {
            $durum->son_hata = Str::limit($e->getMessage(), 500);
            $durum->son_hata_zamani = now();
            $durum->art_arda_hata = $durum->art_arda_hata + 1;
            $durum->save();

            return 'hata: '.$durum->son_hata;
        }

        foreach ($veriler as $varlikId => $veri) {
            $this->kaydet($varlikId, $veri, $ad, false);
        }

        $eksik = $varliklar->reject(fn (Varlik $v): bool => isset($veriler[$v->id]))->pluck('kod');
        if (count($veriler) === 0) {
            $durum->son_hata = 'Hiçbir varlığın fiyatı gelmedi.';
            $durum->son_hata_zamani = now();
            $durum->art_arda_hata = $durum->art_arda_hata + 1;
        } else {
            $durum->son_basari = now();
            $durum->art_arda_hata = 0;
            $durum->guncellenen = count($veriler);
            if ($eksik->isNotEmpty()) {
                $durum->son_hata = 'Fiyatı gelmeyen: '.$eksik->implode(', ');
                $durum->son_hata_zamani = now();
            }
        }
        $durum->save();

        return count($veriler);
    }

    /**
     * Son fiyatı ve bugünün kapanış satırını yazar.
     */
    public function kaydet(int $varlikId, FiyatVerisi $veri, string $kaynak, bool $elle): void
    {
        DB::transaction(function () use ($varlikId, $veri, $kaynak, $elle): void {
            Fiyat::updateOrCreate(['varlik_id' => $varlikId], [
                'fiyat' => $veri->fiyat,
                'alis' => $veri->alis,
                'satis' => $veri->satis,
                'onceki_kapanis' => $veri->oncekiKapanis,
                'degisim_yuzde' => $veri->degisimYuzde,
                'kaynak' => $kaynak,
                'elle' => $elle,
                'zaman' => $veri->zaman ?? now(),
            ]);
            FiyatGecmisi::updateOrCreate(
                ['varlik_id' => $varlikId, 'tarih' => ($veri->zaman ?? now())->copy()->setTimezone(config('app.timezone'))->toDateString()],
                ['kapanis' => $veri->fiyat],
            );
        });
    }

    /**
     * Geçmişi hiç çekilmemiş varlıkların geçmişini çeker (kaynak destekliyorsa).
     *
     * @return int geçmişi çekilen varlık sayısı
     */
    public function gecmisleriCek(): int
    {
        $varliklar = Varlik::where('aktif', true)->whereNull('gecmis_tarihi')
            ->whereIn('kaynak', array_keys($this->kaynaklar()))
            ->orderBy('id')->limit(self::GECMIS_PARTI)->get();

        foreach ($varliklar as $varlik) {
            $this->gecmisCek($varlik);
        }

        return $varliklar->count();
    }

    public function gecmisCek(Varlik $varlik): void
    {
        $kaynak = $this->kaynak($varlik->kaynak);
        $gecmis = [];
        if ($kaynak) {
            try {
                $gecmis = $kaynak->gecmis($varlik, now()->subDays(self::GECMIS_GUN)->startOfDay());
            } catch (Throwable) {
                return; // geçici hata: bir sonraki çalışmada yeniden denenir
            }
        }
        $bugun = now()->toDateString();
        $satirlar = collect($gecmis)
            ->reject(fn (float $kapanis, string $tarih): bool => $tarih >= $bugun) // bugünü güncel fiyat yazar
            ->map(fn (float $kapanis, string $tarih): array => ['varlik_id' => $varlik->id, 'tarih' => $tarih, 'kapanis' => $kapanis])
            ->values()->all();
        foreach (array_chunk($satirlar, 200) as $parca) {
            FiyatGecmisi::upsert($parca, ['varlik_id', 'tarih'], ['kapanis']);
        }
        // Geçmiş gelmese de bir daha denenmez (kaynak vermiyor); elle yeniden çekmek için gecmis_tarihi boşaltılır.
        $varlik->forceFill(['gecmis_tarihi' => $bugun])->save();
    }
}
