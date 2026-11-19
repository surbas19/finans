<?php

namespace Tests\Feature;

use App\Models\EvNakdiHareketi;
use App\Models\Hesap;
use App\Models\Islem;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\VarlikYardimcisi;

/**
 * Ev nakdi = seçilen portföy hesabı ("Ev"): bakiye hesabın Nakit (TL)'si; ev harcaması o hesaba Nakit satışı, para ekle /
 * çıkar Nakit alış / satışı; ayrı kasa kayıtları yok sayılır; bağ kaldırılınca kasa döner; hane izolasyonu.
 */
class EvHesabiTest extends TestCase
{
    use RefreshDatabase, VarlikYardimcisi;

    private User $ben;

    private Hesap $ev;

    private int $nakitId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($this->ben);
        $this->ev = Hesap::factory()->create(['user_id' => $this->ben->id, 'ad' => 'Ev']);
        $this->nakitId = $this->varlik('TL', 'nakit', null, kaynak: 'sabit')->id;
        Islem::create(['user_id' => $this->ben->id, 'hesap_id' => $this->ev->id, 'varlik_id' => $this->nakitId, 'tur' => 'mevcut', 'miktar' => 2000, 'birim_fiyat' => null, 'komisyon' => 0, 'tarih' => '2026-11-01']);
    }

    /**
     * @return array<string, mixed>
     */
    private function evHarcamasi(float $tutar, string $aciklama = 'Market'): array
    {
        return ['aciklama' => $aciklama, 'tutar' => $tutar, 'tarih' => '2026-11-12', 'donem' => '2026-11', 'odeme_yeri' => 'ev'];
    }

    public function test_ev_hesabi_secilince_bakiye_hesabin_nakdi_eski_kasa_yok_sayilir(): void
    {
        $this->actingAs($this->ben);
        // Ayrı kasaya önceden para eklenmişti
        $this->postJson('/api/v1/aile/ev-nakdi/hareketler', ['tur' => 'ekleme', 'tutar' => 500, 'tarih' => '2026-11-02'])->assertCreated()
            ->assertJsonPath('bakiye', 500)->assertJsonPath('hesap', null);

        $this->putJson('/api/v1/aile/ev-nakdi/hesap', ['hesap_id' => $this->ev->id])->assertOk()
            ->assertJsonPath('hesap.ad', 'Ev')->assertJsonPath('bakiye', 2000)->assertJsonPath('hareketler.0.tip', 'ekleme')
            ->assertJsonPath('message', 'Ev nakdi artık "Ev" hesabının nakdi.');
        $this->getJson('/api/v1/aile/odeme-kaynaklari')->assertJsonPath('ev.bakiye', 2000)->assertJsonPath('ev.hesap.id', $this->ev->id);
    }

    public function test_ev_harcamasi_hesaptan_duser_ekle_cikar_hesaba_yazilir(): void
    {
        $this->actingAs($this->ben);
        $this->putJson('/api/v1/aile/ev-nakdi/hesap', ['hesap_id' => $this->ev->id])->assertOk();

        $id = $this->postJson('/api/v1/aile/duzenli-giderler', $this->evHarcamasi(640))->assertCreated()
            ->assertJsonPath('gider.odeme_yeri', 'ev')->assertJsonPath('gider.hesap_id', $this->ev->id)->json('gider.id');
        $this->assertDatabaseHas('islemler', ['hesap_id' => $this->ev->id, 'tur' => 'satis', 'miktar' => 640, 'bag_turu' => 'harcama']);
        $this->getJson("/api/v1/hesaplar/{$this->ev->id}")->assertJsonPath('hesap.deger', 1360);
        // Bütçe gideri değişmez
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonPath('gider.toplam', 640);

        // Para ekle / çıkar: hesaba Nakit alış / satışı (kasa hareketi yazılmaz)
        $this->postJson('/api/v1/aile/ev-nakdi/hareketler', ['tur' => 'ekleme', 'tutar' => 1000, 'tarih' => '2026-11-12', 'not' => 'Maaştan'])->assertCreated()
            ->assertJsonPath('bakiye', 2360)->assertJsonPath('harcama', 640);
        $this->postJson('/api/v1/aile/ev-nakdi/hareketler', ['tur' => 'cikarma', 'tutar' => 360, 'tarih' => '2026-11-13'])->assertCreated()
            ->assertJsonPath('bakiye', 2000)->assertJsonPath('hareketler.0.tip', 'cikarma');
        $this->assertSame(0, EvNakdiHareketi::count());
        $this->assertDatabaseHas('islemler', ['hesap_id' => $this->ev->id, 'tur' => 'alis', 'miktar' => 1000, 'not' => 'Ev nakdi: Maaştan']);

        // Yetmeyen harcama ve çıkarma reddedilir, hesap eksiye düşmez
        $this->postJson('/api/v1/aile/duzenli-giderler', $this->evHarcamasi(5000))->assertStatus(422)->assertJsonValidationErrors('tutar');
        $this->postJson('/api/v1/aile/ev-nakdi/hareketler', ['tur' => 'cikarma', 'tutar' => 9000, 'tarih' => '2026-11-13'])->assertStatus(422);

        // Harcama silinince satış da silinir, para hesaba döner
        $this->deleteJson("/api/v1/aile/duzenli-giderler/{$id}")->assertOk();
        $this->getJson('/api/v1/aile/ev-nakdi')->assertJsonPath('bakiye', 2640);
    }

    public function test_bag_kaldirilinca_kasa_doner_hesaptan_dusen_harcama_kasadan_ikinci_kez_dusmez(): void
    {
        $this->actingAs($this->ben);
        $this->putJson('/api/v1/aile/ev-nakdi/hesap', ['hesap_id' => $this->ev->id])->assertOk();
        $this->postJson('/api/v1/aile/duzenli-giderler', $this->evHarcamasi(300))->assertCreated();

        $this->putJson('/api/v1/aile/ev-nakdi/hesap', ['hesap_id' => null])->assertOk()
            ->assertJsonPath('hesap', null)->assertJsonPath('bakiye', 0)->assertJsonPath('harcama', 0)
            ->assertJsonPath('message', 'Ev nakdi ayrı kasaya döndü.');
    }

    public function test_baska_hanenin_hesabi_secilemez(): void
    {
        $yabanci = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($yabanci);
        $onunHesabi = Hesap::factory()->create(['user_id' => $yabanci->id]);

        $this->actingAs($this->ben)->putJson('/api/v1/aile/ev-nakdi/hesap', ['hesap_id' => $onunHesabi->id])
            ->assertStatus(422)->assertJsonValidationErrors('hesap_id');
        // Benim seçimim yabancının ev nakdini etkilemez
        $this->putJson('/api/v1/aile/ev-nakdi/hesap', ['hesap_id' => $this->ev->id])->assertOk();
        $this->actingAs($yabanci)->getJson('/api/v1/aile/ev-nakdi')->assertJsonPath('hesap', null)->assertJsonPath('bakiye', 0);
    }
}
