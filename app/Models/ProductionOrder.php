<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'order_number',
        'pack_id',
        'quantity',
        'status',
        'note',
        'created_by',
        'confirmed_by',
        'confirmed_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity'     => 'integer',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(Pack::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductionOrderItem::class);
    }

    public function serials(): HasMany
    {
        return $this->hasMany(ProductionSerial::class);
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    // ── Scopes ───────────────────────────────────────────
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }
}
