<?php

namespace App\Filament\Resources\ReportTypes\Pages;

use App\Filament\Resources\ReportTypes\ReportTypeResource;
use App\Models\User;
use App\Services\BaseReportAssignment;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditReportType extends EditRecord
{
    protected static string $resource = ReportTypeResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $actor */
        $actor = Filament::auth()->user();

        return app(BaseReportAssignment::class)->updateSettings($record, $data, $actor);
    }
}
