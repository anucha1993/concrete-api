<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Claim extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'type',
        'status',
        'resolution',
        'customer_name',
        'reference_doc',
        'stock_deduction_id',
        'reason',
        'note',
        'pda_token',
        'created_by',
        'approved_by',
        'approved_at',
        'reject_reason',
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
        return $this->hasMany(ClaimLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function stockDeduction(): BelongsTo
    {
        return $this->belongsTo(StockDeduction::class);
    }

    // ── Helpers ──────────────────────────────────

    public static function generateCode(): string
    {
        $prefix = 'CLM-' . now()->format('ym');
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
            'RETURN'           => 'คืนสินค้า',
            'TRANSPORT_DAMAGE' => 'เสียหายจากขนส่ง',
            'DEFECT'           => 'ชำรุด/ตำหนิ',
            'WRONG_SPEC'       => 'ไม่ตรงสเปค',
            'OTHER'            => 'อื่นๆ',
            default            => $type,
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'DRAFT'     => 'แบบร่าง',
            'PENDING'   => 'รอตรวจสอบ',
            'APPROVED'  => 'อนุมัติแล้ว',
            'REJECTED'  => 'ปฏิเสธ',
            'CANCELLED' => 'ยกเลิก',
            default     => $status,
        };
    }

    public static function resolutionLabel(?string $resolution): string
    {
        return match ($resolution) {
            'RETURN_STOCK'   => 'คืนเข้าสต๊อก',
            'RETURN_DAMAGED' => 'คืนเป็นสินค้าชำรุด',
            'REPLACE'        => 'เปลี่ยนสินค้า',
            'REFUND'         => 'คืนเงิน',
            'CREDIT_NOTE'    => 'ออกใบลดหนี้',
            default          => '-',
        };
    }
}
