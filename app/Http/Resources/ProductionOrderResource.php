<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductionOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'order_number'  => $this->order_number,
            'pack_id'       => $this->pack_id,
            'pack'          => new PackResource($this->whenLoaded('pack')),
            'quantity'      => $this->quantity,
            'status'        => $this->status,
            'note'          => $this->note,
            'created_by'    => $this->created_by,
            'creator'       => $this->whenLoaded('creator', fn () => [
                'id'   => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'confirmed_by'  => $this->confirmed_by,
            'confirmer'     => $this->whenLoaded('confirmer', fn () => [
                'id'   => $this->confirmer->id,
                'name' => $this->confirmer->name,
            ]),
            'confirmed_at'  => $this->confirmed_at?->toISOString(),
            'completed_at'  => $this->completed_at?->toISOString(),
            'items'         => ProductionOrderItemResource::collection($this->whenLoaded('items')),
            'serials_count' => $this->whenCounted('serials'),
            'created_at'    => $this->created_at?->toISOString(),
            'updated_at'    => $this->updated_at?->toISOString(),
        ];
    }
}
