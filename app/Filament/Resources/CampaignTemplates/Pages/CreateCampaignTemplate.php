<?php

namespace App\Filament\Resources\CampaignTemplates\Pages;

use App\Filament\Resources\Campaigns\Schemas\CampaignFields;
use App\Filament\Resources\CampaignTemplates\CampaignTemplateResource;
use App\Services\CampaignTemplateService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCampaignTemplate extends CreateRecord
{
    protected static string $resource = CampaignTemplateResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return CampaignFields::withFormErrors(fn (): Model => app(CampaignTemplateService::class)->create(CampaignFields::actor(), $data, CampaignFields::selectionFrom($data)));
    }

    protected function getRedirectUrl(): string
    {
        return CampaignTemplateResource::getUrl();
    }
}
