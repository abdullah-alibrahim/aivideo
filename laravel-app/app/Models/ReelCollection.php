<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReelCollection extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'type',
        'label',
        'item_count',
    ];

    protected $casts = [
        'item_count' => 'integer',
    ];

    public function reels(): HasMany
    {
        return $this->hasMany(Reel::class, 'collection_id')->orderBy('collection_position');
    }
}
