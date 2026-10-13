<?php

declare(strict_types=1);

namespace Filagora\Forums\Models;

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserModel;
use Filagora\Forums\Enums\PostReportStatus;
use Filagora\Forums\Support\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $post_id
 * @property int $thread_id
 * @property int $reporter_id
 * @property string $reason
 * @property PostReportStatus $status
 * @property int|null $reviewed_by_id
 * @property Carbon|null $reviewed_at
 * @property string|null $resolution_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PostReport extends Model
{
    protected $fillable = [
        'post_id',
        'thread_id',
        'reporter_id',
        'reason',
        'status',
        'reviewed_by_id',
        'reviewed_at',
        'resolution_note',
    ];

    public function getTable(): string
    {
        return Tables::name('post_reports');
    }

    protected function casts(): array
    {
        return [
            'status' => PostReportStatus::class,
            'reviewed_at' => 'datetime',
        ];
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
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Models::class('thread'), 'thread_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'reporter_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'reviewed_by_id');
    }
}
