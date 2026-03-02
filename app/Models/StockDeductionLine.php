<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockDeductionLine extends Model
{
    protected $fillable = [
        'stock_deduction_id',
        'product_id',
        'quantity',
        'scanned_qty',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity'    => 'integer',
            'scanned_qty' => 'integer',
        ];
    }

    public function stockDeduction(): BelongsTo
    {
        return $this->belongsTo(StockDeduction::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(StockDeductionScan::class);
    }
}
