<?php

namespace App\Filament\Resources\CampaignTemplates\Pages;

use App\Filament\Resources\CampaignTemplates\CampaignTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCampaignTemplates extends ListRecords
{
    protected static string $resource = CampaignTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
