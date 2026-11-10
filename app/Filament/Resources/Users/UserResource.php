<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Node;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $modelLabel = 'пользователь';

    protected static ?string $pluralModelLabel = 'Пользователи';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->inBranchOf(auth()->user()->fresh())->with('node');
    }

    public static function nodeOptions(): array
    {
        $actor = auth()->user()->fresh();
        $query = Node::query();
        if (! $actor->isAdmin()) {
            if (! $actor->node) {
                return [];
            }
            $query->inBranch($actor->node, false);
        }

        return $query->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function form(Schema $schema): Schema
    {
        $admin = fn () => auth()->user()->fresh()->isAdmin();

        return $schema->components([
            TextInput::make('login')->label('Логин')->required()->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('name')->label('Имя')->required()->maxLength(255),
            Select::make('node_id')->label('Организация / узел')->options(fn () => static::nodeOptions())
                ->searchable()->required(fn (Get $get) => ! ($admin() && $get('is_admin')))
                ->disabled(fn (Get $get) => $admin() && $get('is_admin')),
            Toggle::make('is_admin')->label('Администратор системы')->visible($admin)->live(),
            Toggle::make('can_start_campaigns')->label('Может запускать сборы')->visible($admin),
            Toggle::make('can_manage_structure')->label('Может добавлять организации и уровни')->visible($admin),
        ]);
    }

    public static function persistUser(array $data, ?User $record = null, #[\SensitiveParameter] ?string $password = null): User
    {
        return DB::transaction(function () use ($data, $record, $password) {
            $actor = auth()->user()->fresh();
            $gate = Gate::forUser($actor);
            if ($record) {
                $record = User::lockForUpdate()->findOrFail($record->id);
                $gate->authorize('update', $record);
            }
            $values = Arr::only($data, ['login', 'name']);
            if ($gate->allows('grantFlags', User::class)) {
                foreach (['is_admin', 'can_start_campaigns', 'can_manage_structure'] as $flag) {
                    $values[$flag] = (bool) ($data[$flag] ?? false);
                }
            }
            $isAdmin = $values['is_admin'] ?? $record?->is_admin ?? false;
            if ($isAdmin) {
                $values['node_id'] = null;
                if (! $record) {
                    $gate->authorize('create', User::class);
                }
            } else {
                $node = Node::find($data['node_id'] ?? null);
                if (! $node) {
                    throw ValidationException::withMessages(['data.node_id' => 'Выберите организацию / узел.']);
                }
                $gate->authorize('create', [User::class, $node]);
                $values['node_id'] = $node->id;
            }
            $record ??= new User;
            if ($password !== null && ! $record->exists) {
                $values['password'] = $password;
            }
            $record->forceFill($values)->save();

            return $record;
        });
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('login')->label('Логин')->searchable()->sortable(),
            TextColumn::make('name')->label('Имя')->searchable(),
            TextColumn::make('node.name')->label('Организация')->placeholder('Администратор системы'),
            TextColumn::make('blocked_at')->label('Состояние')->formatStateUsing(fn () => 'Заблокирован')->placeholder('Активен'),
        ])->filters([
            TernaryFilter::make('blocked_at')->label('Блокировка')->nullable()->trueLabel('Заблокированные')->falseLabel('Активные'),
        ])->recordActions([
            EditAction::make(),
            Action::make('block')->label('Заблокировать')->color('danger')->requiresConfirmation()
                ->visible(fn (User $record) => ! $record->isBlocked())
                ->authorize(fn (User $record) => Gate::forUser(auth()->user()->fresh())->allows('block', $record))
                ->action(fn (User $record) => static::setBlocked($record, true)),
            Action::make('unblock')->label('Разблокировать')->requiresConfirmation()
                ->visible(fn (User $record) => $record->isBlocked())
                ->authorize(fn (User $record) => Gate::forUser(auth()->user()->fresh())->allows('block', $record))
                ->action(fn (User $record) => static::setBlocked($record, false)),
            DeleteAction::make(),
        ]);
    }

    public static function setBlocked(User $record, bool $blocked): void
    {
        Gate::forUser(auth()->user()->fresh())->authorize('block', $record->fresh());
        $record->forceFill(['blocked_at' => $blocked ? now() : null])->save();
    }

    public static function getPages(): array
    {
        return ['index' => ListUsers::route('/'), 'create' => CreateUser::route('/create'), 'edit' => EditUser::route('/{record}/edit')];
    }
}
