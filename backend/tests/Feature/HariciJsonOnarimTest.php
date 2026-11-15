<?php

namespace Tests\Feature;

use App\Models\Ekstre;
use App\Models\HaneUyesi;
use App\Models\KartIslemi;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Sohbet arayüzünden kopyalanan JSON'un biçim artıkları (\*, &#x20;, satır sonu \) otomatik onarılır; onarılamayan
 * hatada yeri söylenir. Referans: gerçek bir Yapı Kredi / World Hepsiburada ekstresinin dış yapay zekâ çıktısı.
 */
class HariciJsonOnarimTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../Fixtures/ekstre/yapi_kredi_hepsiburada.json';

    private int $kart;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        Storage::fake('local');
        Http::fake();
        config(['services.openai.key' => null, 'ekstre.ai_aktarim' => true]);
        $kullanici = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($kullanici);
        $this->actingAs($kullanici);
        $banka = $this->postJson('/api/v1/aile/bankalar', ['ad' => 'Yapı Kredi'])->json('banka.id');
        $this->kart = $this->postJson('/api/v1/aile/kartlar', ['banka_id' => $banka, 'sahip_uye_id' => HaneUyesi::where('user_id', $kullanici->id)->value('id'), 'ad' => 'World'])->json('kart.id');
    }

    private function temiz(): string
    {
        return (string) file_get_contents(self::FIXTURE);
    }

    /** Sohbet arayüzünün bozduğu hâl: `\*` kaçışı, girintide `&#x20;`, her satır sonunda `\` */
    private function kirli(): string
    {
        $metin = str_replace('HEPSIPAY *HEPSIBURADA', 'HEPSIPAY \*HEPSIBURADA', $this->temiz());
        $metin = (string) preg_replace_callback('/^ +/m', fn (array $m): string => str_repeat('&#x20;', strlen($m[0])), $metin);

        return (string) preg_replace('/\R/', "\\\n", $metin);
    }

    private function yapistir(string $json): TestResponse
    {
        return $this->post('/api/v1/aile/ekstreler/json-ile', ['kart_id' => $this->kart, 'json' => $json], ['Accept' => 'application/json']);
    }

    public function test_kirli_json_onarilir_yapi_kredi_ekstresi_7_satir_mutabakat_tutar_ve_onaylanir(): void
    {
        $this->assertStringContainsString('\*', $this->kirli());
        $json = json_decode($this->kirli());
        $this->assertNull($json); // kirli hâl gerçekten geçersiz

        $cevap = $this->yapistir($this->kirli())->assertCreated()
            ->assertJsonPath('ozet', ['satir' => 7, 'inceleme' => 0, 'reddedilen' => 0])
            ->assertJsonPath('ekstre.kesim_tarihi', '2026-11-13')
            ->assertJsonPath('ekstre.son_odeme_tarihi', '2026-11-23')
            ->assertJsonPath('ekstre.donem_borcu', 8406.71)
            ->assertJsonPath('ekstre.denetim.durum', 'tutuyor');
        $this->assertStringContainsString('otomatik temizlendi', implode(' ', $cevap->json('uyarilar')));
        $id = $cevap->json('ekstre.id');

        $satirlar = $this->getJson("/api/v1/aile/ekstreler/{$id}")->assertOk()->json('satirlar');
        $this->assertCount(7, $satirlar);
        $taksitler = array_values(array_filter($satirlar, fn (array $s): bool => $s['taksit_sayisi'] !== null));
        $this->assertCount(4, $taksitler);
        $this->assertSame([[4, 9], [3, 3], [3, 9], [1, 3]], array_map(fn (array $s): array => [$s['taksit_no'], $s['taksit_sayisi']], $taksitler));
        $this->assertSame(1, count(array_filter($satirlar, fn (array $s): bool => $s['tur'] === 'odeme')));
        // Ters eğik çizgi hiçbir yere sızmadı
        foreach (KartIslemi::where('ekstre_id', $id)->get() as $islem) {
            $this->assertStringNotContainsString('\\', (string) $islem->ham_metin);
        }

        $this->postJson("/api/v1/aile/ekstreler/{$id}/onayla")->assertOk()->assertJsonPath('rapor.eklenen', 7);
        Http::assertNothingSent();
    }

    public function test_temiz_json_duzeltme_uyarisi_vermez(): void
    {
        $cevap = $this->yapistir($this->temiz())->assertCreated()->assertJsonPath('ozet.satir', 7);
        $this->assertStringNotContainsString('otomatik temizlendi', implode(' ', $cevap->json('uyarilar')));
    }

    public function test_onarilamayan_json_hatanin_yerini_soyler_ve_kayit_olusmaz(): void
    {
        $ornekler = [
            "{\n \"bank_name\": \"X\",\n}" => 'sondaki virgül',
            "{\n \"a\": [1, 2,\n" => 'açık parantez',
            "{\n \"a\": \"x\ny\"\n}" => 'tırnak içinde satır sonu',
            '{"a": 1} bitti' => 'fazladan metin',
        ];
        foreach ($ornekler as $json => $beklenen) {
            $mesaj = (string) $this->yapistir($json)->assertUnprocessable()->json('errors.json.0');
            $this->assertStringContainsString($beklenen, $mesaj);
            $this->assertStringContainsString('Kopyala', $mesaj);
        }
        $this->assertSame(0, Ekstre::count());
    }
}
