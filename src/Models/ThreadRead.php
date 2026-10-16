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
 * When one person last read one thread, and whether they flagged it unread by hand (which beats
 * every read time until they open the thread again).
 *
 * @property int $id
 * @property int $user_id
 * @property int $thread_id
 * @property Carbon $last_read_at
 * @property bool $is_unread_override
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ThreadRead extends Model
{
    protected $fillable = [
        'user_id',
        'thread_id',
        'last_read_at',
        'is_unread_override',
    ];

    public function getTable(): string
    {
        return Tables::name('thread_reads');
    }

    protected function casts(): array
    {
        return [
            'last_read_at' => 'datetime',
            'is_unread_override' => 'boolean',
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
}
