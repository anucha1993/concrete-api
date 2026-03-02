<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PackResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'code'        => $this->code,
            'name'        => $this->name,
            'description' => $this->description,
            'is_active'   => $this->is_active,
            'items'       => PackItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
            'created_at'  => $this->created_at?->toISOString(),
            'updated_at'  => $this->updated_at?->toISOString(),
        ];
    }
}
