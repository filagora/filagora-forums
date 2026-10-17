<?php

declare(strict_types=1);

namespace Filagora\Forums\Services;

use Closure;
use Filagora\Forums\Enums\AttachmentRefusal;
use Filagora\Forums\Enums\ContentModerationStatus;
use Filagora\Forums\Exceptions\AttachmentRefused;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\PostAttachment;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Support\Models;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Files on posts. Upload first, then claim: a file chosen while a post is being written is stored
 * with no post (`storeInline`), and when the post is saved the ones its body mentions are claimed
 * for it (`claimInline`); a file uploaded alongside a saved post is attached at once (`store`).
 * What nobody claims is cleaned up after `attachments.orphan_hours`.
 *
 * Who may see a file follows the post it belongs to: a file waiting to be claimed is its
 * uploader's alone; a file on a post needs the forum, the thread and the post to be visible to
 * them (a post awaiting approval or deleted is visible to its author and to staff only). Other
 * packages that attach files to something else (private messages) add a rule with `authorizeUsing`.
 * Images get a thumbnail when `intervention/image` is installed. The routes and the controller that
 * calls `stream()` are yours.
 */
class Attachments
{
    /** @var list<Closure(PostAttachment, ?Authenticatable): ?bool> */
    private array $authorizers = [];

    public function __construct(private readonly ForumAccess $access) {}

    /**
     * Add a rule for files attached to something other than a post. It is asked about files that
     * belong to neither a post nor a thread and are not waiting to be claimed; return true or false
     * to decide, or null if the file is not yours to judge.
     *
     * @param  Closure(PostAttachment, ?Authenticatable): ?bool  $rule
     */
    public function authorizeUsing(Closure $rule): void
    {
        $this->authorizers[] = $rule;
    }

    /**
     * Attach uploaded files to a saved post.
     *
     * @param  list<UploadedFile>  $files
     * @return list<PostAttachment>
     *
     * @throws AttachmentRefused
     */
    public function store(Post $post, Authenticatable $uploader, array $files): array
    {
        if ($files === []) {
            return [];
        }

        $this->guardRate($uploader, count($files));

        return array_map(fn (UploadedFile $file): PostAttachment => $this->storeOne($uploader, $file, $post->getKey(), $post->thread_id), $files);
    }

    /**
     * Store a file for a post that does not exist yet.
     *
     * @throws AttachmentRefused
     */
    public function storeInline(Authenticatable $uploader, UploadedFile $file): PostAttachment
    {
        $this->guardRate($uploader, 1);

        return $this->storeOne($uploader, $file, null, null);
    }

    /**
     * Claim the waiting files a body refers to (by `attachments.inline_pattern`) for a post. Only
     * the uploader's own waiting files are claimed.
     *
     * @return int How many were claimed.
     */
    public function claimInline(string $body, Authenticatable $uploader, Post $post): int
    {
        $pattern = (string) config('filagora-forums.attachments.inline_pattern', '#post-attachments/(\d+)#');

        if (@preg_match_all($pattern, $body, $matches) === false || ($matches[1] ?? []) === []) {
            return 0;
        }

        $ids = array_values(array_unique(array_map('intval', $matches[1])));

        return Models::class('post_attachment')::query()
            ->whereIn('id', $ids)
            ->where('uploader_id', $uploader->getAuthIdentifier())
            ->whereNull('post_id')
            ->whereNull('thread_id')
            ->tap(fn ($query) => $this->onlyUnlinked($query))
            ->update(['post_id' => $post->getKey(), 'thread_id' => $post->thread_id]);
    }

