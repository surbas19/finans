<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\ProfilResmiController;
use App\Models\Hesap;
use App\Models\User;
use App\Services\Aile\HaneYonetimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Profil resmi: özel diskte; kendisi, aynı hanedekiler ve yönetici görür, başkası göremez (404).
 */
class ProfilResmiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(ProfilResmiController::DISK);
    }

    private function yukle(User $user, UploadedFile $dosya): TestResponse
    {
        return $this->actingAs($user)->post('/api/v1/ben/resim', ['resim' => $dosya], ['Accept' => 'application/json']);
    }

    public function test_yukle_degistir_ve_kaldir(): void
    {
        $ben = User::factory()->create();
        $this->actingAs($ben)->getJson('/api/v1/ben')->assertJsonPath('kullanici.resim', null);

        $adres = $this->yukle($ben, UploadedFile::fake()->image('ben.webp', 256, 256))
            ->assertOk()->json('kullanici.resim');
        $this->assertStringStartsWith("/api/v1/kullanicilar/{$ben->id}/resim?v=", $adres);
        $ilk = $ben->fresh()->profil_resmi;
        $this->assertMatchesRegularExpression("#^profil/{$ben->id}-[A-Za-z0-9]{20}\\.webp$#", $ilk);
        Storage::disk(ProfilResmiController::DISK)->assertExists($ilk);
        $this->actingAs($ben)->getJson('/api/v1/ben')->assertJsonPath('kullanici.resim', $adres);

        $yanit = $this->actingAs($ben)->get($adres)->assertOk();
        $this->assertSame('image/webp', $yanit->headers->get('Content-Type'));
        $this->assertStringContainsString('private', (string) $yanit->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $yanit->headers->get('X-Content-Type-Options'));

        // Yeni resim: adres değişir, eski dosya silinir
        $yeni = $this->yukle($ben, UploadedFile::fake()->image('yeni.jpg', 300, 300))->assertOk()->json('kullanici.resim');
        $this->assertNotSame($adres, $yeni);
        Storage::disk(ProfilResmiController::DISK)->assertMissing($ilk);
        $this->assertStringEndsWith('.jpg', $ben->fresh()->profil_resmi);

        // Kaldır
        $son = $ben->fresh()->profil_resmi;
        $this->actingAs($ben)->deleteJson('/api/v1/ben/resim')->assertOk()->assertJsonPath('kullanici.resim', null);
        Storage::disk(ProfilResmiController::DISK)->assertMissing($son);
        $this->actingAs($ben)->get("/api/v1/kullanicilar/{$ben->id}/resim")->assertNotFound();
    }

    public function test_resim_olmayan_ya_da_uygunsuz_dosya_reddedilir(): void
    {
        $ben = User::factory()->create();
        $svg = UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"></svg>');
        $this->yukle($ben, $svg)->assertJsonValidationErrors('resim');
        $this->yukle($ben, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'))->assertJsonValidationErrors('resim');
        $this->yukle($ben, UploadedFile::fake()->image('kucuk.png', 16, 16))->assertJsonValidationErrors('resim');
        $this->yukle($ben, UploadedFile::fake()->image('buyuk.png', 3000, 3000))->assertJsonValidationErrors('resim');
        $this->yukle($ben, UploadedFile::fake()->image('agir.png', 256, 256)->size(1500))->assertJsonValidationErrors('resim');
        $this->assertNull($ben->fresh()->profil_resmi);
        $this->assertSame([], Storage::disk(ProfilResmiController::DISK)->allFiles());
    }

    public function test_resmi_kendisi_hanesi_ve_yonetici_gorur_baskasi_goremez(): void
    {
        [$ben, $es, $yabanci] = User::factory()->count(3)->create()->all();
        $yonetici = User::factory()->yonetici()->create();
        $haneler = app(HaneYonetimi::class);
        $haneler->tasi($es, $haneler->hazirla($ben));
        $adres = $this->yukle($ben, UploadedFile::fake()->image('ben.png', 128, 128))->json('kullanici.resim');

        $this->actingAs($es)->get($adres)->assertOk();
        $this->actingAs($yonetici)->get($adres)->assertOk();
        $this->actingAs($yabanci)->get($adres)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson($adres)->assertUnauthorized();

        // Eş, hesabın sahibini ve hane üyelerini resmiyle görür; yönetim listesinde de var
        Hesap::factory()->create(['user_id' => $ben->id]);
        $this->actingAs($es)->getJson('/api/v1/hesaplar')->assertJsonPath('hesaplar.0.sahip_resim', $adres);
        $uyeler = collect($this->actingAs($es)->getJson('/api/v1/aile/hane')->assertOk()->json('uyeler'));
        $this->assertSame($adres, $uyeler->firstWhere('user_id', $ben->id)['resim']);
        $this->assertNull($uyeler->firstWhere('user_id', $es->id)['resim']);
        $listede = collect($this->actingAs($yonetici)->getJson('/api/v1/yonetim/kullanicilar')->json('kullanicilar'))->firstWhere('id', $ben->id);
        $this->assertSame($adres, $listede['resim']);

        // Başkası resim yükleyemez / silemez: uç yalnızca kendi resmine yazar
        $this->actingAs($yabanci)->deleteJson('/api/v1/ben/resim')->assertOk();
        $this->actingAs($es)->get($adres)->assertOk();
    }
}
