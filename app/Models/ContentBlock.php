<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ContentBlockFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $type
 * @property string $title
 * @property string|null $content
 * @property bool $is_active
 * @property int $grid_col
 * @property int $grid_row
 * @property int $col_span
 * @property int $row_span
 * @property array<array-key, mixed>|null $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static>|ContentBlock active()
 * @method static ContentBlockFactory factory($count = null, $state = [])
 * @method static Builder<static>|ContentBlock newModelQuery()
 * @method static Builder<static>|ContentBlock newQuery()
 * @method static Builder<static>|ContentBlock query()
 * @method static Builder<static>|ContentBlock whereColSpan($value)
 * @method static Builder<static>|ContentBlock whereContent($value)
 * @method static Builder<static>|ContentBlock whereCreatedAt($value)
 * @method static Builder<static>|ContentBlock whereGridCol($value)
 * @method static Builder<static>|ContentBlock whereGridRow($value)
 * @method static Builder<static>|ContentBlock whereId($value)
 * @method static Builder<static>|ContentBlock whereIsActive($value)
 * @method static Builder<static>|ContentBlock whereRowSpan($value)
 * @method static Builder<static>|ContentBlock whereSettings($value)
 * @method static Builder<static>|ContentBlock whereTitle($value)
 * @method static Builder<static>|ContentBlock whereType($value)
 * @method static Builder<static>|ContentBlock whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class ContentBlock extends Model
{
    /** @use HasFactory<ContentBlockFactory> */
    use HasFactory;

    /** @var list<string> */
    public const SINGLETON_TYPES = [
        'connection_strip',
        'bandwidth',
        'dns_filter',
    ];

    protected $fillable = [
        'type',
        'title',
        'content',
        'grid_col',
        'grid_row',
        'col_span',
        'row_span',
        'is_active',
        'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<ContentBlock>  $query
     * @return Builder<ContentBlock>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->orderBy('grid_row')
            ->orderBy('grid_col');
    }
}
