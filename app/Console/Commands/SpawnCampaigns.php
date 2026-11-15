<?php

namespace App\Console\Commands;

use App\Models\CampaignTemplate;
use App\Services\CampaignService;
use Illuminate\Console\Command;
use Throwable;

class SpawnCampaigns extends Command
{
    protected $signature = 'campaigns:spawn';

    protected $description = 'Создать сборы, запланированные на сегодня; пропущенные запуски не восстанавливаются';

    public function handle(CampaignService $service): int
    {
        $failed = false;
        foreach (CampaignTemplate::query()->where('active', true)->lazyById() as $template) {
            try {
                $service->startFromTemplate($template);
            } catch (Throwable $exception) {
                report($exception);
                $this->error('Не удалось запустить шаблон №'.$template->id.'.');
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
