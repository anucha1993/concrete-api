<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Inventory extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'serial_number',
        'product_id',
        'location_id',
        'production_order_id',
        'status',
        'condition',
        'note',
        'received_at',
        'last_movement_at',
        'label_printed_at',
        'label_printed_by',
        'label_verified_at',
        'label_verified_by',
        'label_print_count',
    ];

    protected function casts(): array
    {
        return [
            'received_at'       => 'datetime',
            'last_movement_at'  => 'datetime',
            'label_printed_at'  => 'datetime',
            'label_verified_at' => 'datetime',
            'label_print_count' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /**
     * Latest CLAIM_RETURN movement (ถ้ามี = สินค้าถูกคืนจากเคลม)
     */
    public function latestClaimReturn(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(InventoryMovement::class)
            ->where('type', 'CLAIM_RETURN')
            ->latest();
    }

    /**
     * Latest ADJUSTMENT movement — admin edit audit
     */
    public function latestAdjustment(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(InventoryMovement::class)
            ->where('type', 'ADJUSTMENT')
            ->latest();
    }

    public function labelPrintLogs(): HasMany
    {
        return $this->hasMany(LabelPrintLog::class);
    }

    public function labelPrinter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'label_printed_by');
    }

    public function labelVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'label_verified_by');
    }

    // ── Scopes ───────────────────────────────────────────
    public function scopeInStock($query)
    {
        return $query->where('status', 'IN_STOCK');
    }

    public function scopeByProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeByLocation($query, $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    public function scopeDamaged($query)
    {
        return $query->where('condition', 'DAMAGED');
    }
}
