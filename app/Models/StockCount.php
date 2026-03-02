<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockCount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'type', 'status', 'note',
        'filter_category_ids', 'filter_location_ids', 'filter_product_ids',
        'created_by', 'started_at', 'completed_at',
        'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'filter_category_ids' => 'array',
            'filter_location_ids' => 'array',
            'filter_product_ids'  => 'array',
            'started_at'          => 'datetime',
            'completed_at'        => 'datetime',
            'approved_at'         => 'datetime',
        ];
    }

    /* ── Relations ── */

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockCountItem::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(StockCountScan::class);
    }

    public function serialResolutions(): HasMany
    {
        return $this->hasMany(StockCountSerialResolution::class);
    }

    /* ── Helpers ── */

    public static function generateCode(): string
    {
        $prefix = 'SC-' . now()->format('ym');
        $last = static::where('code', 'like', $prefix . '-%')
            ->orderByDesc('code')
            ->value('code');

        $number = $last ? ((int) substr($last, -3)) + 1 : 1;
        return $prefix . '-' . str_pad($number, 3, '0', STR_PAD_LEFT);
    }
}
