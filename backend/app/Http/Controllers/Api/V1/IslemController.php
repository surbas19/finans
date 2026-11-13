<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\IslemTuru;
use App\Http\Controllers\Controller;
use App\Models\Islem;
use App\Services\Portfoy\PortfoyHesabi;
use App\Services\Portfoy\YetersizMiktar;
use App\Support\Bicim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * @return array<string, mixed>
     */
    private function dogrula(Request $request): array
    {
        $veri = $request->validate([
            'hesap_id' => ['required', 'integer', Rule::exists('hesaplar', 'id')->where('user_id', $request->user()->id)],
            'varlik_id' => ['required', 'integer', Rule::exists('varliklar', 'id')],
            'tur' => ['required', Rule::enum(IslemTuru::class)],
            'miktar' => ['required', 'numeric', 'gt:0', 'max:1000000000000'],
            'birim_fiyat' => [Rule::requiredIf($request->input('tur') === IslemTuru::Alis->value), 'nullable', 'numeric', 'gt:0', 'max:1000000000000'],
            'komisyon' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'tarih' => ['required', 'date', 'before_or_equal:today'],
            'not' => ['nullable', 'string', 'max:255'],
        ], [
            'birim_fiyat.required' => 'Alışta birim fiyat gerekir. Fiyatını bilmediğiniz varlık için "Elde olan"ı seçin.',
            'tarih.before_or_equal' => 'İleri tarihli işlem girilemez.',
        ], ['miktar' => 'miktar', 'birim_fiyat' => 'birim fiyat', 'hesap_id' => 'hesap', 'varlik_id' => 'varlık']);
        $veri['komisyon'] = (float) ($veri['komisyon'] ?? 0);
        $veri['hesap_id'] = (int) $veri['hesap_id'];
        $veri['varlik_id'] = (int) $veri['varlik_id'];

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
