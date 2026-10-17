<?php

declare(strict_types=1);

use Filagora\Core\Privacy\PrivacyContributorRegistry;
use Filagora\Core\Privacy\RetentionRuleRegistry;
use Filagora\Entitlements\Models\UserEntitlementGrant;
use Filagora\Forums\Enums\AttachmentRefusal;
use Filagora\Forums\Enums\ContentModerationStatus;
use Filagora\Forums\Enums\ForumVisibility;
use Filagora\Forums\Exceptions\AttachmentRefused;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\ForumGroup;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\PostAttachment;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Services\Attachments;
use Filagora\Forums\Services\ForumAccess;
use Filagora\Forums\Support\Abilities;
use Filagora\Forums\Tests\Support\TestUser;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/*
 * Time is frozen at noon on a fixed day so "an hour ago" never depends on when the suite runs.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    Storage::fake('local');
    app()->forgetInstance(ForumAccess::class);
    app()->forgetInstance(Attachments::class);
});

afterEach(fn () => Carbon::setTestNow());

function attachments(): Attachments
{
    return app(Attachments::class);
}

function attachmentPost(array $post = [], array $thread = [], ForumVisibility $visibility = ForumVisibility::PUBLIC, ?TestUser $author = null): Post
{
    $author ??= TestUser::make('Author'.uniqid());
    $group = ForumGroup::query()->firstOrCreate(['slug' => 'main'], ['name' => 'Main']);
    $forum = Forum::create(['forum_group_id' => $group->id, 'name' => 'F'.uniqid(), 'slug' => 'f-'.uniqid(), 'visibility' => $visibility]);
    $thread = Thread::create(array_merge(['forum_id' => $forum->id, 'author_id' => $author->id, 'title' => 'T', 'slug' => 't-'.uniqid()], $thread));

    return Post::create(array_merge(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => 'Hello'], $post))->refresh();
}

function staffMember(): TestUser
{
    $staff = TestUser::make('Staff'.uniqid());
    UserEntitlementGrant::create(['user_id' => $staff->id, 'entitlement_key' => Abilities::MODERATE, 'grant_type' => 'manual']);
    app()->forgetInstance(ForumAccess::class);
    app()->forgetInstance(Attachments::class);

    return $staff;
}

function waitingFile(TestUser $uploader, string $name = 'a.png'): PostAttachment
{
    return attachments()->storeInline($uploader, UploadedFile::fake()->image($name, 100, 80));
}

// ------------------------------------------------------------------ storing

it('attaches files to a post and records what they are', function () {
    $post = attachmentPost();
    $uploader = TestUser::make('Uploader');
    $image = UploadedFile::fake()->image('photo.PNG', 800, 600);
    $document = UploadedFile::fake()->createWithContent('notes.txt', 'plain words');

    [$first, $second] = attachments()->store($post, $uploader, [$image, $document]);

    expect($first->post_id)->toBe($post->id)->and($first->thread_id)->toBe($post->thread_id)->and($first->uploader_id)->toBe($uploader->id)
        ->and($first->original_filename)->toBe('photo.PNG')->and($first->extension)->toBe('png')->and($first->mime_type)->toBe('image/png')
        ->and($first->width)->toBe(800)->and($first->height)->toBe(600)
        ->and($first->storage_disk)->toBe('local')->and($first->storage_path)->toStartWith('attachments/uploads/2026/06/')
        ->and($first->filehash)->toBe(hash_file('sha256', Storage::disk('local')->path($first->storage_path)))
        ->and($first->filesize_bytes)->toBe(Storage::disk('local')->size($first->storage_path))
        ->and($second->original_filename)->toBe('notes.txt')->and($second->width)->toBeNull()->and($second->thumbnail_storage_path)->toBeNull()
        ->and($second->filehash)->toBe(hash('sha256', 'plain words'));

    Storage::disk('local')->assertExists([$first->storage_path, $second->storage_path]);
});

it('makes a thumbnail of an image, scaled down to the configured width', function () {
    $post = attachmentPost();

    $big = attachments()->store($post, TestUser::make('U'), [UploadedFile::fake()->image('big.jpg', 1000, 500)])[0];
    $small = attachments()->store($post, TestUser::make('U2'), [UploadedFile::fake()->image('small.jpg', 100, 50)])[0];

    expect($big->thumbnail_width)->toBe(640)->and($big->thumbnail_height)->toBe(320)
        ->and($big->thumbnail_storage_path)->toContain('/thumbnails/')->and($small->thumbnail_width)->toBe(100);
    Storage::disk('local')->assertExists($big->thumbnail_storage_path);

    config(['filagora-forums.attachments.thumbnail_width' => 200]);
    $narrow = attachments()->store($post, TestUser::make('U3'), [UploadedFile::fake()->image('n.jpg', 1000, 500)])[0];
    expect($narrow->thumbnail_width)->toBe(200);
});

it('stores a file that is not a readable image without a thumbnail', function () {
    $fake = UploadedFile::fake()->createWithContent('broken.png', 'this is not an image');

    $attachment = attachments()->store(attachmentPost(), TestUser::make('U'), [$fake])[0];

    expect($attachment->thumbnail_storage_path)->toBeNull()->and($attachment->width)->toBeNull();
});

it('does nothing for an empty list', function () {
    expect(attachments()->store(attachmentPost(), TestUser::make('U'), []))->toBe([])->and(PostAttachment::count())->toBe(0);
});

it('stores a file for a post that does not exist yet, waiting to be claimed', function () {
    $attachment = waitingFile(TestUser::make('U'));

    expect($attachment->post_id)->toBeNull()->and($attachment->thread_id)->toBeNull()->and($attachment->isUnclaimed())->toBeTrue();
});

it('refuses a person more than the allowed uploads an hour, counting what they already sent', function () {
    config(['filagora-forums.attachments.max_per_hour' => 3]);
    $uploader = TestUser::make('U');
    $post = attachmentPost();

    attachments()->store($post, $uploader, [UploadedFile::fake()->image('1.png'), UploadedFile::fake()->image('2.png')]);
    waitingFile($uploader, '3.png');

    $refusal = fn (callable $call) => rescue(fn () => $call(), fn (AttachmentRefused $e) => $e->reason, false);
    expect($refusal(fn () => waitingFile($uploader, '4.png')))->toBe(AttachmentRefusal::RATE_LIMITED)
        ->and($refusal(fn () => attachments()->store($post, $uploader, [UploadedFile::fake()->image('5.png')])))->toBe(AttachmentRefusal::RATE_LIMITED)
        ->and(PostAttachment::count())->toBe(3);
});

it('lets the hour pass and keeps one person\'s limit apart from another\'s', function () {
    config(['filagora-forums.attachments.max_per_hour' => 1]);
    $alice = TestUser::make('Alice');
    $bob = TestUser::make('Bob');
    waitingFile($alice);
    waitingFile($bob);

    expect(fn () => waitingFile($alice))->toThrow(AttachmentRefused::class);

    $this->travel(61)->minutes();
    expect(waitingFile($alice))->toBeInstanceOf(PostAttachment::class);
});

it('can be told to refuse every upload', function () {
    config(['filagora-forums.attachments.max_per_hour' => 0]);

    expect(fn () => waitingFile(TestUser::make('U')))->toThrow(AttachmentRefused::class);
});

it('reports a disk that would not take the file', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('putFile')->andReturn(false);
    Storage::set('local', $disk);

    expect(fn () => waitingFile(TestUser::make('U')))->toThrow(AttachmentRefused::class, 'could not be uploaded');
});

it('stores to the configured disk and directory', function () {
    Storage::fake('files');
    config(['filagora-forums.attachments.disk' => 'files', 'filagora-forums.attachments.directory' => '/custom/dir/']);

    $attachment = waitingFile(TestUser::make('U'));

    expect($attachment->storage_disk)->toBe('files')->and($attachment->storage_path)->toStartWith('custom/dir/2026/06/');
    Storage::disk('files')->assertExists($attachment->storage_path);
});

// ----------------------------------------------------------------- claiming

it('claims the waiting files a post body refers to, and only the uploader\'s own', function () {
    $uploader = TestUser::make('U');
    $other = TestUser::make('Other');
    $mine = waitingFile($uploader);
    $alsoMine = waitingFile($uploader, 'b.png');
    $notMentioned = waitingFile($uploader, 'c.png');
    $theirs = waitingFile($other, 'd.png');
    $post = attachmentPost(['author_id' => $uploader->id]);

    $body = "![a](/post-attachments/{$mine->id}) and ![b](/post-attachments/{$alsoMine->id}?disposition=inline) twice /post-attachments/{$mine->id} and /post-attachments/{$theirs->id}";

    expect(attachments()->claimInline($body, $uploader, $post))->toBe(2)
        ->and($mine->refresh()->post_id)->toBe($post->id)->and($mine->thread_id)->toBe($post->thread_id)
        ->and($alsoMine->refresh()->post_id)->toBe($post->id)
        ->and($notMentioned->refresh()->post_id)->toBeNull()
        ->and($theirs->refresh()->post_id)->toBeNull();
});

it('does not take a file that is already on another post', function () {
    $uploader = TestUser::make('U');
    $first = attachmentPost();
    $second = attachmentPost();
    $file = waitingFile($uploader);

    attachments()->claimInline("/post-attachments/{$file->id}", $uploader, $first);

    expect(attachments()->claimInline("/post-attachments/{$file->id}", $uploader, $second))->toBe(0)
        ->and($file->refresh()->post_id)->toBe($first->id);
});

it('claims nothing when the body names no file or the pattern is not a pattern', function () {
    $uploader = TestUser::make('U');
    $file = waitingFile($uploader);
    $post = attachmentPost();

    expect(attachments()->claimInline('no files here', $uploader, $post))->toBe(0);

    config(['filagora-forums.attachments.inline_pattern' => '#unterminated(']);
    expect(attachments()->claimInline("/post-attachments/{$file->id}", $uploader, $post))->toBe(0)
        ->and($file->refresh()->post_id)->toBeNull();
});

it('follows the configured pattern for the file ids in a body', function () {
    config(['filagora-forums.attachments.inline_pattern' => '#/files/(\d+)/raw#']);
    $uploader = TestUser::make('U');
    $file = waitingFile($uploader);
    $post = attachmentPost();

    expect(attachments()->claimInline("/post-attachments/{$file->id} /files/{$file->id}/raw", $uploader, $post))->toBe(1);
});

// ------------------------------------------------------------------- access

it('shows a waiting file to its uploader alone', function () {
    $uploader = TestUser::make('U');
    $file = waitingFile($uploader);

    expect(attachments()->canView($file, $uploader))->toBeTrue()
        ->and(attachments()->canView($file, TestUser::make('Other')))->toBeFalse()
        ->and(attachments()->canView($file, null))->toBeFalse()
        ->and(attachments()->canView($file, staffMember()))->toBeFalse();
});

it('shows a file on a public post to everyone', function () {
    $post = attachmentPost();
    $file = attachments()->store($post, TestUser::make('U'), [UploadedFile::fake()->image('a.png')])[0];

    expect(attachments()->canView($file, null))->toBeTrue()->and(attachments()->canView($file, TestUser::make('Member')))->toBeTrue();
});

it('follows the visibility of the forum', function () {
    $registered = attachments()->store(attachmentPost(visibility: ForumVisibility::REGISTERED), TestUser::make('U'), [UploadedFile::fake()->image('a.png')])[0];
    $staffOnly = attachments()->store(attachmentPost(visibility: ForumVisibility::STAFF_ONLY), TestUser::make('U2'), [UploadedFile::fake()->image('b.png')])[0];
    $member = TestUser::make('Member');
    $staff = staffMember();

    expect(attachments()->canView($registered, null))->toBeFalse()->and(attachments()->canView($registered, $member))->toBeTrue()
        ->and(attachments()->canView($staffOnly, $member))->toBeFalse()->and(attachments()->canView($staffOnly, null))->toBeFalse()
        ->and(attachments()->canView($staffOnly, $staff))->toBeTrue();
});

it('follows the thread, showing an unapproved thread to its author and staff', function () {
    $author = TestUser::make('Author');
    $post = attachmentPost(thread: ['moderation_status' => ContentModerationStatus::PENDING], author: $author);
    $file = attachments()->store($post, $author, [UploadedFile::fake()->image('a.png')])[0];

    expect(attachments()->canView($file, $author))->toBeTrue()->and(attachments()->canView($file, staffMember()))->toBeTrue()
        ->and(attachments()->canView($file, TestUser::make('Other')))->toBeFalse()->and(attachments()->canView($file, null))->toBeFalse();
});

it('follows the post, showing one awaiting approval or deleted to its author and staff only', function (array $post) {
    $author = TestUser::make('Author');
    $theirs = attachmentPost($post, author: $author);
    $file = attachments()->store($theirs, $author, [UploadedFile::fake()->image('a.png')])[0];

    expect(attachments()->canView($file, $author))->toBeTrue()->and(attachments()->canView($file, staffMember()))->toBeTrue()
        ->and(attachments()->canView($file, TestUser::make('Other')))->toBeFalse()->and(attachments()->canView($file, null))->toBeFalse();
})->with([
    'pending' => [['moderation_status' => ContentModerationStatus::PENDING]],
    'rejected' => [['moderation_status' => ContentModerationStatus::REJECTED]],
    'deleted' => [['is_deleted' => true]],
]);

it('shows a file kept after its post went only to those who can see the thread', function () {
    $post = attachmentPost();
    $file = attachments()->store($post, TestUser::make('U'), [UploadedFile::fake()->image('a.png')])[0];
    $post->delete();

    expect($file->refresh()->post_id)->toBeNull()->and($file->thread_id)->toBe($post->thread_id)
        ->and(attachments()->canView($file, null))->toBeTrue();
});

it('lets another package decide for files that belong to something else', function () {
    $file = waitingFile(TestUser::make('U'));
    $file->setAttribute('private_message_id', 7);
    $viewer = TestUser::make('Viewer');

    expect($file->isUnclaimed())->toBeFalse()->and(attachments()->canView($file, $viewer))->toBeFalse();

    attachments()->authorizeUsing(fn (PostAttachment $attachment, $user) => null);
    expect(attachments()->canView($file, $viewer))->toBeFalse();

    attachments()->authorizeUsing(fn (PostAttachment $attachment, $user) => $user !== null && $user->getAuthIdentifier() === $viewer->id ? true : false);
    expect(attachments()->canView($file, $viewer))->toBeTrue()->and(attachments()->canView($file, TestUser::make('Other')))->toBeFalse();
});

it('lists the files of a thread a person may see, in order', function () {
    $author = TestUser::make('Author');
    $post = attachmentPost(author: $author);
    $thread = $post->thread;
    $second = Post::create(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => 'Second']);
    $hidden = Post::create(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => 'Hidden', 'moderation_status' => ContentModerationStatus::PENDING]);
    $b = attachments()->store($second, $author, [UploadedFile::fake()->image('b.png')])[0];
    $a = attachments()->store($post, $author, [UploadedFile::fake()->image('a1.png'), UploadedFile::fake()->image('a2.png')]);
    attachments()->store($hidden, $author, [UploadedFile::fake()->image('h.png')]);

    expect(attachments()->visibleInThread($thread, null)->pluck('id')->all())->toBe([$a[0]->id, $a[1]->id, $b->id])
        ->and(attachments()->visibleInThread($thread, $author)->count())->toBe(4);
});

// ------------------------------------------------------------------ serving

it('serves a file as a download with safe headers, and counts it', function () {
    $file = attachments()->store(attachmentPost(), TestUser::make('U'), [UploadedFile::fake()->createWithContent('report.csv', "a,b\n1,2\n")])[0];

    $response = attachments()->stream($file);
    ob_start();
    $response->sendContent();
    $body = ob_get_clean();

    expect($body)->toBe("a,b\n1,2\n")
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="report.csv"')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Length'))->toBe((string) $file->filesize_bytes)
        ->and($file->refresh()->download_count)->toBe(1);

    attachments()->stream($file);
    expect($file->refresh()->download_count)->toBe(2);
});

it('shows an image or a document in the page only when asked, and never an SVG', function () {
    $post = attachmentPost();
    $image = attachments()->store($post, TestUser::make('U'), [UploadedFile::fake()->image('pic.png')])[0];
    $svg = attachments()->store($post, TestUser::make('U2'), [UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])[0];
    $archive = attachments()->store($post, TestUser::make('U3'), [UploadedFile::fake()->createWithContent('a.zip', 'PK')])[0];
    $svg->update(['mime_type' => 'image/svg+xml']);

    expect(attachments()->stream($image)->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and(attachments()->stream($image, inline: true)->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and(attachments()->stream($svg->refresh(), inline: true)->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and(attachments()->stream($archive, inline: true)->headers->get('Content-Disposition'))->toStartWith('attachment');
});

it('serves a thumbnail in the page without counting a download', function () {
    $image = attachments()->store(attachmentPost(), TestUser::make('U'), [UploadedFile::fake()->image('pic.png', 900, 900)])[0];

    $response = attachments()->stream($image, thumbnail: true);

    expect($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->headers->has('Content-Length'))->toBeFalse()
        ->and($image->refresh()->download_count)->toBe(0);
});

it('makes the file name safe for the header', function (string $name, string $expected) {
    $file = waitingFile(TestUser::make('U'));
    $file->update(['original_filename' => $name]);

    expect(attachments()->stream($file->refresh())->headers->get('Content-Disposition'))->toBe("attachment; filename=\"{$expected}\"");
})->with([
    'a windows path' => ['..\\evil\\me.png', 'me.png'],
    'quotes' => ['a"b.png', 'a_b.png'],
    'a path' => ['../../etc/passwd', 'passwd'],
    'control characters' => ["bad\r\nname.png", 'bad_name.png'],
    'unicode kept' => ['résumé v2.pdf', 'résumé v2.pdf'],
]);

it('falls back to a plain name when none is left', function () {
    $file = waitingFile(TestUser::make('U'));
    $file->update(['original_filename' => '..', 'extension' => 'png']);

    expect(attachments()->stream($file->refresh())->headers->get('Content-Disposition'))->toBe('attachment; filename="attachment.png"');
});

it('says so when the file is gone from the disk', function () {
    $file = waitingFile(TestUser::make('U'));
    Storage::disk('local')->delete([$file->storage_path]);

    expect(fn () => attachments()->stream($file))->toThrow(AttachmentRefused::class, 'no longer available')
        ->and($file->refresh()->download_count)->toBe(0);

    Storage::disk('local')->delete([$file->thumbnail_storage_path]);
    expect(fn () => attachments()->stream($file, thumbnail: true))->toThrow(AttachmentRefused::class);

    $file->update(['thumbnail_storage_path' => null]);
    expect(fn () => attachments()->stream($file->refresh(), thumbnail: true))->toThrow(AttachmentRefused::class);
});

// ------------------------------------------------------------------ cleaning

it('removes the files and the row', function () {
    $file = waitingFile(TestUser::make('U'));
    $paths = [$file->storage_path, $file->thumbnail_storage_path];

    attachments()->delete($file);

    Storage::disk('local')->assertMissing($paths);
    expect(PostAttachment::count())->toBe(0);
});

it('removes unclaimed files after the hours given, and nothing else', function () {
    $uploader = TestUser::make('U');
    $old = waitingFile($uploader);
    $recent = waitingFile($uploader, 'r.png');
    $claimed = attachments()->store(attachmentPost(), $uploader, [UploadedFile::fake()->image('c.png')])[0];
    PostAttachment::whereKey([$old->id, $claimed->id])->toBase()->update(['created_at' => now()->subHours(30)]);
    PostAttachment::whereKey($recent->id)->toBase()->update(['created_at' => now()->subHours(23)]);

    expect(attachments()->pruneOrphaned(24, dryRun: true))->toBe(1)->and(PostAttachment::count())->toBe(3);

    expect(attachments()->pruneOrphaned(24))->toBe(1)
        ->and(PostAttachment::pluck('id')->sort()->values()->all())->toBe([$recent->id, $claimed->id]);
    Storage::disk('local')->assertMissing($old->storage_path);
    Storage::disk('local')->assertExists([$recent->storage_path, $claimed->storage_path]);
});

it('prunes from the command, using the configured hours or --hours', function () {
    $file = waitingFile(TestUser::make('U'));
    PostAttachment::whereKey($file->id)->toBase()->update(['created_at' => now()->subHours(5)]);

    $this->artisan('filagora:forums:prune-attachments')->expectsOutput('Removed 0 unclaimed attachment(s).')->assertExitCode(0);
    $this->artisan('filagora:forums:prune-attachments', ['--hours' => 3, '--dry-run' => true])->expectsOutput('Would remove 1 unclaimed attachment(s).')->assertExitCode(0);
    expect(PostAttachment::count())->toBe(1);

    config(['filagora-forums.attachments.orphan_hours' => 2]);
    $this->artisan('filagora:forums:prune-attachments')->expectsOutput('Removed 1 unclaimed attachment(s).')->assertExitCode(0);
    expect(PostAttachment::count())->toBe(0);
});

it('offers the same clean-up to core\'s retention run, and keeps files when the window is zero', function () {
    $file = waitingFile(TestUser::make('U'));
    PostAttachment::whereKey($file->id)->toBase()->update(['created_at' => now()->subDays(3)]);
    $rule = collect(app(RetentionRuleRegistry::class)->all())->first(fn ($rule) => $rule->key() === 'forums.orphaned_attachments');

    config(['filagora-forums.attachments.orphan_hours' => 0]);
    expect($rule->prune(false))->toBe(0)->and(PostAttachment::count())->toBe(1);

    config(['filagora-forums.attachments.orphan_hours' => 24]);
    expect($rule->prune(true))->toBe(1)->and(PostAttachment::count())->toBe(1)->and($rule->prune(false))->toBe(1)->and(PostAttachment::count())->toBe(0);
});

// ----------------------------------------------------------- keeping the rows

it('keeps the file row when its post or thread goes, and clears the link', function () {
    $post = attachmentPost();
    $file = attachments()->store($post, TestUser::make('U'), [UploadedFile::fake()->image('a.png')])[0];

    $post->thread->delete();

    expect($file->refresh()->post_id)->toBeNull()->and($file->thread_id)->toBeNull()->and(PostAttachment::count())->toBe(1);
});

it('keeps the file row when its uploader goes', function () {
    $uploader = TestUser::make('U');
    $file = attachments()->store(attachmentPost(), $uploader, [UploadedFile::fake()->image('a.png')])[0];

    $uploader->delete();

    expect($file->refresh()->uploader_id)->toBeNull()->and(PostAttachment::count())->toBe(1);
});

it('exports a person\'s uploads and erases only the ones nobody used', function () {
    $uploader = TestUser::make('U');
    $other = TestUser::make('Other');
    $onPost = attachments()->store(attachmentPost(), $uploader, [UploadedFile::fake()->image('used.png')])[0];
    $waiting = waitingFile($uploader, 'waiting.png');
    $theirs = waitingFile($other, 'theirs.png');
    $contributor = app(PrivacyContributorRegistry::class)->all()['forums'];

    $export = $contributor->export($uploader);
    expect(array_column($export['attachments'], 'original_filename'))->toBe(['used.png', 'waiting.png']);

    $contributor->erase($uploader);

    expect(PostAttachment::pluck('id')->sort()->values()->all())->toBe([$onPost->id, $theirs->id]);
    Storage::disk('local')->assertMissing($waiting->storage_path);
    Storage::disk('local')->assertExists([$onPost->storage_path, $theirs->storage_path]);
});

// ------------------------------------------------------------------ the model

it('knows what is an image and what a browser may show', function (?string $mime, ?string $extension, bool $image, bool $inline) {
    $attachment = new PostAttachment(['mime_type' => $mime, 'extension' => $extension]);

    expect($attachment->isImage())->toBe($image)->and($attachment->isInlineDisplayable())->toBe($inline);
})->with([
    'png' => ['image/png', 'png', true, true],
    'jpeg by extension only' => [null, 'JPG', true, true],
    'svg is not shown' => ['image/svg+xml', 'svg', false, false],
    'svg by extension' => [null, 'svg', false, false],
    'pdf' => ['application/pdf', 'pdf', false, true],
    'text by extension' => ['', 'txt', false, true],
    'zip' => ['application/zip', 'zip', false, false],
    'nothing known' => [null, null, false, false],
]);

it('writes a file size people can read', function (int $bytes, string $text) {
    expect((new PostAttachment(['filesize_bytes' => $bytes]))->displaySize())->toBe($text);
})->with([
    [0, 'Unknown'],
    [512, '512 B'],
    [1024, '1 KB'],
    [1536, '1.5 KB'],
    [5 * 1024 * 1024, '5 MB'],
    [3 * 1024 ** 3, '3 GB'],
]);

it('does not prune a file whose post was deleted, because it still belongs to the thread', function () {
    $post = attachmentPost();
    $file = attachments()->store($post, TestUser::make('U'), [UploadedFile::fake()->image('a.png')])[0];
    $post->delete();
    PostAttachment::whereKey($file->id)->toBase()->update(['created_at' => now()->subDays(3)]);

    expect($file->refresh()->post_id)->toBeNull()->and($file->thread_id)->not->toBeNull()
        ->and(attachments()->pruneOrphaned(24))->toBe(0)
        ->and(PostAttachment::count())->toBe(1);
});

it('never prunes a file waiting less than an hour, however the hours are given', function () {
    $file = waitingFile(TestUser::make('U'));
    PostAttachment::whereKey($file->id)->toBase()->update(['created_at' => now()->subMinutes(30)]);

    expect(attachments()->pruneOrphaned(0))->toBe(0)->and(attachments()->pruneOrphaned(-5))->toBe(0)->and(PostAttachment::count())->toBe(1);
});

it('keeps a person\'s file whose post was deleted when they are erased', function () {
    $uploader = TestUser::make('U');
    $post = attachmentPost();
    $file = attachments()->store($post, $uploader, [UploadedFile::fake()->image('a.png')])[0];
    $post->delete();

    app(PrivacyContributorRegistry::class)->all()['forums']->erase($uploader);

    expect(PostAttachment::whereKey($file->id)->exists())->toBeTrue();
    Storage::disk('local')->assertExists($file->storage_path);
});

it('checks a file linked to a post alone through the post\'s thread', function () {
    $post = attachmentPost(visibility: ForumVisibility::STAFF_ONLY);
    $file = attachments()->store($post, TestUser::make('U'), [UploadedFile::fake()->image('a.png')])[0];
    $file->forceFill(['thread_id' => null])->save();

    expect(attachments()->canView($file->refresh(), TestUser::make('Member')))->toBeFalse()
        ->and(attachments()->canView($file, staffMember()))->toBeTrue();
});

it('says the file is gone when the disk cannot be read even though it reports the file', function () {
    $file = waitingFile(TestUser::make('U'));
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->andReturn(true);
    $disk->shouldReceive('readStream')->andReturn(null);
    Storage::set('local', $disk);

    expect(fn () => attachments()->stream($file))->toThrow(AttachmentRefused::class);
});
