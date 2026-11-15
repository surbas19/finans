<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Enums\VarlikTuru;
use App\Http\Controllers\Controller;
use App\Models\Hesap;
use App\Models\ZekatAyari;
use App\Models\ZekatYili;
use App\Services\Zekat\ZekatTakvimi;
use App\Services\Zekat\ZekatVarsayilanlari;
use App\Services\Zekat\ZekatYilKaydi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Hanenin zekât ayarı (tek kayıt). Kurallar sabit değil, ayarlanır; varsayılanlar yalnızca başlangıç değeridir.
 * Hesabın zekâta dahil olup olmadığı da buradan (hanenin hesaplarında) değiştirilebilir.
 */
class ZekatAyarController extends Controller
{
    public function __construct(private ZekatYilKaydi $kayit, private ZekatTakvimi $takvim) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->yanit((int) $request->user()->hane_id, $request));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $haneId = (int) $user->hane_id;
        $veri = $request->validate([
            'nisab_olcutu' => ['sometimes', Rule::in(['altin', 'gumus'])],
            'nisab_altin_gram' => ['sometimes', 'numeric', 'between:1,100000', 'decimal:0,3'],
            'nisab_gumus_gram' => ['sometimes', 'numeric', 'between:1,1000000', 'decimal:0,3'],
            'oran_binde' => ['sometimes', 'integer', 'between:1,1000'],
            'tur_kurallari' => ['sometimes', 'array'],
            'tur_kurallari.*.dahil' => ['required_with:tur_kurallari', 'boolean'],
            'tur_kurallari.*.yuzde' => ['required_with:tur_kurallari', 'integer', 'between:0,100'],
            'borc_dus' => ['sometimes', 'boolean'],
            'borc_kaynaklari' => ['sometimes', 'array'],
            'borc_kaynaklari.kart' => ['sometimes', 'boolean'],
            'borc_kaynaklari.fatura' => ['sometimes', 'boolean'],
            'borc_kaynaklari.elle' => ['sometimes', 'boolean'],
            'yalniz_gunde_nisab' => ['sometimes', 'boolean'],
            'zekat_gunu_turu' => ['sometimes', Rule::in(['hicri', 'miladi'])],
            'hicri_ay' => ['sometimes', 'integer', 'between:1,12'],
            'hicri_gun' => ['sometimes', 'integer', 'between:1,30'],
            'miladi_ay' => ['sometimes', 'integer', 'between:1,12'],
            'miladi_gun' => ['sometimes', 'integer', 'between:1,31'],
            'sonraki_zekat_gunu' => ['nullable', 'date'],
            'hatirlatma_gun' => ['sometimes', 'integer', 'between:0,365'],
            'hesaplar' => ['sometimes', 'array', 'max:200'],
            'hesaplar.*.id' => ['required', 'integer', Rule::exists('hesaplar', 'id')->whereIn('user_id', $user->haneKullaniciIdleri())],
            'hesaplar.*.zekata_dahil' => ['required', 'boolean'],
        ], [], ['oran_binde' => 'oran', 'nisab_altin_gram' => 'altın nisabı', 'nisab_gumus_gram' => 'gümüş nisabı']);
        foreach (array_keys($veri['tur_kurallari'] ?? []) as $tur) {
            if (VarlikTuru::tryFrom((string) $tur) === null) {
                return response()->json(['message' => 'Varlık türü geçersiz.', 'errors' => ['tur_kurallari' => ['Varlık türü geçersiz.']]], 422);
            }
        }

        $ayar = ZekatAyari::haneIcin($haneId);
        $oncekiGun = [$ayar->zekat_gunu_turu, $ayar->hicri_ay, $ayar->hicri_gun, $ayar->miladi_ay, $ayar->miladi_gun];
        $ayar->hane_id = $haneId;
        $ayar->fill(collect($veri)->except(['tur_kurallari', 'borc_kaynaklari', 'hesaplar', 'sonraki_zekat_gunu'])->all());
        if (isset($veri['tur_kurallari'])) {
            $ayar->tur_kurallari = [...$ayar->ozet()['tur_kurallari'], ...$veri['tur_kurallari']];
        }
        if (isset($veri['borc_kaynaklari'])) {
            $ayar->borc_kaynaklari = [...$ayar->ozet()['borc_kaynaklari'], ...$veri['borc_kaynaklari']];
        }

        if (array_key_exists('sonraki_zekat_gunu', $veri) && $veri['sonraki_zekat_gunu'] !== null) {
            $ayar->sonraki_zekat_gunu = Carbon::parse($veri['sonraki_zekat_gunu'])->toDateString();
        } elseif ($ayar->sonraki_zekat_gunu === null || $oncekiGun !== [$ayar->zekat_gunu_turu, $ayar->hicri_ay, $ayar->hicri_gun, $ayar->miladi_ay, $ayar->miladi_gun]) {
            $ayar->sonraki_zekat_gunu = $this->siradakiGun($ayar, $haneId)->toDateString();
        }
        $ayar->save();

        foreach ($veri['hesaplar'] ?? [] as $h) {
            Hesap::whereKey($h['id'])->erisilebilir($user)->update(['zekata_dahil' => $h['zekata_dahil']]);
        }

        return response()->json([...$this->yanit($haneId, $request), 'message' => 'Zekât ayarları kaydedildi.']);
    }

    /** Ayara göre zekât günü; zaten kaydı olan (en son) günden sonrası */
    private function siradakiGun(ZekatAyari $ayar, int $haneId): Carbon
    {
        $gun = $this->takvim->ilk($ayar, now())['tarih'];
        $son = ZekatYili::where('hane_id', $haneId)->max('zekat_gunu');
        if ($son !== null && $gun->lte(Carbon::parse($son))) {
            $gun = $this->takvim->sonrasi($ayar, Carbon::parse($son))['tarih'];
        }

        return $gun;
    }

    /**
     * @return array<string, mixed>
     */
    private function yanit(int $haneId, Request $request): array
    {
        $ayar = ZekatAyari::haneIcin($haneId);

        return [
            'ayar' => $ayar->ozet(),
            'zekat_gunu' => $this->kayit->gunBilgisi($ayar),
            'turler' => collect(VarlikTuru::cases())->map(fn (VarlikTuru $t): array => ['deger' => $t->value, 'etiket' => $t->etiket()])->values(),
            'hesaplar' => Hesap::erisilebilir($request->user())->with('user:id,ad')->orderBy('sira')->orderBy('id')->get()
                ->map(fn (Hesap $h): array => ['id' => $h->id, 'ad' => $h->ad, 'sahip_ad' => $h->user?->ad, 'zekata_dahil' => $h->zekata_dahil ?? true, 'zekat_notu' => $h->zekat_notu])->values(),
            'varsayilanlar' => ['nisab_altin_gram' => ZekatVarsayilanlari::NISAB_ALTIN_GRAM, 'nisab_gumus_gram' => ZekatVarsayilanlari::NISAB_GUMUS_GRAM, 'oran_binde' => 25],
            'uyari' => ZekatVarsayilanlari::UYARI,
        ];
    }
}
