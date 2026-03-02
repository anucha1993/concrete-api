<?php

namespace App\Http\Resources;

use App\Models\ProductionSerial;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductionOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Count verified labels (items with label_verified_at set, still PENDING = not yet received)
        $verifiedQty = ProductionSerial::where('production_order_item_id', $this->id)
            ->whereHas('inventory', fn ($q) => $q->whereNotNull('label_verified_at'))
            ->count();

        return [
            'id'                  => $this->id,
            'production_order_id' => $this->production_order_id,
            'product_id'          => $this->product_id,
            'product'             => new ProductResource($this->whenLoaded('product')),
            'planned_qty'         => $this->planned_qty,
            'good_qty'            => $this->good_qty,
            'damaged_qty'         => $this->damaged_qty,
            'verified_qty'        => $verifiedQty,
            'received_qty'        => $this->good_qty + $this->damaged_qty,
            'created_at'          => $this->created_at?->toISOString(),
            'updated_at'          => $this->updated_at?->toISOString(),
        ];
    }
}
