<?php

namespace App\Policies;

use App\Models\Hesap;
use App\Models\User;

/**
 * Hesabı sahibi ve sahibinin hanesindeki kullanıcılar görür ve değiştirir (`User::haneKullaniciIdleri`).
 */
class HesapPolicy
{
    public function view(User $user, Hesap $hesap): bool
    {
        return $user->ayniHanede($hesap->user_id);
    }

    public function update(User $user, Hesap $hesap): bool
    {
        return $user->ayniHanede($hesap->user_id);
    }

    public function delete(User $user, Hesap $hesap): bool
    {
        return $user->ayniHanede($hesap->user_id);
    }
}
