<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $service = (string) $this->route('service');

        /** @var array<string, array{validation?: array<string, string>}> $integrations */
        $integrations = config('integrations', []);

        if (! array_key_exists($service, $integrations)) {
            throw new NotFoundHttpException('Unknown integration: '.$service);
        }

        $rules = ['config' => 'required|array'];
        foreach ($integrations[$service]['validation'] ?? [] as $field => $rule) {
            $rules['config.'.$field] = $rule;
        }

        return $rules;
    }
}
