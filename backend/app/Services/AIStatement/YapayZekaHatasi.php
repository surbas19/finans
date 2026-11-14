<?php

namespace App\Services\AIStatement;

use RuntimeException;

/**
 * Yapay zekâ ile ekstre analizinin hatası. Mesaj kullanıcıya gösterilir; PDF / ekstre içeriği, istek ve yanıt gövdesi içermez,
 * loglanmaz. `kod` kayıt tablosuna yazılır.
 */
class YapayZekaHatasi extends RuntimeException
{
    public function __construct(public readonly string $kod, string $mesaj)
    {
        parent::__construct($mesaj);
    }

    public static function kapali(): self
    {
        return new self('kapali', 'Yapay zekâ ile analiz kapalı (sunucuda OPENAI_API_KEY tanımlı değil).');
    }

    public static function baglanti(): self
    {
        return new self('baglanti', 'Yapay zekâ servisine bağlanılamadı. Biraz sonra yeniden deneyin; ekstreniz olduğu gibi duruyor.');
    }

    public static function zamanAsimi(): self
    {
        return new self('zaman_asimi', 'Yapay zekâ servisi zamanında yanıt vermedi. Yeniden deneyin; ekstreniz olduğu gibi duruyor.');
    }

    public static function hizSiniri(): self
    {
        return new self('hiz_siniri', 'Yapay zekâ servisi şu an çok yoğun (istek sınırı). Birkaç dakika sonra yeniden deneyin.');
    }

    public static function http(int $durum): self
    {
        $mesaj = in_array($durum, [401, 403], true)
            ? 'Yapay zekâ servisi anahtarı kabul etmedi. Yönetici anahtarı kontrol etmeli.'
            : "Yapay zekâ servisi hata verdi (HTTP {$durum}).";

        return new self("http_{$durum}", $mesaj);
    }

    public static function reddedildi(): self
    {
        return new self('model_reddi', 'Yapay zekâ modeli bu içeriği işlemeyi reddetti. Satırları elle ekleyebilirsiniz.');
    }

    public static function yarimKaldi(string $neden = ''): self
    {
        return new self('yarim', match ($neden) {
            'max_output_tokens' => 'Yapay zekâ yanıtı yarım kaldı: ekstre çıktı sınırını aştı (OPENAI_MAX_OUTPUT_TOKENS). Satırları elle ekleyebilirsiniz.',
            'content_filter' => 'Yapay zekâ yanıtı içerik filtresine takıldı. Satırları elle ekleyebilirsiniz.',
            default => 'Yapay zekâ yanıtı yarım kaldı (ekstre çok uzun olabilir). Satırları elle ekleyebilirsiniz.',
        });
    }

    public static function iptal(): self
    {
        return new self('iptal', 'Yapay zekâ analizi iptal edildi.');
    }

    public static function modelHatasi(): self
    {
        return new self('model_hatasi', 'Yapay zekâ analizi tamamlanamadı (servis hatası). Yeniden deneyin; ekstreniz olduğu gibi duruyor.');
    }

    /** Arka plan modu bu ayarla kabul edilmedi: çalışma senkron yola (cron) geçer; kullanıcıya gösterilmez */
    public static function arkaPlanYok(): self
    {
        return new self('arka_plan_yok', 'Yapay zekâ arka plan modunu kabul etmedi; analiz sıraya alındı.');
    }

    public static function dosyaBuyuk(int $mb): self
    {
        return new self('dosya_buyuk', "PDF yapay zekâ için çok büyük (en çok {$mb} MB).");
    }

    public static function bozukJson(): self
    {
        return new self('bozuk_json', 'Yapay zekâ yanıtı okunamadı (bozuk JSON). Yeniden deneyin.');
    }

    public static function semaHatasi(): self
    {
        return new self('sema', 'Yapay zekâ yanıtı beklenen biçime uymadı; hiçbir satır alınmadı. Yeniden deneyin.');
    }

    public static function metinYok(): self
    {
        return new self('metin_yok', 'Ekstre dosyası bulunamadı ya da okunabilir metni yok.');
    }
}
