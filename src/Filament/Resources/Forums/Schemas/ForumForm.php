<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Resources\Forums\Schemas;

use Filagora\Forums\Enums\ForumPostingPolicy;
use Filagora\Forums\Enums\ForumVisibility;
use Filagora\Forums\Support\KeyField;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ForumForm
{
    public static function configure(Schema $schema): Schema
    {
        $t = static fn (string $key): string => __("filagora-forums::messages.forums.form.{$key}");

        return $schema->components([
            Select::make('forum_group_id')->relationship('group', 'name')->required()->searchable()->preload(),
            TextInput::make('name')
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state): void {
                    if (($get('slug') ?? '') === Str::slug((string) $old)) {
                        $set('slug', Str::slug((string) $state));
                    }
                }),
            TextInput::make('slug')->required(),
            Textarea::make('description')->columnSpanFull(),
            Select::make('visibility')->options(ForumVisibility::options())->default(ForumVisibility::PUBLIC->value)->required(),
            KeyField::make('required_entitlement_scope')
                ->label($t('required_entitlement_scope'))
                ->helperText($t('required_entitlement_scope_help')),
            KeyField::make('reply_entitlement_scope')
                ->label($t('reply_entitlement_scope'))
                ->helperText($t('reply_entitlement_scope_help')),
            Select::make('posting_policy')->options(ForumPostingPolicy::options())->default(ForumPostingPolicy::REGISTERED->value)->required(),
            Toggle::make('is_read_only')->default(false),
            Toggle::make('requires_thread_approval')->label($t('requires_thread_approval'))->helperText($t('requires_thread_approval_help'))->default(false),
            Toggle::make('requires_reply_approval')->label($t('requires_reply_approval'))->helperText($t('requires_reply_approval_help'))->default(false),
            Toggle::make('show_when_empty')->label($t('show_when_empty'))->helperText($t('show_when_empty_help'))->default(false),
            TextInput::make('sort_order')->required()->numeric()->default(0),
            Toggle::make('is_active')->default(true),
        ]);
    }
}
