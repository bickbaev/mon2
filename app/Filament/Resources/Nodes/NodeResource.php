<?php

namespace App\Filament\Resources\Nodes;

use App\Enums\NodeType;
use App\Filament\Resources\Nodes\Pages\CreateNode;
use App\Filament\Resources\Nodes\Pages\ListNodes;
use App\Models\Node;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;

class NodeResource extends Resource
{
    protected static ?string $model = Node::class;

    protected static ?string $modelLabel = 'узел';

    protected static ?string $pluralModelLabel = 'Организации';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user()?->fresh();

        return parent::shouldRegisterNavigation() && $user !== null
            && ($user->isAdmin() || ($user->node?->children()->exists() ?? false));
    }

    public static function typeOptions(): array
    {
        return ['authority' => 'Орган управления', 'organization' => 'Образовательная организация', 'branch' => 'Филиал'];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user()->fresh();

        return $user->isAdmin() ? $query : ($user->node ? $query->inBranch($user->node) : $query->whereRaw('1 = 0'));
    }

    public static function canCreate(): bool
    {
        $user = auth()->user()->fresh();

        return $user->isAdmin() || ($user->can_manage_structure && $user->node && $user->node->type !== NodeType::Branch);
    }

    public static function getCreateAuthorizationResponse(): Response
    {
        return static::canCreate() ? Response::allow() : Response::deny();
    }

    public static function parentOptions(): array
    {
        return static::getEloquentQuery()->where('type', '!=', NodeType::Branch->value)->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Название')->required()->maxLength(255),
            Select::make('type')->label('Тип')->options(static::typeOptions())->required(),
            Select::make('parent_id')->label('Вышестоящий узел')->options(fn () => static::parentOptions())
                ->searchable()->required(fn () => ! auth()->user()->isAdmin())
                ->default(fn () => auth()->user()->node_id)->placeholder('Корневой узел'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Название')->searchable()->sortable(),
            TextColumn::make('type')->label('Тип')->formatStateUsing(fn (NodeType $state) => static::typeOptions()[$state->value]),
            TextColumn::make('parent.name')->label('Вышестоящий узел')->placeholder('Корневой узел'),
        ])->filters([
            SelectFilter::make('type')->label('Тип')->options(static::typeOptions()),
            SelectFilter::make('parent_id')->label('Вышестоящий узел')->options(fn () => static::parentOptions()),
        ])->recordUrl(null);
    }

    public static function getPages(): array
    {
        return ['index' => ListNodes::route('/'), 'create' => CreateNode::route('/create')];
    }
}
