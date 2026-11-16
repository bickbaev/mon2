<?php

namespace App\Filament\Resources\CampaignTemplates;

use App\Enums\Periodicity;
use App\Filament\Resources\Campaigns\Schemas\CampaignFields;
use App\Filament\Resources\CampaignTemplates\Pages\CreateCampaignTemplate;
use App\Filament\Resources\CampaignTemplates\Pages\EditCampaignTemplate;
use App\Filament\Resources\CampaignTemplates\Pages\ListCampaignTemplates;
use App\Models\CampaignTemplate;
use App\Models\Node;
use App\Services\CampaignTemplateService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class CampaignTemplateResource extends Resource
{
    protected static ?string $model = CampaignTemplate::class;

    protected static ?string $modelLabel = 'шаблон сбора';

    protected static ?string $pluralModelLabel = 'Шаблоны сборов';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    public static function getEloquentQuery(): Builder
    {
        return CampaignFields::managedQuery(parent::getEloquentQuery())->with('reportType')->withCount('nodes');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            ...CampaignFields::settings(),
            Select::make('periodicity')->label('Периодичность')->required()->live()
                ->options(collect(Periodicity::cases())->mapWithKeys(fn (Periodicity $periodicity): array => [$periodicity->value => $periodicity->label()])->all()),
            Select::make('start_weekday')->label('День недели')->options([1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье'])
                ->visible(fn (Get $get): bool => $get('periodicity') === 'weekly')->required(fn (Get $get): bool => $get('periodicity') === 'weekly'),
            TextInput::make('start_day')->label('Число месяца')->numeric()->integer()->minValue(1)->maxValue(31)
                ->helperText('Если такого числа нет, сбор начнётся в последний день месяца.')
                ->visible(fn (Get $get): bool => in_array($get('periodicity'), ['monthly', 'yearly'], true))
                ->required(fn (Get $get): bool => in_array($get('periodicity'), ['monthly', 'yearly'], true)),
            Select::make('start_month')->label('Месяц')->options([1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель', 5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август', 9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь'])
                ->visible(fn (Get $get): bool => $get('periodicity') === 'yearly')->required(fn (Get $get): bool => $get('periodicity') === 'yearly'),
            TextInput::make('duration_days')->label('Дней от начала до срока сдачи')->required()->numeric()->integer()->minValue(0)->maxValue(65535),
            TextInput::make('close_after_days')->label('Закрыть через дней после срока сдачи')->numeric()->integer()->minValue(0)->maxValue(65535)->helperText('Оставьте пустым для ручного закрытия.'),
            Toggle::make('active')->label('Активен')->default(true),
            ...CampaignFields::selection(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->label('Название')->placeholder('Без названия')->searchable(),
            TextColumn::make('reportType.title')->label('Вид отчёта')->wrap(),
            TextColumn::make('periodicity')->label('Периодичность')->formatStateUsing(fn (Periodicity $state): string => $state->label()),
            TextColumn::make('nodes_count')->label('Участников'),
            TextColumn::make('last_spawned_for')->label('Последний запуск')->date('d.m.Y'),
            IconColumn::make('active')->label('Активен')->boolean(),
        ])->defaultSort('id', 'desc')->recordActions([
            EditAction::make(),
            Action::make('participants')->label('Участники')->modalSubmitAction(false)->modalCancelActionLabel('Закрыть')
                ->schema([TextEntry::make('participants')->label('Узлы')->state(fn (CampaignTemplate $record): array => $record->nodes()->orderBy('nodes.id')->pluck('name')->all())->listWithLineBreaks()]),
            Action::make('addNode')->label('Добавить узел')
                ->visible(fn (CampaignTemplate $record): bool => Gate::forUser(CampaignFields::actor())->allows('update', $record))
                ->schema([Select::make('node_id')->label('Узел')->required()->searchable()->options(fn (CampaignTemplate $record): array => CampaignFields::nodeOptions($record->creator))])
                ->action(function (CampaignTemplate $record, array $data, CampaignTemplateService $service, Schema $schema): void {
                    CampaignFields::withParticipantErrors(fn () => $service->addNode(CampaignFields::actor(), $record, Node::query()->findOrFail($data['node_id'])), $schema);
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCampaignTemplates::route('/'), 'create' => CreateCampaignTemplate::route('/create'), 'edit' => EditCampaignTemplate::route('/{record}/edit')];
    }
}
