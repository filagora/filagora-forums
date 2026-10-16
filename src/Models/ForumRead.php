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
 * When one person last read (marked read) one forum.
 *
 * @property int $id
 * @property int $user_id
 * @property int $forum_id
 * @property Carbon $last_read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ForumRead extends Model
{
    protected $fillable = [
        'user_id',
        'forum_id',
        'last_read_at',
    ];

    public function getTable(): string
    {
        return Tables::name('forum_reads');
    }

    protected function casts(): array
    {
        return [
            'last_read_at' => 'datetime',
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
    public function forum(): BelongsTo
    {
        return $this->belongsTo(Models::class('forum'), 'forum_id');
    }
}
