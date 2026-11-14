<?php

namespace App\Models;

use App\Services\AIStatement\YapayZekaHatasi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Yapay zekâ analizi çalışması (içeriksiz: yalnızca durum, aşama, sayılar, süre, token, onay sürümü). Kart ekstresine
 * (`ekstre_id`) ya da banka hesabı ekstresine (`hesap_ekstresi_id`) bağlıdır. Yaşam döngüsü: EkstreAiIsleyici.
 */
class EkstreAiCalismasi extends Model
{
    /** Sürüyor sayılan durumlar */
    public const ETKIN = ['bekliyor', 'calisiyor'];

    protected $table = 'ekstre_ai_calismalari';

    protected $fillable = [
        'hane_id', 'ekstre_id', 'hesap_ekstresi_id', 'user_id', 'model', 'durum', 'asama', 'calistirici', 'hata_kodu', 'sayfa_sayisi',
        'kabul_satir', 'reddedilen_satir', 'sure_ms', 'openai_yanit_id', 'kilit', 'onay_surumu', 'onay_at', 'basladi_at',
        'son_kontrol_at', 'bitti_at', 'girdi_token', 'cikti_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['onay_at' => 'datetime', 'basladi_at' => 'datetime', 'son_kontrol_at' => 'datetime', 'bitti_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Ekstre, $this>
     */
    public function ekstre(): BelongsTo
    {
        return $this->belongsTo(Ekstre::class);
    }

    /**
     * @return BelongsTo<HesapEkstresi, $this>
     */
    public function hesapEkstresi(): BelongsTo
    {
        return $this->belongsTo(HesapEkstresi::class);
    }

    public function etkinMi(): bool
    {
        return in_array($this->durum, self::ETKIN, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function ozet(): array
    {
        return [
            'id' => $this->id,
            'durum' => $this->durum,
            'asama' => $this->asama,
            'etkin' => $this->etkinMi(),
            'hata_kodu' => $this->hata_kodu,
            'mesaj' => $this->hata_kodu !== null ? YapayZekaHatasi::mesaj($this->hata_kodu) : null,
            'kabul_satir' => $this->kabul_satir,
            'reddedilen_satir' => $this->reddedilen_satir,
            'model' => $this->model,
            'basladi_at' => ($this->basladi_at ?? $this->created_at)?->toIso8601String(),
            'bitti_at' => $this->bitti_at?->toIso8601String(),
            'sure_ms' => $this->sure_ms,
        ];
    }
}
