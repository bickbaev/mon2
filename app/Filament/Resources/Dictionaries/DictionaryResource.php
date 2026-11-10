<?php

namespace App\Filament\Resources\Dictionaries;

use App\Filament\Resources\Dictionaries\Pages\CreateDictionary;
use App\Filament\Resources\Dictionaries\Pages\EditDictionary;
use App\Filament\Resources\Dictionaries\Pages\ListDictionaries;
use App\Filament\Resources\Dictionaries\Schemas\DictionaryForm;
use App\Filament\Resources\Dictionaries\Tables\DictionariesTable;
use App\Models\Dictionary;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DictionaryResource extends Resource
{
    protected static ?string $modelLabel = 'справочник';

    protected static ?string $pluralModelLabel = 'Справочники';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $model = Dictionary::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return DictionaryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DictionariesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDictionaries::route('/'),
            'create' => CreateDictionary::route('/create'),
            'edit' => EditDictionary::route('/{record}/edit'),
        ];
    }
}
