<?php

declare(strict_types=1);

namespace Filagora\Forums\Models;

use Filagora\Core\Support\Tables;
use Filagora\Forums\Support\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One answer a poll question offers.
 *
 * @property int $id
 * @property int $poll_question_id
 * @property string $label
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PollOption extends Model
{
    protected $fillable = [
        'poll_question_id',
        'label',
        'sort_order',
    ];

    public function getTable(): string
    {
        return Tables::name('poll_options');
    }

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Models::class('poll_question'), 'poll_question_id');
    }

    /**
     * @return HasMany<Model, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(Models::class('poll_vote_answer'), 'poll_option_id');
    }
}
