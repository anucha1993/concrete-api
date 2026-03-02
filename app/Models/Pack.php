<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pack extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Items in this pack.
     */
    public function items(): HasMany
    {
        return $this->hasMany(PackItem::class);
    }

    /**
     * Scope: only active packs.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
