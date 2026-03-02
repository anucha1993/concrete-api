<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockDeductionScan extends Model
{
    protected $fillable = [
        'stock_deduction_id',
        'stock_deduction_line_id',
        'inventory_id',
        'serial_number',
        'pda_token_id',
        'scanned_at',
    ];

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
        ];
    }

    public function stockDeduction(): BelongsTo
    {
        return $this->belongsTo(StockDeduction::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(StockDeductionLine::class, 'stock_deduction_line_id');
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
