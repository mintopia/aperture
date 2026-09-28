<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\HandlesSwitchAuth;
use App\Models\SwitchConfig;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSwitchRequest extends FormRequest
{
    use HandlesSwitchAuth;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<int, string>|string>
     */
    public function rules(): array
    {
        /** @var SwitchConfig $switchConfig */
        $switchConfig = $this->route('switchConfig');

        return [
            ...$this->authRules($switchConfig),
            'name' => 'required|string|max:255',
            'hostname' => 'required|string|max:255|unique:switch_configs,hostname,'.$switchConfig->id,
            'type' => 'required|string|in:cisco',
            'username' => 'nullable|string|max:255',
            'enable_password' => 'nullable|string|max:500',
            'enabled' => 'sometimes|boolean',
            'port' => 'integer|min:1|max:65535',
            'timeout' => 'integer|min:1|max:300',
            'timezone' => 'sometimes|string|timezone:all',
        ];
    }
}
