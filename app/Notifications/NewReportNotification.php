<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class NewReportNotification extends ReportNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return $this->message('Назначен новый отчёт', 'Вашему узлу назначен новый отчёт.');
    }
}
