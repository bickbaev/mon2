<?php

use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Pages\Auth\ResetPassword;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Filament\Facades\Filament;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

test('password request sends a link to confirmed email using login', function () {
    Notification::fake();
    $user = User::factory()->create(['login' => 'school', 'email' => 'confirmed@example.org', 'pending_email' => 'pending@example.org']);
    $page = Livewire::test(RequestPasswordReset::class)->fillForm(['login' => 'school'])->call('request')->assertHasNoFormErrors();
    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($user) {
        $url = $notification->toMail($user)->actionUrl;
        $this->get($url)->assertOk();
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        expect($query['login'])->toBe('school');
        expect($user->getEmailForPasswordReset())->toBe('confirmed@example.org');

        return Password::tokenExists($user, $query['token']);
    });
});

test('unknown unverified blocked and valid accounts receive the same response', function (string $kind) {
    Notification::fake();
    if ($kind !== 'unknown') {
        User::factory()->create([
            'login' => 'school',
            'email_verified_at' => $kind === 'unverified' ? null : now(),
            'blocked_at' => $kind === 'blocked' ? now() : null,
        ]);
    }
    Livewire::test(RequestPasswordReset::class)->fillForm(['login' => 'school'])->call('request')
        ->assertHasNoFormErrors()->assertNotified('Если пользователь существует и почта подтверждена, письмо отправлено.');
    if ($kind !== 'valid') {
        Notification::assertNothingSent();
    } else {
        Notification::assertSentTo(User::where('login', 'school')->first(), ResetPasswordNotification::class);
    }
})->with(['unknown', 'unverified', 'blocked', 'valid']);

test('shared email keeps reset tokens independent and token cannot reset another user', function () {
    $first = User::factory()->create(['email' => 'shared@example.org']);
    $second = User::factory()->create(['email' => 'shared@example.org']);
    $firstToken = Password::createToken($first);
    $secondToken = Password::createToken($second);
    expect(Password::tokenExists($first, $firstToken))->toBeTrue();
    expect(Password::tokenExists($second, $secondToken))->toBeTrue();
    Livewire::test(ResetPassword::class, ['login' => $second->login, 'token' => $firstToken])
        ->fillForm(['password' => 'new-password-123', 'passwordConfirmation' => 'new-password-123'])
        ->call('resetPassword')->assertHasFormErrors(['login']);
    expect(Hash::check('password', $second->fresh()->password))->toBeTrue();
});

test('valid token resets only the selected account and cannot be reused', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);
    $oldRememberToken = $user->remember_token;
    Livewire::test(ResetPassword::class, ['login' => $user->login, 'token' => $token])
        ->fillForm(['password' => 'new-password-123', 'passwordConfirmation' => 'new-password-123'])
        ->call('resetPassword')->assertHasNoFormErrors()->assertRedirect('/cabinet/login');
    expect(Hash::check('new-password-123', $user->fresh()->password))->toBeTrue();
    expect($user->fresh()->remember_token)->not->toBe($oldRememberToken);
    expect(Password::tokenExists($user, $token))->toBeFalse();
});

test('reset refuses expired token changed email unverified email or blocked account', function (string $reason) {
    $this->freezeTime();
    $user = User::factory()->create();
    $token = Password::createToken($user);
    match ($reason) {
        'expired' => $this->travel(61)->minutes(),
        'email' => $user->forceFill(['email' => 'changed@example.org'])->save(),
        'unverified' => $user->forceFill(['email_verified_at' => null])->save(),
        'blocked' => $user->forceFill(['blocked_at' => now()])->save(),
    };
    Livewire::test(ResetPassword::class, ['login' => $user->login, 'token' => $token])
        ->fillForm(['password' => 'new-password-123', 'passwordConfirmation' => 'new-password-123'])
        ->call('resetPassword')->assertHasFormErrors(['login']);
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
})->with(['expired', 'email', 'unverified', 'blocked']);

test('reset requires matching sufficiently long passwords', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);
    Livewire::test(ResetPassword::class, ['login' => $user->login, 'token' => $token])
        ->fillForm(['password' => 'short', 'passwordConfirmation' => 'different'])
        ->call('resetPassword')->assertHasFormErrors(['password']);
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('unknown and ineligible password requests receive timing protection', function (string $kind) {
    Sleep::fake();
    if ($kind !== 'unknown') {
        User::factory()->create(['login' => 'school', 'email_verified_at' => null]);
    }
    Livewire::test(RequestPasswordReset::class)->fillForm(['login' => 'school'])->call('request')->assertHasNoFormErrors();
    Sleep::assertSleptTimes(1);
})->with(['unknown', 'unverified']);

test('password reset mail is queued with an encrypted payload', function () {
    Queue::fake();
    $user = User::factory()->create();
    Livewire::test(RequestPasswordReset::class)->fillForm(['login' => $user->login])->call('request')->assertHasNoFormErrors();
    Queue::assertPushed(SendQueuedNotifications::class,
        fn ($job) => $job->shouldBeEncrypted && $job->notification instanceof ResetPasswordNotification);
});

test('queued reset mail follows current email and account eligibility', function (string $change, int $expectedMessages) {
    Queue::fake();
    $user = User::factory()->create();
    Livewire::test(RequestPasswordReset::class)->fillForm(['login' => $user->login])->call('request');
    $job = Queue::pushed(SendQueuedNotifications::class)->first();
    $payload = serialize($job);
    $user->forceFill(match ($change) {
        'email' => ['email' => 'new-address@example.org'],
        'unverified' => ['email_verified_at' => null],
        'blocked' => ['blocked_at' => now()],
        'unchanged' => [],
    })->save();
    config(['mail.default' => 'array', 'mail.mailers.array' => ['transport' => 'array']]);
    unserialize($payload)->handle(app(ChannelManager::class));
    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount($expectedMessages);
})->with([
    'changed email' => ['email', 0],
    'unverified email' => ['unverified', 0],
    'blocked account' => ['blocked', 0],
    'eligible account' => ['unchanged', 1],
]);

test('reset route rejects tampered signature', function () {
    $user = User::factory()->create();
    $url = (new ResetPasswordNotification(Password::createToken($user)))->toMail($user)->actionUrl;
    $this->get($url.'&tampered=1')->assertForbidden();
});

test('reset broker throttles repeated mail requests without exposing account status', function () {
    Queue::fake();
    $user = User::factory()->create();
    $page = Livewire::test(RequestPasswordReset::class);
    for ($i = 0; $i < 2; $i++) {
        $page->fillForm(['login' => $user->login])->call('request')
            ->assertHasNoFormErrors()->assertNotified('Если пользователь существует и почта подтверждена, письмо отправлено.');
    }
    Queue::assertPushed(SendQueuedNotifications::class, 1);
});
