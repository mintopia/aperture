<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateIpv6DetectionSettingsRequest extends FormRequest
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
            'detection_endpoint' => ['nullable', 'string', 'max:500', 'regex:/^https:\/\/.+/'],
            'jwks_url' => 'nullable|url:https|max:500',
            'jwt_audience' => 'nullable|string|max:255',
            'jwt_issuer' => 'nullable|string|max:255',
        ];
    }
}
