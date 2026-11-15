<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Services\DeadlineReminders;
use Illuminate\Console\Command;
use Throwable;

class SendDeadlineReminders extends Command
{
    protected $signature = 'campaigns:remind';

    protected $description = 'Напомнить о новых отчётах в назначенный день без восстановления пропущенных дат';

    public function handle(DeadlineReminders $reminders): int
    {
        $failed = false;
        $campaigns = Campaign::query()->whereNull('closed_at')
            ->whereNotNull('remind_days_before')
            ->whereRaw('DATE_SUB(ends_at, INTERVAL remind_days_before DAY) = ?', [today()->toDateString()]);
        foreach ($campaigns->lazyById() as $campaign) {
            try {
                $reminders->sendForCampaign($campaign);
            } catch (Throwable $exception) {
                report($exception);
                $this->error('Не удалось подготовить напоминания для сбора №'.$campaign->id.'.');
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
