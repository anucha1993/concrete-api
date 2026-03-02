<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role_id'  => ['required', 'integer', 'exists:roles,id'],
            'status'   => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'อีเมลนี้มีในระบบแล้ว',
        ];
    }
}
