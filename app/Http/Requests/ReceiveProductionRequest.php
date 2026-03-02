<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReceiveProductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items'                   => ['required', 'array', 'min:1'],
            'items.*.production_order_item_id' => ['required', 'integer', 'exists:production_order_items,id'],
            'items.*.damaged_qty'     => ['required', 'integer', 'min:0'],
            'note'                    => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required'                          => 'กรุณาระบุรายการรับเข้า',
            'items.*.production_order_item_id.required' => 'กรุณาระบุรายการสินค้า',
            'items.*.damaged_qty.required'             => 'กรุณาระบุจำนวนชำรุด',
        ];
    }
}
