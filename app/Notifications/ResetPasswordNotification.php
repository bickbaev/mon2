<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

class ResetPasswordNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return ! $notifiable->isBlocked() && $notifiable->getEmailForPasswordReset()
            && Password::tokenExists($notifiable, $this->token);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Восстановление пароля')->greeting('Здравствуйте!')
            ->line('Для вашей учётной записи запрошен сброс пароля.')
            ->action('Задать новый пароль', URL::signedRoute('filament.app.auth.password-reset.reset', [
                'login' => $notifiable->login, 'token' => $this->token,
            ]))
            ->line('Ссылка действительна '.config('auth.passwords.users.expire').' минут.')
            ->line('Если вы не запрашивали сброс пароля, проигнорируйте письмо.')
            ->salutation('Система сбора данных образовательных организаций');
    }
}
