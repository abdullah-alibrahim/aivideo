<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderSetting extends Model
{
    protected $fillable = [
        'name',
        'value',
    ];

    protected $hidden = [
        'value',
    ];

    protected $casts = [
        'value' => 'encrypted',
    ];
}
