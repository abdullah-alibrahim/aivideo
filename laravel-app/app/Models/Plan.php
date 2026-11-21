<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'price_minor',
        'currency',
        'interval_days',
        'limits',
        'is_active',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'interval_days' => 'integer',
            'limits' => 'array',
            'is_active' => 'boolean',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
