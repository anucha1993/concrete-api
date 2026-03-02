<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_code'        => ['required', 'string', 'max:50', 'unique:products,product_code'],
            'name'                => ['required', 'string', 'max:255'],
            'category_id'         => ['required', 'integer', 'exists:categories,id'],
            'counting_unit'       => ['sometimes', 'string', 'max:50'],
            'length'              => ['nullable', 'numeric', 'min:0'],
            'length_unit'         => ['sometimes', 'string', Rule::in(['meter', 'centimeter', 'millimeter', 'inch'])],
            'thickness'           => ['nullable', 'numeric', 'min:0'],
            'thickness_unit'      => ['sometimes', 'string', Rule::in(['meter', 'centimeter', 'millimeter', 'inch'])],
            'width'               => ['nullable', 'string', 'max:100'],
            'steel_type'          => ['nullable', 'string', 'max:100'],
            'side_steel_type'     => ['required', Rule::in(['HIDE', 'SHOW', 'NONE'])],
            'size_type'           => ['required', Rule::in(['STANDARD', 'CUSTOM'])],
            'custom_note'         => ['required_if:size_type,CUSTOM', 'nullable', 'string'],
            'stock_min'           => ['required', 'integer', 'min:0', 'lte:stock_max'],
            'stock_max'           => ['required', 'integer', 'min:0', 'gte:stock_min'],
            'default_location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'barcode'             => ['nullable', 'string', 'max:100', 'unique:products,barcode'],
            'is_active'           => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_code.unique'        => 'รหัสสินค้านี้มีในระบบแล้ว',
            'barcode.unique'             => 'บาร์โค้ดนี้มีในระบบแล้ว',
            'custom_note.required_if'    => 'กรุณาระบุ custom_note เมื่อ size_type เป็น CUSTOM',
            'stock_min.lte'              => 'stock_min ต้องน้อยกว่าหรือเท่ากับ stock_max',
            'stock_max.gte'              => 'stock_max ต้องมากกว่าหรือเท่ากับ stock_min',
        ];
    }
}
