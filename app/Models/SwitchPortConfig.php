<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SwitchPortConfigFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $switch_port_id
 * @property string $config_text
 * @property string $config_hash
 * @property string|null $interface_output
 * @property Carbon|null $last_fetched_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SwitchPort $switchPort
 *
 * @method static SwitchPortConfigFactory factory($count = null, $state = [])
 * @method static Builder<static>|SwitchPortConfig newModelQuery()
 * @method static Builder<static>|SwitchPortConfig newQuery()
 * @method static Builder<static>|SwitchPortConfig query()
 * @method static Builder<static>|SwitchPortConfig whereConfigHash($value)
 * @method static Builder<static>|SwitchPortConfig whereConfigText($value)
 * @method static Builder<static>|SwitchPortConfig whereCreatedAt($value)
 * @method static Builder<static>|SwitchPortConfig whereId($value)
 * @method static Builder<static>|SwitchPortConfig whereInterfaceOutput($value)
 * @method static Builder<static>|SwitchPortConfig whereLastFetchedAt($value)
 * @method static Builder<static>|SwitchPortConfig whereSwitchPortId($value)
 * @method static Builder<static>|SwitchPortConfig whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class SwitchPortConfig extends Model
{
    /** @use HasFactory<SwitchPortConfigFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'switch_port_id',
        'config_text',
        'config_hash',
        'interface_output',
        'last_fetched_at',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'last_fetched_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SwitchPort, $this> */
    public function switchPort(): BelongsTo
    {
        return $this->belongsTo(SwitchPort::class);
    }
}
