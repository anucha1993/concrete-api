<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductionOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pack_id'    => ['required', 'integer', 'exists:packs,id'],
            'quantity'   => ['required', 'integer', 'min:1', 'max:9999'],
            'note'       => ['nullable', 'string'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'pack_id.required'  => 'กรุณาเลือกแพสินค้า',
            'pack_id.exists'    => 'แพสินค้าไม่ถูกต้อง',
            'quantity.required' => 'กรุณาระบุจำนวนชุด',
            'quantity.min'      => 'จำนวนชุดต้องมากกว่า 0',
        ];
    }
}
