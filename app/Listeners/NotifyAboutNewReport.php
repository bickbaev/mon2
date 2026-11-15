<?php

namespace App\Listeners;

use App\Events\ReportAssigned;
use App\Notifications\NewReportNotification;
use App\Services\ReportRecipients;
use Illuminate\Support\Facades\Notification;

class NotifyAboutNewReport
{
    public function __construct(private ReportRecipients $recipients) {}

    public function handle(ReportAssigned $event): void
    {
        $report = $event->report->fresh(['node', 'campaign']);
        if ($report === null || ($report->campaign?->isClosed() ?? false)) {
            return;
        }
        Notification::send($this->recipients->for($report->node), new NewReportNotification($report));
    }
}
