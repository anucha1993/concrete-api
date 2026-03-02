<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'product_code',
        'name',
        'category_id',
        'counting_unit',
        'length',
        'length_unit',
        'thickness',
        'thickness_unit',
        'width',
        'steel_type',
        'side_steel_type',
        'size_type',
        'custom_note',
        'stock_min',
        'stock_max',
        'default_location_id',
        'barcode',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'length' => 'decimal:2',
            'thickness' => 'decimal:2',
            'stock_min' => 'integer',
            'stock_max' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The category that the product belongs to.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * The default location of the product.
     */
    public function defaultLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'default_location_id');
    }

    /**
     * Inventories belonging to this product.
     */
    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    /**
     * Scope: filter by category.
     */
    public function scopeByCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    /**
     * Scope: filter by size type.
     */
    public function scopeBySizeType($query, $sizeType)
    {
        return $query->where('size_type', $sizeType);
    }

    /**
     * Scope: only active products.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
