<?php

namespace Tests\Feature;

use App\Enums\KartIslemDurumu;
use App\Models\Ekstre;
use App\Models\HaneUyesi;
use App\Models\KartIslemi;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use App\Services\Aile\KartIslemKurallari;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Ekstrenin ayı = son ödeme tarihinin ayı (yoksa kesim ayı): 28.10 kesim + 07.11 son ödeme → Kasım ekstresi. Bütçe
 * kart gideri, İşlemler ekstre dönemi süzgeci, taksit rapor ayı ve tarih düzeltmesi buna uyar.
 */
class EkstreAyiTest extends TestCase
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

    private function elleEkstre(string $kesim, string $sonOdeme, float $borc): int
    {
        return $this->postJson('/api/v1/aile/ekstreler/elle', [
            'kart_id' => $this->kart, 'kesim_tarihi' => $kesim, 'son_odeme_tarihi' => $sonOdeme, 'donem_borcu' => $borc,
        ])->assertCreated()->json('ekstre.id');
    }

    public function test_son_odeme_ayi_ekstrenin_ayidir_butce_ve_islemler_ona_gore(): void
    {
        $id = $this->elleEkstre('2026-10-28', '2026-11-07', 1000);
        $this->getJson("/api/v1/aile/ekstreler/{$id}")->assertOk()
            ->assertJsonPath('ekstre.donem', '2026-11')->assertJsonPath('ekstre.donem_etiketi', 'Kasım 2026');

        // Bütçe: kart gideri son ödeme ayında (Kasım), kesim ayında (Ekim) değil
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonPath('gider.kart_harcamasi', 1000);
        $this->getJson('/api/v1/aile/ozet?ay=2026-10')->assertJsonPath('gider.kart_harcamasi', 0);

        // İşlemler ekstre dönemi süzgeci: Kasım ekstresinin satırları Kasım'da; grup başlığında Kasım
        $this->getJson('/api/v1/aile/kart-islemleri?ay=2026-11&donem_turu=ekstre')->assertOk()
            ->assertJsonCount(1, 'islemler')->assertJsonPath('toplam.kartlar.0.ekstre_donemi', 'Kasım 2026');
        $this->getJson('/api/v1/aile/kart-islemleri?ay=2026-10&donem_turu=ekstre')->assertOk()->assertJsonCount(0, 'islemler');
    }

    public function test_son_odeme_yoksa_kesim_ayi(): void
    {
        $ekstre = new Ekstre(['kesim_tarihi' => '2026-10-28']);
        $this->assertSame('2026-10', $ekstre->ay());
        $this->assertSame('Ekim 2026', $ekstre->donemEtiketi());
        $ekstre->son_odeme_tarihi = '2026-11-07';
        $this->assertSame('2026-11', $ekstre->ay());
    }

    public function test_taksit_rapor_ayi_ekstrenin_ayi_son_odeme_duzeltilince_izler(): void
    {
        $id = $this->elleEkstre('2026-10-28', '2026-11-07', 1000);
        $ekstre = Ekstre::findOrFail($id);
        $taksit = new KartIslemi(['islem_tarihi' => '2026-08-15', 'taksit_no' => 3, 'taksit_sayisi' => 6]);
        $this->assertSame('2026-11', app(KartIslemKurallari::class)->raporDonemi($taksit, $ekstre));

        $satir = KartIslemi::create([
            'hane_id' => $this->ben->hane_id, 'kart_id' => $this->kart, 'ekstre_id' => $id, 'durum' => KartIslemDurumu::Onayli,
            'islem_tarihi' => '2026-08-15', 'aciklama' => 'TEKNOSA', 'tutar' => '250.00', 'tur' => 'harcama',
            'taksit_no' => 3, 'taksit_sayisi' => 6, 'rapor_donemi' => '2026-11',
        ]);
        // Onaylı ekstrenin son ödemesi düzeltilir: ekstre Aralık'a geçer, taksitin rapor ayı da
        $this->putJson("/api/v1/aile/ekstreler/{$id}", ['son_odeme_tarihi' => '2026-12-05'])->assertOk()
            ->assertJsonPath('ekstre.donem', '2026-12');
        $this->assertSame('2026-12', $satir->fresh()->rapor_donemi);
    }
}
