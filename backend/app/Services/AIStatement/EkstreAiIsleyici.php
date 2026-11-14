<?php

namespace App\Services\AIStatement;

use App\Enums\EkstreDurumu;
use App\Models\Ekstre;
use App\Models\EkstreAiCalismasi;
use App\Models\HesapEkstresi;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Yapay zekâ analizi çalışmasının yaşam döngüsü (kart ekstresi ve banka hesabı ekstresi):
 *
 * - `baslat`: onaylı istekte çalışma kaydı "bekliyor" açılır; ekstre başına tek etkin çalışma.
 * - `arkaPlandaBaslat`: yanıt döndükten sonra (`defer`) yalnızca kısa oluşturma isteği atılır (OpenAI arka plan modu);
 *   PHP dakikalarca bekletilmez.
 * - `ilerlet`: durum ucu (ön yüz birkaç saniyede bir) ve cron (`ekstre:ai-isle`) çalışmayı ilerletir; OpenAI'ye en
 *   çok SORMA_ARALIGI_SN'de bir sorulur. Sonuç gelince doğrulanır ve hedefe yazılır.
 * - Arka plan modu kabul edilmezse ya da kapalıysa çalışma cron'da senkron yürür (CLI, süre sınırı yok).
 * - Takılan çalışma: `defer` çalışmadıysa cron CRON_DEVRALMA_SN sonra devralır; AZAMI_SURE_SN'yi aşan zaman aşımıdır.
 * - Yarış: "bekliyor → çalışıyor" tek koşullu UPDATE ile alınır; sonuç yalnızca kilit eşleşirse yazılır (geç kalan
 *   süreç sonucu ezmez). Ekstre silinirse kayıt da gider, iş sessizce durur.
 * - Hatalar yeniden atılmaz: yalnızca hata kodu yazılır (yığın izine / loga içerik sızmasın).
 */
class EkstreAiIsleyici
{
    /** Kullanıcının onay metninin sürümü (çalışma kaydına yazılır) */
    public const ONAY_SURUMU = '2026-11-14';

    private const AZAMI_SURE_SN = 900;

    private const SORMA_ARALIGI_SN = 5;

    private const CRON_DEVRALMA_SN = 30;

    public function __construct(
        private StatementAnalyzer $analizci,
        private OpenAIClient $istemci,
        private StatementImporter $aktarici,
        private HesapEkstresiOnizleme $onizleme,
        private KisiselVeriTemizleyici $temizleyici,
    ) {}

    public function etkin(Ekstre|HesapEkstresi $hedef): ?EkstreAiCalismasi
    {
        return $hedef->aiCalismalari()->whereIn('durum', EkstreAiCalismasi::ETKIN)->latest('id')->first();
    }

    public function son(Ekstre|HesapEkstresi $hedef): ?EkstreAiCalismasi
    {
        return $hedef->aiCalismalari()->latest('id')->first();
    }

    /**
     * @throws ValidationException kapalıysa, onaylı ekstreyse, zaten sürüyorsa, dosya yoksa
     */
    public function baslat(Ekstre|HesapEkstresi $hedef, User $user): EkstreAiCalismasi
    {
        if (! $this->istemci->acikMi()) {
            throw ValidationException::withMessages(['ekstre' => YapayZekaHatasi::kapali()->getMessage()]);
        }

        return DB::transaction(function () use ($hedef, $user): EkstreAiCalismasi {
            $kilitli = $hedef::query()->lockForUpdate()->findOrFail($hedef->getKey());
            if ($kilitli instanceof Ekstre && $kilitli->durum !== EkstreDurumu::Taslak) {
                throw ValidationException::withMessages(['ekstre' => 'Yapay zekâ yalnızca incelemedeki (onaylanmamış) ekstrede kullanılır.']);
            }
            if ($this->etkin($kilitli)) {
                throw ValidationException::withMessages(['ekstre' => 'Bu ekstrede yapay zekâ analizi zaten sürüyor.']);
            }
            if ($kilitli->dosya_yolu === null) {
                throw ValidationException::withMessages(['ekstre' => 'Ekstrenin PDF dosyası yok; yeniden yükleyin.']);
            }

            return EkstreAiCalismasi::create([
                'hane_id' => $kilitli->hane_id,
                'ekstre_id' => $kilitli instanceof Ekstre ? $kilitli->id : null,
                'hesap_ekstresi_id' => $kilitli instanceof HesapEkstresi ? $kilitli->id : null,
                'user_id' => $user->id,
                'model' => (string) config('services.openai.model'),
                'durum' => 'bekliyor',
                'asama' => 'sirada',
                'calistirici' => config('services.openai.arka_plan') ? 'arka_plan' : 'cron',
                'sayfa_sayisi' => (int) $kilitli->sayfa_sayisi,
                'onay_surumu' => self::ONAY_SURUMU,
                'onay_at' => now(),
            ]);
        });
    }

