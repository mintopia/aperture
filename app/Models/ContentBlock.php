<?php

namespace App\Models;

use Database\Factories\ContentBlockFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
