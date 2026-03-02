<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountScan extends Model
{
    protected $fillable = [
        'stock_count_id', 'serial_number', 'product_id', 'inventory_id',
        'pda_token_id', 'is_expected', 'is_duplicate', 'scanned_at',
        'resolution', 'resolution_product_id', 'resolution_location_id',
    ];

    protected function casts(): array
    {
        return [
            'is_expected'  => 'boolean',
            'is_duplicate' => 'boolean',
            'scanned_at'   => 'datetime',
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

    public function resolutionProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'resolution_product_id');
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    public function pdaToken(): BelongsTo
    {
        return $this->belongsTo(PdaToken::class);
    }
}
