<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignPermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'permissions'   => ['required', 'array', 'min:1'],
            'permissions.*' => ['required', 'integer', 'exists:permissions,id'],
        ];
    }
}
