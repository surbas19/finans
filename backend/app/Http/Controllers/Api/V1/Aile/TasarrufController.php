<?php

namespace App\Http\Controllers\Api\V1\Aile;

use App\Enums\IslemTuru;
use App\Enums\VarlikTuru;
use App\Http\Controllers\Controller;
use App\Models\Islem;
use App\Models\Tasarruf;
use App\Models\Varlik;
use App\Services\Aile\ButceRaporu;
use App\Services\Aile\SilinenArsivi;
use App\Services\Portfoy\PortfoyHesabi;
use App\Services\Portfoy\YetersizMiktar;
use App\Support\Donem;
use App\Support\Kurus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Tasarruf yap": seçilen portföy hesabına (hanedeki kullanıcıların hesapları) alış işlemi yazar ve o ayın
 * tasarrufu olarak işaretler. Tasarruf gider sayılmaz (Gelir − Gider − Tasarruf = Kalan). Düzeltme bağlı alışı da günceller.
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
        ['veri' => $veri, 'verilen' => $verilen, 'birim' => $birim, 'komisyon' => $komisyon, 'tutar' => $tutar] = $this->girdi($request);

        if ($verilen) {
            $tasarruf = Tasarruf::create([
                'hane_id' => $request->user()->hane_id, 'uye_id' => $veri['uye_id'] ?? null, 'hesap_id' => null, 'islem_id' => null,
                'varlik_id' => (int) $veri['varlik_id'], 'miktar' => $veri['miktar'], 'gun_id' => $veri['gun_id'],
                'tarih' => $veri['tarih'], 'donem' => substr((string) Carbon::parse($veri['tarih'])->toDateString(), 0, 7),
                'tutar_tl' => Kurus::decimal($tutar), 'not' => trim((string) $veri['not']), 'olusturan_id' => $request->user()->id,
            ]);

            return $this->yanit($tasarruf);
        }

        $tasarruf = DB::transaction(function () use ($veri, $request, $birim, $komisyon, $tutar): Tasarruf {
            $islem = Islem::create([
                'user_id' => $request->user()->id, 'hesap_id' => (int) $veri['hesap_id'], 'varlik_id' => (int) $veri['varlik_id'],
                'tur' => IslemTuru::Alis, 'miktar' => $veri['miktar'], 'birim_fiyat' => $birim, 'komisyon' => $komisyon,
                'tarih' => $veri['tarih'], 'not' => $veri['not'] ?? null,
            ]);

            return Tasarruf::create([
                'hane_id' => $request->user()->hane_id, 'uye_id' => $veri['uye_id'] ?? null, 'hesap_id' => $islem->hesap_id,
                'islem_id' => $islem->id, 'varlik_id' => $islem->varlik_id, 'miktar' => $islem->miktar, 'gun_id' => $veri['gun_id'] ?? null, 'tarih' => $veri['tarih'], 'donem' => substr((string) Carbon::parse($veri['tarih'])->toDateString(), 0, 7),
                'tutar_tl' => Kurus::decimal($tutar), 'not' => $veri['not'] ?? null, 'olusturan_id' => $request->user()->id,
            ]);
        });

        return $this->yanit($tasarruf);
    }

    /**
     * Tasarrufu düzeltir: alanlar eklemedeki gibi doğrulanır, bağlı alış işlemi aynı anda güncellenir (hesap, varlık,
     * miktar, fiyat, tarih). Eski ve yeni hesap / varlık satırları portföy kuralından geçmeli (sonraki bir satış elde
     * olandan fazla kalmaz). "Verildi" (hesapsız) olursa alış silinir; hesaba eklenirse alış yazılır. Dönem tarihten.
     */
    public function update(Request $request, Tasarruf $tasarruf): JsonResponse
    {
        Gate::authorize('hane-kaydi', $tasarruf);
        ['veri' => $veri, 'verilen' => $verilen, 'birim' => $birim, 'komisyon' => $komisyon, 'tutar' => $tutar] = $this->girdi($request);

        DB::transaction(function () use ($tasarruf, $veri, $verilen, $birim, $komisyon, $tutar, $request): void {
            $islem = $tasarruf->islem;
            $ortak = [
                'uye_id' => $veri['uye_id'] ?? null, 'varlik_id' => (int) $veri['varlik_id'], 'miktar' => $veri['miktar'],
                'gun_id' => $veri['gun_id'] ?? null, 'tarih' => $veri['tarih'],
                'donem' => substr((string) Carbon::parse($veri['tarih'])->toDateString(), 0, 7), 'tutar_tl' => Kurus::decimal($tutar),
                'not' => filled($veri['not'] ?? null) ? trim((string) $veri['not']) : null,
            ];

            if ($verilen) {
                // Hesaba eklenmişti, artık başkasına verildi: alış kalkar (önce bağ çözülür; işlem silinince satır gitmesin)
                $tasarruf->forceFill([...$ortak, 'hesap_id' => null, 'islem_id' => null])->save();
                if ($islem !== null) {
                    $this->kalanlarGecerli($islem, $islem->hesap_id, $islem->varlik_id);
                    $islem->delete();
                }

                return;
            }

            $alanlar = [
                'hesap_id' => (int) $veri['hesap_id'], 'varlik_id' => (int) $veri['varlik_id'], 'miktar' => $veri['miktar'],
                'birim_fiyat' => $birim, 'komisyon' => $komisyon, 'tarih' => $veri['tarih'], 'not' => $veri['not'] ?? null,
            ];
            if ($islem === null) {
                $islem = Islem::create(['user_id' => $request->user()->id, 'tur' => IslemTuru::Alis, ...$alanlar]);
            } else {
                $eskiHesap = (int) $islem->hesap_id;
                $eskiVarlik = (int) $islem->varlik_id;
                $islem->fill($alanlar);
                // Yeni yerde (güncellenmiş alışla) ve hesap / varlık değiştiyse eski yerde (alışsız) kural tutmalı
                $this->gecerli(Islem::where('hesap_id', $islem->hesap_id)->where('varlik_id', $islem->varlik_id)->whereKeyNot($islem->id)->get()->push($islem));
                if ($eskiHesap !== (int) $islem->hesap_id || $eskiVarlik !== (int) $islem->varlik_id) {
                    $this->kalanlarGecerli($islem, $eskiHesap, $eskiVarlik);
                }
                $islem->save();
            }
            $tasarruf->forceFill([...$ortak, 'hesap_id' => $islem->hesap_id, 'islem_id' => $islem->id, 'miktar' => $islem->miktar])->save();
        });

        return $this->yanit($tasarruf->fresh(), 200, 'Tasarruf düzeltildi.');
    }

    /** Alış o hesap / varlıktan çıkınca kalan işlemler kurala uymalı */
    private function kalanlarGecerli(Islem $islem, int $hesapId, int $varlikId): void
    {
        $this->gecerli(Islem::where('hesap_id', $hesapId)->where('varlik_id', $varlikId)->whereKeyNot($islem->id)->get());
    }

    /**
     * @param  Collection<int, Islem>  $islemler
     */
    private function gecerli(Collection $islemler): void
    {
        try {
            $this->portfoy->dogrula($islemler);
        } catch (YetersizMiktar $e) {
            throw ValidationException::withMessages([
                'miktar' => 'Bu değişiklikle '.$e->islem->tarih->format('d.m.Y').' tarihli satış elde olandan fazla kalır. Önce o satışı düzeltin.',
            ]);
        }
    }

    /**
     * Ekleme ve düzeltmenin ortak doğrulaması: alanlar, BES yasağı, birim fiyat / komisyon ve TL tutarı (kuruş).
     *
     * @return array{veri: array<string, mixed>, verilen: bool, birim: float, komisyon: float, tutar: int}
     */
    private function girdi(Request $request): array
    {
        $haneId = $request->user()->hane_id;
        $varlikId = $request->input('varlik_id');
        $sabit = (is_numeric($varlikId) ? Varlik::whereKey((int) $varlikId)->first()?->tur : null)?->sabitFiyatli() ?? false;
        // Gün alımında "kime verildi" notu doluysa alım başkasına verilmiştir: hiçbir hesaba yazılmaz, yalnız tasarruf toplamına girer
        $verilen = filled($request->input('gun_id')) && filled(trim((string) $request->input('not')));
        $veri = $request->validate([
            'hesap_id' => [Rule::requiredIf(! $verilen), 'nullable', 'integer', Rule::exists('hesaplar', 'id')->whereIn('user_id', $request->user()->haneKullaniciIdleri())],
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

        return ['veri' => $veri, 'verilen' => $verilen, 'birim' => $birim, 'komisyon' => $komisyon, 'tutar' => $tutar];
    }

    private function yanit(Tasarruf $tasarruf, int $durum = 201, ?string $mesaj = null): JsonResponse
    {
        // Aynı gün için o ay ikinci alım engellenmez (iki alım gerçekten yapılmış olabilir) ama uyarılır
        $tekrar = $tasarruf->gun_id !== null && Tasarruf::where('gun_id', $tasarruf->gun_id)->where('donem', $tasarruf->donem)->whereKeyNot($tasarruf->id)->exists();

        return response()->json([
            'tasarruf' => $tasarruf->load(['hesap', 'islem.varlik', 'varlik', 'gun'])->ozet(),
            'message' => ($mesaj ?? Donem::etiket($tasarruf->donem).' tasarrufu kaydedildi.').($tasarruf->islem_id === null ? ' (Hiçbir hesaba eklenmedi.)' : ''),
            'uyari' => $tekrar ? 'Bu gün için '.Donem::etiket($tasarruf->donem).' ayında zaten bir alım kaydı vardı; ikincisi de eklendi.' : null,
        ], $durum);
    }

    public function destroy(Tasarruf $tasarruf): JsonResponse
    {
        Gate::authorize('hane-kaydi', $tasarruf);
        $islem = $tasarruf->islem;
        if ($islem === null) {
            // Başkasına verilen alım: portföyde işlem yok, yalnız tasarruf satırı silinir
            $tasarruf->delete();

            return response()->json(['message' => 'Tasarruf silindi.']);
        }
        // Alış silinince sonraki bir satış elde olandan fazla kalmamalı (portföy kuralı)
        $kalan = Islem::where('hesap_id', $islem->hesap_id)->where('varlik_id', $islem->varlik_id)->whereKeyNot($islem->id)->get();
        try {
            $this->portfoy->dogrula($kalan);
        } catch (YetersizMiktar $e) {
            throw ValidationException::withMessages([
                'tasarruf' => 'Bu tasarruf silinirse '.$e->islem->tarih->format('d.m.Y').' tarihli satış elde olandan fazla kalır. Önce o satışı düzeltin.',
            ]);
        }
        // Tasarruf arşive alışıyla birlikte yazılır; alış silmesi ikinci kayıt üretmez
        DB::transaction(function () use ($tasarruf, $islem): void {
            $tasarruf->delete();
            SilinenArsivi::sessiz(fn () => $islem->delete());
        });

        return response()->json(['message' => 'Tasarruf ve portföydeki alış silindi.']);
    }
}
