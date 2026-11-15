<?php

namespace Tests\Feature;

use App\Models\HaneUyesi;
use App\Models\KartIslemi;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use App\Services\AIStatement\StatementAnalyzer;
use App\Services\Ekstre\EkstreAyristirici;
use App\Services\Ekstre\EkstreMutabakati;
use App\Services\Ekstre\PdfMetinOkuyucu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GarantiAiYaniti;
use Tests\Support\OrnekPdf;
use Tests\TestCase;

/**
 * Referans: gerçek bir Garanti BBVA Bonus kredi kartı ekstresinin anonim kopyası (tests/Fixtures/ekstre/garanti_bonus;
 * `php artisan ekstre:fixture` ile üretildi, gerçek PDF depoda yok). 4 sayfa: işlemler 1. ve 2. sayfada, 3. ve 4.
 * bilgilendirme; taksitler, iadeler, bonus sütunu hareketleri, devreden borç ve ödemesi; dönem borcu 100.632,22 TL.
 * Sentetik PDF aynı sütun düzeniyle (x konumları) yeniden kurulur; her işlem satırı ayrı ayrı denetlenir.
 */
class GarantiReferansEkstreTest extends TestCase
{
    use RefreshDatabase;

    private const KLASOR = __DIR__.'/../Fixtures/ekstre/garanti_bonus';

