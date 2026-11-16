<?php

namespace Tests\Feature;

use App\Enums\KartIslemDurumu;
use App\Models\HaneUyesi;
use App\Models\KartIslemi;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Şirket geçmişi (GET aile/kart-islemleri/{islem}/gecmis): aynı açıklamalı (Metin::sade) hanenin bütün kartlarındaki
 * onaylı satırlar, işlem ayına göre ay ay; bağlı iade düşer; başka açıklama ve taslak girmez; hane izolasyonu.
 */
class SirketGecmisiTest extends TestCase
{
    use RefreshDatabase;

    private User $ben;

    /** @var list<int> */
    private array $kartlar = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($this->ben);
        $this->actingAs($this->ben);
        $banka = $this->postJson('/api/v1/aile/bankalar', ['ad' => 'Garanti'])->assertCreated()->json('banka.id');
        $uye = HaneUyesi::where('user_id', $this->ben->id)->value('id');
        foreach (['Bonus', 'Maximum'] as $ad) {
            $this->kartlar[] = $this->postJson('/api/v1/aile/kartlar', ['banka_id' => $banka, 'sahip_uye_id' => $uye, 'ad' => $ad, 'kesim_gunu' => 10, 'son_odeme_gunu' => 20])
                ->assertCreated()->json('kart.id');
        }
    }

    private function islem(array $alanlar): array
    {
        return $this->postJson('/api/v1/aile/kart-islemleri', [
            'kart_id' => $this->kartlar[0], 'islem_tarihi' => '2026-11-05', 'aciklama' => 'MIGROS KADIKOY', 'tutar' => 500, 'tur' => 'harcama', ...$alanlar,
        ])->assertCreated()->json('islem');
    }

    public function test_ayni_sirketin_harcamalari_ay_ay_toplanir(): void
    {
        $ilk = $this->islem([]);
        // Büyük / küçük harf, Türkçe karakter ve boşluk farkı aynı şirket sayılır; öbür kartta da
        $this->islem(['kart_id' => $this->kartlar[1], 'aciklama' => 'Migros  Kadıköy', 'tutar' => 300, 'islem_tarihi' => '2026-11-02']);
        $this->islem(['tutar' => 1200, 'islem_tarihi' => '2026-09-10']);
        // Bağlı iade, açıklaması farklı olsa da düşer
        $this->islem(['aciklama' => 'MIGROS IADE', 'tutar' => 100, 'tur' => 'iade', 'islem_tarihi' => '2026-11-08', 'iade_islem_id' => $ilk['id']]);
        // Başka şirket ve taslak satır girmez
        $this->islem(['aciklama' => 'A101', 'tutar' => 50]);
        KartIslemi::create([
            'hane_id' => $this->ben->hane_id, 'kart_id' => $this->kartlar[0], 'durum' => KartIslemDurumu::Taslak,
            'islem_tarihi' => '2026-11-06', 'aciklama' => 'MIGROS KADIKOY', 'tutar' => '999.00', 'tur' => 'harcama',
        ]);

        $yanit = $this->getJson("/api/v1/aile/kart-islemleri/{$ilk['id']}/gecmis")->assertOk();
        $yanit->assertJsonPath('aciklama', 'MIGROS KADIKOY')
            ->assertJsonPath('toplam', 1900)
            ->assertJsonPath('adet', 4)
            ->assertJsonPath('ilk_donem', '2026-09')
            ->assertJsonPath('bu_ay', 700)
            // Eylül–Kasım 3 ay (Ekim boş da sayılır)
            ->assertJsonPath('aylik_ortalama', 633.33)
            ->assertJsonCount(2, 'aylar')
            ->assertJsonPath('aylar.0.donem', '2026-11')
            ->assertJsonPath('aylar.0.etiket', 'Kasım 2026')
            ->assertJsonPath('aylar.0.toplam', 700)
            ->assertJsonPath('aylar.0.adet', 3)
            ->assertJsonPath('aylar.1.donem', '2026-09')
            ->assertJsonPath('aylar.1.toplam', 1200);
        // Ay içinde yeniden eskiye; iade eksi etkiyle
        $kasim = collect($yanit->json('aylar.0.islemler'));
        $this->assertSame(['2026-11-08', '2026-11-05', '2026-11-02'], $kasim->pluck('islem_tarihi')->all());
        $this->assertEquals(-100, $kasim->firstWhere('tur', 'iade')['etki']);
        $this->assertSame([$this->kartlar[0], $this->kartlar[0], $this->kartlar[1]], $kasim->pluck('kart_id')->all());
    }

    public function test_baska_hane_goremez(): void
    {
        $islem = $this->islem([]);
        $baska = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($baska);
        $this->actingAs($baska)->getJson("/api/v1/aile/kart-islemleri/{$islem['id']}/gecmis")->assertForbidden();
    }
}
