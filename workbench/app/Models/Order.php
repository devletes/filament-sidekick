<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $guarded = [];

    protected $casts = [
        'total' => 'float',
        'refunded' => 'float',
        'placed_at' => 'datetime',
        'shipped_at' => 'datetime',
    ];

    public static function money(float $amount): string
    {
        return '$'.number_format($amount, 2);
    }
}
