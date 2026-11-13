<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IzlemeKaydi extends Model
{
    protected $table = 'izleme_listesi';

    protected $fillable = ['user_id', 'varlik_id', 'sira'];

    /**
     * @return BelongsTo<Varlik, $this>
     */
    public function varlik(): BelongsTo
    {
        return $this->belongsTo(Varlik::class);
    }
}
