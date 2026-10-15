<?php

declare(strict_types=1);

namespace Filagora\Forums\Models;

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserModel;
use Filagora\Forums\Support\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A new post waiting to go into someone's next daily or weekly digest, and when it was sent.
 *
 * @property int $id
 * @property int $user_id
 * @property int $thread_id
 * @property int $post_id
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ThreadSubscriptionDigestItem extends Model
{
    protected $fillable = [
        'user_id',
        'thread_id',
        'post_id',
        'sent_at',
    ];

    public function getTable(): string
    {
        return Tables::name('thread_subscription_digest_items');
    }

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'user_id');
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
    public function post(): BelongsTo
    {
        return $this->belongsTo(Models::class('post'), 'post_id');
    }
}
