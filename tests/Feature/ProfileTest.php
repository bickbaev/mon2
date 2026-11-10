<?php

use App\Filament\Pages\Profile;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

test('profile saves own name and ignores forged privileges', function () {
    $user = User::factory()->create(['pd_consent_at' => now()]);
    $this->actingAs($user);
    Livewire::test(Profile::class)->fillForm(['name' => 'Новое имя'])
        ->set('data.is_admin', true)->set('data.can_start_campaigns', true)->set('data.node_id', 999)
        ->call('save')->assertHasNoFormErrors();
    expect($user->fresh()->name)->toBe('Новое имя');
    expect($user->fresh()->is_admin)->toBeFalse();
    expect($user->fresh()->can_start_campaigns)->toBeFalse();
    expect($user->fresh()->node_id)->toBeNull();
});

test('profile refuses password change without correct current password', function () {
    $user = User::factory()->create(['pd_consent_at' => now()]);
    $this->actingAs($user);
    Livewire::test(Profile::class)->fillForm([
        'password' => 'new-password-123', 'passwordConfirmation' => 'new-password-123', 'currentPassword' => 'wrong',
    ])->call('save')->assertHasFormErrors(['currentPassword']);
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('profile changes password with current password and session stays usable', function () {
    $user = User::factory()->create(['pd_consent_at' => now()]);
    $this->actingAs($user);
    Livewire::test(Profile::class)->fillForm([
        'password' => 'new-password-123', 'passwordConfirmation' => 'new-password-123', 'currentPassword' => 'password',
    ])->call('save')->assertHasNoFormErrors();
    expect(Hash::check('new-password-123', $user->fresh()->password))->toBeTrue();
    $this->get('/cabinet/profile')->assertOk();
});

test('profile changes email only after verification and shared address is allowed', function () {
    Notification::fake();
    User::factory()->create(['email' => 'shared@example.org']);
    $user = User::factory()->create(['email' => 'old@example.org', 'pd_consent_at' => now()]);
    $verifiedAt = $user->email_verified_at;
    $this->actingAs($user);
    Livewire::test(Profile::class)->fillForm(['email' => 'shared@example.org', 'currentPassword' => 'password'])
        ->call('save')->assertHasNoFormErrors();
    expect($user->fresh()->email)->toBe('old@example.org');
    expect($user->fresh()->pending_email)->toBe('shared@example.org');
    expect($user->fresh()->email_verified_at->equalTo($verifiedAt))->toBeTrue();
    $this->get('/cabinet')->assertOk();
    $url = null;
    Notification::assertSentOnDemand(VerifyEmailNotification::class, function ($notification, $channels, $recipient) use (&$url) {
        $url = $notification->toMail($recipient)->actionUrl;

        return $recipient->routes['mail'] === 'shared@example.org';
    });
    $this->get($url)->assertRedirect('/cabinet');
    expect($user->fresh()->email)->toBe('shared@example.org');
});

test('profile is protected from guests and blocked sessions', function () {
    $this->get('/cabinet/profile')->assertRedirect('/cabinet/login');
    $user = User::factory()->create(['pd_consent_at' => now(), 'blocked_at' => now()]);
    $this->actingAs($user)->get('/cabinet/profile')->assertForbidden();
});

test('profile rejects missing current password or mismatched confirmation', function (string $current, string $confirmation, string $errorField) {
    $user = User::factory()->create(['pd_consent_at' => now()]);
    $this->actingAs($user);
    Livewire::test(Profile::class)->fillForm([
        'password' => 'new-password-123', 'passwordConfirmation' => $confirmation, 'currentPassword' => $current,
    ])->call('save')->assertHasFormErrors([$errorField]);
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
})->with([
    'missing current password' => ['', 'new-password-123', 'currentPassword'],
    'confirmation mismatch' => ['password', 'different-password', 'password'],
]);
