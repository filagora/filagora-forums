<?php

declare(strict_types=1);

namespace Filagora\Forums\Models;

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserModel;
use Filagora\Forums\Enums\PollStatus;
use Filagora\Forums\Support\Models;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A poll on a thread: one per thread, its questions, and the votes cast in it.
 *
 * @property int $id
 * @property int $thread_id
 * @property int|null $created_by_id
 * @property PollStatus $status
 * @property int|null $closed_by_id
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Poll extends Model
{
    protected $fillable = [
        'thread_id',
        'created_by_id',
        'status',
        'closed_by_id',
        'closed_at',
    ];

    public function getTable(): string
    {
        return Tables::name('polls');
    }

    protected function casts(): array
    {
        return [
            'status' => PollStatus::class,
            'closed_at' => 'datetime',
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
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'created_by_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'closed_by_id');
    }

    /**
     * @return HasMany<Model, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Models::class('poll_question'), 'poll_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<Model, $this>
     */
    public function votes(): HasMany
    {
        return $this->hasMany(Models::class('poll_vote'), 'poll_id');
    }

    public function isOpen(): bool
    {
        return $this->status === PollStatus::OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === PollStatus::CLOSED;
    }

    /**
     * Whether anyone has voted. The questions can only be changed while this is false.
     */
    public function hasVotes(): bool
    {
        return $this->votes()->exists();
    }

    public function hasVoted(?Authenticatable $user): bool
    {
        return $user !== null && $this->votes()->where('user_id', $user->getAuthIdentifier())->exists();
    }
}
