<?php

namespace App\Fiyat;

use App\Models\Varlik;

/**
 * Hazır katalog (her ortamda; canlıda kurulumda `katalog:esitle`). Hisse ve fonları kullanıcı koduyla ekler.
 * Var olan kayıtların yalnızca kaynak bilgisi güncellenir; kullanıcının eklediği varlıklara dokunulmaz.
 */
class Katalog
{
    /**
     * @return list<array{tur: string, kod: string, ad: string, birim: string, para_birimi?: string, kaynak: string, kaynak_kodu: string, gecikmeli?: bool}>
     */
    public static function hazir(): array
    {
        $altin = fn (string $kod, string $ad, string $birim, string $kaynakKodu): array => ['tur' => 'altin', 'kod' => $kod, 'ad' => $ad, 'birim' => $birim, 'kaynak' => 'truncgil', 'kaynak_kodu' => $kaynakKodu];
        $doviz = fn (string $kod, string $ad): array => ['tur' => 'doviz', 'kod' => $kod, 'ad' => $ad, 'birim' => 'birim', 'kaynak' => 'truncgil', 'kaynak_kodu' => $kod];
        $kripto = fn (string $kod, string $ad): array => ['tur' => 'kripto', 'kod' => $kod, 'ad' => $ad, 'birim' => 'adet', 'kaynak' => 'binance', 'kaynak_kodu' => $kod.'TRY'];

        return [
            $altin('GRAM', 'Gram altın', 'gram', 'GRA'),
            $altin('HAS', 'Has altın', 'gram', 'HAS'),
            $altin('CEYREK', 'Çeyrek altın', 'adet', 'CEYREKALTIN'),
            $altin('YARIM', 'Yarım altın', 'adet', 'YARIMALTIN'),
            $altin('TAM', 'Tam altın', 'adet', 'TAMALTIN'),
            $altin('CUMHURIYET', 'Cumhuriyet altını', 'adet', 'CUMHURIYETALTINI'),
            $altin('ATA', 'Ata altın', 'adet', 'ATAALTIN'),
            $altin('AYAR22', '22 ayar bilezik', 'gram', 'YIA'),
            $altin('AYAR18', '18 ayar altın', 'gram', '18AYARALTIN'),
            $altin('AYAR14', '14 ayar altın', 'gram', '14AYARALTIN'),
            $altin('GUMUS', 'Gümüş', 'gram', 'GUMUS'),
            ['tur' => 'altin', 'kod' => 'ONS', 'ad' => 'Ons altın', 'birim' => 'ons', 'para_birimi' => 'USD', 'kaynak' => 'yahoo', 'kaynak_kodu' => 'GC=F', 'gecikmeli' => true],
            $doviz('USD', 'ABD doları'),
            $doviz('EUR', 'Euro'),
            $doviz('GBP', 'İngiliz sterlini'),
            $doviz('CHF', 'İsviçre frangı'),
            $doviz('SAR', 'Suudi Arabistan riyali'),
            $doviz('AED', 'BAE dirhemi'),
            $kripto('BTC', 'Bitcoin'),
            $kripto('ETH', 'Ethereum'),
            $kripto('USDT', 'Tether'),
        ];
    }

    public static function esitle(): int
    {
        foreach (self::hazir() as $sira => $kalem) {
            $varlik = Varlik::firstOrNew(['tur' => $kalem['tur'], 'kod' => $kalem['kod']]);
            $varlik->fill([
                'ad' => $varlik->exists ? $varlik->ad : $kalem['ad'],
                'birim' => $kalem['birim'],
                'para_birimi' => $kalem['para_birimi'] ?? 'TRY',
                'kaynak' => $kalem['kaynak'],
                'kaynak_kodu' => $kalem['kaynak_kodu'],
                'gecikmeli' => $kalem['gecikmeli'] ?? false,
                'sira' => $sira + 1,
            ]);
            if (! $varlik->exists) {
                $varlik->aktif = true;
            }
            $varlik->save();
        }

        return count(self::hazir());
    }
}
