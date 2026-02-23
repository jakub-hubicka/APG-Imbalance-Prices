<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImbalancePrice extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'imbalance_prices';

    // Vždycky unikátní čas, EIC, měna, source
    protected $primaryKey = ['time', 'eic', 'currency', 'source'];

    protected $fillable = [
        'time',
        'eic',
        'price_up',
        'price_down',
        'currency',
        'unit',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'time' => 'datetime',
            'price_up' => 'decimal:3',
            'price_down' => 'decimal:3',
        ];
    }
}
