<?php

namespace App\Services\Ekstre;

use App\Enums\EkstreDurumu;
use App\Enums\KartIslemDurumu;
use App\Enums\KartIslemTuru;
use App\Models\Ekstre;
use App\Models\FaturaSaglayicisi;
use App\Models\HarcamaKategorisi;
use App\Models\Kart;
use App\Models\KartIslemi;
use App\Models\User;
use App\Services\Aile\FaturaEslestirici;
use App\Services\Aile\KartIslemKurallari;
use App\Services\Aile\KategoriBulucu;
use App\Support\Donem;
use App\Support\Kurus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Ekstre içe aktarma (tek yer): yükleme → taslak → inceleme → onay; silme.
 *
 * - Aynı dosya (SHA-256) hanede bir kez yüklenir. Dosya özel diskte, açık adres yok.
 * - Ayrıştırma sonucu tek veritabanı işleminde taslak olarak yazılır; hata olursa dosya da silinir, yarım kayıt kalmaz.
 * - Taslak satırın aynısı (parmak izi) onaylı başka bir satırda varsa satır varsayılan olarak dışarıda bırakılır.
 * - Onay tek işlemde: bir satır bile yazılamazsa (tarih / tutar eksik, tekrar, aynı dönem) hiçbir şey değişmez.
 * - PDF içeriği, satırlar ve tutarlar loglanmaz.
 */
class EkstreIceAktarici
{
    public const DISK = 'local';

    public function __construct(
        private PdfMetinOkuyucu $okuyucu,
        private EkstreAyristirici $ayristirici,
        private KartIslemKurallari $kurallar,
        private KategoriBulucu $kategoriler,
        private FaturaEslestirici $faturalar,
        private EkstreMutabakati $mutabakat,
        private EkstreTeshisi $teshis,
    ) {}

    /** Hanede aynı dosya daha önce yüklendiyse o ekstre */
    public function ayniDosya(int $haneId, string $ozet): ?Ekstre
    {
        return Ekstre::where('hane_id', $haneId)->where('dosya_ozeti', $ozet)->with('kart')->first();
    }

    /**
     * @throws ValidationException PDF değilse / okunamazsa / aynı dosya varsa
     */
    public function yukle(User $user, Kart $kart, UploadedFile $dosya): Ekstre
    {
        $icerik = (string) file_get_contents($dosya->getRealPath());
        // Uzantı ve MIME'a ek: içerik gerçekten PDF mi (ilk 1 KB'ta imza)
        if (! str_contains(substr($icerik, 0, 1024), '%PDF-')) {
            throw ValidationException::withMessages(['dosya' => 'Dosya PDF değil.']);
        }
        $ozet = hash('sha256', $icerik);
        if ($onceki = $this->ayniDosya((int) $user->hane_id, $ozet)) {
            throw ValidationException::withMessages(['dosya' => 'Bu dosya '.$onceki->created_at->format('d.m.Y').' tarihinde yüklendi ('
                .$onceki->kart->ad.($onceki->donemEtiketi() ? ', '.$onceki->donemEtiketi().' ekstresi' : '').'). Aynı ekstre ikinci kez eklenmez.']);
        }

        try {
            $metin = $this->okuyucu->oku($icerik);
        } catch (EkstreOkunamadi $e) {
            throw ValidationException::withMessages(['dosya' => $e->getMessage()]);
        }
        $sonuc = $this->ayristirici->ayristir($metin['sayfalar']);
        $sonuc['teshis'] = $this->teshis->olustur($metin['sayfalar'], $sonuc);

        $yol = 'ekstreler/'.$user->hane_id.'/'.Str::uuid()->toString().'.pdf';
        Storage::disk(self::DISK)->put($yol, $icerik);
        try {
            return DB::transaction(fn (): Ekstre => $this->taslakYaz($user, $kart, $dosya, $yol, $ozet, $metin['sayfa_sayisi'], $sonuc));
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete($yol);
            throw $e;
        }
    }

