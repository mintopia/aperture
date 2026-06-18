<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConnectionTestLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $integration
 * @property bool $success
 * @property string|null $message
 * @property string|null $request_method
 * @property string|null $request_url
 * @property int|null $response_status
 * @property string|null $response_data
 * @property int|null $response_time_ms
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static ConnectionTestLogFactory factory($count = null, $state = [])
 * @method static Builder<static>|ConnectionTestLog newModelQuery()
 * @method static Builder<static>|ConnectionTestLog newQuery()
 * @method static Builder<static>|ConnectionTestLog query()
 * @method static Builder<static>|ConnectionTestLog whereCreatedAt($value)
 * @method static Builder<static>|ConnectionTestLog whereId($value)
 * @method static Builder<static>|ConnectionTestLog whereIntegration($value)
 * @method static Builder<static>|ConnectionTestLog whereMessage($value)
 * @method static Builder<static>|ConnectionTestLog whereRequestMethod($value)
 * @method static Builder<static>|ConnectionTestLog whereRequestUrl($value)
 * @method static Builder<static>|ConnectionTestLog whereResponseData($value)
 * @method static Builder<static>|ConnectionTestLog whereResponseStatus($value)
 * @method static Builder<static>|ConnectionTestLog whereResponseTimeMs($value)
 * @method static Builder<static>|ConnectionTestLog whereSuccess($value)
 * @method static Builder<static>|ConnectionTestLog whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
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
