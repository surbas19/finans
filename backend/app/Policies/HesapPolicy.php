<?php

namespace App\Policies;

use App\Models\Hesap;
use App\Models\User;

/**
 * Hesabı yalnızca sahibi görür ve değiştirir.
 */
class HesapPolicy
{
    public function view(User $user, Hesap $hesap): bool
    {
        return $hesap->user_id === $user->id;
    }

    public function update(User $user, Hesap $hesap): bool
    {
        return $hesap->user_id === $user->id;
    }

    public function delete(User $user, Hesap $hesap): bool
    {
        return $hesap->user_id === $user->id;
    }
}
