<?php

namespace Tests\Feature;

use App\Models\Ekstre;
use App\Models\HaneUyesi;
use App\Models\KartIslemi;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Dosyasız (yalnızca tutarla) ekstre: onaylı ekstre + dönem borcu kadar tek gider satırı.
 */
class ElleEkstreTest extends TestCase
{
    use RefreshDatabase;

    private User $ben;

    private int $kart;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        Storage::fake('local');
        $this->ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($this->ben);
        $this->actingAs($this->ben);
        $banka = $this->postJson('/api/v1/aile/bankalar', ['ad' => 'Garanti'])->json('banka.id');
        $uye = HaneUyesi::where('user_id', $this->ben->id)->value('id');
        $this->kart = $this->postJson('/api/v1/aile/kartlar', ['banka_id' => $banka, 'sahip_uye_id' => $uye, 'ad' => 'Bonus'])->json('kart.id');
    }

    /**
     * @param  array<string, mixed>  $alan
     * @return array<string, mixed>
     */
    private function veri(array $alan = []): array
    {
        return ['kart_id' => $this->kart, 'kesim_tarihi' => '2026-11-10', 'son_odeme_tarihi' => '2026-11-20', 'donem_borcu' => 12345.67, ...$alan];
    }

    public function test_elle_ekstre_onayli_olur_ve_tek_satir_gidere_girer(): void
    {
        $id = $this->postJson('/api/v1/aile/ekstreler/elle', $this->veri())->assertCreated()
            ->assertJsonPath('ekstre.durum', 'onaylandi')->assertJsonPath('ekstre.donem', '2026-11')
            ->assertJsonPath('ekstre.donem_borcu', 12345.67)->assertJsonPath('ekstre.dosya_var', false)
            ->assertJsonPath('ekstre.banka_profili', 'elle')->assertJsonPath('ekstre.asgari_odeme', 4938.27)
            ->json('ekstre.id');

        $satir = KartIslemi::where('ekstre_id', $id)->sole();
        $this->assertSame(['onayli', 'harcama', '2026-11', '12345.67'], [$satir->durum->value, $satir->tur->value, $satir->rapor_donemi, $satir->tutar]);
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonPath('gider.toplam', 12345.67)->assertJsonPath('gider.kart_harcamasi', 12345.67);
        // Ekstre listesi ve ayrıntısı dosyasız çalışır; dosya ucu 404
        $this->getJson("/api/v1/aile/ekstreler/{$id}")->assertOk()->assertJsonPath('ekstre.dosya_var', false);
        $this->get("/api/v1/aile/ekstreler/{$id}/dosya")->assertNotFound();

        // Silinince tek satır da gider
        $this->deleteJson("/api/v1/aile/ekstreler/{$id}")->assertOk();
        $this->assertSame(0, KartIslemi::where('kart_id', $this->kart)->count());
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonPath('gider.toplam', 0);
    }

    public function test_asgari_girilirse_kullanilir_ayni_donem_ikinci_kez_eklenmez(): void
    {
        $this->postJson('/api/v1/aile/ekstreler/elle', $this->veri(['asgari_odeme' => 5000]))->assertCreated()->assertJsonPath('ekstre.asgari_odeme', 5000);
        $this->postJson('/api/v1/aile/ekstreler/elle', $this->veri())->assertStatus(422)->assertJsonValidationErrors('kesim_tarihi');
        $this->assertSame(1, Ekstre::count());
        // Başka ay eklenebilir
        $this->postJson('/api/v1/aile/ekstreler/elle', $this->veri(['kesim_tarihi' => '2026-10-10', 'son_odeme_tarihi' => '2026-10-20']))->assertCreated();
        $this->getJson('/api/v1/aile/ozet?ay=2026-10')->assertJsonPath('gider.toplam', 12345.67);
    }

    public function test_dogrulama_ve_baska_hane_kartina_eklenemez(): void
    {
        $this->postJson('/api/v1/aile/ekstreler/elle', $this->veri(['donem_borcu' => 0]))->assertStatus(422)->assertJsonValidationErrors('donem_borcu');
        $this->postJson('/api/v1/aile/ekstreler/elle', $this->veri(['kesim_tarihi' => '2026-11-20', 'son_odeme_tarihi' => '2026-11-30']))->assertStatus(422)->assertJsonValidationErrors('kesim_tarihi');
        $this->postJson('/api/v1/aile/ekstreler/elle', $this->veri(['son_odeme_tarihi' => '2026-11-01']))->assertStatus(422)->assertJsonValidationErrors('son_odeme_tarihi');

        $yabanci = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($yabanci);
        $this->actingAs($yabanci);
        $this->postJson('/api/v1/aile/ekstreler/elle', $this->veri())->assertStatus(422)->assertJsonValidationErrors('kart_id');
        $this->assertSame(0, Ekstre::count());
    }
}
