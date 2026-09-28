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
        return $this->resource->toPublicArray();
    }
}
