<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'email'         => $this->email,
            'role_id'       => $this->role_id,
            'role'          => new RoleResource($this->whenLoaded('role')),
            'status'        => $this->status,
            'last_login_at' => $this->last_login_at?->toISOString(),
            'created_at'    => $this->created_at?->toISOString(),
            'updated_at'    => $this->updated_at?->toISOString(),
        ];
    }
}
