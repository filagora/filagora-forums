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
 * @property int $id
 * @property int $user_id
 * @property int $site_notification_id
 * @property Carbon $dismissed_at
 */
class UserSiteNotificationDismissal extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'site_notification_id',
        'dismissed_at',
    ];

    public function getTable(): string
    {
        return Tables::name('user_site_notification_dismissals');
    }

    protected function casts(): array
    {
        return [
            'dismissed_at' => 'datetime',
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
    public function siteNotification(): BelongsTo
    {
        return $this->belongsTo(Models::class('site_notification'), 'site_notification_id');
    }
}
