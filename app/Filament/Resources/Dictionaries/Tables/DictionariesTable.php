<?php

namespace App\Filament\Resources\Dictionaries\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DictionariesTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('key')->label('Ключ')->searchable(),
            TextColumn::make('name')->label('Название')->searchable(),
        ])->recordActions([EditAction::make()]);
    }
}
