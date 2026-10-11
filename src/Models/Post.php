<?php

declare(strict_types=1);

namespace ForumKit\Forums\Models;

use ForumKit\Core\Support\Tables;
use ForumKit\Core\Support\UserModel;
use ForumKit\Forums\Enums\ContentModerationStatus;
use ForumKit\Forums\Support\Models;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $thread_id
 * @property int $author_id
 * @property string $body
 * @property string $content_format
 * @property ContentModerationStatus $moderation_status
 * @property string|null $signature
 * @property string|null $signature_format
 * @property bool $is_deleted
 * @property Carbon|null $deleted_at
 * @property int|null $deleted_by_id
 * @property string|null $deleted_reason
 * @property int|null $approved_by_id
 * @property Carbon|null $approved_at
 * @property int|null $rejected_by_id
 * @property Carbon|null $rejected_at
 * @property string|null $moderation_reason
 * @property Carbon|null $edited_at
 * @property int|null $edited_by_id
 */
class Post extends Model
{
    protected $fillable = [
        'thread_id',
        'author_id',
        'body',
        'content_format',
        'moderation_status',
        'signature',
        'signature_format',
        'is_deleted',
        'deleted_at',
        'deleted_by_id',
        'deleted_reason',
        'approved_by_id',
        'approved_at',
        'rejected_by_id',
        'rejected_at',
        'moderation_reason',
        'edited_by_id',
        'edited_at',
    ];

    public function getTable(): string
    {
        return Tables::name('posts');
    }

    protected function casts(): array
    {
        return [
            'moderation_status' => ContentModerationStatus::class,
            'is_deleted' => 'boolean',
            'deleted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'edited_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Models::class('thread'), 'thread_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'author_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'deleted_by_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'approved_by_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'rejected_by_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'edited_by_id');
    }

    /**
     * @return HasMany<Model, $this>
     */
    public function moderationLogs(): HasMany
    {
        return $this->hasMany(Models::class('post_moderation_log'), 'post_id');
    }

    /**
     * @return HasMany<Model, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Models::class('post_report'), 'post_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('moderation_status', ContentModerationStatus::APPROVED->value);
    }

    public function isApproved(): bool
    {
        return $this->moderation_status === ContentModerationStatus::APPROVED;
    }

    public function isPending(): bool
    {
        return $this->moderation_status === ContentModerationStatus::PENDING;
    }

    public function isRejected(): bool
    {
        return in_array($this->moderation_status, [ContentModerationStatus::REJECTED, ContentModerationStatus::REJECTED_FINAL], true);
    }
}
