<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Models\IntegrationConfig;
use Illuminate\Http\Request;

class IntegrationConfigMerger
{
    /**
     * Merge DB-persisted config with non-empty request values.
     *
     * Only keys that are declared in the integration's fields config are
     * accepted from the request, preventing mass-assignment of arbitrary
     * request input.  Request values take precedence over DB values.
     *
     * @return array<string, mixed>
     */
    public function merge(string $integration, Request $request): array
    {
        $dbConfig = IntegrationConfig::getAll($integration);
        $allowedKeys = array_keys(config("integrations.{$integration}.fields", []));
        $requestValues = $request->only($allowedKeys);

        return array_merge($dbConfig, array_filter($requestValues, fn ($v) => $v !== null && $v !== ''));
    }
}