    /**
     * @param  array{alanlar: array<string, string|null>, okunamayan: list<string>, satirlar: list<array<string, mixed>>}  $sonuc
     */
    private function taslakYaz(User $user, Kart $kart, UploadedFile $dosya, string $yol, string $ozet, int $sayfaSayisi, array $sonuc): Ekstre
    {
        $alanlar = $sonuc['alanlar'];
        $kaynaklar = collect($alanlar)->filter()->map(fn (): string => 'pdf')->all();
        $okunamayan = $sonuc['okunamayan'];
        // Asgari ödeme PDF'ten okunamadıysa: dönem borcu × kartın asgari oranı (kaynağı "hesaplandi")
        if (($alanlar['asgari_odeme'] ?? null) === null && ($alanlar['donem_borcu'] ?? null) !== null) {
            $alanlar['asgari_odeme'] = self::asgariHesapla($kart, $alanlar['donem_borcu']);
            $kaynaklar['asgari_odeme'] = 'hesaplandi';
            $okunamayan = array_values(array_diff($okunamayan, ['asgari_odeme']));
        }
        $ekstre = Ekstre::create([
            'hane_id' => $user->hane_id,
            'kart_id' => $kart->id,
            'durum' => EkstreDurumu::Taslak,
            ...$alanlar,
            'alan_kaynaklari' => $kaynaklar,
            'okunamayan' => $okunamayan,
            'banka_profili' => $sonuc['profil'] ?? null,
            'denetim' => [...$this->mutabakat->denetle($sonuc['alanlar'], $sonuc['ozet'] ?? [], $sonuc['satirlar']), ...($sonuc['teshis'] ? ['teshis' => $sonuc['teshis']] : [])],
            'kalan_taksit' => $this->kalanTaksit($sonuc['satirlar']),
            'dosya_yolu' => $yol,
            'dosya_adi' => mb_substr($dosya->getClientOriginalName(), 0, 190),
            'dosya_boyutu' => $dosya->getSize(),
            'sayfa_sayisi' => $sayfaSayisi,
            'dosya_ozeti' => $ozet,
            'olusturan_id' => $user->id,
        ]);

        $faturaKategorisi = HarcamaKategorisi::where('hane_id', $user->hane_id)->where('fatura', true)->value('id');
        foreach ($sonuc['satirlar'] as $sira => $satir) {
            $islem = new KartIslemi([
                'hane_id' => $user->hane_id,
                'kart_id' => $kart->id,
                'ekstre_id' => $ekstre->id,
                'sira' => $sira + 1,
                'durum' => KartIslemDurumu::Taslak,
                'islem_tarihi' => $satir['islem_tarihi'],
                'aciklama' => $satir['aciklama'],
                'tutar' => $satir['tutar'],
                'tur' => $satir['tur'],
                'taksit_no' => $satir['taksit_no'],
                'taksit_sayisi' => $satir['taksit_sayisi'],
                'taksit_toplam' => $satir['taksit_toplam'],
                'taksit_kalan' => $satir['taksit_kalan'] ?? null,
                'kategori_id' => $this->kategoriler->bul((int) $user->hane_id, $satir['aciklama']),
                'ham_metin' => mb_substr($satir['ham'], 0, 1000),
                'sayfa' => $satir['sayfa'],
                'okuma' => $satir['okuma'],
                'olusturan_id' => $user->id,
            ]);
            // Fatura adayı (dönem açıklamada yoksa ekstrenin kesim ayı); kategori kuralı yoksa sağlayıcısı belli fatura
            // Faturalar kategorisine
            $islem->setRelation('ekstre', $ekstre);
            $islem->fatura_onerisi = $this->faturalar->oner($islem);
            if ($islem->kategori_id === null && ($islem->fatura_onerisi['saglayici_id'] ?? null) !== null) {
                $islem->kategori_id = $faturaKategorisi;
            }
            $islem->save();
        }
        $this->tekrarlariIsaretle($ekstre, disaridaBirak: true);

        return $ekstre;
    }

