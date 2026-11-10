<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Timebox;

class RequestPasswordReset extends \Filament\Auth\Pages\PasswordReset\RequestPasswordReset
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('login')->label('Логин')->required()->maxLength(255)->autocomplete('username')->autofocus(),
        ]);
    }

    public function request(): void
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }
        $data = $this->form->getState();
        app(Timebox::class)->call(function () use ($data): void {
            $user = User::where('login', $data['login'])->first();
            if ($user && $user->getEmailForPasswordReset() && ! $user->isBlocked()) {
                Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
                    ['login' => $user->login],
                    fn (User $recipient, string $token) => $recipient->notify(new ResetPasswordNotification($token)),
                );
            }
        }, (int) config('auth.timebox_duration', 200_000));
        Notification::make()->success()
            ->title('Если пользователь существует и почта подтверждена, письмо отправлено.')->send();
        $this->form->fill();
    }
}
