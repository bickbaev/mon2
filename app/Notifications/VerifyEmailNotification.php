<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class VerifyEmailNotification extends Notification
{
    public function __construct(public int $userId, public string $email) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Подтверждение электронной почты')
            ->greeting('Здравствуйте!')
            ->line('Подтвердите адрес электронной почты для работы в системе сбора данных.')
            ->action('Подтвердить почту', URL::temporarySignedRoute('email.verify', now()->addHour(), [
                'id' => $this->userId,
                'hash' => sha1($this->email),
            ]))
            ->line('Ссылка действительна 60 минут. Если вы не запрашивали письмо, проигнорируйте его.')
            ->salutation('Система сбора данных образовательных организаций');
    }
}
