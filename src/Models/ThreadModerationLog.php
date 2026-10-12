<?php

declare(strict_types=1);

namespace ForumKit\Forums\Models;

use ForumKit\Core\Support\Tables;
use ForumKit\Core\Support\UserModel;
use ForumKit\Forums\Support\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $thread_id
 * @property int|null $moderator_id
 * @property string $action Free-form: add-ons (polls, for one) write their own actions.
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ThreadModerationLog extends Model
{
    protected $fillable = [
        'thread_id',
        'moderator_id',
        'action',
        'reason',
    ];

    public function getTable(): string
    {
        return Tables::name('thread_moderation_logs');
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
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'moderator_id');
    }
}
