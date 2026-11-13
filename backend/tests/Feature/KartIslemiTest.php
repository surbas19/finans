<?php

namespace Tests\Feature;

use App\Enums\EkstreDurumu;
use App\Enums\KartIslemDurumu;
use App\Enums\KartIslemTuru;
use App\Models\Ekstre;
use App\Models\HaneUyesi;
use App\Models\HarcamaKategorisi;
use App\Models\KartIslemi;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use App\Services\Aile\KartIslemKurallari;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Kart işlemleri ve gider kuralları: kart borcu ödemesi / transfer / nakit avans gider değildir; iade düşer;
 * taksit ekstrenin ayına; hane izolasyonu.
 */
class KartIslemiTest extends TestCase
{
    use RefreshDatabase;

    private User $ben;

    private int $kart;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->ben = User::factory()->create();
        app(HaneYonetimi::class)->hazirla($this->ben);
        $this->actingAs($this->ben);
        $banka = $this->postJson('/api/v1/aile/bankalar', ['ad' => 'Garanti'])->assertCreated()->json('banka.id');
        $uye = HaneUyesi::where('user_id', $this->ben->id)->value('id');
        $this->kart = $this->postJson('/api/v1/aile/kartlar', ['banka_id' => $banka, 'sahip_uye_id' => $uye, 'ad' => 'Bonus', 'kesim_gunu' => 10, 'son_odeme_gunu' => 20])
            ->assertCreated()->json('kart.id');
    }

    private function islem(array $alanlar): array
    {
        return $this->postJson('/api/v1/aile/kart-islemleri', [
            'kart_id' => $this->kart, 'islem_tarihi' => '2026-11-05', 'aciklama' => 'MIGROS KADIKOY', 'tutar' => 500, 'tur' => 'harcama', ...$alanlar,
        ])->assertCreated()->json('islem');
    }

    public function test_kart_borcu_odemesi_transfer_avans_gider_degil_iade_duser(): void
    {
        $market = $this->islem([]);
        $iade = $this->islem(['aciklama' => 'MIGROS KADIKOY IADE', 'tutar' => 100, 'tur' => 'iade', 'islem_tarihi' => '2026-11-08']);
        $this->islem(['aciklama' => 'HESAPTAN ODEME', 'tutar' => 2000, 'tur' => 'odeme']);
        $this->islem(['aciklama' => 'VIRMAN', 'tutar' => 300, 'tur' => 'transfer']);
        $this->islem(['aciklama' => 'NAKIT AVANS', 'tutar' => 1000, 'tur' => 'nakit_avans']);
        $this->islem(['aciklama' => 'KKDF', 'tutar' => 50.1, 'tur' => 'ucret']);
        $this->islem(['aciklama' => 'TEKNOSA', 'tutar' => 250.25, 'tur' => 'harcama', 'taksit_no' => 1, 'taksit_sayisi' => 6]);

        // Market kuralıyla kategori; iade asıl harcamaya bağlanır
        $marketKategori = HarcamaKategorisi::where('ad', 'Market')->value('id');
        $this->assertSame($marketKategori, $market['kategori_id']);
        $this->assertSame($market['id'], $iade['iade_islem_id']);

        $ozet = $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertOk();
        // 500 − 100 + 50,10 + 250,25 (ödeme 2000, transfer 300, avans 1000 sayılmaz)
        $ozet->assertJsonPath('gider.toplam', 700.35)->assertJsonPath('fark', -700.35);
        $kategoriler = collect($ozet->json('gider.kategoriler'))->keyBy('ad');
        $this->assertSame(400, $kategoriler['Market']['toplam']); // iade kendi kategorisi yoksa asıl harcamanınkinden düşer
        $this->assertSame(300.35, $kategoriler['Kategorisiz']['toplam']);
        $haric = collect($ozet->json('gider.haric'))->pluck('toplam', 'tur');
        $this->assertSame(['odeme' => 2000, 'transfer' => 300, 'nakit_avans' => 1000], $haric->all());
        $ozet->assertJsonPath('gider.taksitler.0.aya_dusen', 250.25)
            ->assertJsonPath('gider.taksitler.0.toplam_borc', 1501.5)
            ->assertJsonPath('gider.taksitler.0.toplam_tahmini', true);

        // Liste toplamı aynı kuralla
        $this->getJson('/api/v1/aile/kart-islemleri?ay=2026-11')->assertOk()
            ->assertJsonPath('toplam.gider', 700.35)->assertJsonPath('toplam.gidere_girmeyen', 3300)->assertJsonPath('toplam.adet', 7);
        $this->getJson('/api/v1/aile/kart-islemleri?ay=2026-11&tur=iade')->assertJsonPath('toplam.gider', -100);
        $this->getJson('/api/v1/aile/kart-islemleri?ay=2026-11&kategori_id=yok')->assertJsonCount(5, 'islemler');
        // İade de "MIGROS" geçtiği için kuralla Market'e girdi: alış + iade
        $this->getJson("/api/v1/aile/kart-islemleri?ay=2026-11&kategori_id={$marketKategori}")->assertJsonCount(2, 'islemler')
            ->assertJsonPath('toplam.gider', 400);
    }

    public function test_taksit_ekstrenin_ayina_islem_tarihi_degil(): void
    {
        $ekstre = Ekstre::create(['hane_id' => $this->ben->hane_id, 'kart_id' => $this->kart, 'durum' => EkstreDurumu::Onaylandi, 'kesim_tarihi' => '2026-11-10']);
        $taksit = new KartIslemi([
            'hane_id' => $this->ben->hane_id, 'kart_id' => $this->kart, 'ekstre_id' => $ekstre->id, 'durum' => KartIslemDurumu::Taslak,
            'islem_tarihi' => '2026-09-15', 'aciklama' => 'BUYUK MAGAZA', 'tutar' => '1000.00', 'tur' => KartIslemTuru::Harcama,
            'taksit_no' => 3, 'taksit_sayisi' => 6, 'taksit_toplam' => '6000.00',
        ]);
        app(KartIslemKurallari::class)->onayla($taksit, $ekstre, 1);
        $this->assertSame('2026-11', $taksit->rapor_donemi);

        $this->getJson('/api/v1/aile/ozet?ay=2026-09')->assertJsonPath('gider.toplam', 0);
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonPath('gider.toplam', 1000)
            ->assertJsonPath('gider.taksitler.0.toplam_borc', 6000)->assertJsonPath('gider.taksitler.0.toplam_tahmini', false);
        // İşlem ayına göre süzgeç Eylül'de, ekstre ayına göre Kasım'da bulur
        $this->getJson('/api/v1/aile/kart-islemleri?ay=2026-09&donem_turu=islem')->assertJsonCount(1, 'islemler');
        $this->getJson('/api/v1/aile/kart-islemleri?ay=2026-11&donem_turu=ekstre')->assertJsonCount(1, 'islemler')
            ->assertJsonPath('islemler.0.ekstre_donemi', 'Kasım 2026');
        $this->getJson('/api/v1/aile/kart-islemleri?ay=2026-11&donem_turu=islem')->assertJsonCount(0, 'islemler');
    }

    public function test_ekstre_satiri_silinmez_disarida_birakilir_elle_satir_silinir(): void
    {
        $elle = $this->islem([]);
        // Aynı satır elle iki kez girilebilir (iki ayrı kahve)
        $this->islem([]);
        $this->assertSame(2, KartIslemi::where('aciklama', 'MIGROS KADIKOY')->count());
        $this->deleteJson("/api/v1/aile/kart-islemleri/{$elle['id']}")->assertOk();
        $this->assertSame(1, KartIslemi::count());

        $ekstre = Ekstre::create(['hane_id' => $this->ben->hane_id, 'kart_id' => $this->kart, 'durum' => EkstreDurumu::Onaylandi, 'kesim_tarihi' => '2026-11-10']);
        $satir = new KartIslemi(['hane_id' => $this->ben->hane_id, 'kart_id' => $this->kart, 'ekstre_id' => $ekstre->id, 'durum' => KartIslemDurumu::Taslak,
            'islem_tarihi' => '2026-11-02', 'aciklama' => 'SHELL', 'tutar' => '900.00', 'tur' => KartIslemTuru::Harcama]);
        app(KartIslemKurallari::class)->onayla($satir, $ekstre, 1);
        $this->deleteJson("/api/v1/aile/kart-islemleri/{$satir->id}")->assertOk()->assertJsonPath('islem.durum', 'atlandi');
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonPath('gider.toplam', 500);

        // Düzeltme: tür ödeme yapılınca gider düşer
        $kalan = KartIslemi::where('aciklama', 'MIGROS KADIKOY')->first();
        $this->putJson("/api/v1/aile/kart-islemleri/{$kalan->id}", ['kart_id' => $this->kart, 'islem_tarihi' => '2026-11-05', 'aciklama' => 'MIGROS KADIKOY', 'tutar' => 500, 'tur' => 'odeme'])
            ->assertOk();
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonPath('gider.toplam', 0);
    }

    public function test_kart_bilgisi_ve_silme_kurallari(): void
    {
        $banka = $this->postJson('/api/v1/aile/bankalar', ['ad' => 'Yapı Kredi'])->json('banka.id');
        $uye = HaneUyesi::where('user_id', $this->ben->id)->value('id');
        $this->postJson('/api/v1/aile/kartlar', ['banka_id' => $banka, 'sahip_uye_id' => $uye, 'ad' => '4543 1234 5678 9012'])->assertJsonValidationErrors('ad');
        $this->postJson('/api/v1/aile/kartlar', ['banka_id' => $banka, 'sahip_uye_id' => $uye, 'ad' => 'World', 'kesim_gunu' => 32])->assertJsonValidationErrors('kesim_gunu');
        $this->islem([]);
        $this->deleteJson("/api/v1/aile/kartlar/{$this->kart}")->assertJsonValidationErrors('kart');
        $bankaId = KartIslemi::first()->kart->banka_id;
        $this->deleteJson("/api/v1/aile/bankalar/{$bankaId}")->assertJsonValidationErrors('banka');
        $this->postJson('/api/v1/aile/kart-islemleri', ['kart_id' => $this->kart, 'islem_tarihi' => '2026-11-20', 'aciklama' => 'X', 'tutar' => 1, 'tur' => 'harcama'])
            ->assertJsonValidationErrors('islem_tarihi');
        $this->postJson('/api/v1/aile/kart-islemleri', ['kart_id' => $this->kart, 'islem_tarihi' => '2026-11-02', 'aciklama' => 'X', 'tutar' => 1, 'tur' => 'harcama', 'taksit_no' => 4, 'taksit_sayisi' => 3])
            ->assertJsonValidationErrors('taksit_no');
    }

    public function test_son_odeme_uyarisi(): void
    {
        Ekstre::create(['hane_id' => $this->ben->hane_id, 'kart_id' => $this->kart, 'durum' => EkstreDurumu::Onaylandi, 'kesim_tarihi' => '2026-11-06', 'son_odeme_tarihi' => '2026-11-16', 'donem_borcu' => '4500.00']);
        Ekstre::create(['hane_id' => $this->ben->hane_id, 'kart_id' => $this->kart, 'durum' => EkstreDurumu::Onaylandi, 'kesim_tarihi' => '2026-10-06', 'son_odeme_tarihi' => '2026-10-16', 'odendi' => true]);
        $this->getJson('/api/v1/aile/ozet')->assertOk()
            ->assertJsonCount(1, 'uyarilar.son_odemeler')
            ->assertJsonPath('uyarilar.son_odemeler.0.gun', 3)
            ->assertJsonPath('uyarilar.son_odemeler.0.kart_ad', 'Bonus')
            ->assertJsonPath('uyarilar.son_odemeler.0.donem_borcu', 4500);
    }

    public function test_baska_hane_kartlari_ve_islemleri_goremez(): void
    {
        $islem = $this->islem([]);
        $bankaId = KartIslemi::first()->kart->banka_id;
        $kategori = HarcamaKategorisi::where('hane_id', $this->ben->hane_id)->first();

        $yabanci = User::factory()->create();
        $this->actingAs($yabanci);
        $this->getJson('/api/v1/aile/kartlar')->assertOk()->assertJsonCount(0, 'kartlar');
        $this->getJson('/api/v1/aile/kart-islemleri?ay=2026-11')->assertOk()->assertJsonCount(0, 'islemler');
        $this->getJson('/api/v1/aile/ozet?ay=2026-11')->assertJsonPath('gider.toplam', 0);
        $this->getJson("/api/v1/aile/kartlar/{$this->kart}")->assertForbidden();
        $this->putJson("/api/v1/aile/bankalar/{$bankaId}", ['ad' => 'X'])->assertForbidden();
        $this->deleteJson("/api/v1/aile/kart-islemleri/{$islem['id']}")->assertForbidden();
        $this->putJson("/api/v1/aile/kategoriler/{$kategori->id}", ['ad' => 'X'])->assertForbidden();
        $this->postJson("/api/v1/aile/kategoriler/{$kategori->id}/kurallar", ['desen' => 'X'])->assertForbidden();
        $this->postJson('/api/v1/aile/kart-islemleri', ['kart_id' => $this->kart, 'islem_tarihi' => '2026-11-02', 'aciklama' => 'X', 'tutar' => 1, 'tur' => 'harcama'])
            ->assertJsonValidationErrors('kart_id');
        // Kendi hanesinin varsayılan kategorileri ayrı
        $this->getJson('/api/v1/aile/kategoriler')->assertOk()->assertJsonCount(10, 'kategoriler');
        $this->assertSame(1, KartIslemi::count());
    }
}