    /** Yanıt kullanıcıya gittikten sonra ilk adım (yalnızca kısa oluşturma isteği) */
    public function arkaPlandaBaslat(EkstreAiCalismasi $calisma): void
    {
        if ($calisma->calistirici === 'cron') {
            return;
        }
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
        defer(function () use ($calisma): void {
            if (function_exists('set_time_limit')) {
                @set_time_limit(120);
            }
            $this->ilerlet($calisma->fresh());
        });
    }

    /**
     * Çalışmayı bir adım ilerletir; hiçbir zaman istisna atmaz.
     */
    public function ilerlet(?EkstreAiCalismasi $c, bool $cron = false): ?EkstreAiCalismasi
    {
        if ($c === null || ! $c->etkinMi()) {
            return $c;
        }
        try {
            return $c->durum === 'bekliyor' ? $this->gonder($c, $cron) : $this->yokla($c);
        } catch (YapayZekaHatasi $e) {
            return $this->hata($c, $e->kod);
        } catch (Throwable) {
            return $this->hata($c, 'beklenmeyen');
        }
    }

    /** Cron: defer'in başlatmadığı ve süren çalışmalar; işlenen sayı */
    public function cron(): int
    {
        $sayi = 0;
        foreach (EkstreAiCalismasi::whereIn('durum', EkstreAiCalismasi::ETKIN)->orderBy('id')->limit(20)->get() as $c) {
            // Arka plan yolunda defer'e süre tanınır
            if ($c->durum === 'bekliyor' && $c->calistirici !== 'cron' && $c->created_at->gt(now()->subSeconds(self::CRON_DEVRALMA_SN))) {
                continue;
            }
            $this->ilerlet($c, cron: true);
            $sayi++;
        }

        return $sayi;
    }

    public function iptal(EkstreAiCalismasi $c): ?EkstreAiCalismasi
    {
        if ($c->openai_yanit_id !== null && $c->etkinMi()) {
            $this->istemci->iptal($c->openai_yanit_id);
        }
        EkstreAiCalismasi::whereKey($c->id)->whereIn('durum', EkstreAiCalismasi::ETKIN)
            ->update(['durum' => 'iptal', 'asama' => 'iptal', 'hata_kodu' => 'iptal', 'kilit' => null, 'bitti_at' => now()]);

        return $c->fresh();
    }

    /** "bekliyor": çalışmayı al, PDF'i gönder (arka plan) ya da cron'da yanıtı bekle (senkron) */
    private function gonder(EkstreAiCalismasi $c, bool $cron): ?EkstreAiCalismasi
    {
        if ($c->calistirici === 'cron' && ! $cron) {
            return $c;
        }
        $kilit = Str::uuid()->toString();
        $alindi = EkstreAiCalismasi::whereKey($c->id)->where('durum', 'bekliyor')
            ->update(['durum' => 'calisiyor', 'kilit' => $kilit, 'basladi_at' => now(), 'asama' => 'gonderildi']);
        $c = $c->fresh();
        if ($alindi !== 1 || $c === null) {
            return $c;
        }
        $hedef = $this->hedef($c);
        $senkron = $c->calistirici === 'cron';
        $hazir = $this->analizci->hazirla($this->analizci->pdf($hedef?->dosya_yolu), arkaPlan: ! $senkron);

        if ($senkron) {
            EkstreAiCalismasi::whereKey($c->id)->where('kilit', $kilit)->update(['asama' => 'okunuyor']);

            return $this->bitir($c, $this->istemci->yapilandirilmisCevap($hazir['govde']), $hazir);
        }
        try {
            $durum = $this->istemci->baslat($hazir['govde']);
        } catch (YapayZekaHatasi $e) {
            if ($e->kod !== 'arka_plan_yok') {
                throw $e;
            }
            // Arka plan modu kabul edilmedi: cron senkron çalıştırır
            EkstreAiCalismasi::whereKey($c->id)->where('kilit', $kilit)
                ->update(['durum' => 'bekliyor', 'calistirici' => 'cron', 'kilit' => null, 'asama' => 'sirada']);

            return $c->fresh();
        }
        EkstreAiCalismasi::whereKey($c->id)->where('kilit', $kilit)
            ->update(['openai_yanit_id' => $durum['id'], 'asama' => 'okunuyor', 'son_kontrol_at' => now()]);

        return $durum['sonuc'] !== null ? $this->bitir($c->fresh(), $durum['sonuc'], $hazir) : $c->fresh();
    }

