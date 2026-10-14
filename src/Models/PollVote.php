<?php

declare(strict_types=1);

namespace Filagora\Forums\Models;

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserModel;
use Filagora\Forums\Support\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One user's vote in a poll: cast once, never changed.
 *
 * @property int $id
 * @property int $poll_id
 * @property int $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PollVote extends Model
{
    protected $fillable = [
        'poll_id',
        'user_id',
    ];

    public function getTable(): string
    {
        return Tables::name('poll_votes');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function poll(): BelongsTo
    {
        return $this->belongsTo(Models::class('poll'), 'poll_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'user_id');
    }

    /**
     * @return HasMany<Model, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(Models::class('poll_vote_answer'), 'poll_vote_id');
    }
}
