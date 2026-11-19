<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\IslemTuru;
use App\Enums\VarlikTuru;
use App\Http\Controllers\Controller;
use App\Models\Hesap;
use App\Models\Islem;
use App\Models\Varlik;
use App\Services\Portfoy\PortfoyHesabi;
use App\Services\Portfoy\TemettuKaydi;
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
        // Hanedeki hesaplardaki bütün işlemler (kimin girdiğine bakılmaz)
        $islemler = Islem::whereIn('hesap_id', Hesap::erisilebilir($request->user())->select('id'))
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

    public function store(Request $request, TemettuKaydi $temettuKaydi): JsonResponse
    {
        $veri = $this->dogrula($request);
        $islem = new Islem([...$veri, 'user_id' => $request->user()->id]);
        // Temettü: hisse satırı + aynı hesaba nakit, eşleşik iki kayıt
        if ($islem->tur === IslemTuru::Temettu) {
            $temettuKaydi->kaydet($islem);

            return response()->json(['islem' => $islem->ozet(), 'message' => 'Temettü kaydedildi; tutar hesaba nakit olarak eklendi.'], 201);
        }
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
            'hesap_id' => ['required', 'integer', Rule::exists('hesaplar', 'id')->whereIn('user_id', $request->user()->haneKullaniciIdleri())],
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
        if ($islem->bag_turu === 'harcama') {
            throw ValidationException::withMessages(['islem' => 'Bu satış bir nakit harcamasından geldi; harcamayı Bütçe\'den düzenleyin.']);
        }
        if ($islem->aktarim_kodu !== null) {
            throw ValidationException::withMessages(['islem' => $islem->bag_turu === 'temettu'
                ? 'Temettü kaydı düzenlenemez; silip yeniden girin.'
                : 'Aktarım kaydı düzenlenemez; silip yeniden aktarın.']);
        }
        $veri = $this->dogrula($request);
        if ($veri['tur'] === IslemTuru::Temettu->value) {
            throw ValidationException::withMessages(['tur' => 'Temettüyü yeni kayıt olarak girin (tutar hesaba nakit olarak da eklenir).']);
        }
        $eski = $islem->replicate();
        $eski->id = $islem->id;
        $islem->fill($veri);
        $this->miktarYeterliMi($islem, $eski);
        $islem->save();

        return response()->json(['islem' => $islem->ozet(), 'message' => 'İşlem güncellendi.']);
    }

    /**
     * İşlemi genel toplama / zekâta dahil eder ya da çıkarır. Aktarım kaydıysa eşleşik bütün kayıtlar birlikte değişir.
     * Kapatma, dahil kalan işlemlerde bir satışı elde olandan fazla bırakıyorsa reddedilir.
     */
    public function dahil(Request $request, Islem $islem): JsonResponse
    {
        Gate::authorize('update', $islem);
        $veri = $request->validate(['dahil' => ['required', 'boolean']]);
        $dahil = (bool) $veri['dahil'];
        $grup = $islem->aktarim_kodu !== null ? Islem::where('aktarim_kodu', $islem->aktarim_kodu)->get() : collect([$islem]);
        foreach ($grup as $g) {
            Gate::authorize('update', $g);
        }
        $ids = $grup->pluck('id')->all();
        $ciftler = $grup->map(fn (Islem $g): array => [$g->hesap_id, $g->varlik_id])->unique(fn (array $c): string => implode('-', $c));
        foreach ($ciftler as [$hesapId, $varlikId]) {
            $dahilKalan = Islem::where('hesap_id', $hesapId)->where('varlik_id', $varlikId)->get()
                ->filter(fn (Islem $i): bool => in_array($i->id, $ids, true) ? $dahil : ($i->dahil ?? true));
            try {
                $this->portfoy->dogrula($dahilKalan);
            } catch (YetersizMiktar $e) {
                throw ValidationException::withMessages([
                    'dahil' => 'Bu işlem toplamdan çıkarılırsa '.self::gecersizKalan($e).' Önce o kaydı da toplamdan çıkarın.',
                ]);
            }
        }
        Islem::whereIn('id', $ids)->update(['dahil' => $dahil]);

        return response()->json([
            'dahil' => $dahil,
            'message' => $dahil ? 'İşlem genel toplama eklendi.' : 'İşlem genel toplamdan ve zekâttan çıkarıldı.',
        ]);
    }

    public function destroy(Islem $islem): JsonResponse
    {
        Gate::authorize('delete', $islem);
        if ($islem->bag_turu === 'harcama') {
            throw ValidationException::withMessages(['islem' => 'Bu satış bir nakit harcamasından geldi; harcamayı Bütçe\'den silin.']);
        }
        // Aktarımın bir kaydı silinirse eşleşik bütün kayıtlar (kaynak satışı + hedef alışı) birlikte silinir
        $silinecek = $islem->aktarim_kodu !== null ? Islem::where('aktarim_kodu', $islem->aktarim_kodu)->get() : collect([$islem]);
        foreach ($silinecek as $s) {
            Gate::authorize('delete', $s);
        }
        $ciftler = $silinecek->map(fn (Islem $s): array => [$s->hesap_id, $s->varlik_id])->unique(fn (array $c): string => implode('-', $c));
        foreach ($ciftler as [$hesapId, $varlikId]) {
            $kalan = Islem::where('hesap_id', $hesapId)->where('varlik_id', $varlikId)->whereNotIn('id', $silinecek->pluck('id')->all())->get();
            try {
                $this->portfoy->dogrula($kalan);
            } catch (YetersizMiktar $e) {
                throw ValidationException::withMessages([
                    'islem' => 'Bu işlem silinirse '.self::gecersizKalan($e).' Önce o kaydı düzeltin.',
                ]);
            }
        }
        DB::transaction(fn () => $silinecek->each->delete());

        $mesaj = match (true) {
            $silinecek->count() === 1 => 'İşlem silindi.',
            $islem->bag_turu === 'temettu' => 'Temettü ve hesaba eklenen nakit silindi.',
            default => 'Aktarım ve eşleşik kayıtlar silindi.',
        };

        return response()->json(['message' => $mesaj]);
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
        $hisseOlayi = IslemTuru::tryFrom((string) $request->input('tur'))?->hisseOlayi() ?? false;
        $bedelsiz = $request->input('tur') === IslemTuru::Bedelsiz->value;

        $veri = $request->validate([
            'hesap_id' => ['required', 'integer', Rule::exists('hesaplar', 'id')->whereIn('user_id', $request->user()->haneKullaniciIdleri())],
            'varlik_id' => ['required', 'integer', Rule::exists('varliklar', 'id')],
            'tur' => [
                'required', Rule::enum(IslemTuru::class),
                $bes ? Rule::in([IslemTuru::Deger->value]) : Rule::notIn([IslemTuru::Deger->value]),
            ],
            // Temettüde net TL tutarı, bedelsizde oran yüzdesi
            'miktar' => ['required', 'numeric', 'gt:0', $bedelsiz ? 'max:10000' : 'max:1000000000000'],
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
            ...($bedelsiz ? ['miktar.max' => 'Bedelsiz oranı en çok %10.000 olabilir.'] : []),
        ], [
            'miktar' => $bedelsiz ? 'bedelsiz oranı' : ($request->input('tur') === IslemTuru::Temettu->value ? 'net tutar' : 'miktar'),
            'komisyon' => $request->input('tur') === IslemTuru::Temettu->value ? 'stopaj' : 'komisyon',
            'birim_fiyat' => 'birim fiyat', 'kar_yuzde' => 'kâr yüzdesi', 'hesap_id' => 'hesap', 'varlik_id' => 'varlık',
        ]);
        if ($hisseOlayi && $varlikTuru !== VarlikTuru::Hisse) {
            throw ValidationException::withMessages(['tur' => 'Temettü ve bedelsiz yalnızca hisse senedinde girilir.']);
        }
        $karYuzde = isset($veri['kar_yuzde']) ? (float) $veri['kar_yuzde'] : null;
        unset($veri['kar_yuzde']);
        // Temettüde komisyon alanı stopajdır; bedelsizde ücret / fiyat yoktur
        $veri['komisyon'] = $sabit || $bedelsiz ? 0.0 : (float) ($veri['komisyon'] ?? 0);
        if ($hisseOlayi) {
            $veri['birim_fiyat'] = null;
        }
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
                $mesaj = match (true) {
                    $e->islem === $islem && $islem->tur->hisseOlayi() => 'Bu tarihte hesapta bu hisse yok.',
                    $e->islem === $islem => 'Bu tarihte hesapta yeterli miktar yok (elde olan: '.Bicim::sayi($e->eldeki).').',
                    default => 'Bu değişiklikle '.self::gecersizKalan($e),
                };
                throw ValidationException::withMessages([$islem->tur->hisseOlayi() ? 'tarih' : 'miktar' => $mesaj]);
            }
        }
    }

    /**
     * Denetimde geçersiz kalan kaydı anlatır: satış elde olandan fazla, temettü / bedelsiz elde hisse yokken.
     */
    private static function gecersizKalan(YetersizMiktar $e): string
    {
        $tarih = $e->islem->tarih->format('d.m.Y');

        return $e->islem->tur->hisseOlayi()
            ? $tarih.' tarihli '.mb_strtolower($e->islem->tur->etiket()).' kaydı elde hisse olmadan kalır.'
            : $tarih.' tarihli satış elde olandan fazla kalır.';
    }
}
