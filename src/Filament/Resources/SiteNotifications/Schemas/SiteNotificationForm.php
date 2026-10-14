<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Resources\SiteNotifications\Schemas;

use Filagora\Forums\Enums\AnnouncementAudience;
use Filagora\Forums\Enums\AnnouncementSeverity;
use Filagora\Forums\Enums\AnnouncementStatus;
use Filagora\Forums\Targeting\TargetingRules;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SiteNotificationForm
{
    public static function configure(Schema $schema): Schema
    {
        $t = static fn (string $key): string => __("filagora-forums::announcements.form.{$key}");

        return $schema->components([
            Section::make($t('content'))->columnSpanFull()->schema([
                TextInput::make('title')->label($t('title'))->required()->maxLength(255),
                MarkdownEditor::make('body')->label($t('body'))->required(),
            ]),

            Section::make($t('publishing'))->columnSpanFull()->columns(3)->schema([
                Select::make('status')->label($t('status'))->options(AnnouncementStatus::options())->default(AnnouncementStatus::DRAFT->value)->required(),
                DateTimePicker::make('starts_at')->label($t('starts_at'))->helperText($t('starts_at_help')),
                DateTimePicker::make('ends_at')->label($t('ends_at'))->helperText($t('ends_at_help'))->after('starts_at'),
            ]),

            Section::make($t('display'))->columnSpanFull()->columns(2)->schema([
                Select::make('audience')->label($t('audience'))->options(AnnouncementAudience::options())->default(AnnouncementAudience::EVERYONE->value)->required(),
                Select::make('severity')->label($t('severity'))->options(AnnouncementSeverity::options())->default(AnnouncementSeverity::INFO->value)->required(),
                TextInput::make('priority')->label($t('priority'))->helperText($t('priority_help'))->numeric()->minValue(0)->default(0)->required(),
                Toggle::make('is_dismissible')->label($t('is_dismissible'))->helperText($t('is_dismissible_help'))->default(true),
                Toggle::make('is_persistent')->label($t('is_persistent'))->helperText($t('is_persistent_help'))->default(true),
            ]),

            Section::make($t('targeting'))->columnSpanFull()->description($t('targeting_help'))->schema([
                Repeater::make('targeting_rules')
                    ->label($t('rules'))
                    ->addActionLabel($t('add_rule'))
                    ->defaultItems(0)
                    ->schema(self::ruleFields())
                    ->columns(2),
            ]),
        ]);
    }

    /**
     * The picker for a rule's type, and under it each registered type's own fields, shown when
     * that type is picked.
     *
     * @return list<Component>
     */
    private static function ruleFields(): array
    {
        $rules = app(TargetingRules::class);

        $fields = [
            Select::make('type')
                ->label(__('filagora-forums::announcements.form.rule_type'))
                ->options(fn (): array => $rules->options())
                ->required()
                ->live(),
        ];

        foreach ($rules->all() as $type => $rule) {
            if ($rule->schema() === []) {
                continue;
            }

            $fields[] = Group::make($rule->schema())
                ->columnSpanFull()
                ->columns(2)
                ->visible(fn (Get $get): bool => $get('type') === $type);
        }

        return $fields;
    }
}
