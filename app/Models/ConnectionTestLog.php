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

    protected $fillable = ['integration', 'success', 'message', 'response_time_ms'];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'response_time_ms' => 'integer',
        ];
    }

    /**
     * Record a new connection test result.
     */
    public static function record(string $integration, bool $success, ?string $message = null, ?int $responseTimeMs = null): self
    {
        return static::create([
            'integration' => $integration,
            'success' => $success,
            'message' => $message,
            'response_time_ms' => $responseTimeMs,
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
