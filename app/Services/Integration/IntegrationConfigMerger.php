<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Models\IntegrationConfig;
use Illuminate\Http\Request;

class IntegrationConfigMerger
{
    /**
     * @return array<string, mixed>
     */
    public function merge(string $integration, Request $request): array
    {
        $dbConfig = IntegrationConfig::getAll($integration);
        $allowedKeys = array_keys(config(sprintf('integrations.%s.fields', $integration), []));
        $requestValues = $request->only($allowedKeys);

        return array_merge($dbConfig, array_filter($requestValues, fn ($v): bool => $v !== null && $v !== ''));
    }
}
