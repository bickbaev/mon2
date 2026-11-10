<?php

namespace App\Filament\Resources\Dictionaries\Pages;

use App\Filament\Resources\Dictionaries\DictionaryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDictionaries extends ListRecords
{
    protected static string $resource = DictionaryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
