<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConnectionTestLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ConnectionTestLog extends Model
{
    /** @use HasFactory<ConnectionTestLogFactory> */
    use HasFactory;

    protected $fillable = ['integration', 'success', 'message', 'request_method', 'request_url', 'response_status', 'response_time_ms', 'response_data'];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'response_time_ms' => 'integer',
            'response_status' => 'integer',
        ];
    }

    /**
     * Record a new connection test result.
     */
    public static function record(
        string $integration,
        bool $success,
        ?string $message = null,
        ?int $responseTimeMs = null,
        ?string $responseData = null,
        ?string $requestMethod = null,
        ?string $requestUrl = null,
        ?int $responseStatus = null,
    ): self {
        return static::create([
            'integration' => $integration,
            'success' => $success,
            'message' => $message,
            'response_time_ms' => $responseTimeMs,
            'response_data' => $responseData,
            'request_method' => $requestMethod,
            'request_url' => $requestUrl,
            'response_status' => $responseStatus,
        ]);
    }

    /**
     * Get the most recent test for an integration.
     */
    public static function latestFor(string $integration): ?self
    {
        return static::where('integration', $integration)
            ->latest()
            ->first();
    }

    /**
     * Get recent test logs for an integration.
     *
     * @return Collection<int, self>
     */
    public static function recentFor(string $integration, int $limit = 20): Collection
    {
        return static::where('integration', $integration)
            ->latest()
            ->limit($limit)
            ->get();
    }
}
