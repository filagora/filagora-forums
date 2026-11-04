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
 * A person following a forum, to hear of new threads in it.
 *
 * @property int $id
 * @property int $forum_id
 * @property int $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ForumSubscription extends Model
{
    protected $fillable = [
        'forum_id',
        'user_id',
    ];

    public function getTable(): string
    {
        return Tables::name('forum_subscriptions');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function forum(): BelongsTo
    {
        return $this->belongsTo(Models::class('forum'), 'forum_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'user_id');
    }
}
