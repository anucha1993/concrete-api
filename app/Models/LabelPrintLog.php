<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabelPrintLog extends Model
{
    protected $fillable = [
        'inventory_id',
        'serial_number',
        'print_type',
        'paper_size',
        'reprint_reason',
        'reprint_request_id',
        'printed_by',
        'printed_at',
    ];

    protected function casts(): array
    {
        return [
            'printed_at' => 'datetime',
        ];
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by');
    }

    public function reprintRequest(): BelongsTo
    {
        return $this->belongsTo(LabelReprintRequest::class, 'reprint_request_id');
    }
}
