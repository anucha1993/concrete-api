<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PackItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'pack_id'    => $this->pack_id,
            'product_id' => $this->product_id,
            'product'    => new ProductResource($this->whenLoaded('product')),
            'quantity'   => $this->quantity,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
