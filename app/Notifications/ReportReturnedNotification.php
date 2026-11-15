<?php

namespace App\Notifications;

use App\Enums\ReportStatus;
use App\Models\Report;
use Illuminate\Notifications\Messages\MailMessage;

class ReportReturnedNotification extends ReportNotification
{
    protected function isRelevant(Report $report): bool
    {
        return $report->status === ReportStatus::Rework;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->message('Отчёт возвращён на доработку', 'Ваш отчёт возвращён на доработку. Проверьте и исправьте данные.');
    }
}
