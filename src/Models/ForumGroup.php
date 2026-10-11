<?php

declare(strict_types=1);

namespace ForumKit\Forums\Models;

use ForumKit\Core\Support\Tables;
use ForumKit\Forums\Support\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $sort_order
 * @property bool $is_active
 */
class ForumGroup extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'sort_order',
        'is_active',
    ];

    public function getTable(): string
    {
        return Tables::name('forum_groups');
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Model, $this>
     */
    public function forums(): HasMany
    {
        return $this->hasMany(Models::class('forum'), 'forum_group_id')->orderBy('sort_order');
    }
}
