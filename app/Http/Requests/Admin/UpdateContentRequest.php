<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateContentRequest extends FormRequest
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
        return [
            'type' => 'sometimes|string|max:50',
            'title' => 'sometimes|string|max:255',
            'content' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
            'settings' => 'nullable|array',
        ];
    }
}
