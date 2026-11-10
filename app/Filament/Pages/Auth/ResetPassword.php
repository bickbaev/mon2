<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Auth\Events\PasswordReset as PasswordResetEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class ResetPassword extends \Filament\Auth\Pages\PasswordReset\ResetPassword
{
    #[Locked]
    public ?string $login = null;

    public function mount(?string $email = null, ?string $token = null, ?string $login = null): void
    {
        parent::mount($email, $token);
        $this->login = $login ?? request()->query('login');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('login')->label('Логин')->disabled()->dehydrated(false),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
        ]);
    }

    public function resetPassword(): ?PasswordResetResponse
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }
        $data = $this->form->getState();
        $status = DB::transaction(function () use ($data) {
            $user = User::where('login', $this->login)->lockForUpdate()->first();
            if (! $user || ! $user->getEmailForPasswordReset() || $user->isBlocked()) {
                return Password::INVALID_TOKEN;
            }

            return Password::broker(Filament::getAuthPasswordBroker())->reset([
                'login' => $user->login, 'password' => $data['password'], 'token' => $this->token ?? '',
            ], function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                event(new PasswordResetEvent($user));
            });
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['login' => 'Ссылка недействительна или срок её действия истёк.']);
        }

        return app(PasswordResetResponse::class);
    }
}
