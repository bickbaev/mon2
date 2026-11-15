<?php

namespace App\Services;

use App\Enums\ReportStatus;
use App\Models\Campaign;
use App\Notifications\DeadlineReminderNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class DeadlineReminders
{
    public function __construct(private ReportRecipients $recipients) {}

    /** Задания и отметки сохраняются в одной БД; обработчик видит задания только после коммита. */
    public function sendForCampaign(Campaign $campaign): int
    {
        return DB::transaction(function () use ($campaign): int {
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);
            if ($campaign->isClosed() || $campaign->remind_days_before === null
                || ! $campaign->ends_at->subDays($campaign->remind_days_before)->isToday()) {
                return 0;
            }
            $count = 0;
            $reports = $campaign->reports()->where('status', ReportStatus::New)->whereNull('deadline_reminded_at')->with('node')->lockForUpdate();
            foreach ($reports->lazyById() as $report) {
                $recipients = $this->recipients->for($report->node);
                if ($recipients->isEmpty()) {
                    continue;
                }
                $report->setRelation('campaign', $campaign);
                $report->forceFill(['deadline_reminded_at' => now()])->save();
                Notification::send($recipients, (new DeadlineReminderNotification($report))
                    ->onConnection('report-notifications')->beforeCommit());
                $count++;
            }

            return $count;
        }, 3);
    }
}
