<?php

namespace Tests\Feature;

use App\Models\KurtarmaKodu;
use App\Models\User;
use App\Notifications\SifreSifirlamaBildirimi;
use App\Services\KurtarmaKodlari;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Şifremi unuttum: e-postayla bağlantı (herkes), yönetici kurtarma kodları; sabit yanıtlar (hesap sızdırılmaz), tek
 * kullanımlık jeton / kod, oturum + "hatırla" kapanması, istek sınırı, oturum sürekliliği.
 */
class SifreSifirlamaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost'], 'mail.default' => 'smtp']);
        $this->withHeader('Origin', 'http://localhost');
    }

    /** Sanctum, önceki istekte varsayılan korumayı değiştirir; testte oturum durumu her seferinde web'e döndürülür */
    private function girisYap(User $user): void
    {
        $this->app['auth']->shouldUse('web');
        $this->actingAs($user, 'web');
    }

    private function cikisYap(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
    }

    public function test_baglanti_epostasi_gider_kayitsiz_ve_pasif_icin_ayni_yanit_gitmez(): void
    {
        Notification::fake();
        $kayitli = User::factory()->create(['email' => 'ben@ornek.test']);
        $pasif = User::factory()->create(['email' => 'pasif@ornek.test', 'aktif' => false]);

        $a = $this->postJson('/api/v1/sifre-unuttum', ['email' => 'BEN@ornek.test'])->assertOk()->json('message');
        $b = $this->postJson('/api/v1/sifre-unuttum', ['email' => 'yok@ornek.test'])->assertOk()->json('message');
        $c = $this->postJson('/api/v1/sifre-unuttum', ['email' => 'pasif@ornek.test'])->assertOk()->json('message');
        $this->assertSame($a, $b);
        $this->assertSame($a, $c);
        Notification::assertSentTo($kayitli, SifreSifirlamaBildirimi::class);
        Notification::assertNotSentTo($pasif, SifreSifirlamaBildirimi::class);
        Notification::assertCount(1);
    }

    public function test_eposta_ayari_yoksa_503_sahte_gonderildi_denmez(): void
    {
        config(['mail.default' => 'log']);
        $this->postJson('/api/v1/sifre-unuttum', ['email' => 'ben@ornek.test'])->assertStatus(503);
    }

    public function test_jetonla_sifre_degisir_oturum_ve_hatirla_kapanir_jeton_tek_kullanimlik(): void
    {
        $user = User::factory()->create(['email' => 'ben@ornek.test']);
        DB::table('sessions')->insert(['id' => 'eski', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $eskiToken = $user->remember_token;
        $jeton = Password::broker()->createToken($user);

        $govde = ['email' => 'ben@ornek.test', 'token' => $jeton, 'yeni_sifre' => 'YeniSifre#2026x', 'yeni_sifre_confirmation' => 'YeniSifre#2026x'];
        $this->postJson('/api/v1/sifre-sifirla', $govde)->assertOk();
        $user->refresh();
        $this->assertTrue(Hash::check('YeniSifre#2026x', $user->password));
        $this->assertNotSame($eskiToken, $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'eski']);
        // Aynı jeton ikinci kez kullanılamaz
        $this->postJson('/api/v1/sifre-sifirla', $govde)->assertStatus(422)->assertJsonValidationErrors('email');
        // Yeni şifreyle giriş olur
        $this->postJson('/api/v1/giris', ['email' => 'ben@ornek.test', 'sifre' => 'YeniSifre#2026x'])->assertOk();
    }

    public function test_hatali_jeton_zayif_sifre_ve_pasif_kullanici_reddedilir(): void
    {
        $user = User::factory()->create(['email' => 'ben@ornek.test']);
        $jeton = Password::broker()->createToken($user);
        $this->postJson('/api/v1/sifre-sifirla', ['email' => 'ben@ornek.test', 'token' => 'yanlis', 'yeni_sifre' => 'YeniSifre#2026x', 'yeni_sifre_confirmation' => 'YeniSifre#2026x'])->assertStatus(422);
        $this->postJson('/api/v1/sifre-sifirla', ['email' => 'ben@ornek.test', 'token' => $jeton, 'yeni_sifre' => '123', 'yeni_sifre_confirmation' => '123'])->assertStatus(422)->assertJsonValidationErrors('yeni_sifre');
        $this->postJson('/api/v1/sifre-sifirla', ['email' => 'ben@ornek.test', 'token' => $jeton, 'yeni_sifre' => 'YeniSifre#2026x', 'yeni_sifre_confirmation' => 'baska'])->assertStatus(422);

        $user->forceFill(['aktif' => false])->save();
        $this->postJson('/api/v1/sifre-sifirla', ['email' => 'ben@ornek.test', 'token' => $jeton, 'yeni_sifre' => 'YeniSifre#2026x', 'yeni_sifre_confirmation' => 'YeniSifre#2026x'])->assertStatus(422);
        $this->assertFalse(Hash::check('YeniSifre#2026x', $user->fresh()->password));
    }

    public function test_istek_siniri(): void
    {
        Notification::fake();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/sifre-sifirla', ['email' => 'ben@ornek.test', 'token' => 'x', 'yeni_sifre' => 'YeniSifre#2026x', 'yeni_sifre_confirmation' => 'YeniSifre#2026x']);
        }
        $this->postJson('/api/v1/sifre-sifirla', ['email' => 'ben@ornek.test', 'token' => 'x', 'yeni_sifre' => 'YeniSifre#2026x', 'yeni_sifre_confirmation' => 'YeniSifre#2026x'])->assertStatus(429);
    }

    public function test_kurtarma_kodlari_uretilir_duz_kod_saklanmaz_eskisi_gecersiz_sifre_ister(): void
    {
        $yonetici = User::factory()->create(['yonetici' => true]);
        $this->girisYap($yonetici);
        $this->postJson('/api/v1/ben/kurtarma-kodlari', ['sifre' => 'yanlis'])->assertStatus(422)->assertJsonValidationErrors('sifre');

        $ilk = $this->postJson('/api/v1/ben/kurtarma-kodlari', ['sifre' => 'password'])->assertCreated()->json('kodlar');
        $this->assertCount(8, $ilk);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}(-[A-Z2-9]{4}){3}$/', $ilk[0]);
        $this->assertSame(8, KurtarmaKodu::count());
        $this->assertDatabaseMissing('kurtarma_kodlari', ['ozet' => $ilk[0]]);
        $this->getJson('/api/v1/ben/kurtarma-kodlari')->assertOk()->assertJsonPath('kalan', 8);
        // Düz kod hiçbir yanıtta tekrar görünmez
        $this->assertStringNotContainsString($ilk[0], $this->getJson('/api/v1/ben/kurtarma-kodlari')->getContent());

        $yeni = $this->postJson('/api/v1/ben/kurtarma-kodlari', ['sifre' => 'password'])->assertCreated()->json('kodlar');
        $this->assertSame(8, KurtarmaKodu::count());
        $this->assertNotSame($ilk, $yeni);
        // Eski kod artık geçersiz
        $this->cikisYap();
        $this->postJson('/api/v1/sifre-sifirla/kodla', ['email' => $yonetici->email, 'kod' => $ilk[0], 'yeni_sifre' => 'YeniSifre#2026x', 'yeni_sifre_confirmation' => 'YeniSifre#2026x'])
            ->assertStatus(422)->assertJsonValidationErrors('kod');
    }

    public function test_kodla_sifirlama_tek_kullanimlik_oturum_kapanir_ve_kullanim_kaydedilir(): void
    {
        $yonetici = User::factory()->create(['yonetici' => true, 'email' => 'yon@ornek.test']);
        $this->girisYap($yonetici);
        $kodlar = $this->postJson('/api/v1/ben/kurtarma-kodlari', ['sifre' => 'password'])->json('kodlar');
        $this->cikisYap();
        DB::table('sessions')->insert(['id' => 'eski', 'user_id' => $yonetici->id, 'payload' => '', 'last_activity' => time()]);

        $govde = fn (string $kod): array => ['email' => 'yon@ornek.test', 'kod' => $kod, 'yeni_sifre' => 'YeniSifre#2026x', 'yeni_sifre_confirmation' => 'YeniSifre#2026x'];
        // Küçük harf / biçimsiz yazım da kabul edilir
        $this->postJson('/api/v1/sifre-sifirla/kodla', $govde(strtolower(str_replace('-', ' ', $kodlar[0]))))->assertOk();
        $yonetici->refresh();
        $this->assertTrue(Hash::check('YeniSifre#2026x', $yonetici->password));
        $this->assertNotNull($yonetici->kurtarma_kullanildi_at);
        $this->assertDatabaseMissing('sessions', ['id' => 'eski']);
        // Aynı kod ikinci kez olmaz, ikincisi hâlâ geçerli
        $this->postJson('/api/v1/sifre-sifirla/kodla', $govde($kodlar[0]))->assertStatus(422);
        $this->postJson('/api/v1/sifre-sifirla/kodla', $govde($kodlar[1]))->assertOk();
        $this->assertSame(6, KurtarmaKodu::where('user_id', $yonetici->id)->whereNull('kullanildi_at')->count());
    }

    public function test_kodla_sifirlama_yalniz_etkin_yonetici_icin_ve_hata_mesaji_sabit(): void
    {
        $yonetici = User::factory()->create(['yonetici' => true, 'email' => 'yon@ornek.test']);
        $this->girisYap($yonetici);
        $kod = $this->postJson('/api/v1/ben/kurtarma-kodlari', ['sifre' => 'password'])->json('kodlar.0');
        $this->cikisYap();
        $normal = User::factory()->create(['email' => 'normal@ornek.test']);
        KurtarmaKodu::create(['user_id' => $normal->id, 'ozet' => app(KurtarmaKodlari::class)->ozet('AAAA-BBBB-CCCC-DDDD'), 'created_at' => now()]);

        $hata = fn (array $a): string => $this->postJson('/api/v1/sifre-sifirla/kodla', [...$a, 'yeni_sifre' => 'YeniSifre#2026x', 'yeni_sifre_confirmation' => 'YeniSifre#2026x'])
            ->assertStatus(422)->json('errors.kod.0');
        $a = $hata(['email' => 'normal@ornek.test', 'kod' => 'AAAA-BBBB-CCCC-DDDD']);
        $b = $hata(['email' => 'yok@ornek.test', 'kod' => $kod]);
        $c = $hata(['email' => 'yon@ornek.test', 'kod' => 'YANLIS-KOD']);
        $this->assertSame($a, $b);
        $this->assertSame($a, $c);

        $yonetici->forceFill(['aktif' => false])->save();
        $hata(['email' => 'yon@ornek.test', 'kod' => $kod]);
        // Yönetici olmayan kod üretemez / sayısını göremez
        $this->girisYap($normal);
        $this->getJson('/api/v1/ben/kurtarma-kodlari')->assertForbidden();
        $this->postJson('/api/v1/ben/kurtarma-kodlari', ['sifre' => 'password'])->assertForbidden();
    }

    public function test_giris_her_zaman_hatirla_cerezi_verir_pasif_kullanici_iceri_alinmaz(): void
    {
        $user = User::factory()->create(['email' => 'ben@ornek.test']);
        $yanit = $this->postJson('/api/v1/giris', ['email' => 'ben@ornek.test', 'sifre' => 'password'])->assertOk();
        $this->assertNotNull($user->fresh()->remember_token, 'hatırla jetonu yazıldı');
        $this->assertTrue(collect($yanit->headers->getCookies())->contains(fn ($c) => str_starts_with($c->getName(), 'remember_web_')));

        // Hesabı kapatılan kullanıcı (hatırla çerezi geçerli olsa da) içeri alınmaz
        $this->cikisYap();
        $this->girisYap($user);
        $this->getJson('/api/v1/ben')->assertOk();
        $user->forceFill(['aktif' => false])->save();
        $this->getJson('/api/v1/ben')->assertStatus(401);
        $this->assertSame(525600, config('session.lifetime'));
    }
}
