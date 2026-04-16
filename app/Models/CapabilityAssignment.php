<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CapabilityAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

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