    /**
     * Whether the viewer may see (and download) the file.
     */
    public function canView(PostAttachment $attachment, ?Authenticatable $viewer): bool
    {
        if ($attachment->isUnclaimed()) {
            return $viewer !== null && $attachment->uploader_id !== null && (string) $attachment->uploader_id === (string) $viewer->getAuthIdentifier();
        }

        $post = $attachment->post;
        $thread = $attachment->thread ?? ($post instanceof Post ? $post->thread : null);

        if (! $thread instanceof Thread) {
            foreach ($this->authorizers as $rule) {
                $decision = $rule($attachment, $viewer);

                if ($decision !== null) {
                    return $decision;
                }
            }

            return false;
        }

        $forum = $thread->forum;

        if (! $forum instanceof Forum || ! $this->access->canViewForum($forum, $viewer) || ! $this->access->canAccessThread($thread, $viewer)) {
            return false;
        }

        return ! $post instanceof Post || $this->canSeePost($post, $viewer);
    }

    /**
     * The files of a thread the viewer may see, oldest first.
     *
     * @return Collection<int, PostAttachment>
     */
    public function visibleInThread(Thread $thread, ?Authenticatable $viewer): Collection
    {
        /** @var Collection<int, PostAttachment> $attachments */
        $attachments = Models::class('post_attachment')::query()
            ->with(['post', 'thread'])
            ->where('thread_id', $thread->getKey())
            ->orderBy('post_id')
            ->orderBy('id')
            ->get();

        return $attachments->filter(fn (PostAttachment $attachment): bool => $this->canView($attachment, $viewer))->values();
    }

    /**
     * Serve the file (or its thumbnail), counting a download. Check `canView()` first.
     *
     * @throws AttachmentRefused when the file is no longer on its disk
     */
    public function stream(PostAttachment $attachment, bool $thumbnail = false, bool $inline = false): StreamedResponse
    {
        $path = (string) ($thumbnail ? $attachment->thumbnail_storage_path : $attachment->storage_path);
        $disk = Storage::disk($attachment->storage_disk !== '' ? $attachment->storage_disk : $this->disk());

        if ($path === '' || ! $disk->exists($path)) {
            throw new AttachmentRefused(AttachmentRefusal::FILE_MISSING);
        }

        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            throw new AttachmentRefused(AttachmentRefusal::FILE_MISSING);
        }

        if (! $thumbnail) {
            $attachment->newQuery()->whereKey($attachment->getKey())->increment('download_count');
        }

