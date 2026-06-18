<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\ContentBlock;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<int, mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => [
                'required',
                'string',
                'max:50',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (
                        in_array($value, ContentBlock::SINGLETON_TYPES, true)
                        && ContentBlock::where('type', $value)->exists()
                    ) {
                        $fail('A block of this type already exists.');
                    }
                },
            ],
            'title' => 'required|string|max:255',
            'content' => 'nullable|string',
            'is_active' => 'boolean',
            'settings' => 'nullable|array',
        ];
    }
}
