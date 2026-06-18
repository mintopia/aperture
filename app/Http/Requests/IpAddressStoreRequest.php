<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\IpAddress;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IpAddressStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorised to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', IpAddress::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<int, string>|string>
     */
    public function rules(): array
    {
        return [
            'address' => 'required|ipv4|unique:\App\Models\IpAddress,address',
            'comment' => 'max:100',
        ];
    }
}
