<?php

namespace App\Services\Ekstre;

use RuntimeException;

/**
 * PDF okunamadı: şifreli, metinsiz (taranmış) ya da bozuk. Mesaj kullanıcıya gösterilir; içerik içermez, loglanmaz.
 */
class EkstreOkunamadi extends RuntimeException
{
    public static function sifreli(): self
    {
        return new self('PDF şifreli. Bankanın şifreli ekstresini açıp şifresiz olarak kaydedin (ör. "PDF olarak yazdır") ve onu yükleyin.');
    }

    public static function metinsiz(): self
    {
        return new self('Bu PDF\'de okunabilir metin yok (taranmış görüntü olabilir). Sunucuda OCR aracı yok; yapay zekâ açıksa "Yapay zekâ ile oku" onayıyla yükleyin (PDF görüntüsünden okunur), değilse satırları kartın sayfasından elle girebilirsiniz.');
    }

    public static function bozuk(): self
    {
        return new self('PDF okunamadı (bozuk ya da desteklenmeyen biçim). Bankadan ekstreyi yeniden indirip deneyin.');
    }
}
