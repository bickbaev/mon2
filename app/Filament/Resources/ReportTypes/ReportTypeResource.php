<?php

namespace App\Filament\Resources\ReportTypes;

use App\Enums\NodeType;
use App\Enums\ReportKind;
use App\Filament\Resources\ReportTypes\Pages\EditReportType;
use App\Filament\Resources\ReportTypes\Pages\ListReportTypes;
use App\Models\Node;
use App\Models\ReportType;
use App\Models\User;
use App\Reports\ReportRegistry;
use App\Services\BaseReportAssignment;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class ReportTypeResource extends Resource
{
    protected static ?string $model = ReportType::class;

    protected static ?string $modelLabel = 'вид отчёта';

    protected static ?string $pluralModelLabel = 'Виды отчётов';

    protected static ?string $recordTitleAttribute = 'title';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->whereIn('key', array_keys(app(ReportRegistry::class)->all()));

        return Gate::allows('viewAny', ReportType::class) ? $query : $query->whereRaw('1 = 0');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('key')->label('Ключ')->disabled(),
            TextInput::make('title')->label('Название')->disabled(),
            TextInput::make('kind')->label('Формат')->disabled()->formatStateUsing(fn ($state): string => $state instanceof ReportKind ? $state->value : (string) $state),
            Toggle::make('is_base')->label('Базовый отчёт')->live()->disabled(fn (?ReportType $record): bool => $record?->kind === ReportKind::Complex),
            Toggle::make('is_required')->label('Обязательный')->disabled(fn (Get $get): bool => ! $get('is_base')),
            Toggle::make('auto_create')->label('Создавать автоматически')->helperText('При включении отчёты назначаются всем существующим и новым организациям.'),
            Toggle::make('excel_enabled')->label('Выгрузка в Excel')->disabled(fn (?ReportType $record): bool => $record?->kind === ReportKind::Complex),
            Toggle::make('list_allows_empty')->label('Разрешить пустой список')->visible(fn (?ReportType $record): bool => $record?->kind === ReportKind::List),
            Toggle::make('active')->label('Активен'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->label('Название')->searchable(),
            TextColumn::make('key')->label('Ключ'),
            IconColumn::make('is_base')->label('Базовый')->boolean(),
            IconColumn::make('is_required')->label('Обязательный')->boolean(),
            IconColumn::make('auto_create')->label('Автоматически')->boolean(),
            IconColumn::make('active')->label('Активен')->boolean(),
        ])->defaultSort('id')->recordActions([
            EditAction::make(),
            Action::make('assign')->label('Назначить организациям')
                ->visible(function (ReportType $record): bool {
                    $type = $record->fresh();

                    return $type !== null && Gate::allows('assign', $type) && $type->is_base && $type->active && ! $type->auto_create;
                })
                ->schema([
                    Toggle::make('all')->label('Все организации')->live(),
                    Select::make('organizations')->label('Организации')->multiple()->searchable()
                        ->options(fn (): array => Gate::allows('viewAny', ReportType::class)
                            ? Node::query()->where('type', NodeType::Organization)->orderBy('name')->pluck('name', 'id')->all() : [])
                        ->required(fn (Get $get): bool => ! $get('all'))->hidden(fn (Get $get): bool => (bool) $get('all')),
                ])
                ->action(function (ReportType $record, array $data, BaseReportAssignment $assignment): void {
                    /** @var User $actor */
                    $actor = Filament::auth()->user();
                    $assignment->assign($record, ($data['all'] ?? false) ? null : ($data['organizations'] ?? []), $actor);
                }),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReportTypes::route('/'),
            'edit' => EditReportType::route('/{record}/edit'),
        ];
    }
}
