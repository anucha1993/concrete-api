<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdaToken extends Model
{
    protected $fillable = [
        'token',
        'name',
        'created_by',
        'expires_at',
        'last_used_at',
        'scan_count',
        'is_revoked',
    ];

    protected function casts(): array
    {
        return [
            'expires_at'   => 'datetime',
            'last_used_at' => 'datetime',
            'is_revoked'   => 'boolean',
        ];
    }

    /* ── Relations ── */

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ── Helpers ── */

    public function isValid(): bool
    {
        return !$this->is_revoked && $this->expires_at->isFuture();
    }
}
