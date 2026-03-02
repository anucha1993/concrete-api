<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionOrderItem extends Model
{
    protected $fillable = [
        'production_order_id',
        'product_id',
        'planned_qty',
        'good_qty',
        'damaged_qty',
    ];

    protected function casts(): array
    {
        return [
            'planned_qty'  => 'integer',
            'good_qty'     => 'integer',
            'damaged_qty'  => 'integer',
        ];
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function serials(): HasMany
    {
        return $this->hasMany(ProductionSerial::class);
    }
}
