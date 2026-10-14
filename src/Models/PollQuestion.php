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
 * One question of a poll, single or multiple choice.
 *
 * @property int $id
 * @property int $poll_id
 * @property string $prompt
 * @property bool $is_multi_select
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PollQuestion extends Model
{
    protected $fillable = [
        'poll_id',
        'prompt',
        'is_multi_select',
        'sort_order',
    ];

    public function getTable(): string
    {
        return Tables::name('poll_questions');
    }

    protected function casts(): array
    {
        return [
            'is_multi_select' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function poll(): BelongsTo
    {
        return $this->belongsTo(Models::class('poll'), 'poll_id');
    }

    /**
     * @return HasMany<Model, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(Models::class('poll_option'), 'poll_question_id')->orderBy('sort_order')->orderBy('id');
    }
}
