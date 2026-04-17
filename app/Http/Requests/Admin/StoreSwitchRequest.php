<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSwitchRequest extends FormRequest
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
        return [
            'name' => 'required|string|max:255',
            'hostname' => 'required|string|max:255|unique:switch_configs',
            'type' => 'required|string|in:cisco',
            'username' => 'required|string|max:255',
            'password' => 'required|string|max:500',
            'enable_password' => 'nullable|string|max:500',
            'enabled' => 'sometimes|boolean',
            'port' => 'integer|min:1|max:65535',
            'timeout' => 'integer|min:1|max:300',
        ];
    }
}
