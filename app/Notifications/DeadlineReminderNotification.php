<?php

namespace App\Notifications;

use App\Enums\ReportStatus;
use App\Models\Report;
use Illuminate\Notifications\Messages\MailMessage;

class DeadlineReminderNotification extends ReportNotification
{
    public string $endsAt;

    public int $remindDaysBefore;

    public function __construct(Report $report)
    {
        parent::__construct($report);
        $this->endsAt = $report->campaign->ends_at->toDateString();
        $this->remindDaysBefore = $report->campaign->remind_days_before;
    }

    protected function isRelevant(Report $report): bool
    {
        $campaign = $report->campaign;

        return $report->status === ReportStatus::New && $campaign !== null
            && $campaign->ends_at->toDateString() === $this->endsAt
            && $campaign->remind_days_before === $this->remindDaysBefore
            && $campaign->ends_at->subDays($this->remindDaysBefore)->isToday();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->message('Приближается срок сдачи отчёта', 'Напоминаем о сроке сдачи. Ваш отчёт ещё находится в статусе «Новый».');
    }
}
