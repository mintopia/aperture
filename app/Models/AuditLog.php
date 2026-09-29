<?php

declare(strict_types=1);

namespace App\Models;

use App\Events\AuditLogRecorded;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $related_type
 * @property int|null $related_id
 * @property string|null $actor_type
 * @property int|null $actor_id
 * @property string $process
 * @property string $severity
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 */
#[Fillable([
    'action',
    'subject_type',
    'subject_id',
    'related_type',
    'related_id',
    'actor_type',
    'actor_id',
    'process',
    'severity',
    'metadata',
])]
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public static function write(
        string $action,
        ?Model $subject = null,
        ?Model $related = null,
        ?Model $actor = null,
        string $process = 'system',
        ?array $metadata = null,
        string $severity = 'info',
    ): self {
        return self::create([
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'process' => $process,
            'metadata' => $metadata,
            'severity' => $severity,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public static function record(
        string $action,
        ?Model $subject = null,
        ?Model $related = null,
        ?Model $actor = null,
        string $process = 'system',
        ?array $metadata = null,
        string $severity = 'info',
    ): self {
        $log = self::write($action, $subject, $related, $actor, $process, $metadata, $severity);

        event(new AuditLogRecorded($log));

        return $log;
    }
}
