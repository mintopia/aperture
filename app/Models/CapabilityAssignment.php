<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CapabilityAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $capability
 * @property string $integration
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static CapabilityAssignmentFactory factory($count = null, $state = [])
 * @method static Builder<static>|CapabilityAssignment newModelQuery()
 * @method static Builder<static>|CapabilityAssignment newQuery()
 * @method static Builder<static>|CapabilityAssignment query()
 * @method static Builder<static>|CapabilityAssignment whereCapability($value)
 * @method static Builder<static>|CapabilityAssignment whereCreatedAt($value)
 * @method static Builder<static>|CapabilityAssignment whereId($value)
 * @method static Builder<static>|CapabilityAssignment whereIntegration($value)
 * @method static Builder<static>|CapabilityAssignment whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class CapabilityAssignment extends Model
{
    /** @use HasFactory<CapabilityAssignmentFactory> */
    use HasFactory;

    protected $fillable = ['capability', 'integration'];

    /**
     * Assign an integration as the active provider for a capability.
     * If another integration was assigned, it is replaced.
     */
    public static function assign(string $capability, string $integration): self
    {
        return static::updateOrCreate(
            ['capability' => $capability],
            ['integration' => $integration]
        );
    }

    /**
     * Remove the active provider for a capability.
     */
    public static function unassign(string $capability): void
    {
        static::where('capability', $capability)->delete();
    }

    /**
     * Get the integration currently assigned as the active provider for a capability.
     */
    public static function activeIntegration(string $capability): ?string
    {
        return static::where('capability', $capability)->first()?->integration;
    }

    /**
     * Check if a given integration is the active provider for a capability.
     */
    public static function isActiveProvider(string $integration, string $capability): bool
    {
        return static::where('capability', $capability)
            ->where('integration', $integration)
            ->exists();
    }

    /**
     * Get all capabilities assigned to an integration.
     *
     * @return Collection<int, string>
     */
    public static function getForIntegration(string $integration): Collection
    {
        return static::where('integration', $integration)
            ->pluck('capability');
    }
}
