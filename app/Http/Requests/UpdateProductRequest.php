<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productId = $this->route('product');

        return [
            'product_code'        => ['sometimes', 'string', 'max:50', Rule::unique('products', 'product_code')->ignore($productId)],
            'name'                => ['sometimes', 'string', 'max:255'],
            'category_id'         => ['sometimes', 'integer', 'exists:categories,id'],
            'counting_unit'       => ['sometimes', 'string', 'max:50'],
            'length'              => ['nullable', 'numeric', 'min:0'],
            'length_unit'         => ['sometimes', 'string', Rule::in(['meter', 'centimeter', 'millimeter', 'inch'])],
            'thickness'           => ['nullable', 'numeric', 'min:0'],
            'thickness_unit'      => ['sometimes', 'string', Rule::in(['meter', 'centimeter', 'millimeter', 'inch'])],
            'width'               => ['nullable', 'string', 'max:100'],
            'steel_type'          => ['nullable', 'string', 'max:100'],
            'side_steel_type'     => ['sometimes', Rule::in(['HIDE', 'SHOW', 'NONE'])],
            'size_type'           => ['sometimes', Rule::in(['STANDARD', 'CUSTOM'])],
            'custom_note'         => ['required_if:size_type,CUSTOM', 'nullable', 'string'],
            'stock_min'           => ['sometimes', 'integer', 'min:0'],
            'stock_max'           => ['sometimes', 'integer', 'min:0'],
            'default_location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'barcode'             => ['nullable', 'string', 'max:100', Rule::unique('products', 'barcode')->ignore($productId)],
            'is_active'           => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $stockMin = $this->input('stock_min', $this->route('product') ? null : 0);
            $stockMax = $this->input('stock_max', $this->route('product') ? null : 0);

            if ($stockMin !== null && $stockMax !== null && $stockMin > $stockMax) {
                $validator->errors()->add('stock_min', 'stock_min ต้องน้อยกว่าหรือเท่ากับ stock_max');
            }
        });
    }

    public function messages(): array
    {
        return [
            'product_code.unique'     => 'รหัสสินค้านี้มีในระบบแล้ว',
            'barcode.unique'          => 'บาร์โค้ดนี้มีในระบบแล้ว',
            'custom_note.required_if' => 'กรุณาระบุ custom_note เมื่อ size_type เป็น CUSTOM',
        ];
    }
}
