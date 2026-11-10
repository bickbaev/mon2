<?php

namespace App\Filament\Resources\Dictionaries\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class DictionaryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('key')->label('Ключ')->required()->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('name')->label('Название')->required()->maxLength(255),
        ]);
    }
}
