<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionSerial extends Model
{
    protected $fillable = [
        'production_order_id',
        'production_order_item_id',
        'inventory_id',
        'condition',
    ];

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderItem::class, 'production_order_item_id');
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }
}
