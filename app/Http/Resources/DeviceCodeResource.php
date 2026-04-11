<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Borealis\DeviceCode;
use App\Services\Borealis\DeviceCodeStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeviceCode
 */
class DeviceCodeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'expires_at' => $this->expiresAt->toIso8601String(),
            'status' => $this->status->name ?? DeviceCodeStatus::dcsFailed->name,
            'interval' => $this->interval ?? null,
            'code' => $this->userCode ?? null,
        ];
    }
}
