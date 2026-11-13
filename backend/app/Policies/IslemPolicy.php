<?php

namespace App\Policies;

use App\Models\Islem;
use App\Models\User;

/**
 * İşlemi yalnızca sahibi değiştirir / siler.
 */
class IslemPolicy
{
    public function update(User $user, Islem $islem): bool
    {
        return $islem->user_id === $user->id;
    }

    public function delete(User $user, Islem $islem): bool
    {
        return $islem->user_id === $user->id;
    }
}
