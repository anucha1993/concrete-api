<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $packId = $this->route('pack')?->id ?? $this->route('pack');

        return [
            'code'                 => ['sometimes', 'string', 'max:50', Rule::unique('packs', 'code')->ignore($packId)],
            'name'                 => ['sometimes', 'string', 'max:200'],
            'description'          => ['nullable', 'string'],
            'is_active'            => ['sometimes', 'boolean'],
            'items'                => ['sometimes', 'array', 'min:1'],
            'items.*.product_id'   => ['required_with:items', 'integer', 'exists:products,id'],
            'items.*.quantity'     => ['required_with:items', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique'                => 'รหัสแพนี้ถูกใช้แล้ว',
            'name.required'              => 'กรุณาระบุชื่อแพ',
            'items.min'                  => 'กรุณาเพิ่มสินค้าอย่างน้อย 1 รายการ',
            'items.*.product_id.required_with' => 'กรุณาเลือกสินค้า',
            'items.*.product_id.exists'  => 'สินค้าที่เลือกไม่ถูกต้อง',
            'items.*.quantity.required_with' => 'กรุณาระบุจำนวน',
            'items.*.quantity.min'       => 'จำนวนต้องมากกว่า 0',
        ];
    }
}
