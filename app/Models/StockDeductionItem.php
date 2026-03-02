<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockDeductionItem extends Model
{
    protected $fillable = [
        'stock_deduction_id',
        'inventory_id',
        'serial_number',
        'product_id',
        'note',
    ];

    public function stockDeduction(): BelongsTo
    {
        return $this->belongsTo(StockDeduction::class);
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
