<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\VarlikTuru;
use App\Fiyat\FiyatGuncelleyici;
use App\Fiyat\FiyatVerisi;
use App\Fiyat\Kaynaklar\BinanceKaynagi;
use App\Fiyat\Kaynaklar\YahooKaynagi;
use App\Http\Controllers\Controller;
use App\Models\FiyatGecmisi;
use App\Models\IzlemeKaydi;
use App\Models\Varlik;
use App\Services\Portfoy\PortfoyHesabi;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Ortak varlık kataloğu. Herkes görür; hisse / kripto / fon kodu ekleyebilir; elle fiyatı yönetici girer.
 */
class VarlikController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $veri = $request->validate([
            'tur' => ['nullable', Rule::enum(VarlikTuru::class)],
            'q' => ['nullable', 'string', 'max:60'],
        ]);
        $izlenen = IzlemeKaydi::where('user_id', $request->user()->id)->pluck('varlik_id')->flip();
        $varliklar = Varlik::where('aktif', true)->with('fiyat')
            ->when($veri['tur'] ?? null, fn ($q, $tur) => $q->where('tur', $tur))
            ->when($veri['q'] ?? null, fn ($q, $metin) => $q->where(fn ($w) => $w->where('kod', 'like', '%'.$metin.'%')->orWhere('ad', 'like', '%'.$metin.'%')))
            ->orderBy('sira')->orderBy('kod')->limit(300)->get();

        return response()->json([
            'varliklar' => $varliklar->map(fn (Varlik $v): array => [...$v->ozet(), 'izleniyor' => $izlenen->has($v->id)]),
            'turler' => collect(VarlikTuru::cases())->map(fn (VarlikTuru $t): array => ['deger' => $t->value, 'etiket' => $t->etiket()]),
        ]);
    }

    /**
     * Varlık ve kullanıcının bu varlıktan hangi hesapta ne kadar tuttuğu.
     */
    public function show(Request $request, Varlik $varlik, PortfoyHesabi $portfoy): JsonResponse
    {
        $varlik->load('fiyat');
        $hesap = $portfoy->hesapla($request->user());

        return response()->json([
            'varlik' => [
                ...$varlik->ozet(),
                'onceki_kapanis' => $varlik->fiyat?->dunkuFiyat(),
                'tl_fiyat' => PortfoyHesabi::tlFiyat($varlik, $hesap['usd_kuru']),
                'izleniyor' => IzlemeKaydi::where('user_id', $request->user()->id)->where('varlik_id', $varlik->id)->exists(),
            ],
            'toplam' => collect($hesap['varliklar'])->firstWhere('varlik.id', $varlik->id),
            'pozisyonlar' => collect($hesap['pozisyonlar'])->where('varlik.id', $varlik->id)->values()
                ->map(fn (array $p): array => [...$p, 'hesap_ad' => collect($hesap['hesaplar'])->firstWhere('id', $p['hesap_id'])['ad'] ?? '']),
        ]);
    }

    public function gecmis(Request $request, Varlik $varlik): JsonResponse
    {
        $veri = $request->validate(['aralik' => ['nullable', Rule::in(['1H', '1A', '3A', '1Y', 'T'])]]);
        $gun = match ($veri['aralik'] ?? '3A') {
            '1H' => 7,
            '1A' => 31,
            '3A' => 92,
            '1Y' => 366,
            'T' => null,
        };
        $satirlar = FiyatGecmisi::where('varlik_id', $varlik->id)
            ->when($gun, fn ($q, $g) => $q->where('tarih', '>=', now()->subDays($g)->toDateString()))
            ->orderBy('tarih')->get(['tarih', 'kapanis']);

        return response()->json([
            'gecmis' => $satirlar->map(fn (FiyatGecmisi $s): array => ['tarih' => $s->tarih->toDateString(), 'kapanis' => $s->kapanis]),
        ]);
    }

    /**
     * Katalogda olmayan hisse / kripto / fon: hisse ve kripto kaynaktan doğrulanır, fonun fiyatı elle girilir.
     */
    public function store(Request $request, YahooKaynagi $yahoo, BinanceKaynagi $binance, FiyatGuncelleyici $guncelleyici): JsonResponse
    {
        $veri = $request->validate([
            'tur' => ['required', Rule::in([VarlikTuru::Hisse->value, VarlikTuru::Kripto->value, VarlikTuru::Fon->value])],
            'kod' => ['required', 'string', 'regex:/^[A-Za-z0-9]{2,12}$/'],
            'ad' => [Rule::requiredIf($request->input('tur') === VarlikTuru::Fon->value), 'nullable', 'string', 'max:120'],
        ], ['kod.regex' => 'Kod 2–12 harf ya da rakam olmalı (ör. THYAO, BTC, AFT).'], ['kod' => 'kod', 'ad' => 'fon adı']);
        $tur = VarlikTuru::from($veri['tur']);
        $kod = mb_strtoupper($veri['kod']);

        $varolan = Varlik::where('tur', $tur->value)->where('kod', $kod)->first();
        if ($varolan) {
            $varolan->aktif || $varolan->forceFill(['aktif' => true])->save();

            return response()->json(['varlik' => $varolan->load('fiyat')->ozet(), 'message' => 'Bu varlık katalogda zaten var.']);
        }

        [$alanlar, $fiyat] = match ($tur) {
            VarlikTuru::Hisse => $this->hisse($yahoo, $kod),
            VarlikTuru::Kripto => $this->kripto($binance, $kod),
            VarlikTuru::Fon => [['ad' => trim((string) $veri['ad']), 'birim' => 'pay', 'kaynak' => 'elle', 'kaynak_kodu' => null], null],
        };
        $varlik = $this->olustur($tur, $kod, $alanlar, $fiyat, $request->user()->id, $guncelleyici);

        return response()->json(['varlik' => $varlik->load('fiyat')->ozet(), 'message' => 'Varlık kataloğa eklendi.'], 201);
    }

    /**
     * Toplu eklemede katalogda olmayan kodlar: önce Borsa İstanbul, bulunamazsa kripto (TRY paritesi); bulunanlar
     * kataloğa eklenir. Katalogda zaten olan (hisse / kripto / fon) olduğu gibi döner. Fon kaynaktan bulunamaz.
     */
    public function toplu(Request $request, YahooKaynagi $yahoo, BinanceKaynagi $binance, FiyatGuncelleyici $guncelleyici): JsonResponse
    {
        $veri = $request->validate([
            'kodlar' => ['required', 'array', 'min:1', 'max:50'],
            'kodlar.*' => ['required', 'string', 'regex:/^[A-Za-z0-9]{2,12}$/'],
        ], [
            'kodlar.max' => 'Tek seferde en çok :max kod aranabilir.',
            'kodlar.*.regex' => 'Kod 2–12 harf ya da rakam olmalı (ör. THYAO, BTC).',
        ], ['kodlar' => 'kodlar', 'kodlar.*' => 'kod']);
        $kodlar = array_values(array_unique(array_map(mb_strtoupper(...), $veri['kodlar'])));
        $kullaniciId = $request->user()->id;

        $sira = [VarlikTuru::Hisse, VarlikTuru::Kripto, VarlikTuru::Fon];
        $varolan = Varlik::whereIn('kod', $kodlar)->whereIn('tur', array_map(fn (VarlikTuru $t): string => $t->value, $sira))->get()
            ->sortBy(fn (Varlik $v): int => (int) array_search($v->tur, $sira, true))
            ->unique('kod')->keyBy('kod');
        $bulunan = new EloquentCollection;
        foreach ($varolan as $varlik) {
            $varlik->aktif || $varlik->forceFill(['aktif' => true])->save();
            $bulunan->push($varlik);
        }

        $bulunamayan = [];
        $ulasilamayan = [];
        $kalan = array_values(array_diff($kodlar, $varolan->keys()->all()));
        if ($kalan !== []) {
            $borsa = $yahoo->dogrulaHepsi(array_map(fn (string $k): string => $k.'.IS', $kalan));
            $kriptoAranacak = [];
            foreach ($kalan as $kod) {
                $sonuc = $borsa['bulunan'][$kod.'.IS'] ?? null;
                if ($sonuc && $sonuc['para_birimi'] === 'TRY') {
                    $bulunan->push($this->olustur(VarlikTuru::Hisse, $kod, self::hisseAlanlari($kod, $sonuc['ad']), $sonuc['fiyat'], $kullaniciId, $guncelleyici));
                } else {
                    $kriptoAranacak[] = $kod;
                }
            }

            $kripto = $kriptoAranacak !== [] ? $binance->dogrulaHepsi(array_map(fn (string $k): string => $k.'TRY', $kriptoAranacak)) : null;
            foreach ($kriptoAranacak as $kod) {
                $sonuc = $kripto['bulunan'][$kod.'TRY'] ?? null;
                if ($sonuc) {
                    $bulunan->push($this->olustur(VarlikTuru::Kripto, $kod, self::kriptoAlanlari($kod), $sonuc['fiyat'], $kullaniciId, $guncelleyici));
                } elseif (in_array($kod.'.IS', $borsa['ulasilamayan'], true) || in_array($kod.'TRY', $kripto['ulasilamayan'] ?? [], true)) {
                    $ulasilamayan[] = $kod;
                } else {
                    $bulunamayan[] = $kod;
                }
            }
        }

        return response()->json([
            'varliklar' => $bulunan->load('fiyat')->map(fn (Varlik $v): array => $v->ozet())->values(),
            'bulunamayan' => $bulunamayan,
            'ulasilamayan' => $ulasilamayan,
        ]);
    }

    /**
     * Elle fiyat (fonlar ya da kaynak bozulduğunda). Yalnızca yönetici; kaynak sonra yeniden çekerse üzerine yazar.
     */
    public function elleFiyat(Request $request, Varlik $varlik, FiyatGuncelleyici $guncelleyici): JsonResponse
    {
        Gate::authorize('yonetici');
        if ($varlik->tur->sabitFiyatli()) {
            throw ValidationException::withMessages(['fiyat' => $varlik->tur->etiket().' için fiyat girilmez (birimi 1 TL).']);
        }
        $veri = $request->validate([
            'fiyat' => ['required', 'numeric', 'gt:0', 'max:1000000000000'],
        ], [], ['fiyat' => 'fiyat']);
        $fiyat = (float) $veri['fiyat'];
        $onceki = FiyatGecmisi::where('varlik_id', $varlik->id)->where('tarih', '<', now()->toDateString())
            ->orderByDesc('tarih')->value('kapanis');
        $onceki = $onceki !== null ? (float) $onceki : null;

        $guncelleyici->kaydet($varlik->id, new FiyatVerisi(
            fiyat: $fiyat,
            oncekiKapanis: $onceki,
            degisimYuzde: $onceki ? round(($fiyat - $onceki) / $onceki * 100, 4) : null,
            zaman: now(),
        ), 'elle', true);

        return response()->json(['varlik' => $varlik->load('fiyat')->ozet(), 'message' => 'Fiyat kaydedildi.']);
    }

    /**
     * @return array{0: array<string, mixed>, 1: FiyatVerisi}
     */
    private function hisse(YahooKaynagi $yahoo, string $kod): array
    {
        $sonuc = $this->kaynaktanDogrula(fn () => $yahoo->dogrula($kod.'.IS'));
        if (! $sonuc || $sonuc['para_birimi'] !== 'TRY') {
            throw ValidationException::withMessages(['kod' => "Borsa İstanbul'da {$kod} kodlu hisse bulunamadı."]);
        }

        return [self::hisseAlanlari($kod, $sonuc['ad']), $sonuc['fiyat']];
    }

    /**
     * @return array{0: array<string, mixed>, 1: FiyatVerisi}
     */
    private function kripto(BinanceKaynagi $binance, string $kod): array
    {
        $sonuc = $this->kaynaktanDogrula(fn () => $binance->dogrula($kod.'TRY'));
        if (! $sonuc) {
            throw ValidationException::withMessages(['kod' => "{$kod}/TRY paritesi bulunamadı."]);
        }

        return [self::kriptoAlanlari($kod), $sonuc['fiyat']];
    }

    /**
     * @return array<string, mixed>
     */
    private static function hisseAlanlari(string $kod, string $ad): array
    {
        return ['ad' => $ad, 'birim' => 'pay', 'kaynak' => 'yahoo', 'kaynak_kodu' => $kod.'.IS', 'gecikmeli' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private static function kriptoAlanlari(string $kod): array
    {
        return ['ad' => $kod, 'birim' => 'adet', 'kaynak' => 'binance', 'kaynak_kodu' => $kod.'TRY'];
    }

    /**
     * Kullanıcının eklediği varlık: katalogda sona dizilir; kaynaktan gelen ilk fiyat hemen yazılır.
     *
     * @param  array<string, mixed>  $alanlar
     */
    private function olustur(VarlikTuru $tur, string $kod, array $alanlar, ?FiyatVerisi $fiyat, int $kullaniciId, FiyatGuncelleyici $guncelleyici): Varlik
    {
        $varlik = Varlik::create([
            ...$alanlar,
            'tur' => $tur->value,
            'kod' => $kod,
            'aktif' => true,
            'sira' => 1000,
            'ekleyen_id' => $kullaniciId,
        ]);
        if ($fiyat) {
            $guncelleyici->kaydet($varlik->id, $fiyat, $varlik->kaynak, false);
        }

        return $varlik;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $istek
     * @return T
     */
    private function kaynaktanDogrula(callable $istek): mixed
    {
        try {
            return $istek();
        } catch (Throwable) {
            throw ValidationException::withMessages(['kod' => 'Fiyat kaynağına şu an ulaşılamıyor; biraz sonra yeniden deneyin.']);
        }
    }
}
