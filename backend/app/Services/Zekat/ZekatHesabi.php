<?php

namespace App\Services\Zekat;

use App\Enums\EkstreDurumu;
use App\Enums\FaturaDurumu;
use App\Models\Ekstre;
use App\Models\Fatura;
use App\Models\Fiyat;
use App\Models\Hesap;
use App\Models\User;
use App\Models\Varlik;
use App\Models\ZekatAyari;
use App\Models\ZekatBorcu;
use App\Services\Portfoy\PortfoyHesabi;
use App\Support\Kurus;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Zekât hesabı (önizleme, hiçbir şey yazmaz). Değerleme TEK kaynaktan gelir: `PortfoyHesabi::hesapla` pozisyonları.
 * Para kuruşla hesaplanır; zekât = net × oran_binde / 1000 (yarım kuruş yukarı).
 *
 * Fiyatı olmayan kalem sıfır sayılmaz: `eksikler`'e yazılır ve sonuç "eksik veri" işaretlenir. Nisab fiyatı yoksa
 * hesap hata verir (uydurulmaz). Hawl basitleştirilmiştir: nisab yalnızca hesaplama gününde (zekât günü) denetlenir.
 */
class ZekatHesabi
{
    public function __construct(private PortfoyHesabi $portfoy) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException nisab fiyatı bulunamazsa
     */
    public function hesapla(User $user, ?CarbonInterface $gun = null): array
    {
        $gun = ($gun ?? now())->copy()->startOfDay();
        $haneId = (int) $user->hane_id;
        $ayar = ZekatAyari::haneIcin($haneId);
        $nisab = $this->nisab($ayar);

        $portfoy = $this->portfoy->hesapla($user);
        $bayrak = Hesap::erisilebilir($user)->pluck('zekata_dahil', 'id');
        $hesapAdlari = collect($portfoy['hesaplar'])->pluck('ad', 'id');
        $zamanlar = Fiyat::whereIn('varlik_id', collect($portfoy['pozisyonlar'])->pluck('varlik.id')->unique()->all())->pluck('zaman', 'varlik_id');

        $kalemler = [];
        $eksikler = [];
        $notlar = [];
        $brut = 0;
        foreach ($portfoy['pozisyonlar'] as $p) {
            $tur = $p['varlik']['tur'];
            $kural = $ayar->turKurali($tur);
            $hesapAd = (string) ($hesapAdlari[$p['hesap_id']] ?? '');
            $neden = null;
            if (! ($p['dahil'] ?? true)) {
                $neden = 'Yatırım toplamına dahil değil';
            } elseif (! ($bayrak[$p['hesap_id']] ?? true)) {
                $neden = 'Hesap zekât hesabına dahil değil';
            } elseif (! $kural['dahil'] || $kural['yuzde'] <= 0) {
                $neden = 'Bu varlık türü zekât hesabına dahil değil';
            }
            $dahil = $neden === null;
            $kurus = $p['deger'] !== null ? $this->kurus((float) $p['deger']) : null;
            $giren = $dahil && $kurus !== null ? intdiv($kurus * $kural['yuzde'] + 50, 100) : 0;
            $brut += $giren;

            if ($dahil && $kurus === null) {
                $eksikler[] = [
                    'hesap_ad' => $hesapAd,
                    'varlik_kod' => $p['varlik']['kod'],
                    'varlik_ad' => $p['varlik']['ad'],
                    'neden' => 'Fiyat bulunamadı; değer sıfır sayılmadı, toplama girmedi.',
                ];
            }
            if ($dahil && $tur === 'bes' && ($p['maliyeti_bilinmeyen_miktar'] ?? 0) > 0) {
                $notlar[] = $hesapAd.': bireysel emeklilikte maliyet bilinmiyor; toplam tutar sayıldı.';
            }

            $kalemler[] = [
                'hesap_id' => $p['hesap_id'],
                'hesap_ad' => $hesapAd,
                'varlik_kod' => $p['varlik']['kod'],
                'varlik_ad' => $p['varlik']['ad'],
                'varlik_tur' => $tur,
                'miktar' => $p['miktar'],
                'birim_fiyat_tl' => $p['fiyat_tl'],
                'deger' => $kurus !== null ? Kurus::tl($kurus) : null,
                'dahil_edilen' => $dahil,
                'kapsam_yuzde' => $kural['yuzde'],
                'hesaba_giren' => Kurus::tl($giren),
                'dislanma_nedeni' => $neden,
                'fiyat_zamani' => isset($zamanlar[$p['varlik']['id']]) ? $zamanlar[$p['varlik']['id']]->toIso8601String() : null,
            ];
        }

        $borclar = $ayar->borc_dus ? $this->borclar($user, $ayar, $gun) : [];
        $borc = array_sum(array_map(fn (array $b): int => $this->kurus($b['tutar']), $borclar));
        $net = max(0, $brut - $borc);
        $nisabUstu = $nisab['kurus'] > 0 && $net >= $nisab['kurus'];
        $zekat = $nisabUstu ? intdiv($net * (int) $ayar->oran_binde + 500, 1000) : 0;

        return [
            'tarih' => $gun->toDateString(),
            'kalemler' => $kalemler,
            'brut' => Kurus::tl($brut),
            'borc_dusuldu' => (bool) $ayar->borc_dus,
            'borclar' => $borclar,
            'borc' => Kurus::tl($borc),
            'net' => Kurus::tl($net),
            'nisab' => ['olcut' => $nisab['olcut'], 'gram' => $nisab['gram'], 'birim_fiyat' => $nisab['birim_fiyat'], 'tl' => Kurus::tl($nisab['kurus']), 'fiyat_zamani' => $nisab['fiyat_zamani']],
            'nisab_ustu' => $nisabUstu,
            'oran_binde' => (int) $ayar->oran_binde,
            'zekat' => Kurus::tl($zekat),
            'eksikler' => $eksikler,
            'eksik_veri' => $eksikler !== [],
            'notlar' => array_values(array_unique($notlar)),
            'usd_kuru' => $portfoy['usd_kuru'],
            'ayar' => $ayar->ozet(),
            'uyari' => ZekatVarsayilanlari::UYARI,
        ];
    }

