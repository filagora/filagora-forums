<?php

declare(strict_types=1);

namespace Filagora\Forums\Models;

use Filagora\Core\Support\Tables;
use Filagora\Forums\Support\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An option a vote chose for one question.
 *
 * @property int $id
 * @property int $poll_vote_id
 * @property int $poll_question_id
 * @property int $poll_option_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PollVoteAnswer extends Model
{
    protected $fillable = [
        'poll_vote_id',
        'poll_question_id',
        'poll_option_id',
    ];

    public function getTable(): string
    {
        return Tables::name('poll_vote_answers');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function vote(): BelongsTo
    {
        return $this->belongsTo(Models::class('poll_vote'), 'poll_vote_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Models::class('poll_question'), 'poll_question_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(Models::class('poll_option'), 'poll_option_id');
    }
}
