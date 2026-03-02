<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $roleId = $this->route('role');

        return [
            'name'         => ['sometimes', 'string', 'max:50', Rule::unique('roles', 'name')->ignore($roleId)],
            'display_name' => ['sometimes', 'string', 'max:100'],
            'description'  => ['nullable', 'string'],
        ];
    }
}
