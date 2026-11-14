<?php

namespace App\Services\AIStatement;

use App\Models\User;
use App\Services\Ekstre\EkstreIceAktarici;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ekstre PDF'i yükleme (kart ve banka hesabı ekstresi ortak): uzantıya güvenilmez, içerikten MIME (finfo) ve `%PDF-`
 * imzası; ayarlanabilir boyut sınırı (`EKSTRE_MAX_MB`); SHA-256 özeti (aynı dosyanın tekrar yüklenmesini çağıran
 * tablo kendi denetler); özel diskte rastgele adla saklama (açık adres yok). API hatasında dosya silinmez; yalnızca
 * kaydı yazılamazsa çağıran siler. İçerik loglanmaz.
 */
class PdfUploadService
{
    /**
     * @return array{icerik: string, ozet: string, ad: string, boyut: int}
     *
     * @throws ValidationException
     */
    public function oku(UploadedFile $dosya): array
    {
        $sinir = (int) config('ekstre.max_mb') * 1024 * 1024;
        if ($dosya->getSize() > $sinir) {
            throw ValidationException::withMessages(['dosya' => 'PDF en çok '.config('ekstre.max_mb').' MB olabilir.']);
        }
        $icerik = (string) file_get_contents($dosya->getRealPath());
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($icerik);
        if (! str_contains(substr($icerik, 0, 1024), '%PDF-') || ! in_array($mime, ['application/pdf', 'application/x-pdf'], true)) {
            throw ValidationException::withMessages(['dosya' => 'Dosya PDF değil.']);
        }

        return [
            'icerik' => $icerik,
            'ozet' => hash('sha256', $icerik),
            'ad' => mb_substr($dosya->getClientOriginalName(), 0, 190),
            'boyut' => strlen($icerik),
        ];
    }

    /** Özel diske rastgele adla yazar; yolunu döner (`{klasor}/{hane}/{uuid}.pdf`) */
    public function sakla(User $user, string $icerik, string $klasor): string
    {
        $yol = $klasor.'/'.$user->hane_id.'/'.Str::uuid()->toString().'.pdf';
        Storage::disk(EkstreIceAktarici::DISK)->put($yol, $icerik);

        return $yol;
    }

    public function sil(?string $yol): void
    {
        if ($yol !== null) {
            Storage::disk(EkstreIceAktarici::DISK)->delete($yol);
        }
    }
}