    /** "çalışıyor": zaman aşımı, ya da arka plandaki yanıtı sor (en çok SORMA_ARALIGI_SN'de bir) */
    private function yokla(EkstreAiCalismasi $c): ?EkstreAiCalismasi
    {
        if (($c->basladi_at ?? $c->created_at)->lt(now()->subSeconds(self::AZAMI_SURE_SN))) {
            if ($c->openai_yanit_id !== null) {
                $this->istemci->iptal($c->openai_yanit_id);
            }

            return $this->hata($c, 'zaman_asimi');
        }
        if ($c->openai_yanit_id === null) {
            return $c; // senkron çalışma cron'da sürüyor
        }
        $sira = EkstreAiCalismasi::whereKey($c->id)->where('durum', 'calisiyor')
            ->where(fn ($q) => $q->whereNull('son_kontrol_at')->orWhere('son_kontrol_at', '<', now()->subSeconds(self::SORMA_ARALIGI_SN)))
            ->update(['son_kontrol_at' => now()]);
        if ($sira !== 1) {
            return $c->fresh();
        }
        $durum = $this->istemci->sor($c->openai_yanit_id);
        if ($durum['sonuc'] === null) {
            return $c->fresh();
        }
        $hedef = $this->hedef($c);

        return $this->bitir($c, $durum['sonuc'], $this->analizci->sayfaMetinleri($this->analizci->pdf($hedef?->dosya_yolu)));
    }

    /**
     * Yanıt geldi: doğrula, hedefe yaz, çalışmayı bitir; kilit eşleşmiyorsa (zaman aşımı / iptal) hiçbir şey yazılmaz.
     *
     * @param  array<string, mixed>  $veri
     * @param  array{sayfa_metinleri: list<string>, sayfa_sayisi: int}  $hazir
     */
    private function bitir(EkstreAiCalismasi $c, array $veri, array $hazir): ?EkstreAiCalismasi
    {
        EkstreAiCalismasi::whereKey($c->id)->where('kilit', $c->kilit)->update(['asama' => 'dogrulaniyor']);
        $kullanim = $this->istemci->sonKullanim;
        $hedef = $this->hedef($c);
        $sonuc = $hedef instanceof Ekstre ? $this->analizci->coz($veri, $hazir['sayfa_metinleri'], $hazir['sayfa_sayisi']) : null;

        return DB::transaction(function () use ($c, $veri, $hazir, $hedef, $sonuc, $kullanim): ?EkstreAiCalismasi {
            $kayit = EkstreAiCalismasi::lockForUpdate()->find($c->id);
            if ($kayit === null || $kayit->durum !== 'calisiyor' || $kayit->kilit !== $c->kilit || $hedef === null) {
                return $kayit;
            }
            if ($hedef instanceof Ekstre) {
                $kullanici = User::find($kayit->user_id) ?? User::findOrFail($hedef->olusturan_id);
                $this->aktarici->uygula($hedef->fresh(), $kullanici, [...$sonuc, 'ai' => $this->temizleyici->maskeleHepsi($sonuc['ai'])]);
                [$kabul, $red] = [count($sonuc['kabul']), count($sonuc['reddedilen'])];
            } else {
                [$kabul, $red] = $this->onizleme->uygula($hedef->fresh(), $veri, $hazir['sayfa_metinleri'], $hazir['sayfa_sayisi']);
            }
            $kayit->forceFill([
                'durum' => 'basarili', 'asama' => 'tamam', 'kilit' => null, 'bitti_at' => now(),
                'kabul_satir' => $kabul, 'reddedilen_satir' => $red,
                'sure_ms' => (int) (($kayit->basladi_at ?? $kayit->created_at)->diffInMilliseconds(now(), true)),
                'girdi_token' => $kullanim['girdi'] ?? null, 'cikti_token' => $kullanim['cikti'] ?? null,
            ])->save();

            return $kayit;
        });
    }

    private function hata(EkstreAiCalismasi $c, string $kod): ?EkstreAiCalismasi
    {
        EkstreAiCalismasi::whereKey($c->id)->whereIn('durum', EkstreAiCalismasi::ETKIN)->update([
            'durum' => 'hata', 'asama' => 'hata', 'hata_kodu' => mb_substr($kod, 0, 30), 'kilit' => null, 'bitti_at' => now(),
            'sure_ms' => (int) (($c->basladi_at ?? $c->created_at)->diffInMilliseconds(now(), true)),
        ]);

        return $c->fresh();
    }

    private function hedef(EkstreAiCalismasi $c): Ekstre|HesapEkstresi|null
    {
        return $c->ekstre_id !== null ? Ekstre::find($c->ekstre_id) : HesapEkstresi::find($c->hesap_ekstresi_id);
    }
}
