<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<int, string>|string>
     */
    public function rules(): array
    {
        $rules = [
            'nickname' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'roles' => 'sometimes|array',
            'roles.*' => 'string|exists:roles,code',
        ];

        if ($this->filled('password')) {
            $rules['password'] = 'required|string|min:8|confirmed';
        }

        return $rules;
    }
}
