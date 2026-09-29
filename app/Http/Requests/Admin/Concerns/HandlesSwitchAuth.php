<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Concerns;

use App\Models\SwitchConfig;
use Illuminate\Validation\Rule;

trait HandlesSwitchAuth
{
    /**
     * @return array<string, mixed>
     */
    public function switchAttributes(?SwitchConfig $existing = null): array
    {
        $data = $this->validated();
        $method = $data['auth_method'];
        $clearPassphrase = (bool) ($data['clear_passphrase'] ?? false);
        unset($data['auth_method'], $data['clear_passphrase']);

        foreach (['username', 'password', 'enable_password', 'private_key', 'passphrase'] as $field) {
            if ($existing instanceof SwitchConfig && blank($data[$field] ?? null)) {
                unset($data[$field]);
            }
        }

        if ($method === 'password') {
            $data['private_key'] = null;
            $data['passphrase'] = null;
        } else {
            $data['password'] = null;

            if (filled($data['private_key'] ?? null)) {
                $data['private_key'] = rtrim(str_replace("\r\n", "\n", $data['private_key']))."\n";
                $data['passphrase'] = filled($data['passphrase'] ?? null) ? $data['passphrase'] : null;
            }
        }

        if ($method === 'private_key' && $clearPassphrase && blank($data['passphrase'] ?? null)) {
            $data['passphrase'] = null;
        }

        if ($existing instanceof SwitchConfig && $data['hostname'] !== $existing->hostname) {
            $data['host_key'] = null;
        }

        return $data;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('auth_method')) {
            return;
        }

        $existing = $this->route('switchConfig');
        $usesKey = $this->filled('private_key')
            || (! $this->filled('password') && $existing instanceof SwitchConfig && $existing->usesPrivateKey());

        $this->merge(['auth_method' => $usesKey ? 'private_key' : 'password']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function authRules(?SwitchConfig $existing = null): array
    {
        $needsPassword = fn (): bool => $this->input('auth_method') === 'password'
            && (! $existing instanceof SwitchConfig || blank($existing->password));
        $needsKey = fn (): bool => $this->input('auth_method') === 'private_key'
            && (! $existing instanceof SwitchConfig || ! $existing->usesPrivateKey());

        return [
            'auth_method' => ['required', 'string', 'in:password,private_key'],
            'password' => [Rule::requiredIf($needsPassword), 'nullable', 'string', 'max:500'],
            'private_key' => [Rule::requiredIf($needsKey), 'nullable', 'string', 'max:16000', 'regex:/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----/'],
            'passphrase' => ['nullable', 'string', 'max:500'],
            'clear_passphrase' => ['sometimes', 'boolean'],
        ];
    }
}
