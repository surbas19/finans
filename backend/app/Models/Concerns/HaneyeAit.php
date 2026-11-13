<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Hane kaydı (`hane_id` taşır). Listeler `->hanenin($user)` ile süzülür; tek kayıtta `Gate::authorize('hane-kaydi')`.
 */
trait HaneyeAit
{
    /**
     * @param  Builder<static>  $sorgu
     */
    public function scopeHanenin(Builder $sorgu, User $user): void
    {
        // Hanesi olmayan kullanıcı hiçbir aile kaydını görmez (Bütçe'ye girince hanesi kurulur)
        $sorgu->where($this->getTable().'.hane_id', $user->hane_id ?? 0);
    }
}
