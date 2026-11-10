<?php

namespace App\Filament\Resources\Dictionaries\RelationManagers;

use App\Models\DictionaryItem;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Записи';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('label')->label('Название')->required()->maxLength(255),
            TextInput::make('sort')->label('Порядок')->numeric()->integer()->minValue(0)->default(0)->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->recordTitleAttribute('label')->defaultSort('sort')->columns([
            TextColumn::make('label')->label('Название')->searchable(),
            TextColumn::make('sort')->label('Порядок'),
            IconColumn::make('active')->label('Активна')->boolean(),
        ])->headerActions([CreateAction::make()])->recordActions([
            EditAction::make(),
            Action::make('deactivate')->label('Сделать неактивной')->authorize('deactivate')
                ->visible(fn (DictionaryItem $record): bool => $record->active)
                ->requiresConfirmation()->action(function (DictionaryItem $record): void {
                    Gate::forUser(auth()->user()->fresh())->authorize('deactivate', $record);
                    $record->update(['active' => false]);
                }),
        ]);
    }
}
