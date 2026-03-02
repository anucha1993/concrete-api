<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountItem extends Model
{
    protected $fillable = [
        'stock_count_id', 'product_id',
        'expected_qty', 'scanned_qty', 'difference',
        'resolution', 'note',
    ];

    protected function casts(): array
    {
        return [
            'expected_qty' => 'integer',
            'scanned_qty'  => 'integer',
            'difference'   => 'integer',
        ];
    }

    /* ── Relations ── */

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
