<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\IpAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IpAddress
 */
class IpAddressResource extends JsonResource
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
            'address' => $this->address,
            'internet_enabled' => $this->internet_enabled,
            'rate_limit_enabled' => $this->rate_limit_enabled,
            'dns_filtering_enabled' => $this->dns_filtering_enabled,
            'last_seen_at' => $this->last_seen_at,
        ];
    }
}
