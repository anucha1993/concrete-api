<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LabelReprintRequest extends Model
{
    protected $fillable = [
        'status',
        'reason',
        'reject_reason',
        'requested_by',
        'approved_by',
        'approved_at',
        'production_order_id',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
        ];
    }

    /* ── Relationships ────────────────────────────────── */

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function inventories(): BelongsToMany
    {
        return $this->belongsToMany(
            Inventory::class,
            'label_reprint_request_items',
            'reprint_request_id',
            'inventory_id'
        )->withTimestamps();
    }

    public function printLogs(): HasMany
    {
        return $this->hasMany(LabelPrintLog::class, 'reprint_request_id');
    }

    /* ── Scopes ───────────────────────────────────────── */

    public function scopePending($query)
    {
        return $query->where('status', 'PENDING');
    }
}
