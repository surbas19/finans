<?php

namespace App\Services\Ekstre\Profil;

/**
 * Banka profili: bankanın ekstre yazımına özgü, bildirimsel kurallar. Mantık `EkstreAyristirici`'de; profil yalnızca
 * "neye bakılacağını" söyler. Metin normalize edilmiş (ay adları sayı, kod sayfası düzgün) ve "sade" (büyük harf,
 * işaretsiz; `App\Support\Metin::sade`) gelir. Varsayılanlar genel ekstre içindir; bankalar gerekeni ezer.
 */
abstract class EkstreProfili
{
    abstract public function ad(): string;

    abstract public function etiket(): string;

    /**
     * Sade metinde geçerse bu banka (ilk iki sayfada aranır).
     *
     * @return list<string>
     */
    public function parmakIzleri(): array
    {
        return [];
    }

    /**
     * Bankanın alan etiketleri (sade); genel etiketlerin önüne eklenir.
     *
     * @return array<string, list<string>>
     */
    public function alanEtiketleri(): array
    {
        return [];
    }

    /** Sade satır işlem tablosunun başlığı mı (varsa yalnızca başlıktan sonraki satırlar işlem sayılır) */
    public function tabloBasi(string $sade): bool
    {
        return false;
    }

    /** Sade satır tablonun sonu mu (toplam satırı, puan özeti…) */
    public function tabloSonu(string $sade): bool
    {
        return false;
    }

    /** Tablo başlığı tanımlı mı; yoksa bütün satırlar aday */
    public function tabloSinirliMi(): bool
    {
        return false;
    }

    /** Tablo içinde işlem sayılmayacak satır (kategori başlığı, ara başlık) */
    public function atla(string $sade): bool
    {
        return false;
    }

    /**
     * Ham satırın sağındaki, tutar olmayan sütunlar (puan, kalan taksit). Her desen uygulanır; yakalanan isimli
     * gruplar (`kalan`) işleme yazılır. Sıra: sağdan sola tekrar tekrar.
     *
     * @return list<string> regex
     */
    public function sondakiSutunlar(): array
    {
        return [];
    }

    /** Satırda birden çok tutar varsa hangisi asıl: `ilk` (soldaki) ya da `son` (sağdaki) */
    public function tutarKonumu(): string
    {
        return 'ilk';
    }

    /**
     * Önceki işleme ait devam satırı (taksit açıklaması gibi): işleme eklenecek alanlar ya da null.
     *
     * @return array<string, mixed>|null
     */
    public function devamSatiri(string $sade): ?array
    {
        return null;
    }

    /**
     * Bankaya özgü taksit yazımı: [no, sayı, toplam|null] ya da null (genel desenler ayrıca denenir).
     *
     * @return array{0: int, 1: int, 2: string|null}|null
     */
    public function taksit(string $sade): ?array
    {
        return null;
    }

    /** Açıklamadan atılacak kuyruk (taksit hesabı gibi); sade değil, ham açıklama */
    public function aciklamaTemizle(string $aciklama): string
    {
        return $aciklama;
    }

    /** Tablo "TOPLAM" satırındaki toplam (decimal) ya da null */
    public function toplamSatiri(string $sade): ?string
    {
        return null;
    }

    /**
     * Genel etiket aramasından sonra bankaya özgü türetme (ör. ekstre numarasındaki kesim tarihi). Dönen null olmayan
     * değerler genel sonucu ezer. Anahtarlar: kesim_tarihi, son_odeme_tarihi, donem_baslangic, donem_borcu,
     * asgari_odeme, onceki_borc, harcama_toplami, odeme_toplami, faiz_toplami.
     *
     * @param  array<string, string|null>  $alanlar
     * @param  list<string>  $sadeSatirlar
     * @return array<string, string|null>
     */
    public function turetilenAlanlar(array $alanlar, array $sadeSatirlar): array
    {
        return [];
    }
}