    /**
     * Taslak satırların aynısı onaylı başka satırda varsa (ör. dönemleri çakışan iki ekstre, elle girilmiş satır)
     * `tekrar_islem_id` yazılır. Yüklemede (`$disaridaBirak`) satır dışarıda bırakılır; sonraki düzenlemelerde yalnızca
     * işaret güncellenir, kullanıcının geri aldığı satır onda kalır (onayda yine denetlenir).
     */
    public function tekrarlariIsaretle(Ekstre $ekstre, bool $disaridaBirak = false): void
    {
        foreach ($this->kacinciSiralari($ekstre) as [$islem, $kacinci]) {
            if ($islem->durum === KartIslemDurumu::Onayli) {
                continue;
            }
            $aynisi = $islem->islem_tarihi !== null && $islem->tutar !== null ? $this->kurallar->onayliAynisi($islem, $kacinci) : null;
            $islem->tekrar_islem_id = $aynisi?->id;
            if ($aynisi && $disaridaBirak) {
                $islem->durum = KartIslemDurumu::Atlandi;
            }
            if ($islem->isDirty()) {
                $islem->save();
            }
        }
    }

    /**
     * Ekstredeki her satır ve aynı satırlar arasında kaçıncısı olduğu (iki aynı kahve: 1 ve 2).
     *
     * @return list<array{0: KartIslemi, 1: int}>
     */
    private function kacinciSiralari(Ekstre $ekstre): array
    {
        $sayac = [];
        $sonuc = [];
        foreach ($ekstre->islemler()->get() as $islem) {
            $taban = $this->kurallar->taban($islem);
            $sayac[$taban] = ($sayac[$taban] ?? 0) + 1;
            $sonuc[] = [$islem, $sayac[$taban]];
        }

        return $sonuc;
    }

    /**
     * Satırlardaki "kalan taksit tutarı"nın toplamı (bankalar yazıyorsa); hiçbiri yazmıyorsa null.
     *
     * @param  list<array<string, mixed>>  $satirlar
     */
    private function kalanTaksit(array $satirlar): ?string
    {
        $tutarlar = array_filter(array_column($satirlar, 'taksit_kalan'), fn ($t): bool => $t !== null);

        return $tutarlar === [] ? null : Kurus::decimal(array_sum(array_map(fn ($t): int => Kurus::kurus($t), $tutarlar)));
    }

    /** Dönem borcu × kartın asgari oranı (%, varsayılan 40), kuruşa yarıdan yukarı yuvarlanır */
    public static function asgariHesapla(Kart $kart, string $donemBorcu): string
    {
        $oran = (int) ($kart->asgari_oran ?? 40);

        return Kurus::decimal(intdiv(Kurus::kurus($donemBorcu) * $oran + 50, 100));
    }

    /**
     * Dönem bilgilerini kullanıcı düzeltir; kaynağı "kullanıcı" olur, okunamayan listesinden çıkar.
     *
     * @param  array<string, mixed>  $veri
     */
    public function alanlariGuncelle(Ekstre $ekstre, array $veri): void
    {
        $kaynaklar = $ekstre->alan_kaynaklari ?? [];
        $okunamayan = $ekstre->okunamayan ?? [];
        foreach (['donem_baslangic', 'kesim_tarihi', 'son_odeme_tarihi', 'donem_borcu', 'asgari_odeme'] as $alan) {
            if (! array_key_exists($alan, $veri)) {
                continue;
            }
            $deger = $veri[$alan];
            if (in_array($alan, ['donem_borcu', 'asgari_odeme'], true) && $deger !== null) {
                $deger = Kurus::decimal(Kurus::kurus($deger));
            }
            $eski = $ekstre->getAttribute($alan);
            $eskiMetin = $eski instanceof \DateTimeInterface ? $eski->format('Y-m-d') : $eski;
            if ((string) $eskiMetin !== (string) $deger) {
                $ekstre->setAttribute($alan, $deger);
                $kaynaklar[$alan] = 'kullanici';
            }
            if ($deger !== null) {
                $okunamayan = array_values(array_diff($okunamayan, [$alan]));
            }
        }
        // Hesaplanan asgari ödeme dönem borcuyla birlikte yenilenir; kullanıcının elle girdiğine dokunulmaz
        $asgariGirildi = array_key_exists('asgari_odeme', $veri) && $veri['asgari_odeme'] !== null;
        if (! $asgariGirildi && $ekstre->donem_borcu !== null
            && (($kaynaklar['asgari_odeme'] ?? null) === 'hesaplandi' || $ekstre->asgari_odeme === null)) {
            $ekstre->asgari_odeme = self::asgariHesapla($ekstre->kart, (string) $ekstre->donem_borcu);
            $kaynaklar['asgari_odeme'] = 'hesaplandi';
            $okunamayan = array_values(array_diff($okunamayan, ['asgari_odeme']));
        }
        $ekstre->alan_kaynaklari = $kaynaklar;
        $ekstre->okunamayan = $okunamayan;
        if (array_key_exists('odendi', $veri)) {
            $ekstre->odendi = (bool) $veri['odendi'];
        }
        $ekstre->save();
    }