        $disposition = ($thumbnail || $inline) && $attachment->isInlineDisplayable() ? 'inline' : 'attachment';
        $headers = [
            'Content-Type' => trim((string) $attachment->mime_type) !== '' ? (string) $attachment->mime_type : ($disk->mimeType($path) ?: 'application/octet-stream'),
            'Content-Disposition' => $disposition.'; filename="'.$this->safeFilename($attachment->original_filename, (string) $attachment->extension).'"',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (! $thumbnail && $attachment->filesize_bytes > 0) {
            $headers['Content-Length'] = (string) $attachment->filesize_bytes;
        }

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, $headers);
    }

    /**
     * Remove the file, its thumbnail and the row.
     */
    public function delete(PostAttachment $attachment): void
    {
        $disk = Storage::disk($attachment->storage_disk !== '' ? $attachment->storage_disk : $this->disk());

        $disk->delete($attachment->storage_path);

        if ($attachment->thumbnail_storage_path !== null) {
            $disk->delete($attachment->thumbnail_storage_path);
        }

        $attachment->delete();
    }

    /**
     * Remove the files nobody claimed within `$hours` hours of their upload.
     *
     * @return int How many were removed (counted, not removed, when `$dryRun`).
     */
    public function pruneOrphaned(int $hours, bool $dryRun = false): int
    {
        $orphans = Models::class('post_attachment')::query()
            ->whereNull('post_id')
            ->whereNull('thread_id')
            ->tap(fn ($query) => $this->onlyUnlinked($query))
            ->where('created_at', '<', now()->subHours(max(1, $hours)))
            ->get();

        if (! $dryRun) {
            foreach ($orphans as $attachment) {
                assert($attachment instanceof PostAttachment);
                $this->delete($attachment);
            }
        }

        return $orphans->count();
    }

    private function canSeePost(Post $post, ?Authenticatable $viewer): bool
    {
        if ($this->access->actsAsStaff($viewer)) {
            return true;
        }

        if ($post->moderation_status === ContentModerationStatus::APPROVED && ! $post->is_deleted) {
            return true;
        }

        return $viewer !== null && (string) $post->author_id === (string) $viewer->getAuthIdentifier();
    }

    private function storeOne(Authenticatable $uploader, UploadedFile $file, int|string|null $postId, int|string|null $threadId): PostAttachment
    {
        $disk = $this->disk();
        $directory = trim((string) config('filagora-forums.attachments.directory', 'attachments/uploads'), '/').'/'.now()->format('Y/m');
        $path = Storage::disk($disk)->putFile($directory, $file);

        if ($path === false) {
            throw new AttachmentRefused(AttachmentRefusal::UPLOAD_FAILED);
        }

        $mime = (string) ($file->getMimeType() ?? '');
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $width = $height = $thumbnail = $thumbnailWidth = $thumbnailHeight = null;

        if (str_starts_with($mime, 'image/')) {
            $absolute = Storage::disk($disk)->path($path);
            $size = @getimagesize($absolute);

            if (is_array($size)) {
                [$width, $height] = [$size[0], $size[1]];
            }

            [$thumbnail, $thumbnailWidth, $thumbnailHeight] = $this->makeThumbnail($disk, $directory, $absolute, $extension);
        }

        $attachment = Models::class('post_attachment')::query()->create([
            'post_id' => $postId,
            'thread_id' => $threadId,
            'uploader_id' => $uploader->getAuthIdentifier(),
            'original_filename' => $file->getClientOriginalName(),
            'extension' => $extension,
            'mime_type' => $mime,
            'filesize_bytes' => (int) $file->getSize(),
            'width' => $width,
            'height' => $height,
            'thumbnail_width' => $thumbnailWidth,
            'thumbnail_height' => $thumbnailHeight,
            'filehash' => hash_file('sha256', $file->getRealPath() ?: '') ?: null,
            'storage_disk' => $disk,
            'storage_path' => $path,
            'thumbnail_storage_path' => $thumbnail,
        ]);
        assert($attachment instanceof PostAttachment);

        return $attachment;
    }

    /**
     * @return array{0: string|null, 1: int|null, 2: int|null} Path, width and height; all null without the image library or on any failure.
     */
    private function makeThumbnail(string $disk, string $directory, string $absolutePath, string $extension): array
    {
        if (! class_exists(ImageManager::class)) {
            return [null, null, null];
        }

        try {
            $image = (new ImageManager(new Driver))->decodePath($absolutePath)->scaleDown(width: max(1, (int) config('filagora-forums.attachments.thumbnail_width', 640)));
            $relative = $directory.'/thumbnails/'.pathinfo($absolutePath, PATHINFO_FILENAME).'.'.$extension;
            Storage::disk($disk)->makeDirectory($directory.'/thumbnails');
            $image->save(Storage::disk($disk)->path($relative));

            return [$relative, $image->width(), $image->height()];
        } catch (Throwable) {
            return [null, null, null];
        }
    }

    private function guardRate(Authenticatable $uploader, int $incoming): void
    {
        $recent = Models::class('post_attachment')::query()
            ->where('uploader_id', $uploader->getAuthIdentifier())
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($recent + $incoming > max(0, (int) config('filagora-forums.attachments.max_per_hour', 30))) {
            throw new AttachmentRefused(AttachmentRefusal::RATE_LIMITED);
        }
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function onlyUnlinked(Builder $query): void
    {
        // Columns other packages add (a private message link) must be empty too.
        foreach (['private_message_id'] as $column) {
            if (Schema::hasColumn($query->getModel()->getTable(), $column)) {
                $query->whereNull($column);
            }
        }
    }

    private function safeFilename(string $name, string $extension): string
    {
        $clean = trim((string) preg_replace('/[^\w. \-]+/u', '_', basename(str_replace('\\', '/', $name))));

        if ($clean === '' || $clean === '.' || $clean === '..') {
            return 'attachment'.($extension !== '' ? ".{$extension}" : '');
        }

        return $clean;
    }

    private function disk(): string
    {
        return (string) config('filagora-forums.attachments.disk', 'local');
    }
}
