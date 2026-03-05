<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockDeduction extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'type',
        'status',
        'customer_name',
        'shipping_address',
        'reference_doc',
        'reason',
        'note',
        'pda_token',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
        ];
    }

    // ── Relations ────────────────────────────────

    public function lines(): HasMany
    {
        return $this->hasMany(StockDeductionLine::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(StockDeductionScan::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ── Helpers ──────────────────────────────────

    public static function generateCode(): string
    {
        $prefix = 'SD-' . now()->format('ym');
        $last = static::withTrashed()
            ->where('code', 'like', $prefix . '%')
            ->orderByDesc('code')
            ->value('code');

        $num = $last ? ((int) substr($last, -3)) + 1 : 1;
        return $prefix . '-' . str_pad($num, 3, '0', STR_PAD_LEFT);
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'SOLD'    => 'ขาย',
            'LOST'    => 'สูญหาย',
            'DAMAGED' => 'ชำรุด/ทำลาย',
            'OTHER'   => 'อื่นๆ',
            default   => $type,
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'DRAFT'       => 'แบบร่าง',
            'PENDING'     => 'รอสแกน',
            'IN_PROGRESS' => 'กำลังสแกน',
            'COMPLETED'   => 'สแกนครบ',
            'APPROVED'    => 'อนุมัติแล้ว',
            'CANCELLED'   => 'ยกเลิก',
            default       => $status,
        };
    }
}
