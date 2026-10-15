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
 * One person's reaction of one kind to one post.
 *
 * @property int $id
 * @property int $post_id
 * @property int $user_id
 * @property string $type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Reaction extends Model
{
    protected $fillable = [
        'post_id',
        'user_id',
        'type',
    ];

    public function getTable(): string
    {
        return Tables::name('reactions');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Models::class('post'), 'post_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'user_id');
    }
}
