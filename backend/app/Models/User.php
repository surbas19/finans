<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

#[Fillable(['ad', 'soyad', 'email', 'password', 'yonetici', 'aktif'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'yonetici' => 'boolean',
            'aktif' => 'boolean',
            'son_giris' => 'datetime',
            'hane_id' => 'integer',
        ];
    }

    /** @var list<int>|null İstek boyunca bir kez okunur */
    private ?array $haneKullanicilari = null;

    /**
     * Kullanıcının kendi açtığı hesaplar (hesap adı kişi başına tekildir). Görebildiği hesaplar: `Hesap::erisilebilir`.
     *
     * @return HasMany<Hesap, $this>
     */
    public function hesaplar(): HasMany
    {
        return $this->hasMany(Hesap::class)->orderBy('sira')->orderBy('id');
    }

    /**
     * @return BelongsTo<Hane, $this>
     */
    public function hane(): BelongsTo
    {
        return $this->belongsTo(Hane::class);
    }

    /**
     * Kayıtlarını görebildiği kullanıcılar: hanesi varsa hanedeki herkes, yoksa yalnızca kendisi. Hesap / işlem
     * erişiminin tek kaynağı (Policy'ler, sorgular ve doğrulama kuralları bunu kullanır).
     *
     * @return list<int>
     */
    public function haneKullaniciIdleri(): array
    {
        if ($this->hane_id === null) {
            return [$this->id];
        }

        return $this->haneKullanicilari ??= User::where('hane_id', $this->hane_id)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    public function ayniHanede(int $kullaniciId): bool
    {
        return in_array($kullaniciId, $this->haneKullaniciIdleri(), true);
    }

    /** Hane değişince önbellek bırakılır */
    public function haneOnbeleginiBirak(): void
    {
        $this->haneKullanicilari = null;
    }

    /**
     * Profil resminin adresi (yoksa null). Dosya adı her yüklemede değiştiği için adresteki sürüm de değişir;
     * tarayıcı eski resmi önbellekten göstermez. Adres yalnızca kendisi, hanedekiler ve yönetici için açılır.
     */
    public function resimAdresi(): ?string
    {
        if ($this->profil_resmi === null) {
            return null;
        }

        return '/api/v1/kullanicilar/'.$this->id.'/resim?v='.substr(md5($this->profil_resmi), 0, 10);
    }

    /**
     * @return array{id: int, ad: string, soyad: string, ad_soyad: string, email: string, yonetici: bool, resim: ?string}
     */
    public function oturumBilgisi(): array
    {
        return [
            'id' => $this->id,
            'ad' => $this->ad,
            'soyad' => $this->soyad,
            'ad_soyad' => $this->ad.' '.$this->soyad,
            'email' => $this->email,
            'yonetici' => $this->yonetici,
            'resim' => $this->resimAdresi(),
        ];
    }
}
