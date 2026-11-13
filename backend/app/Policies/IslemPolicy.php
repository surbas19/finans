<?php

namespace App\Policies;

use App\Models\Islem;
use App\Models\User;

/**
 * İşlem, durduğu hesaba erişebilen herkesçe değiştirilir / silinir (işlemi kimin girdiğine bakılmaz: eşin benim
 * hesabıma girdiği işlemi ben de düzeltebilirim).
 */
class IslemPolicy
{
    public function update(User $user, Islem $islem): bool
    {
        return $user->ayniHanede((int) $islem->hesap->user_id);
    }

    public function delete(User $user, Islem $islem): bool
    {
        return $user->ayniHanede((int) $islem->hesap->user_id);
    }
}
