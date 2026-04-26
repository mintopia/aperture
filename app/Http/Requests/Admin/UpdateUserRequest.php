<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
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
