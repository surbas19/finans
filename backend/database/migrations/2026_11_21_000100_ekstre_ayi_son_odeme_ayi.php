<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Ekstrenin ayı artık son ödeme ayıdır (yoksa kesim ayı). Saklanan iki değer bu kurala göre yeniden yazılır:
     * - Onaylı ekstredeki taksit satırlarının rapor ayı (`rapor_donemi`).
     * - Hizmet dönemi ekstreden alınmış (`donem_kaynagi = kesim`) kartla ödenmiş faturaların dönemi. Aynı sağlayıcının
     *   yeni ayda başka faturası varsa o fatura değişmez (aynı dönem iki kez olmaz).
     * Tutar değişmez; Bütçe kart gideri okunurken hesaplandığından kendiliğinden yeni aya geçer.
     */
    public function up(): void
    {
        $ay = fn (object $e): ?string => substr((string) ($e->son_odeme_tarihi ?? $e->kesim_tarihi ?? ''), 0, 7) ?: null;
        $ekstreler = DB::table('ekstreler')->where('durum', 'onaylandi')
            ->get(['id', 'kesim_tarihi', 'son_odeme_tarihi'])->keyBy('id');

        foreach ($ekstreler as $ekstre) {
            if (($donem = $ay($ekstre)) === null) {
                continue;
            }
            DB::table('kart_islemleri')->where('ekstre_id', $ekstre->id)->where('durum', 'onayli')
                ->where('taksit_sayisi', '>', 1)->where(fn ($q) => $q->whereNull('rapor_donemi')->orWhere('rapor_donemi', '!=', $donem))
                ->update(['rapor_donemi' => $donem]);
        }

        $faturalar = DB::table('faturalar')->join('kart_islemleri', 'kart_islemleri.id', '=', 'faturalar.kart_islemi_id')
            ->where('faturalar.donem_kaynagi', 'kesim')->whereNotNull('kart_islemleri.ekstre_id')
            ->get(['faturalar.id', 'faturalar.saglayici_id', 'faturalar.hizmet_donemi', 'kart_islemleri.ekstre_id']);
        foreach ($faturalar as $fatura) {
            $ekstre = $ekstreler[$fatura->ekstre_id] ?? DB::table('ekstreler')->where('id', $fatura->ekstre_id)->first(['id', 'kesim_tarihi', 'son_odeme_tarihi']);
            $donem = $ekstre ? $ay($ekstre) : null;
            if ($donem === null || $donem === $fatura->hizmet_donemi) {
                continue;
            }
            $dolu = DB::table('faturalar')->where('saglayici_id', $fatura->saglayici_id)->where('hizmet_donemi', $donem)
                ->where('id', '!=', $fatura->id)->exists();
            if (! $dolu) {
                DB::table('faturalar')->where('id', $fatura->id)->update(['hizmet_donemi' => $donem]);
            }
        }
    }

    public function down(): void
    {
        // Geri alınmaz: eski kural (kesim ayı) tutarları değiştirmez; gerekirse kesim ayına göre yeniden yazılır
    }
};
