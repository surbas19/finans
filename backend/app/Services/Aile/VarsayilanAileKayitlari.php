<?php

namespace App\Services\Aile;

use App\Models\GelirTuru;
use App\Models\Hane;
use App\Models\HarcamaKategorisi;
use App\Models\KategoriKurali;
use App\Support\Metin;

/**
 * Yeni haneye yazılan başlangıç kayıtları: harcama kategorileri ve birkaç yaygın işyeri kuralı, gelir türleri.
 * Kullanıcı değiştirebilir / silebilir / ekleyebilir. Her biri hanede hiç yoksa yazılır (bir kez çalışır).
 */
class VarsayilanAileKayitlari
{
    public const GELIR_TURLERI = ['Maaş', 'Kira geliri', 'Serbest çalışma', 'Ek iş', 'Prim / ikramiye', 'Faiz / temettü', 'Emekli maaşı', 'Diğer'];

    /** ad => [ikon, renk, fatura mı, kurallar] */
    private const KATEGORILER = [
        'Market' => ['pi pi-shopping-cart', 'emerald', false, ['MIGROS', 'CARREFOURSA', 'A101', 'BIM', 'SOK MARKET', 'METRO GROSMARKET', 'FILE MARKET']],
        'Akaryakıt' => ['pi pi-car', 'amber', false, ['OPET', 'SHELL', 'PETROL OFISI', 'TOTALENERGIES', 'AYTEMIZ']],
        'Yeme-içme' => ['pi pi-shopping-bag', 'orange', false, ['YEMEKSEPETI', 'TRENDYOL YEMEK', 'STARBUCKS', 'RESTORAN', 'CAFE']],
        'Giyim' => ['pi pi-tag', 'pink', false, []],
        'Sağlık' => ['pi pi-heart', 'rose', false, ['ECZANE', 'HASTANE']],
        'Eğitim' => ['pi pi-book', 'indigo', false, []],
        'Ulaşım' => ['pi pi-map', 'sky', false, ['ISTANBULKART', 'HGS', 'OTOPARK']],
        'Eğlence' => ['pi pi-star', 'violet', false, []],
        'Faturalar' => ['pi pi-bolt', 'teal', true, []],
        'Diğer' => ['pi pi-ellipsis-h', 'slate', false, []],
    ];

    public function yaz(Hane $hane): void
    {
        if (! GelirTuru::where('hane_id', $hane->id)->exists()) {
            foreach (self::GELIR_TURLERI as $sira => $ad) {
                GelirTuru::create(['hane_id' => $hane->id, 'ad' => $ad, 'sira' => $sira + 1]);
            }
        }
        if (HarcamaKategorisi::where('hane_id', $hane->id)->exists()) {
            return;
        }
        $sira = 0;
        foreach (self::KATEGORILER as $ad => [$ikon, $renk, $fatura, $kurallar]) {
            $kategori = HarcamaKategorisi::create([
                'hane_id' => $hane->id, 'ad' => $ad, 'ikon' => $ikon, 'renk' => $renk, 'sira' => ++$sira, 'fatura' => $fatura,
            ]);
            foreach ($kurallar as $desen) {
                KategoriKurali::create(['hane_id' => $hane->id, 'kategori_id' => $kategori->id, 'desen' => Metin::sade($desen)]);
            }
        }
    }
}
