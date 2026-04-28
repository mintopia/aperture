<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SwitchConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SwitchConfig
 */
class SwitchConfigResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'hostname' => $this->hostname,
            'type' => $this->type,
            'enabled' => $this->enabled,
            'port' => $this->port,
            'timeout' => $this->timeout,
            'last_synced_at' => $this->relationLoaded('latestSyncRun') ? $this->latestSyncRun?->finished_at : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
