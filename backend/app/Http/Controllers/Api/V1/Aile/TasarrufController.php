<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Enums\IslemTuru;
use App\Enums\VarlikTuru;
use App\Http\Controllers\Controller;
use App\Models\Islem;
use App\Models\Tasarruf;
use App\Models\Varlik;
use App\Services\Aile\ButceRaporu;
use App\Services\Portfoy\PortfoyHesabi;
use App\Services\Portfoy\YetersizMiktar;
use App\Support\Donem;
use App\Support\Kurus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Tasarruf yap": seçilen portföy hesabına (hanedeki kullanıcıların hesapları) alış işlemi yazar ve o ayın
 * tasarrufu olarak işaretler. Tasarruf gider sayılmaz (Gelir − Gider − Tasarruf = Kalan). Düzenleme yok; sil + yeniden gir.
 */
class TasarrufController extends Controller
{
    public function __construct(private PortfoyHesabi $portfoy) {}

    public function index(Request $request, ButceRaporu $rapor): JsonResponse
    {
        $veri = $request->validate(['donem' => ['nullable', 'regex:'.Donem::DESEN]]);

        return response()->json(['tasarruf' => $rapor->tasarrufOzeti((int) $request->user()->hane_id, $veri['donem'] ?? Donem::buAy())]);
    }

    public function store(Request $request): JsonResponse
    {
        $haneId = $request->user()->hane_id;
        $varlikId = $request->input('varlik_id');
        $sabit = (is_numeric($varlikId) ? Varlik::whereKey((int) $varlikId)->first()?->tur : null)?->sabitFiyatli() ?? false;
        $veri = $request->validate([
            'hesap_id' => ['required', 'integer', Rule::exists('hesaplar', 'id')->whereIn('user_id', $request->user()->haneKullaniciIdleri())],
            'varlik_id' => ['required', 'integer', Rule::exists('varliklar', 'id')],
            'uye_id' => ['nullable', 'integer', Rule::exists('hane_uyeleri', 'id')->where('hane_id', $haneId)],
            'gun_id' => ['nullable', 'integer', Rule::exists('tasarruf_gunleri', 'id')->where('hane_id', $haneId)],
            'miktar' => ['required', 'numeric', 'gt:0', 'max:1000000000000'],
            'birim_fiyat' => [Rule::requiredIf(! $sabit), 'nullable', 'numeric', 'gt:0', 'max:1000000000000'],
            'komisyon' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'tarih' => ['required', 'date', 'before_or_equal:today'],
            'not' => ['nullable', 'string', 'max:255'],
        ], [
            'birim_fiyat.required' => 'Birim fiyat gerekir.',
            'tarih.before_or_equal' => 'İleri tarihli tasarruf girilemez.',
        ], ['hesap_id' => 'hesap', 'varlik_id' => 'varlık', 'miktar' => 'miktar', 'birim_fiyat' => 'birim fiyat', 'tarih' => 'tarih']);

        $varlik = Varlik::findOrFail((int) $veri['varlik_id']);
        if ($varlik->tur === VarlikTuru::Bes) {
            throw ValidationException::withMessages(['varlik_id' => 'Bireysel emeklilik tasarrufa bu ekrandan eklenmez; Portföy\'de girin.']);
        }
        $birim = $sabit ? 1.0 : (float) $veri['birim_fiyat'];
        $komisyon = $sabit ? 0.0 : (float) ($veri['komisyon'] ?? 0);
        $tutar = Kurus::kurus(number_format((float) $veri['miktar'] * $birim + $komisyon, 2, '.', ''));

        $tasarruf = DB::transaction(function () use ($veri, $request, $birim, $komisyon, $tutar): Tasarruf {
            $islem = Islem::create([
                'user_id' => $request->user()->id, 'hesap_id' => (int) $veri['hesap_id'], 'varlik_id' => (int) $veri['varlik_id'],
                'tur' => IslemTuru::Alis, 'miktar' => $veri['miktar'], 'birim_fiyat' => $birim, 'komisyon' => $komisyon,
                'tarih' => $veri['tarih'], 'not' => $veri['not'] ?? null,
            ]);

            return Tasarruf::create([
                'hane_id' => $request->user()->hane_id, 'uye_id' => $veri['uye_id'] ?? null, 'hesap_id' => $islem->hesap_id,
                'islem_id' => $islem->id, 'gun_id' => $veri['gun_id'] ?? null, 'tarih' => $veri['tarih'], 'donem' => substr((string) Carbon::parse($veri['tarih'])->toDateString(), 0, 7),
                'tutar_tl' => Kurus::decimal($tutar), 'not' => $veri['not'] ?? null, 'olusturan_id' => $request->user()->id,
            ]);
        });

        // Aynı gün için o ay ikinci alım engellenmez (iki alım gerçekten yapılmış olabilir) ama uyarılır
        $tekrar = $tasarruf->gun_id !== null && Tasarruf::where('gun_id', $tasarruf->gun_id)->where('donem', $tasarruf->donem)->whereKeyNot($tasarruf->id)->exists();

        return response()->json([
            'tasarruf' => $tasarruf->load(['hesap', 'islem.varlik', 'gun'])->ozet(),
            'message' => Donem::etiket($tasarruf->donem).' tasarrufu kaydedildi.',
            'uyari' => $tekrar ? 'Bu gün için '.Donem::etiket($tasarruf->donem).' ayında zaten bir alım kaydı vardı; ikincisi de eklendi.' : null,
        ], 201);
    }

    public function destroy(Tasarruf $tasarruf): JsonResponse
    {
        Gate::authorize('hane-kaydi', $tasarruf);
        $islem = $tasarruf->islem;
        // Alış silinince sonraki bir satış elde olandan fazla kalmamalı (portföy kuralı)
        $kalan = Islem::where('hesap_id', $islem->hesap_id)->where('varlik_id', $islem->varlik_id)->whereKeyNot($islem->id)->get();
        try {
            $this->portfoy->dogrula($kalan);
        } catch (YetersizMiktar $e) {
            throw ValidationException::withMessages([
                'tasarruf' => 'Bu tasarruf silinirse '.$e->islem->tarih->format('d.m.Y').' tarihli satış elde olandan fazla kalır. Önce o satışı düzeltin.',
            ]);
        }
        $islem->delete(); // tasarruf satırı da gider (cascade)

        return response()->json(['message' => 'Tasarruf ve portföydeki alış silindi.']);
    }
}
