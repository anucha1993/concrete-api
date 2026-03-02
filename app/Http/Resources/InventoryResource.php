<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $claimReturn = $this->whenLoaded('latestClaimReturn', function () {
            $m = $this->latestClaimReturn;
            if (!$m) return null;
            $claim = $m->reference_type === 'claims' ? \App\Models\Claim::find($m->reference_id) : null;
            return [
                'claim_code' => $claim?->code,
                'claim_id'   => $claim?->id,
                'note'       => $m->note,
                'date'       => $m->created_at?->toISOString(),
            ];
        });

        return [
            'id'                  => $this->id,
            'serial_number'       => $this->serial_number,
            'product_id'          => $this->product_id,
            'product'             => new ProductResource($this->whenLoaded('product')),
            'location_id'         => $this->location_id,
            'location'            => new LocationResource($this->whenLoaded('location')),
            'production_order_id' => $this->production_order_id,
            'status'              => $this->status,
            'condition'           => $this->condition,
            'note'                => $this->note,
            'received_at'         => $this->received_at?->toISOString(),
            'last_movement_at'    => $this->last_movement_at?->toISOString(),
            'label_printed_at'    => $this->label_printed_at?->toISOString(),
            'label_printed_by'    => $this->label_printed_by,
            'label_verified_at'   => $this->label_verified_at?->toISOString(),
            'label_verified_by'   => $this->label_verified_by,
            'label_print_count'   => $this->label_print_count ?? 0,
            'claim_return'        => $claimReturn,
            'last_adjustment'     => $this->whenLoaded('latestAdjustment', function () {
                $m = $this->latestAdjustment;
                if (!$m) return null;
                return [
                    'note'   => $m->note,
                    'by'     => $m->creator?->name,
                    'date'   => $m->created_at?->toISOString(),
                ];
            }),
            'created_at'          => $this->created_at?->toISOString(),
            'updated_at'          => $this->updated_at?->toISOString(),
        ];
    }
}
