<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

class Onboarding extends Page
{
    use WithRateLimiting;

    protected static ?string $title = 'Подтверждение пользователя';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.onboarding';

    public bool $consent = false;

    public string $email = '';

    #[Locked]
    public bool $verificationSent = false;

    public function mount(): void
    {
        $user = $this->currentUser();
        if ($user->pd_consent_at && $user->email_verified_at) {
            $this->redirect('/cabinet');

            return;
        }
        $this->email = $user->pending_email ?? $user->email ?? '';
    }

    #[Computed]
    public function hasConsent(): bool
    {
        return $this->currentUser()->pd_consent_at !== null;
    }

    public function acceptConsent(): void
    {
        $user = $this->currentUser();
        $this->validate(['consent' => 'accepted'], ['consent.accepted' => 'Для продолжения необходимо ваше согласие.']);
        User::whereKey($user->id)->whereNull('pd_consent_at')->update(['pd_consent_at' => now()]);
        unset($this->hasConsent);
    }

    public function sendVerification(): void
    {
        $user = $this->currentUser();
        abort_unless($user->pd_consent_at, 403);
        $this->validate(['email' => 'required|email|max:255']);
        try {
            $this->rateLimit(3, 60);
        } catch (TooManyRequestsException $exception) {
            throw ValidationException::withMessages([
                'email' => "Повторите отправку через {$exception->secondsUntilAvailable} сек.",
            ]);
        }
        $user->forceFill(['pending_email' => $this->email])->save();
        Notification::route('mail', $this->email)->notify(new VerifyEmailNotification($user->id, $this->email));
        $this->verificationSent = true;
    }

    protected function currentUser(): User
    {
        $user = auth()->user()?->fresh();
        abort_unless($user && ! $user->isBlocked(), 403);

        return $user;
    }
}
