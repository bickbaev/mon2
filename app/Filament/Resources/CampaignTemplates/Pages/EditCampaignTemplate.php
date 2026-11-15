<?php

namespace App\Filament\Resources\CampaignTemplates\Pages;

use App\Filament\Resources\Campaigns\Schemas\CampaignFields;
use App\Filament\Resources\CampaignTemplates\CampaignTemplateResource;
use App\Services\CampaignTemplateService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCampaignTemplate extends EditRecord
{
    protected static string $resource = CampaignTemplateResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return CampaignFields::withFormErrors(fn (): Model => app(CampaignTemplateService::class)->update(CampaignFields::actor(), $record, $data));
    }
}
