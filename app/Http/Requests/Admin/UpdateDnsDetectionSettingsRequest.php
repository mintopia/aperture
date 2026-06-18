<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDnsDetectionSettingsRequest extends FormRequest
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
            'dns_check_url' => [
                'nullable',
                'string',
                'max:500',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== null && $value !== '' && ! str_contains($value, '{uuid}')) {
                        $fail('The URL must contain the {uuid} placeholder.');
                    }
                },
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== null && $value !== '' && ! filter_var(str_replace('{uuid}', 'test', $value), FILTER_VALIDATE_URL)) {
                        $fail('The URL must be a valid URL.');
                    }
                },
            ],
            'dns_warning_message' => 'nullable|string|max:500',
        ];
    }
}
