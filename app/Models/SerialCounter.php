<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SerialCounter extends Model
{
    protected $fillable = [
        'category_id',
        'prefix',
        'last_number',
    ];

    protected function casts(): array
    {
        return [
            'last_number' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
