<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\ValueObjects\IpBandwidthResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BandwidthResource extends JsonResource
{
    /**
     * @return array{timestamps: array<int, string>, download: array<int, float>, upload: array<int, float>, totalReceived: int, totalSent: int}
     */
    public function toArray(Request $request): array
    {
        /** @var IpBandwidthResult $result */
        $result = $this->resource;

        return [
            'timestamps' => $result->timestamps,
            'download' => $result->download,
            'upload' => $result->upload,
            'totalReceived' => $result->received,
            'totalSent' => $result->sent,
        ];
    }
}
