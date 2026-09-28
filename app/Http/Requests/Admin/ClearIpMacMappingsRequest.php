<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ConfirmsPassword;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ClearIpMacMappingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    use ConfirmsPassword;

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->confirmPassword($validator)];
    }
}
