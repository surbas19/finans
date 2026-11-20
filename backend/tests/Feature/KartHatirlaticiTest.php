<?php

namespace Tests\Feature;

use App\Enums\EkstreDurumu;
use App\Models\Ekstre;
use App\Models\HaneUyesi;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Kart borç hatırlatıcısı: hanenin kart sırası (listede olmayanın sırası boşalır), ekstrenin "toplamda" işareti
 * (onaylı ekstrede de değişir), başka hane göremez / değiştiremez.
 */
class KartHatirlaticiTest extends TestCase
{
    use RefreshDatabase;

    private User $ben;

    /** @var list<int> */
    private array $kartlar = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($this->ben);
        $this->actingAs($this->ben);
        $banka = $this->postJson('/api/v1/aile/bankalar', ['ad' => 'Garanti'])->json('banka.id');
        $uye = HaneUyesi::where('user_id', $this->ben->id)->value('id');
        foreach (['Bonus', 'Maximum', 'World'] as $ad) {
            $this->kartlar[] = $this->postJson('/api/v1/aile/kartlar', ['banka_id' => $banka, 'sahip_uye_id' => $uye, 'ad' => $ad])->json('kart.id');
        }
    }

    public function test_sira_kaydedilir_listede_olmayanin_sirasi_bosalir(): void
    {
        [$bonus, $maximum, $world] = $this->kartlar;
        $this->putJson('/api/v1/aile/kartlar/hatirlatici-sirasi', ['kart_idler' => [$world, $bonus, $maximum]])->assertOk()
            ->assertJsonPath('message', 'Sıra kaydedildi.');
        $sira = collect($this->getJson('/api/v1/aile/kartlar')->json('kartlar'))->pluck('hatirlatici_sira', 'id');
        $this->assertSame([$bonus => 1, $maximum => 2, $world => 0], [$bonus => $sira[$bonus], $maximum => $sira[$maximum], $world => $sira[$world]]);

        $this->putJson('/api/v1/aile/kartlar/hatirlatici-sirasi', ['kart_idler' => [$maximum]])->assertOk();
        $sira = collect($this->getJson('/api/v1/aile/kartlar')->json('kartlar'))->pluck('hatirlatici_sira', 'id');
        $this->assertSame(0, $sira[$maximum]);
        $this->assertNull($sira[$bonus]);
        $this->assertNull($sira[$world]);

        $this->putJson('/api/v1/aile/kartlar/hatirlatici-sirasi', ['kart_idler' => [$bonus, $bonus]])->assertStatus(422);
    }

    public function test_toplamda_onayli_ekstrede_de_degisir(): void
    {
        $ekstre = Ekstre::create(['hane_id' => $this->ben->hane_id, 'kart_id' => $this->kartlar[0], 'durum' => EkstreDurumu::Onaylandi,
            'kesim_tarihi' => '2026-11-10', 'son_odeme_tarihi' => '2026-11-20', 'donem_borcu' => '1000.00']);
        $this->getJson('/api/v1/aile/ekstreler')->assertJsonPath('ekstreler.0.toplamda', false);
        $this->putJson("/api/v1/aile/ekstreler/{$ekstre->id}", ['toplamda' => true])->assertOk()->assertJsonPath('ekstre.toplamda', true);
        $this->putJson("/api/v1/aile/ekstreler/{$ekstre->id}", ['odendi' => true])->assertOk()
            ->assertJsonPath('ekstre.odendi', true)->assertJsonPath('ekstre.toplamda', true);
        $this->putJson("/api/v1/aile/ekstreler/{$ekstre->id}", ['toplamda' => false])->assertOk()->assertJsonPath('ekstre.toplamda', false);
    }

    public function test_baska_hane_siraya_ve_toplama_karisamaz(): void
    {
        $ekstre = Ekstre::create(['hane_id' => $this->ben->hane_id, 'kart_id' => $this->kartlar[0], 'durum' => EkstreDurumu::Onaylandi,
            'kesim_tarihi' => '2026-11-10', 'donem_borcu' => '1000.00']);
        $yabanci = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($yabanci);
        $this->actingAs($yabanci);

        $this->putJson('/api/v1/aile/kartlar/hatirlatici-sirasi', ['kart_idler' => [$this->kartlar[0]]])->assertStatus(422);
        $this->putJson("/api/v1/aile/ekstreler/{$ekstre->id}", ['toplamda' => true])->assertForbidden();
        // Kendi (boş) listesini kaydetmek benim sıramı bozmaz
        $this->putJson('/api/v1/aile/kartlar/hatirlatici-sirasi', ['kart_idler' => []])->assertOk();
        $this->actingAs($this->ben);
        $this->putJson('/api/v1/aile/kartlar/hatirlatici-sirasi', ['kart_idler' => [$this->kartlar[2]]])->assertOk();
        $this->assertSame(0, collect($this->getJson('/api/v1/aile/kartlar')->json('kartlar'))->firstWhere('id', $this->kartlar[2])['hatirlatici_sira']);
        $this->assertFalse($ekstre->fresh()->toplamda);
    }
}
