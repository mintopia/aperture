<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Capability;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ToggleCapabilityRequest extends FormRequest
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
            'capability' => ['required', Rule::enum(Capability::class)],
            'integration' => 'required|string',
            'active' => 'required|boolean',
        ];
    }
}
