<?php

namespace Tests\Feature;

use App\Enums\EkstreDurumu;
use App\Enums\KartIslemDurumu;
use App\Enums\KartIslemTuru;
use App\Models\Ekstre;
use App\Models\HaneUyesi;
use App\Models\KartIslemi;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use App\Services\Aile\KartIslemKurallari;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Kart gideri = kesim ayı bu ay olan onaylı ekstrenin dönem borcu (devreden borç, ödeme, bonus… dahil). Kategori
 * dağılımı satırlardan, borçla farkı "ekstredeki diğer"; ekstreye girmemiş elle satırlar ay giderine eklenir.
 */
class KartGideriTest extends TestCase
{
    use RefreshDatabase;

    private User $ben;

    private int $kart;

    private int $uye;

    private int $banka;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($this->ben);
        $this->actingAs($this->ben);
        $this->banka = $this->postJson('/api/v1/aile/bankalar', ['ad' => 'Garanti'])->json('banka.id');
        $this->uye = HaneUyesi::where('user_id', $this->ben->id)->value('id');
        $this->kart = $this->kartEkle('Bonus');
    }

    private function kartEkle(string $ad): int
    {
        return $this->postJson('/api/v1/aile/kartlar', ['banka_id' => $this->banka, 'sahip_uye_id' => $this->uye, 'ad' => $ad])->assertCreated()->json('kart.id');
    }

    private function ekstre(string $kesim, ?string $borc, ?int $kart = null, EkstreDurumu $durum = EkstreDurumu::Onaylandi): Ekstre
    {
        return Ekstre::create([
            'hane_id' => $this->ben->hane_id, 'kart_id' => $kart ?? $this->kart, 'durum' => $durum, 'kesim_tarihi' => $kesim,
            'son_odeme_tarihi' => Carbon::parse($kesim)->addDays(10)->toDateString(), 'donem_borcu' => $borc,
            'onayli_kesim_tarihi' => $durum === EkstreDurumu::Onaylandi ? $kesim : null,
        ]);
    }

    private function satir(Ekstre $ekstre, string $tarih, string $aciklama, string $tutar, KartIslemTuru $tur = KartIslemTuru::Harcama, int $sira = 1): void
    {
        $islem = new KartIslemi([
            'hane_id' => $this->ben->hane_id, 'kart_id' => $ekstre->kart_id, 'ekstre_id' => $ekstre->id, 'sira' => $sira,
            'durum' => KartIslemDurumu::Taslak, 'islem_tarihi' => $tarih, 'aciklama' => $aciklama, 'tutar' => $tutar, 'tur' => $tur,
        ]);
        app(KartIslemKurallari::class)->onayla($islem, $ekstre, 1);
    }

    private function elle(string $tarih, float $tutar, ?int $kart = null, string $aciklama = 'ELLE'): void
    {
        $this->postJson('/api/v1/aile/kart-islemleri', ['kart_id' => $kart ?? $this->kart, 'islem_tarihi' => $tarih, 'aciklama' => $aciklama, 'tutar' => $tutar, 'tur' => 'harcama'])->assertCreated();
    }

    public function test_kart_gideri_ekstre_borcudur_kategori_toplami_gideri_verir(): void
    {
        $ekstre = $this->ekstre('2026-11-10', '5000.00');
        $this->satir($ekstre, '2026-10-15', 'MIGROS KADIKOY', '1000.00', sira: 1);
        $this->satir($ekstre, '2026-10-20', 'HESAPTAN ODEME', '200.00', KartIslemTuru::Odeme, 2);
        $this->satir($ekstre, '2026-10-22', 'BONUS BEDAVA', '60.00', KartIslemTuru::Bonus, 3);

        $ozet = $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertOk()
            ->assertJsonPath('gider.toplam', 5000)->assertJsonPath('gider.kart_harcamasi', 5000)
            ->assertJsonPath('gider.kartlar.0.toplam', 5000)->assertJsonPath('gider.kartlar.0.ekstre.id', $ekstre->id)
            ->assertJsonPath('gider.kartlar.0.ekstre.donem_borcu', 5000)->assertJsonPath('fark', -5000);
        $kategoriler = collect($ozet->json('gider.kategoriler'));
        $this->assertEqualsWithDelta(5000, $kategoriler->sum('toplam'), 0.001);
        $diger = $kategoriler->firstWhere('diger', true);
        $this->assertSame(4000, $diger['toplam']); // borç − satırlar (devreden borç, ödeme, bonus…)
        $this->assertNull($diger['kategori_id']);
        // Ödeme ve bonus ekstre borcunun içinde, ayrıca eklenmez
        $this->assertEqualsCanonicalizing(['odeme', 'bonus'], array_column($ozet->json('gider.haric'), 'tur'));
        // Eski aylar ekstre satırı yüzünden gider almaz
        $this->getJson('/api/v1/aile/ozet?ay=2026-10')->assertJsonPath('gider.toplam', 0);
    }

    public function test_iki_kart_toplanir_ekstresi_baska_ayda_olan_kart_yok_taslak_sayilmaz(): void
    {
        $ikinci = $this->kartEkle('Enpara');
        $uc = $this->kartEkle('Yapı Kredi');
        $this->ekstre('2026-11-10', '5000.00');
        $this->ekstre('2026-11-12', '2500.50', $ikinci);
        $this->ekstre('2026-10-10', '9999.00', $uc);
        $this->ekstre('2026-11-11', '777.00', $uc, EkstreDurumu::Taslak);

        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertOk()->assertJsonPath('gider.toplam', 7500.5)
            ->assertJsonCount(2, 'gider.kartlar');
        $this->getJson('/api/v1/aile/ozet?ay=2026-10')->assertJsonPath('gider.toplam', 9999);
    }

    public function test_elle_satir_ekstreye_girmeden_giderde_ekstre_gelince_ekstrede_sayilir(): void
    {
        // Ekstresi olmayan geçmiş ve bu ayın satırı gidere girer
        $this->elle('2026-05-04', 300);
        $this->elle('2026-11-05', 500);
        $this->getJson('/api/v1/aile/ozet?ay=2026-05')->assertJsonPath('gider.toplam', 300);
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonPath('gider.toplam', 500);

        // 10 Kasım kesimli ekstre gelir: 5 Kasım satırı ekstrenin dönemindedir (35 gün içinde), çift sayılmaz
        $this->ekstre('2026-11-10', '1800.00');
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonPath('gider.toplam', 1800);
        // Ekstreden çok önceki (ekstresi olmayan) satır hâlâ kendi ayında
        $this->getJson('/api/v1/aile/ozet?ay=2026-05')->assertJsonPath('gider.toplam', 300);
        // Ekstre kesiminden sonraki satır (henüz ekstresi yok) ay giderine eklenir
        $this->elle('2026-11-15', 120);
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonPath('gider.toplam', 1920);
    }

    public function test_donem_borcu_okunamadiysa_satirlardan_ve_tutarla_ekstre_borctur(): void
    {
        $ekstre = $this->ekstre('2026-11-10', null);
        $this->satir($ekstre, '2026-11-02', 'SHELL', '900.00', sira: 1);
        $this->satir($ekstre, '2026-11-03', 'IADE', '100.00', KartIslemTuru::Iade, 2);
        $ozet = $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonPath('gider.toplam', 800)->assertJsonPath('gider.kartlar.0.ekstre.donem_borcu', null);
        $this->assertNull(collect($ozet->json('gider.kategoriler'))->firstWhere('diger', true)); // fark yok

        $ikinci = $this->kartEkle('Enpara');
        $this->postJson('/api/v1/aile/ekstreler/elle', ['kart_id' => $ikinci, 'kesim_tarihi' => '2026-11-12', 'son_odeme_tarihi' => '2026-11-22', 'donem_borcu' => 1234.56])->assertCreated();
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonPath('gider.toplam', 2034.56);
    }

    public function test_baska_hane_ekstre_giderini_gormez(): void
    {
        $this->ekstre('2026-11-10', '5000.00');
        $yabanci = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($yabanci);
        $this->actingAs($yabanci);
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonPath('gider.toplam', 0)->assertJsonCount(0, 'gider.kartlar');
    }
}
