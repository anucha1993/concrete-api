<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code'                 => ['required', 'string', 'max:50', 'unique:packs,code'],
            'name'                 => ['required', 'string', 'max:200'],
            'description'          => ['nullable', 'string'],
            'is_active'            => ['sometimes', 'boolean'],
            'items'                => ['required', 'array', 'min:1'],
            'items.*.product_id'   => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity'     => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required'              => 'กรุณาระบุรหัสแพ',
            'code.unique'                => 'รหัสแพนี้ถูกใช้แล้ว',
            'name.required'              => 'กรุณาระบุชื่อแพ',
            'items.required'             => 'กรุณาเพิ่มสินค้าอย่างน้อย 1 รายการ',
            'items.min'                  => 'กรุณาเพิ่มสินค้าอย่างน้อย 1 รายการ',
            'items.*.product_id.required' => 'กรุณาเลือกสินค้า',
            'items.*.product_id.exists'  => 'สินค้าที่เลือกไม่ถูกต้อง',
            'items.*.quantity.required'  => 'กรุณาระบุจำนวน',
            'items.*.quantity.min'       => 'จำนวนต้องมากกว่า 0',
        ];
    }
}
