<?php

namespace App\Filament\Resources\Campaigns\Pages;

use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\Campaigns\Schemas\CampaignFields;
use App\Services\CampaignService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCampaign extends CreateRecord
{
    protected static string $resource = CampaignResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return CampaignFields::withFormErrors(fn (): Model => app(CampaignService::class)->start(CampaignFields::actor(), $data, CampaignFields::selectionFrom($data)));
    }

    protected function getRedirectUrl(): string
    {
        return CampaignResource::getUrl();
    }
}