    /**
     * @return array<string, mixed>
     */
    private static function veri(string $ad): array
    {
        return json_decode((string) file_get_contents(self::KLASOR."/{$ad}.json"), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  callable(list<list<array<int, string>>>): list<list<array<int, string>>>|null  $degistir
     */
    private function pdf(?callable $degistir = null): string
    {
        $s = self::veri('sayfalar');

        return OrnekPdf::yaz($degistir ? $degistir($s['sayfalar']) : $s['sayfalar'], false, $s['satir_araligi']);
    }

    public function test_yapay_zeka_yolu_her_satiri_dogrular_capraz_uyumlu_ve_aktarilir(): void
    {
        Carbon::setTestNow('2026-11-14 10:00:00');
        Storage::fake('local');
        $adres = 'https://openai.test/v1/responses';
        config(['services.openai.key' => 'test-anahtar', 'services.openai.url' => $adres, 'ekstre.ai_aktarim' => true]);
        $beklenen = self::veri('beklenen');
        $pdf = $this->pdf();
        $ai = GarantiAiYaniti::olustur($beklenen, app(StatementAnalyzer::class)->sayfaMetinleri($pdf)['sayfa_metinleri']);
        Http::fake([$adres => Http::response(['id' => 'resp_g', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($ai)]]]]])]);

        $ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($ben);
        $this->actingAs($ben);
        $banka = $this->postJson('/api/v1/aile/bankalar', ['ad' => 'Garanti BBVA'])->json('banka.id');
        $kart = $this->postJson('/api/v1/aile/kartlar', ['banka_id' => $banka, 'sahip_uye_id' => HaneUyesi::where('user_id', $ben->id)->value('id'), 'ad' => 'Bonus'])->json('kart.id');
        $id = $this->post('/api/v1/aile/ekstreler', ['kart_id' => $kart, 'dosya' => UploadedFile::fake()->createWithContent('ekstre.pdf', $pdf), 'yapay_zeka' => '1'], ['Accept' => 'application/json'])
            ->assertCreated()->json('ekstre.id');

        $cevap = $this->getJson("/api/v1/aile/ekstreler/{$id}")->assertOk()
            ->assertJsonPath('ai_calisma.durum', 'basarili')
            ->assertJsonPath('ai_calisma.kabul_satir', 75)
            ->assertJsonPath('ai_calisma.reddedilen_satir', 0)
            ->assertJsonPath('ekstre.banka_profili', 'ai')
            ->assertJsonPath('ekstre.donem_borcu', 100632.22)
            ->assertJsonPath('ekstre.denetim.durum', 'tutuyor')
            ->assertJsonPath('ekstre.denetim.capraz.durum', 'uyumlu')
            ->assertJsonPath('ekstre.denetim.capraz.secilen', 'ai')
            ->assertJsonPath('ekstre.denetim.kesim_teyit_gerekli', false)
            ->assertJsonPath('ekstre.denetim.ai.bonus_hareketi', 4)
            ->assertJsonPath('ekstre.denetim.ai.sayfa_denetimi.sorunlu_sayfa', 0);
        // Her satır tek tek (PDF okuyucunun beklenen sonucu ile aynı)
        $satirlar = $cevap->json('satirlar');
        $this->assertCount(75, $satirlar);
        foreach ($beklenen['satirlar'] as $i => $b) {
            $s = $satirlar[$i];
            $this->assertSame(
                [$b['sayfa'], $b['islem_tarihi'], $b['aciklama'], (float) $b['tutar'], $b['tur'], $b['taksit_no'], $b['taksit_sayisi'], $b['taksit_toplam'] !== null ? (float) $b['taksit_toplam'] : null, false],
                [$s['sayfa'], $s['islem_tarihi'], $s['aciklama'], (float) $s['tutar'], $s['tur'], $s['taksit_no'], $s['taksit_sayisi'], $s['taksit_toplam'] !== null ? (float) $s['taksit_toplam'] : null, $s['okuma']['inceleme']],
                "Satır {$b['sira']}",
            );
        }
        $this->assertSame(['tamam', 'tamam', 'tamam', 'tamam'], array_column($cevap->json('ekstre.denetim.ai.sayfa_denetimi.sayfalar'), 'durum'));

        $this->postJson("/api/v1/aile/ekstreler/{$id}/onayla")->assertOk()->assertJsonPath('rapor.eklenen', 75)->assertJsonPath('rapor.atlanan', 0);
    }

    public function test_php_okuyucu_her_satiri_tek_tek_dogru_okur_ve_mutabakat_tutar(): void
    {
        $beklenen = self::veri('beklenen');
        $okunan = app(PdfMetinOkuyucu::class)->oku($this->pdf());
        $sonuc = app(EkstreAyristirici::class)->ayristir($okunan['sayfalar']);

        $this->assertSame([4, 'garanti'], [$okunan['sayfa_sayisi'], $sonuc['profil']]);
        $this->assertSame($beklenen['alanlar'], $sonuc['alanlar']);
        $this->assertSame($beklenen['ozet'], $sonuc['ozet']);
        $this->assertCount(count($beklenen['satirlar']), $sonuc['satirlar']);
        $alanlar = ['sayfa', 'islem_tarihi', 'aciklama', 'tutar', 'tur', 'alacak', 'taksit_no', 'taksit_sayisi', 'taksit_toplam'];
        foreach ($beklenen['satirlar'] as $i => $b) {
            $this->assertSame(
                array_map(fn (string $a): mixed => $b[$a], $alanlar),
                array_map(fn (string $a): mixed => $sonuc['satirlar'][$i][$a], $alanlar),
                "Satır {$b['sira']} ({$b['islem_tarihi']} {$b['aciklama']} {$b['tutar']})",
            );
        }

        // İşlemler yalnızca 1. ve 2. sayfada; 3. ve 4. sayfa bilgilendirme
        $this->assertSame([1, 2], array_values(array_unique(array_column($sonuc['satirlar'], 'sayfa'))));
        // Bonus sütunundaki hareketler (kazanılan / kullanılan) TL işlemi değil, ayrı
        $bonus = fn (array $liste): array => array_map(fn (array $b): array => [$b['sayfa'], $b['islem_tarihi'], $b['aciklama'], $b['tutar'], $b['yon']], $liste);
        $this->assertSame($bonus($beklenen['bonus_hareketleri']), $bonus($sonuc['bonus_hareketleri']));
        // Kapsam: devreden borç ödemesi, iadeler, taksitler, satır faizleri
        $turler = array_count_values(array_column($sonuc['satirlar'], 'tur'));
        $this->assertSame(['odeme' => 1, 'harcama' => 65, 'iade' => 6, 'faiz' => 3], $turler);
        $this->assertCount(24, array_filter($sonuc['satirlar'], fn (array $s): bool => $s['taksit_sayisi'] !== null));

        // Mutabakat: borç denklemi, ekstrenin dönem hareketi ve ödemeler; dönem borcu 100.632,22
        $m = app(EkstreMutabakati::class)->denetle($sonuc['alanlar'], $sonuc['ozet'], $sonuc['satirlar']);
        $this->assertSame('100632.22', $sonuc['alanlar']['donem_borcu']);
        $this->assertSame(['tutuyor', 'tutuyor', 'tutuyor'], array_column($m['kontroller'], 'durum'));
        $this->assertSame([$beklenen['mutabakat']['durum'], $beklenen['mutabakat']['toplamlar']], [$m['durum'], $m['toplamlar']]);
    }

    public function test_aktarimda_hepsi_girer_ayni_gun_ayni_tutarli_islemler_tekrar_sayilmaz(): void
    {
        Carbon::setTestNow('2026-11-14 10:00:00');
        Storage::fake('local');
        $ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($ben);
        $this->actingAs($ben);
        $banka = $this->postJson('/api/v1/aile/bankalar', ['ad' => 'Garanti BBVA'])->json('banka.id');
        $kart = $this->postJson('/api/v1/aile/kartlar', ['banka_id' => $banka, 'sahip_uye_id' => HaneUyesi::where('user_id', $ben->id)->value('id'), 'ad' => 'Bonus'])->json('kart.id');

        // Ekstreye aynı tarih, tutar ve açıklamalı ikinci bir işlem eklenir (ör. aynı gün iki kez aynı benzin)
        $pdf = $this->pdf(function (array $sayfalar): array {
            $kopya = array_values(array_filter($sayfalar[1], fn (array $s): bool => in_array('ISYERI 01', $s, true)))[0]
                ?? array_values(array_filter($sayfalar[0], fn (array $s): bool => in_array('ISYERI 01', $s, true)))[0];
            $yer = array_search($kopya, $sayfalar[0], true);
            array_splice($sayfalar[0], $yer + 1, 0, [$kopya]);

            return $sayfalar;
        });
        $id = $this->post('/api/v1/aile/ekstreler', ['kart_id' => $kart, 'dosya' => UploadedFile::fake()->createWithContent('ekstre.pdf', $pdf)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('ozet.satir', 76)->assertJsonPath('ozet.tekrar', 0)->json('ekstre.id');
        $this->getJson("/api/v1/aile/ekstreler/{$id}")->assertOk()->assertJsonPath('ekstre.denetim.durum', 'tutmuyor'); // eklenen satır kadar fark

        $this->postJson("/api/v1/aile/ekstreler/{$id}/onayla")->assertOk()
            ->assertJsonPath('rapor.eklenen', 76)->assertJsonPath('rapor.atlanan', 0)->assertJsonPath('rapor.tekrar', 0);
        $onayli = KartIslemi::where('ekstre_id', $id)->where('durum', 'onayli');
        $this->assertSame(76, $onayli->count());
        $this->assertSame(76, $onayli->distinct()->count('parmak_izi'));
        // Aynı gün ve tutarlı iki satır ayrı işlem olarak kaldı
        $ilk = KartIslemi::where('ekstre_id', $id)->where('aciklama', 'ISYERI 01')->orderBy('sira')->first();
        $this->assertSame(2, KartIslemi::where('ekstre_id', $id)->where('aciklama', 'ISYERI 01')
            ->whereDate('islem_tarihi', $ilk->islem_tarihi)->where('tutar', $ilk->tutar)->count());
    }
}
