<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateGeneralSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorised to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'site_title' => 'required|string|max:255',
            'terms_type' => 'required|string|in:page,url',
            'terms_value' => 'nullable|string|max:500',
            'privacy_type' => 'required|string|in:page,url',
            'privacy_value' => 'nullable|string|max:500',
            'theme_mode' => 'required|string|in:light,dark',
            'accent_hue' => 'required|integer|min:0|max:360',
            'accent_chroma' => 'nullable|numeric|min:0.01|max:0.37',
            'accent_lightness' => 'nullable|integer|min:40|max:95',
            'custom_css' => ['nullable', 'string', 'max:10000', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && stripos($value, '<script') !== false) {
                    $fail('The custom CSS must not contain script tags.');
                }
            }],
        ];
    }
}
