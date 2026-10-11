<?php

declare(strict_types=1);

namespace ForumKit\Forums\Models;

use ForumKit\Core\Support\Tables;
use ForumKit\Forums\Enums\ForumPostingPolicy;
use ForumKit\Forums\Enums\ForumVisibility;
use ForumKit\Forums\Support\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $forum_group_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property ForumVisibility $visibility
 * @property string|null $required_entitlement_scope
 * @property string|null $reply_entitlement_scope
 * @property ForumPostingPolicy $posting_policy
 * @property bool $is_read_only
 * @property bool $requires_thread_approval
 * @property bool $requires_reply_approval
 * @property bool $show_when_empty
 * @property int $sort_order
 * @property bool $is_active
 * @property int $thread_count
 * @property int $post_count
 * @property int|null $last_post_id
 * @property int|null $last_post_thread_id
 * @property int|null $last_post_author_id
 * @property Carbon|null $last_post_at
 */
class Forum extends Model
{
    protected $fillable = [
        'forum_group_id',
        'name',
        'slug',
        'description',
        'visibility',
        'required_entitlement_scope',
        'reply_entitlement_scope',
        'posting_policy',
        'is_read_only',
        'requires_thread_approval',
        'requires_reply_approval',
        'show_when_empty',
        'sort_order',
        'is_active',
        'thread_count',
        'post_count',
        'last_post_id',
        'last_post_thread_id',
        'last_post_author_id',
        'last_post_at',
    ];

    public function getTable(): string
    {
        return Tables::name('forums');
    }

    protected function casts(): array
    {
        return [
            'visibility' => ForumVisibility::class,
            'posting_policy' => ForumPostingPolicy::class,
            'required_entitlement_scope' => 'string',
            'reply_entitlement_scope' => 'string',
            'is_read_only' => 'boolean',
            'requires_thread_approval' => 'boolean',
            'requires_reply_approval' => 'boolean',
            'show_when_empty' => 'boolean',
            'is_active' => 'boolean',
            'thread_count' => 'integer',
            'post_count' => 'integer',
            'last_post_id' => 'integer',
            'last_post_thread_id' => 'integer',
            'last_post_author_id' => 'integer',
            'last_post_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Models::class('forum_group'), 'forum_group_id');
    }

    /**
     * @return HasMany<Model, $this>
     */
    public function threads(): HasMany
    {
        return $this->hasMany(Models::class('thread'), 'forum_id');
    }
}
