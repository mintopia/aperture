<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\NetworkRangeService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNetworkSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
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
            'managed_ranges_v4' => ['nullable', 'string', $this->cidrValidationRule(4)],
            'managed_ranges_v6' => ['nullable', 'string', $this->cidrValidationRule(6)],
            'dns_filter_default' => 'boolean',
            'oui_auto_allow' => ['nullable', 'string', $this->ouiValidationRule()],
        ];
    }

    /**
     * @param  4|6  $family
     */
    private function cidrValidationRule(int $family): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($family): void {
            if ($value === null || $value === '') {
                return;
            }

            $lines = $this->parseLines((string) $value);
            foreach ($lines as $line) {
                if (! NetworkRangeService::isValidCidr($line, $family)) {
                    $label = $family === 4 ? 'IPv4' : 'IPv6';
                    $fail(sprintf('Invalid %s CIDR notation: %s', $label, $line));

                    return;
                }
            }
        };
    }

    private function ouiValidationRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            $lines = $this->parseLines((string) $value);
            foreach ($lines as $line) {
                if (! preg_match('/^([0-9A-Fa-f]{2}:){0,5}[0-9A-Fa-f]{2}$/', $line)) {
                    $fail('Invalid OUI prefix: '.$line);

                    return;
                }
            }
        };
    }

    /**
     * @return list<string>
     */
    private function parseLines(string $text): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $text)), fn (string $line): bool => $line !== ''));
    }
}
