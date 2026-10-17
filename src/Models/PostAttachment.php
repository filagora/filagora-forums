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
 * A file stored on a disk, attached to a post, or uploaded and waiting to be claimed by one.
 *
 * @property int $id
 * @property int|null $post_id
 * @property int|null $thread_id
 * @property int|null $uploader_id
 * @property string $original_filename
 * @property string|null $caption
 * @property string|null $extension
 * @property string|null $mime_type
 * @property int $filesize_bytes
 * @property int|null $width
 * @property int|null $height
 * @property int|null $thumbnail_width
 * @property int|null $thumbnail_height
 * @property string|null $filehash
 * @property string $storage_disk
 * @property string $storage_path
 * @property string|null $thumbnail_storage_path
 * @property int $download_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PostAttachment extends Model
{
    /** Image types that are safe to show in the page. SVG can carry script, so it is a download. */
    private const DISPLAYABLE_IMAGES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];

    private const DISPLAYABLE_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    private const DISPLAYABLE_DOCUMENTS = ['application/pdf', 'text/plain', 'text/csv', 'application/json', 'text/json', 'text/xml', 'application/xml'];

    private const DISPLAYABLE_DOCUMENT_EXTENSIONS = ['pdf', 'txt', 'csv', 'json', 'xml'];

    protected $fillable = [
        'post_id',
        'thread_id',
        'uploader_id',
        'original_filename',
        'caption',
        'extension',
        'mime_type',
        'filesize_bytes',
        'width',
        'height',
        'thumbnail_width',
        'thumbnail_height',
        'filehash',
        'storage_disk',
        'storage_path',
        'thumbnail_storage_path',
        'download_count',
    ];

    public function getTable(): string
    {
        return Tables::name('post_attachments');
    }

    protected function casts(): array
    {
        return [
            'filesize_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'thumbnail_width' => 'integer',
            'thumbnail_height' => 'integer',
            'download_count' => 'integer',
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
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'uploader_id');
    }

    /**
     * Not yet part of a post (or of anything else a package links it to): only its uploader may
     * see it, and it is cleaned up if it stays that way.
     */
    public function isUnclaimed(): bool
    {
        return $this->post_id === null && $this->thread_id === null && $this->getAttribute('private_message_id') === null;
    }

    public function isImage(): bool
    {
        $mime = strtolower((string) $this->mime_type);

        return in_array($mime, self::DISPLAYABLE_IMAGES, true)
            || ($mime === '' && in_array(strtolower((string) $this->extension), self::DISPLAYABLE_IMAGE_EXTENSIONS, true));
    }

    /**
     * Whether a browser may be asked to show it in the page rather than download it.
     */
    public function isInlineDisplayable(): bool
    {
        if ($this->isImage()) {
            return true;
        }

        $mime = strtolower((string) $this->mime_type);

        return in_array($mime, self::DISPLAYABLE_DOCUMENTS, true)
            || ($mime === '' && in_array(strtolower((string) $this->extension), self::DISPLAYABLE_DOCUMENT_EXTENSIONS, true));
    }

    public function displaySize(): string
    {
        $bytes = $this->filesize_bytes;

        if ($bytes <= 0) {
            return 'Unknown';
        }

        foreach (['B', 'KB', 'MB', 'GB'] as $index => $unit) {
            if ($bytes < 1024 ** ($index + 1) || $unit === 'GB') {
                return $index === 0 ? "{$bytes} B" : rtrim(rtrim(number_format($bytes / 1024 ** $index, 1), '0'), '.').' '.$unit;
            }
        }

        return "{$bytes} B";
    }
}