    /**
     * Nisabın TL karşılığı: gram × "GRAM" (altın) ya da "GUMUS" güncel fiyatı.
     *
     * @return array{olcut: string, gram: float, birim_fiyat: float, kurus: int, fiyat_zamani: ?string}
     */
    private function nisab(ZekatAyari $ayar): array
    {
        $olcut = $ayar->nisab_olcutu === 'gumus' ? 'gumus' : 'altin';
        $kod = ZekatVarsayilanlari::NISAB_VARLIKLARI[$olcut];
        $varlik = Varlik::where('tur', 'altin')->where('kod', $kod)->with('fiyat')->first();
        $fiyat = $varlik?->fiyat?->fiyat;
        if ($fiyat === null || $fiyat <= 0) {
            throw ValidationException::withMessages([
                'nisab' => ($olcut === 'gumus' ? 'Gümüş' : 'Gram altın').' fiyatı bulunamadı; nisab hesaplanamıyor. Fiyatı güncelleyin ya da elle girin.',
            ]);
        }
        $gram = $olcut === 'gumus' ? (float) $ayar->nisab_gumus_gram : (float) $ayar->nisab_altin_gram;

        return [
            'olcut' => $olcut,
            'gram' => $gram,
            'birim_fiyat' => $fiyat,
            'kurus' => $this->kurus($gram * $fiyat),
            'fiyat_zamani' => $varlik->fiyat->zaman?->toIso8601String(),
        ];
    }

    /**
     * Net değerden düşülecek borçlar (hane süzgeciyle): ödenmemiş onaylı ekstre dönem borcu, vadesi gelmiş bekleyen
     * fatura, elle girilen borç. Ödenmiş ekstre ve vadesi gelmemiş fatura düşmez.
     *
     * @return list<array{kaynak: string, ad: string, tutar: float}>
     */
    private function borclar(User $user, ZekatAyari $ayar, CarbonInterface $gun): array
    {
        $haneId = (int) $user->hane_id;
        $borclar = [];
        if ($ayar->borcKaynagiAcik('kart')) {
            Ekstre::where('hane_id', $haneId)->where('durum', EkstreDurumu::Onaylandi->value)->where('odendi', false)
                ->whereNotNull('donem_borcu')->where('donem_borcu', '>', 0)->with('kart:id,ad')->orderBy('kesim_tarihi')->get()
                ->each(function (Ekstre $e) use (&$borclar): void {
                    $borclar[] = ['kaynak' => 'kart', 'ad' => ($e->kart?->ad ?? 'Kart').' ekstresi'.($e->donemEtiketi() ? ' ('.$e->donemEtiketi().')' : ''), 'tutar' => Kurus::tl(Kurus::kurus($e->donem_borcu))];
                });
        }
        if ($ayar->borcKaynagiAcik('fatura')) {
            Fatura::where('hane_id', $haneId)->where('durum', FaturaDurumu::Bekliyor->value)
                ->whereNotNull('son_odeme_tarihi')->whereDate('son_odeme_tarihi', '<=', $gun->toDateString())
                ->with('saglayici:id,ad')->orderBy('son_odeme_tarihi')->get()
                ->each(function (Fatura $f) use (&$borclar): void {
                    $borclar[] = ['kaynak' => 'fatura', 'ad' => ($f->saglayici?->ad ?? 'Fatura').' faturası', 'tutar' => Kurus::tl(Kurus::kurus($f->tutar))];
                });
        }
        if ($ayar->borcKaynagiAcik('elle')) {
            ZekatBorcu::hanenin($user)->orderBy('id')->get()
                ->each(function (ZekatBorcu $b) use (&$borclar): void {
                    $borclar[] = ['kaynak' => 'elle', 'ad' => $b->ad, 'tutar' => Kurus::tl(Kurus::kurus($b->tutar))];
                });
        }

        return $borclar;
    }

    private function kurus(float $tl): int
    {
        return Kurus::kurus(number_format($tl, 2, '.', ''));
    }
}
