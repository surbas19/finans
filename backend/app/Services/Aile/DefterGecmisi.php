<?php

namespace App\Services\Aile;

use App\Models\DefterDegisikligi;
use App\Support\Donem;
use Throwable;

/**
 * Kapanmış aya ait kayıtta ekleme / düzeltme / silme olunca: önce o ayın defteri mühürlenir (değişiklikten ÖNCEKİ hâl),
 * sonra değişiklik `defter_degisiklikleri`'ne yazılır (kim, ne zaman, eski → yeni). Kayıtlar `DefterIzler` trait'inden
 * `defterBilgisi()` verir. Mühür ya da kayıt başarısız olursa kullanıcının işlemi engellenmez (hata raporlanır).
 *
 * Bilgi: ['hane_id', 'donem', 'tur', 'baslik', 'tarih', 'tutar', 'not'].
 */
class DefterGecmisi
{
    public static function acikMi(): bool
    {
        return (bool) config('defter.izleme');
    }

    /** @param  array<string, mixed>|null  $bilgi */
    private static function kapali(?array $bilgi): bool
    {
        return self::acikMi() && $bilgi !== null && ! empty($bilgi['hane_id']) && Donem::gecerli($bilgi['donem'] ?? null) && DefterKapanisi::kapaliMi($bilgi['donem']);
    }

    /** @param  array<string, mixed>|null  $bilgi */
    public static function onceMuhurla(?array $bilgi): void
    {
        if (! self::kapali($bilgi)) {
            return;
        }
        try {
            app(DefterKapanisi::class)->muhurla((int) $bilgi['hane_id'], $bilgi['donem']);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>|null  $bilgi
     * @param  array<string, mixed>|null  $eski
     */
    public static function kaydet(string $tip, ?array $bilgi, ?array $eski = null): void
    {
        if (! self::kapali($bilgi)) {
            return;
        }
        try {
            self::onceMuhurla($bilgi);
            $kim = auth()->user();
            DefterDegisikligi::create([
                'hane_id' => $bilgi['hane_id'],
                'donem' => $bilgi['donem'],
                'tip' => $tip,
                'tur' => $bilgi['tur'],
                'kayit_id' => $bilgi['kayit_id'] ?? 0,
                'baslik' => mb_substr((string) $bilgi['baslik'], 0, 255),
                'kayit_tarihi' => $bilgi['tarih'] ?? null,
                'eski_tutar' => $tip === 'eklendi' ? null : ($eski['tutar'] ?? $bilgi['tutar'] ?? null),
                'yeni_tutar' => $tip === 'silindi' ? null : ($bilgi['tutar'] ?? null),
                'not' => $bilgi['not'] ?? null,
                'yapan_id' => $kim?->id,
                'yapan_ad' => $kim ? trim($kim->ad.' '.$kim->soyad) : 'Sistem',
                'yapildi_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>|null  $eski
     * @param  array<string, mixed>|null  $yeni
     */
    public static function guncelle(?array $eski, ?array $yeni): void
    {
        if ($eski === null || $yeni === null || ! self::acikMi()) {
            return;
        }
        $degisti = ($eski['tutar'] ?? null) != ($yeni['tutar'] ?? null) || ($eski['tarih'] ?? null) != ($yeni['tarih'] ?? null)
            || ($eski['baslik'] ?? null) != ($yeni['baslik'] ?? null) || ($eski['donem'] ?? null) !== ($yeni['donem'] ?? null);
        if (! $degisti) {
            return;
        }
        // Eski ayın hâli değişiklikten önce mühürlenir; kayıt başka aya taşındıysa yeni ay için ayrıca "eklendi" yazılır
        self::kaydet('duzeltildi', $yeni, $eski);
        if (($eski['donem'] ?? null) !== ($yeni['donem'] ?? null)) {
            self::kaydet('eklendi', $yeni);
        }
    }
}
