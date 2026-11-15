<?php

namespace App\Listeners;

use App\Enums\ReportStatus;
use App\Events\ReportReturnedForRework;
use App\Notifications\ReportReturnedNotification;
use App\Services\ReportRecipients;
use Illuminate\Support\Facades\Notification;

class NotifyAboutRework
{
    public function __construct(private ReportRecipients $recipients) {}

    public function handle(ReportReturnedForRework $event): void
    {
        $report = $event->report->fresh(['node', 'campaign']);
        if ($report === null || $report->status !== ReportStatus::Rework || ($report->campaign?->isClosed() ?? false)) {
            return;
        }
        Notification::send($this->recipients->for($report->node), new ReportReturnedNotification($report));
    }
}
