<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\IslemTuru;
use App\Enums\VarlikTuru;
use App\Http\Controllers\Controller;
use App\Models\Islem;
use App\Models\Varlik;
use App\Services\Portfoy\PortfoyHesabi;
use App\Services\Portfoy\YetersizMiktar;
use App\Support\Bicim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Alış / satış / elde olan. Tutarlar TL; satış o tarihte hesapta olandan fazla olamaz (ekleme, düzeltme ve
 * silmede bütün işlemler yeniden denetlenir).
 */
class IslemController extends Controller
{
    public function __construct(private PortfoyHesabi $portfoy) {}

    public function index(Request $request): JsonResponse
    {
        $veri = $request->validate([
            'hesap_id' => ['nullable', 'integer'],
            'varlik_id' => ['nullable', 'integer'],
        ]);
        $islemler = Islem::where('user_id', $request->user()->id)
            ->when($veri['hesap_id'] ?? null, fn ($q, $id) => $q->where('hesap_id', $id))
            ->when($veri['varlik_id'] ?? null, fn ($q, $id) => $q->where('varlik_id', $id))
            ->with(['varlik', 'hesap'])
            ->orderByDesc('tarih')->orderByDesc('id')->limit(1000)->get();

        return response()->json([
            'islemler' => $islemler->map(fn (Islem $i): array => [
                ...$i->ozet(),
                'varlik' => $i->varlik->ozet(),
                'hesap_ad' => $i->hesap->ad,
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $veri = $this->dogrula($request);
        $islem = new Islem([...$veri, 'user_id' => $request->user()->id]);
        $this->miktarYeterliMi($islem, null);
        $islem->save();

        return response()->json(['islem' => $islem->ozet(), 'message' => 'İşlem kaydedildi.'], 201);
    }

    /**
     * Elde olan varlıkları tek seferde ekler (arayüzde `;` ile ayrılmış satırlar). Hepsi ya eklenir ya hiçbiri.
     * Satış denetimi gerekmez: "elde olan" miktarı yalnızca artırır, sonraki bir satışı geçersiz kılamaz.
     */
    public function toplu(Request $request): JsonResponse
    {
        $veri = $request->validate([
            'hesap_id' => ['required', 'integer', Rule::exists('hesaplar', 'id')->where('user_id', $request->user()->id)],
            'satirlar' => ['required', 'array', 'min:1', 'max:200'],
            'satirlar.*.varlik_id' => ['required', 'integer', Rule::exists('varliklar', 'id')],
            'satirlar.*.miktar' => ['required', 'numeric', 'gt:0', 'max:1000000000000'],
            'satirlar.*.birim_fiyat' => ['nullable', 'numeric', 'gt:0', 'max:1000000000000'],
            'satirlar.*.tarih' => ['required', 'date', 'before_or_equal:today'],
            'satirlar.*.not' => ['nullable', 'string', 'max:255'],
        ], [
            'satirlar.max' => 'Tek seferde en çok :max satır eklenebilir.',
            'satirlar.*.tarih.before_or_equal' => 'İleri tarihli işlem girilemez.',
        ], [
            'hesap_id' => 'hesap',
            'satirlar' => 'satırlar',
            'satirlar.*.varlik_id' => 'varlık',
            'satirlar.*.miktar' => 'miktar',
            'satirlar.*.birim_fiyat' => 'birim fiyat',
            'satirlar.*.tarih' => 'tarih',
            'satirlar.*.not' => 'not',
        ]);

        $varliklar = Varlik::whereIn('id', array_column($veri['satirlar'], 'varlik_id'))->get()->keyBy('id');
        foreach ($veri['satirlar'] as $i => $satir) {
            if ($varliklar[(int) $satir['varlik_id']]->tur === VarlikTuru::Bes) {
                throw ValidationException::withMessages([
                    "satirlar.{$i}.varlik_id" => 'Bireysel emekliliği "İşlem ekle" ile girin (toplam tutar ve kâr yüzdesi).',
                ]);
            }
        }

        DB::transaction(function () use ($veri, $request, $varliklar): void {
            foreach ($veri['satirlar'] as $satir) {
                Islem::create([
                    'user_id' => $request->user()->id,
                    'hesap_id' => (int) $veri['hesap_id'],
                    'varlik_id' => (int) $satir['varlik_id'],
                    'tur' => IslemTuru::Mevcut,
                    'miktar' => $satir['miktar'],
                    // Nakitte birim 1 TL
                    'birim_fiyat' => $varliklar[(int) $satir['varlik_id']]->tur->sabitFiyatli() ? 1.0 : ($satir['birim_fiyat'] ?? null),
                    'komisyon' => 0,
                    'tarih' => $satir['tarih'],
                    'not' => $satir['not'] ?? null,
                ]);
            }
        });
        $sayi = count($veri['satirlar']);

        return response()->json(['eklenen' => $sayi, 'message' => "{$sayi} varlık eklendi."], 201);
    }

    public function update(Request $request, Islem $islem): JsonResponse
    {
        Gate::authorize('update', $islem);
        $eski = $islem->replicate();
        $eski->id = $islem->id;
        $islem->fill($this->dogrula($request));
        $this->miktarYeterliMi($islem, $eski);
        $islem->save();

        return response()->json(['islem' => $islem->ozet(), 'message' => 'İşlem güncellendi.']);
    }

    public function destroy(Islem $islem): JsonResponse
    {
        Gate::authorize('delete', $islem);
        $kalan = Islem::where('hesap_id', $islem->hesap_id)->where('varlik_id', $islem->varlik_id)
            ->whereKeyNot($islem->id)->get();
        try {
            $this->portfoy->dogrula($kalan);
        } catch (YetersizMiktar $e) {
            throw ValidationException::withMessages([
                'islem' => 'Bu işlem silinirse '.$e->islem->tarih->format('d.m.Y').' tarihli satış elde olandan fazla kalır. Önce o satışı düzeltin.',
            ]);
        }
        $islem->delete();

        return response()->json(['message' => 'İşlem silindi.']);
    }

    /**
     * Nakitte birim fiyat 1 TL, komisyon yok. Bireysel emeklilikte yalnızca "güncel tutar" girilir: miktar toplam
     * tutar, birim fiyat kâr yüzdesinden maliyet / tutar oranı (yüzde boşsa maliyet bilinmiyor).
     *
     * @return array<string, mixed>
     */
    private function dogrula(Request $request): array
    {
        $varlikId = $request->input('varlik_id');
        $varlikTuru = is_numeric($varlikId) ? Varlik::whereKey((int) $varlikId)->first()?->tur : null;
        $sabit = $varlikTuru?->sabitFiyatli() ?? false;
        $bes = $varlikTuru === VarlikTuru::Bes;

        $veri = $request->validate([
            'hesap_id' => ['required', 'integer', Rule::exists('hesaplar', 'id')->where('user_id', $request->user()->id)],
            'varlik_id' => ['required', 'integer', Rule::exists('varliklar', 'id')],
            'tur' => [
                'required', Rule::enum(IslemTuru::class),
                $bes ? Rule::in([IslemTuru::Deger->value]) : Rule::notIn([IslemTuru::Deger->value]),
            ],
            'miktar' => ['required', 'numeric', 'gt:0', 'max:1000000000000'],
            'birim_fiyat' => [Rule::requiredIf(! $sabit && $request->input('tur') === IslemTuru::Alis->value), 'nullable', 'numeric', 'gt:0', 'max:1000000000000'],
            'kar_yuzde' => ['nullable', 'numeric', 'gt:-100', 'max:100000'],
            'komisyon' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'tarih' => ['required', 'date', 'before_or_equal:today'],
            'not' => ['nullable', 'string', 'max:255'],
        ], [
            'birim_fiyat.required' => 'Alışta birim fiyat gerekir. Fiyatını bilmediğiniz varlık için "Elde olan"ı seçin.',
            'tur.in' => 'Bireysel emeklilikte toplam tutarı ve kâr yüzdesini girin.',
            'tur.not_in' => 'Güncel tutar yalnızca bireysel emeklilikte girilir.',
            'kar_yuzde.gt' => 'Kâr yüzdesi -100\'den büyük olmalı.',
            'tarih.before_or_equal' => 'İleri tarihli işlem girilemez.',
        ], ['miktar' => 'miktar', 'birim_fiyat' => 'birim fiyat', 'kar_yuzde' => 'kâr yüzdesi', 'hesap_id' => 'hesap', 'varlik_id' => 'varlık']);
        $karYuzde = isset($veri['kar_yuzde']) ? (float) $veri['kar_yuzde'] : null;
        unset($veri['kar_yuzde']);
        $veri['komisyon'] = $sabit ? 0.0 : (float) ($veri['komisyon'] ?? 0);
        $veri['hesap_id'] = (int) $veri['hesap_id'];
        $veri['varlik_id'] = (int) $veri['varlik_id'];
        if ($bes) {
            $veri['birim_fiyat'] = $karYuzde !== null ? round(1 / (1 + $karYuzde / 100), 8) : null;
        } elseif ($sabit) {
            $veri['birim_fiyat'] = 1.0;
        }

        return $veri;
    }

    /**
     * @throws ValidationException
     */
    private function miktarYeterliMi(Islem $islem, ?Islem $eski): void
    {
        $etkilenen = collect([[$islem->hesap_id, $islem->varlik_id]]);
        if ($eski && ($eski->hesap_id !== $islem->hesap_id || $eski->varlik_id !== $islem->varlik_id)) {
            $etkilenen->push([$eski->hesap_id, $eski->varlik_id]);
        }
        foreach ($etkilenen as [$hesapId, $varlikId]) {
            $liste = Islem::where('hesap_id', $hesapId)->where('varlik_id', $varlikId)
                ->when($islem->exists, fn ($q) => $q->whereKeyNot($islem->id))->get();
            if ($islem->hesap_id === $hesapId && $islem->varlik_id === $varlikId) {
                $liste->push($islem);
            }
            try {
                $this->portfoy->dogrula($liste);
            } catch (YetersizMiktar $e) {
                $mesaj = $e->islem === $islem
                    ? 'Bu tarihte hesapta yeterli miktar yok (elde olan: '.Bicim::sayi($e->eldeki).').'
                    : 'Bu değişiklikle '.$e->islem->tarih->format('d.m.Y').' tarihli satış elde olandan fazla kalır.';
                throw ValidationException::withMessages(['miktar' => $mesaj]);
            }
        }
    }
}
