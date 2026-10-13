<x-filament-panels::page>
    @php
        $decode = fn (?string $text): string => \Illuminate\Support\Str::limit((string) $text, 160);
    @endphp

    <x-filament::section :heading="__('filagora-forums::messages.queue.pending_threads')" :description="trans_choice('filagora-forums::messages.queue.count', $pendingThreads->count(), ['count' => $pendingThreads->count()])">
        @forelse ($pendingThreads as $thread)
            <div class="flex flex-wrap items-center justify-between gap-3 py-3 {{ $loop->first ? '' : 'border-t border-gray-200 dark:border-white/10' }}">
                <div class="min-w-0">
                    <div class="font-medium">{{ $thread->title }}</div>
                    <div class="text-sm text-gray-500">{{ $thread->forum?->name }} · {{ $thread->author?->{\Filagora\Core\Support\UserModel::titleAttribute()} }} · {{ $thread->created_at?->diffForHumans() }}</div>
                </div>
                <div class="flex gap-2">
                    {{ ($this->approveThreadAction)(['id' => $thread->id]) }}
                    {{ ($this->deleteThreadAction)(['id' => $thread->id]) }}
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500">{{ __('filagora-forums::messages.queue.empty') }}</p>
        @endforelse
    </x-filament::section>

    <x-filament::section :heading="__('filagora-forums::messages.queue.pending_posts')" :description="trans_choice('filagora-forums::messages.queue.count', $pendingPosts->count(), ['count' => $pendingPosts->count()])">
        @forelse ($pendingPosts as $post)
            <div class="flex flex-wrap items-center justify-between gap-3 py-3 {{ $loop->first ? '' : 'border-t border-gray-200 dark:border-white/10' }}">
                <div class="min-w-0">
                    <div class="text-sm text-gray-500">{{ $post->thread?->title }} · {{ $post->author?->{\Filagora\Core\Support\UserModel::titleAttribute()} }} · {{ $post->created_at?->diffForHumans() }}</div>
                    <div class="whitespace-pre-wrap">{{ $decode($post->body) }}</div>
                </div>
                <div class="flex gap-2">
                    {{ ($this->approvePostAction)(['id' => $post->id]) }}
                    {{ ($this->rejectPostAction)(['id' => $post->id]) }}
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500">{{ __('filagora-forums::messages.queue.empty') }}</p>
        @endforelse
    </x-filament::section>

    <x-filament::section :heading="__('filagora-forums::messages.queue.reported_posts')" :description="trans_choice('filagora-forums::messages.queue.count', $reportedPosts->count(), ['count' => $reportedPosts->count()])">
        @forelse ($reportedPosts as $post)
            <div class="flex flex-wrap items-center justify-between gap-3 py-3 {{ $loop->first ? '' : 'border-t border-gray-200 dark:border-white/10' }}">
                <div class="min-w-0">
                    <div class="text-sm text-gray-500">{{ $post->thread?->title }} · {{ $post->author?->{\Filagora\Core\Support\UserModel::titleAttribute()} }} · {{ trans_choice('filagora-forums::messages.queue.open_reports', $post->open_reports_count, ['count' => $post->open_reports_count]) }}</div>
                    <div class="whitespace-pre-wrap">{{ $decode($post->body) }}</div>
                </div>
                <div class="flex gap-2">
                    {{ ($this->resolveReportAction)(['id' => $post->id]) }}
                    {{ ($this->deletePostAction)(['id' => $post->id]) }}
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500">{{ __('filagora-forums::messages.queue.empty') }}</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
