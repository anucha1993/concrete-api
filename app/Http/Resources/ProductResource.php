<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'product_code'        => $this->product_code,
            'name'                => $this->name,
            'category_id'         => $this->category_id,
            'category'            => new CategoryResource($this->whenLoaded('category')),
            'counting_unit'       => $this->counting_unit,
            'length'              => $this->length,
            'length_unit'         => $this->length_unit,
            'thickness'           => $this->thickness,
            'thickness_unit'      => $this->thickness_unit,
            'width'               => $this->width,
            'steel_type'          => $this->steel_type,
            'side_steel_type'     => $this->side_steel_type,
            'size_type'           => $this->size_type,
            'custom_note'         => $this->custom_note,
            'stock_min'           => $this->stock_min,
            'stock_max'           => $this->stock_max,
            'default_location_id' => $this->default_location_id,
            'default_location'    => new LocationResource($this->whenLoaded('defaultLocation')),
            'barcode'             => $this->barcode,
            'is_active'           => $this->is_active,
            'stock_count'         => $this->when(isset($this->resource->stock_count), $this->resource->stock_count ?? 0),
            'reserved_count'      => $this->when(isset($this->resource->reserved_count), $this->resource->reserved_count ?? 0),
            'created_at'          => $this->created_at?->toISOString(),
            'updated_at'          => $this->updated_at?->toISOString(),
        ];
    }
}
