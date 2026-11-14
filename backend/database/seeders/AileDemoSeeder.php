<?php

namespace Database\Seeders;

use App\Enums\EkstreDurumu;
use App\Enums\FaturaDurumu;
use App\Enums\KartIslemDurumu;
use App\Models\Banka;
use App\Models\Ekstre;
use App\Models\Fatura;
use App\Models\FaturaSaglayicisi;
use App\Models\FaturaTuru;
use App\Models\Gelir;
use App\Models\GelirKaynagi;
use App\Models\GelirTuru;
use App\Models\HaneUyesi;
use App\Models\Kart;
use App\Models\KartIslemi;
use App\Models\SaglayiciDeseni;
use App\Models\User;
use App\Services\Aile\FaturaEslestirici;
use App\Services\Aile\HaneYonetimi;
use App\Services\Aile\KartIslemKurallari;
use App\Services\Aile\KategoriBulucu;
use App\Support\Donem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Yalnızca yerel ortam: iki kullanıcı aynı hanede; maaş ve ek gelirler (bu ay biri beklenen), iki kart, onaylı bir
 * ekstre (PDF'siz), faturası bağlanmış satırlar, ödenmemiş bir fatura. Tarihler bugüne göre: her açılışta bu ay dolu.
 * Gerçek kişi / kart / banka bilgisi yok.
 */
class AileDemoSeeder extends Seeder
{
    public function run(HaneYonetimi $haneler, KartIslemKurallari $kurallar, FaturaEslestirici $faturalar, KategoriBulucu $kategoriler): void
    {
        $ben = User::where('email', 'ben@finans.test')->firstOrFail();
        $es = User::where('email', 'es@finans.test')->firstOrFail();
        $hane = $haneler->hazirla($ben);
        $haneler->tasi($es, $hane);
        $hane->update(['ad' => 'Demo ailesi']);
        $benUye = HaneUyesi::where('user_id', $ben->id)->firstOrFail();
        $esUye = HaneUyesi::where('user_id', $es->id)->firstOrFail();
        $benUye->update(['ad' => 'Ben']);
        $esUye->update(['ad' => 'Eşim']);
        HaneUyesi::create(['hane_id' => $hane->id, 'ad' => 'Deniz', 'sira' => 3, 'aktif' => true]);

        $buAy = Donem::buAy();
        $ay = fn (int $once): string => Donem::ekle($buAy, -$once);
        $gun = fn (string $donem, int $g): string => Donem::ilkGun($donem)->day(min($g, Donem::sonGun($donem)->day))->toDateString();

        // Gelirler: benim maaşım bu ay henüz yatmadı (beklenen), eşimin yattı. Türler hanenin varsayılanlarından
        $turler = GelirTuru::where('hane_id', $hane->id)->pluck('id', 'ad');
        $kaynak = fn (HaneUyesi $u, string $tur, string $ad, int $tutar, int $g): GelirKaynagi => GelirKaynagi::create([
            'hane_id' => $hane->id, 'uye_id' => $u->id, 'gelir_turu_id' => $turler[$tur], 'ad' => $ad, 'duzen' => 'duzenli', 'siklik' => 'aylik',
            'beklenen_tutar' => $tutar, 'para_birimi' => 'TRY', 'gun' => $g, 'baslangic' => Donem::ilkGun($ay(6))->toDateString(),
        ]);
        $benMaas = $kaynak($benUye, 'Maaş', 'ABC Teknoloji A.Ş.', 85000, 28);
        $esMaas = $kaynak($esUye, 'Maaş', 'XYZ Danışmanlık Ltd.', 72000, 1);
        $kira = $kaynak($esUye, 'Kira geliri', 'Kiradaki daire', 25000, 5);
        $gelir = fn (?GelirKaynagi $k, HaneUyesi $u, string $tur, string $duzen, int $tutar, string $donem, int $g, ?string $aciklama = null) => Gelir::create([
            'hane_id' => $hane->id, 'uye_id' => $u->id, 'gelir_kaynagi_id' => $k?->id, 'gelir_turu_id' => $turler[$tur], 'duzen' => $duzen,
            'aciklama' => $aciklama, 'tutar' => $tutar, 'para_birimi' => 'TRY', 'tl_tutar' => $tutar,
            'tarih' => min($gun($donem, $g), now()->toDateString()), 'donem' => $donem, 'olusturan_id' => $ben->id,
        ]);
        foreach ([3, 2, 1] as $once) {
            $gelir($benMaas, $benUye, 'Maaş', 'duzenli', 85000, $ay($once), 28);
            $gelir($esMaas, $esUye, 'Maaş', 'duzenli', 72000, $ay($once), 1);
            $gelir($kira, $esUye, 'Kira geliri', 'duzenli', 25000, $ay($once), 5);
        }
        $gelir($esMaas, $esUye, 'Maaş', 'duzenli', 72000, $buAy, 1);
        $gelir(null, $benUye, 'Serbest çalışma', 'duzensiz', 7500, $ay(1), 18, 'Çeviri işi');
        $gelir(null, $esUye, 'Prim / ikramiye', 'tek_seferlik', 15000, $ay(1), 20, 'Yıl ortası primi');

        // Bankalar, kartlar, fatura sağlayıcıları
        $garanti = Banka::create(['hane_id' => $hane->id, 'ad' => 'Garanti BBVA']);
        $yapiKredi = Banka::create(['hane_id' => $hane->id, 'ad' => 'Yapı Kredi']);
        $bonus = Kart::create(['hane_id' => $hane->id, 'banka_id' => $garanti->id, 'sahip_uye_id' => $benUye->id, 'ad' => 'Bonus', 'kesim_gunu' => 10, 'son_odeme_gunu' => 20, 'aktif' => true]);
        Kart::create(['hane_id' => $hane->id, 'banka_id' => $yapiKredi->id, 'sahip_uye_id' => $esUye->id, 'ad' => 'World', 'kesim_gunu' => 25, 'son_odeme_gunu' => 5, 'aktif' => true]);
        $tur = fn (string $ad): int => (int) FaturaTuru::whereNull('hane_id')->where('ad', $ad)->value('id');
        $saglayici = function (string $ad, string $turAd, string $desen, bool $otomatik) use ($hane, $tur): FaturaSaglayicisi {
            $s = FaturaSaglayicisi::create(['hane_id' => $hane->id, 'fatura_turu_id' => $tur($turAd), 'ad' => $ad, 'otomatik_kayit' => $otomatik, 'aktif' => true]);
            SaglayiciDeseni::create(['hane_id' => $hane->id, 'saglayici_id' => $s->id, 'desen' => $desen]);

            return $s;
        };
        $enerjisa = $saglayici('Enerjisa', 'Elektrik', 'ENERJISA', false);
        $iski = $saglayici('İSKİ', 'Su', 'ISKI', true);
        $igdas = $saglayici('İGDAŞ', 'Doğalgaz', 'IGDAS', true);

        // Son kesimli Bonus ekstresi (onaylı, PDF'siz): son ödemesi yaklaşıyor
        $kesim = now()->day >= 10 ? now()->copy()->day(10) : now()->copy()->subMonthNoOverflow()->day(10);
        $ekstre = Ekstre::create([
            'hane_id' => $hane->id, 'kart_id' => $bonus->id, 'durum' => EkstreDurumu::Taslak,
            'donem_baslangic' => $kesim->copy()->subMonthNoOverflow()->addDay()->toDateString(), 'kesim_tarihi' => $kesim->toDateString(),
            'son_odeme_tarihi' => $kesim->copy()->addDays(10)->toDateString(), 'donem_borcu' => '9481.05', 'asgari_odeme' => '1896.21',
            'alan_kaynaklari' => ['kesim_tarihi' => 'kullanici', 'son_odeme_tarihi' => 'kullanici', 'donem_borcu' => 'kullanici', 'asgari_odeme' => 'kullanici'],
            'okunamayan' => [], 'olusturan_id' => $ben->id,
        ]);
        $once = fn (int $g): string => $kesim->copy()->subDays($g)->toDateString();
        $satirlar = [
            [$once(27), 'MIGROS KADIKOY', '1840.75', 'harcama', null, null],
            [$once(24), 'OPET ATASEHIR', '1450.00', 'harcama', null, null],
            [$once(22), 'IGDAS OTOMATIK ODEME', '1215.40', 'harcama', null, null],
            [$once(20), 'ISKI SU FATURA', '312.40', 'harcama', null, null],
            [$once(18), 'ENERJISA BASKENT', '845.10', 'harcama', null, null],
            [$once(15), 'TRENDYOL YEMEK', '420.00', 'harcama', null, null],
            [$once(12), 'MIGROS KADIKOY IADE', '120.50', 'iade', null, null],
            [$once(10), 'HESAPTAN ODEME', '7500.00', 'odeme', null, null],
            [Carbon::parse($once(60))->toDateString(), 'TEKNOSA ISTANBUL', '1500.00', 'harcama', 2, 6],
            [$once(5), 'A101 MAGAZA', '612.90', 'harcama', null, null],
            [$once(2), 'KKDF', '35.00', 'ucret', null, null],
        ];
        foreach ($satirlar as $i => [$tarih, $aciklama, $tutar, $islemTuru, $taksitNo, $taksitSayisi]) {
            $islem = new KartIslemi([
                'hane_id' => $hane->id, 'kart_id' => $bonus->id, 'ekstre_id' => $ekstre->id, 'sira' => $i + 1,
                'durum' => KartIslemDurumu::Taslak, 'islem_tarihi' => $tarih, 'aciklama' => $aciklama, 'tutar' => $tutar,
                'tur' => $islemTuru, 'taksit_no' => $taksitNo, 'taksit_sayisi' => $taksitSayisi,
                'kategori_id' => $kategoriler->bul($hane->id, $aciklama), 'olusturan_id' => $ben->id,
            ]);
            $kurallar->onayla($islem, $ekstre, 1);
        }
        $ekstre->forceFill(['durum' => EkstreDurumu::Onaylandi, 'onayli_kesim_tarihi' => $ekstre->kesim_tarihi, 'onaylayan_id' => $ben->id, 'onaylandi_at' => now()])->save();

        // Fatura bağları: doğalgaz ve su geçen ayın hizmeti; elektriğin dönemi bilinmiyor
        $bagla = fn (string $aciklama, FaturaSaglayicisi $s, ?string $donem) => $faturalar->bagla(
            KartIslemi::where('ekstre_id', $ekstre->id)->where('aciklama', $aciklama)->firstOrFail(), $s, $donem, $donem ? 'kullanici' : null,
            ['guven' => 'kullanici', 'gerekce' => ['Demo verisi'], 'otomatik' => false], $ben->id,
        );
        $bagla('IGDAS OTOMATIK ODEME', $igdas, $ay(2));
        $bagla('ISKI SU FATURA', $iski, $ay(2));
        $bagla('ENERJISA BASKENT', $enerjisa, null);

        // Ödenmemiş elektrik faturası: son ödemesi 5 gün sonra (uyarıda görünür)
        Fatura::create([
            'hane_id' => $hane->id, 'saglayici_id' => $enerjisa->id, 'fatura_turu_id' => $enerjisa->fatura_turu_id,
            'hizmet_donemi' => $ay(1), 'donem_kaynagi' => 'kullanici', 'tutar' => '910.25',
            'son_odeme_tarihi' => now()->addDays(5)->toDateString(), 'durum' => FaturaDurumu::Bekliyor, 'kaynak' => 'elle', 'olusturan_id' => $ben->id,
        ]);
    }
}
