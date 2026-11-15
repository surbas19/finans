<?php

namespace Tests\Feature;

use App\Models\HaneUyesi;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Halkbank Paraf: tutarlar İngilizce yazımlı (749.63, 1,243.53), bonus sütunu 0.00, özette harcama toplamı yok. */
class HalkbankJsonTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingilizce_tutar_yazimli_ekstre_kabul_edilir_ve_mutabakat_tutar(): void
    {
        Carbon::setTestNow('2026-11-15 10:00:00');
        Storage::fake('local');
        Http::fake();
        config(['services.openai.key' => null, 'ekstre.ai_aktarim' => true]);
        $kullanici = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($kullanici);
        $this->actingAs($kullanici);
        $banka = $this->postJson('/api/v1/aile/bankalar', ['ad' => 'Halkbank'])->json('banka.id');
        $kart = $this->postJson('/api/v1/aile/kartlar', ['banka_id' => $banka, 'sahip_uye_id' => HaneUyesi::where('user_id', $kullanici->id)->value('id'), 'ad' => 'Paraf'])->json('kart.id');

        $this->post('/api/v1/aile/ekstreler/json-ile', ['kart_id' => $kart, 'json' => (string) file_get_contents(__DIR__.'/../Fixtures/ekstre/halkbank_paraf.json')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('ozet.satir', 14)
            ->assertJsonPath('ozet.reddedilen', 0)
            ->assertJsonPath('ekstre.donem_borcu', 6166.37)
            ->assertJsonPath('ekstre.denetim.durum', 'tutuyor');
    }
}
