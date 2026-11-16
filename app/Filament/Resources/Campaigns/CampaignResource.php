<?php

namespace App\Filament\Resources\Campaigns;

use App\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\Resources\Campaigns\Pages\ListCampaigns;
use App\Filament\Resources\Campaigns\Schemas\CampaignFields;
use App\Models\Campaign;
use App\Models\Node;
use App\Services\CampaignService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class CampaignResource extends Resource
{
    protected static ?string $model = Campaign::class;

    protected static ?string $modelLabel = 'сбор';

    protected static ?string $pluralModelLabel = 'Сборы';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function getEloquentQuery(): Builder
    {
        return CampaignFields::managedQuery(parent::getEloquentQuery())->with('reportType')->withCount('reports');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            ...CampaignFields::settings(),
            DatePicker::make('starts_at')->label('Дата начала')->required()->native(false),
            DatePicker::make('ends_at')->label('Сдать до')->required()->afterOrEqual('starts_at')->native(false),
            DatePicker::make('closes_at')->label('Дата закрытия')->required()->afterOrEqual('ends_at')->native(false),
            ...CampaignFields::selection(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->label('Сбор')->placeholder('Без названия')->searchable()->wrap()
                ->description(fn (Campaign $record): string => $record->reportType->title.' ('.$record->starts_at->format('d.m.Y').'-'.$record->ends_at->format('d.m.Y').')'),
            TextColumn::make('closes_at')->label('Закрытие')->date('d.m.Y')->placeholder('Вручную'),
            TextColumn::make('reports_count')->label('Отчётов'),
            TextColumn::make('closed_at')->label('Состояние')->state(fn (Campaign $record): string => $record->isClosed() ? 'Закрыт' : 'Открыт')->badge(),
        ])->defaultSort('id', 'desc')->recordActions([
            Action::make('participants')->label('Участники')->modalSubmitAction(false)->modalCancelActionLabel('Закрыть')
                ->schema([TextEntry::make('participants')->label('Узлы и статусы')->state(fn (Campaign $record): array => $record->reports()->with('node')->orderBy('node_id')->get()->map(fn ($report): string => $report->node->name.' — '.$report->status->label())->all())->listWithLineBreaks()]),
            Action::make('addNode')->label('Добавить узел')
                ->visible(fn (Campaign $record): bool => Gate::forUser(CampaignFields::actor())->allows('manage', $record))
                ->schema([Select::make('node_id')->label('Узел')->required()->searchable()->options(fn (Campaign $record): array => CampaignFields::nodeOptions($record->creator))])
                ->action(function (Campaign $record, array $data, CampaignService $service, Schema $schema): void {
                    CampaignFields::withParticipantErrors(fn () => $service->addNode(CampaignFields::actor(), $record, Node::query()->findOrFail($data['node_id'])), $schema);
                }),
            Action::make('blockAll')->label('Заблокировать отчеты')->requiresConfirmation()
                ->modalDescription('Все отчёты сбора, включая принятые, получат статус «Заблокирован».')
                ->visible(fn (Campaign $record): bool => Gate::forUser(CampaignFields::actor())->allows('manage', $record))
                ->action(fn (Campaign $record, CampaignService $service): int => $service->blockAll(CampaignFields::actor(), $record)),
            Action::make('close')->label('Закрыть')->requiresConfirmation()
                ->modalDescription('После закрытия добавлять узлы, изменять отчёты и их статусы нельзя.')
                ->visible(fn (Campaign $record): bool => Gate::forUser(CampaignFields::actor())->allows('manage', $record))
                ->action(fn (Campaign $record, CampaignService $service) => $service->close(CampaignFields::actor(), $record)),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCampaigns::route('/'), 'create' => CreateCampaign::route('/create')];
    }
}
