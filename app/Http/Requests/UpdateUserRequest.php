<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user');

        return [
            'name'     => ['sometimes', 'string', 'max:255'],
            'email'    => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['sometimes', 'string', 'min:8', 'confirmed'],
            'role_id'  => ['sometimes', 'integer', 'exists:roles,id'],
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