    /**
     * Taslak satırı düzeltir ya da dışarıda bırakır / geri alır. Onaylı ekstrenin satırı burada değişmez.
     *
     * @param  array<string, mixed>  $veri
     */
    public function satirGuncelle(KartIslemi $islem, array $veri): void
    {
        if (isset($veri['dahil'])) {
            $islem->durum = $veri['dahil'] ? KartIslemDurumu::Taslak : KartIslemDurumu::Atlandi;
            unset($veri['dahil']);
        }
        if (array_key_exists('fatura', $veri)) {
            $islem->fatura_onerisi = $this->faturaSecimi($islem, $veri['fatura']);
            unset($veri['fatura']);
        }
        foreach (['tutar', 'taksit_toplam'] as $alan) {
            if (array_key_exists($alan, $veri) && $veri[$alan] !== null) {
                $veri[$alan] = Kurus::decimal(Kurus::kurus($veri[$alan]));
            }
        }
        $islem->fill($veri);
        // Kullanıcının düzelttiği alan artık "okunamadı" değildir
        $okuma = $islem->okuma ?? ['alanlar' => [], 'uyarilar' => [], 'tutar_adaylari' => []];
        $okuma['alanlar'] = array_values(array_filter($okuma['alanlar'] ?? [], fn (string $a): bool => $islem->getAttribute($a) === null));
        $islem->okuma = $okuma;
        $islem->save();
        if ($islem->ekstre) {
            $this->tekrarlariIsaretle($islem->ekstre);
        }
    }

    /**
     * Kullanıcının fatura kararı: işaretle / kaldır, sağlayıcı seç, hizmet dönemini seç ya da bilinmiyor bırak. Önerinin
     * gerekçesi korunur; kullanıcının seçtiği dönemin kaynağı "kullanıcı" olur.
     *
     * @param  array{secildi?: bool, saglayici_id?: int|null, hizmet_donemi?: string|null}|null  $secim
     * @return array<string, mixed>|null
     */
    private function faturaSecimi(KartIslemi $islem, ?array $secim): ?array
    {
        $oneri = $islem->fatura_onerisi ?? [
            'guven' => 'kullanici', 'puan' => 0, 'saglayici_id' => null, 'fatura_turu_id' => null, 'adaylar' => [],
            'gerekce' => ['Faturayı siz eşleştirdiniz.'], 'hizmet_donemi' => null, 'donem_kaynagi' => null, 'otomatik' => false, 'secildi' => false,
        ];
        if ($secim === null) {
            return [...$oneri, 'secildi' => false];
        }
        if (array_key_exists('saglayici_id', $secim) && $secim['saglayici_id'] !== $oneri['saglayici_id']) {
            $saglayici = $secim['saglayici_id'] !== null
                ? FaturaSaglayicisi::where('hane_id', $islem->hane_id)->findOrFail($secim['saglayici_id'])
                : null;
            $oneri['saglayici_id'] = $saglayici?->id;
            $oneri['fatura_turu_id'] = $saglayici?->fatura_turu_id;
        }
        if (array_key_exists('hizmet_donemi', $secim) && $secim['hizmet_donemi'] !== $oneri['hizmet_donemi']) {
            $oneri['hizmet_donemi'] = $secim['hizmet_donemi'];
            $oneri['donem_kaynagi'] = $secim['hizmet_donemi'] !== null ? 'kullanici' : null;
        }
        if (array_key_exists('secildi', $secim)) {
            $oneri['secildi'] = (bool) $secim['secildi'];
        }
        if ($oneri['secildi'] && $oneri['saglayici_id'] === null) {
            throw ValidationException::withMessages(['fatura' => 'Faturayı bağlamak için sağlayıcıyı seçin.']);
        }

        return $oneri;
    }

