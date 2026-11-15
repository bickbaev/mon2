<?php

namespace App\Notifications;

use App\Filament\Pages\ReportForm;
use App\Models\Report;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

abstract class ReportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $reportId;

    public int $nodeId;

    public function __construct(Report $report)
    {
        $this->reportId = $report->id;
        $this->nodeId = $report->node_id;
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return config('report-notifications.channels', ['mail']);
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! in_array($channel, $this->via($notifiable), true)) {
            return false;
        }
        $user = User::query()->find($notifiable->getKey());
        $report = Report::query()->with('campaign')->find($this->reportId);

        return $user !== null && ! $user->isBlocked() && $user->email_verified_at !== null
            && filled($user->email) && $user->email === $notifiable->email
            && $user->node_id === $this->nodeId && $report !== null
            && $report->node_id === $this->nodeId && ! ($report->campaign?->isClosed() ?? false)
            && $this->isRelevant($report);
    }

    protected function isRelevant(Report $report): bool
    {
        return true;
    }

    protected function message(string $subject, string $intro): MailMessage
    {
        $report = Report::query()->with(['reportType', 'node', 'campaign'])->findOrFail($this->reportId);
        $mail = (new MailMessage)->subject($subject)->greeting('Здравствуйте!')
            ->line($intro)->line('Вид отчёта: '.$report->reportType->title)
            ->line('Организация / узел: '.$report->node->name);
        if ($report->campaign !== null) {
            if (filled($report->campaign->title)) {
                $mail->line('Сбор: '.$report->campaign->title);
            }
            $mail->line('Период сбора: '.$report->campaign->starts_at->format('d.m.Y').' — '.$report->campaign->ends_at->format('d.m.Y'))
                ->line('Срок сдачи: '.$report->campaign->ends_at->format('d.m.Y'));
        }

        return $mail->action('Открыть отчёт', ReportForm::getUrl(['report' => $report->id], panel: 'app'))
            ->salutation('Система сбора данных образовательных организаций');
    }
}