    /**
     * Onay: dahil satırlar onaylı (rapor ayı + parmak izi), diğerleri dışarıda; işaretli fatura adayları faturaya
     * bağlanır. Hepsi ya da hiçbiri.
     *
     * @return array{eklenen: int, atlanan: int, tekrar: int, fatura: int}
     *
     * @throws ValidationException
     */
    public function onayla(Ekstre $ekstre, User $user): array
    {
        if ($ekstre->durum === EkstreDurumu::Onaylandi) {
            throw ValidationException::withMessages(['ekstre' => 'Bu ekstre zaten onaylandı.']);
        }
        if ($ekstre->kesim_tarihi === null) {
            throw ValidationException::withMessages(['kesim_tarihi' => 'Kesim tarihi okunamadı; onaylamadan önce girin (taksitlerin ayı buna göre belirlenir).']);
        }
        $ayniDonem = Ekstre::where('kart_id', $ekstre->kart_id)->where('onayli_kesim_tarihi', $ekstre->kesim_tarihi->toDateString())->first();
        if ($ayniDonem) {
            throw ValidationException::withMessages(['kesim_tarihi' => 'Bu kartın '.$ekstre->kesim_tarihi->format('d.m.Y').' kesimli ekstresi zaten onaylı ('
                .$ayniDonem->created_at->format('d.m.Y').' yüklendi). Aynı dönem ikinci kez eklenmez.']);
        }

        return DB::transaction(function () use ($ekstre, $user): array {
            $eklenen = 0;
            $atlanan = 0;
            $tekrar = 0;
            $fatura = 0;
            foreach ($this->kacinciSiralari($ekstre) as [$islem, $kacinci]) {
                if ($islem->durum === KartIslemDurumu::Atlandi) {
                    $atlanan++;
                    $tekrar += $islem->tekrar_islem_id !== null ? 1 : 0;

                    continue;
                }
                if ($islem->tur === KartIslemTuru::Iade && $islem->iade_islem_id === null) {
                    $islem->iade_islem_id = $this->kurallar->iadeAsliniBul($islem)?->id;
                }
                $this->kurallar->onayla($islem, $ekstre, $kacinci);
                $eklenen++;

                $oneri = $islem->fatura_onerisi;
                // Dönem yüklemede belirsizdi (kesim tarihi sonradan girildi): ekstrenin kesim ayı
                if (($oneri['secildi'] ?? false) && ($oneri['hizmet_donemi'] ?? null) === null) {
                    $oneri['hizmet_donemi'] = Donem::tarihten($ekstre->kesim_tarihi);
                    $oneri['donem_kaynagi'] = 'kesim';
                }
                if (($oneri['secildi'] ?? false) && ($oneri['saglayici_id'] ?? null) !== null) {
                    $this->faturalar->bagla(
                        $islem,
                        FaturaSaglayicisi::where('hane_id', $ekstre->hane_id)->findOrFail($oneri['saglayici_id']),
                        $oneri['hizmet_donemi'] ?? null,
                        $oneri['donem_kaynagi'] ?? null,
                        ['guven' => $oneri['guven'] ?? null, 'gerekce' => $oneri['gerekce'] ?? [], 'otomatik' => $oneri['otomatik'] ?? false],
                        $user->id,
                    );
                    $fatura++;
                }
            }
            $ekstre->forceFill([
                'durum' => EkstreDurumu::Onaylandi,
                'onayli_kesim_tarihi' => $ekstre->kesim_tarihi,
                'onaylayan_id' => $user->id,
                'onaylandi_at' => now(),
            ])->save();

            return ['eklenen' => $eklenen, 'atlanan' => $atlanan, 'tekrar' => $tekrar, 'fatura' => $fatura];
        });
    }

    /** Ekstre, satırları ve dosyası silinir (dosya işlem bittikten sonra); bağlı faturalar "bekliyor"a döner */
    public function sil(Ekstre $ekstre): void
    {
        $yol = $ekstre->dosya_yolu;
        DB::transaction(function () use ($ekstre): void {
            $ekstre->islemler()->get()->each(fn (KartIslemi $i) => $this->faturalar->islemCikti($i));
            $ekstre->delete();
        });
        if ($yol !== null) {
            Storage::disk(self::DISK)->delete($yol);
        }
    }
}
