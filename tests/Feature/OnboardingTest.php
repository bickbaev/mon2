<?php

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Onboarding;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Livewire\Mechanisms\ComponentRegistry;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

test('login uses login field and rejects email as identifier', function () {
    $user = User::factory()->create(['login' => 'school_1']);
    Livewire::test(Login::class)->fillForm(['login' => $user->email, 'password' => 'password'])
        ->call('authenticate')->assertHasFormErrors(['login']);
    $this->assertGuest();
    Livewire::test(Login::class)->fillForm(['login' => 'school_1', 'password' => 'password'])
        ->call('authenticate')->assertHasNoFormErrors();
    $this->assertAuthenticatedAs($user);
});

test('blocked user cannot login', function () {
    $user = User::factory()->create(['blocked_at' => now()]);
    Livewire::test(Login::class)->fillForm(['login' => $user->login, 'password' => 'password'])
        ->call('authenticate')->assertHasFormErrors(['login']);
    $this->assertGuest();
});

test('all users including admins need consent before panel access', function (bool $admin) {
    $user = User::factory()->create(['is_admin' => $admin]);
    $this->actingAs($user)->get('/cabinet')->assertRedirect('/cabinet/onboarding');
    $this->get('/cabinet/onboarding')->assertOk();
})->with([false, true]);

test('consent must be explicitly accepted and timestamp is retained', function () {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user);
    $page = Livewire::test(Onboarding::class)->call('acceptConsent')->assertHasErrors(['consent']);
    expect($user->fresh()->pd_consent_at)->toBeNull();
    $page->set('consent', true)->call('acceptConsent')->assertHasNoErrors();
    $acceptedAt = $user->fresh()->pd_consent_at;
    expect($acceptedAt)->not->toBeNull();
    $this->travel(5)->minutes();
    $page->call('acceptConsent');
    expect($user->fresh()->pd_consent_at->equalTo($acceptedAt))->toBeTrue();
});

test('email cannot be requested before consent and invalid email is rejected', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    $this->actingAs($user);
    Livewire::test(Onboarding::class)->set('email', 'school@example.org')->call('sendVerification')->assertForbidden();
    expect($user->fresh()->pending_email)->toBeNull();
    $user->forceFill(['pd_consent_at' => now()])->save();
    Livewire::test(Onboarding::class)->set('email', 'bad-email')->call('sendVerification')->assertHasErrors(['email']);
    Notification::assertNothingSent();
});

test('imported email requires confirmation and mail goes to pending address', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create(['email' => 'old@example.org', 'pd_consent_at' => now()]);
    $this->actingAs($user)->get('/cabinet')->assertRedirect('/cabinet/onboarding');
    Livewire::test(Onboarding::class)->assertSet('email', 'old@example.org')
        ->set('email', 'new@example.org')->call('sendVerification')->assertHasNoErrors();
    expect($user->fresh()->email)->toBe('old@example.org')
        ->and($user->fresh()->pending_email)->toBe('new@example.org')
        ->and($user->fresh()->email_verified_at)->toBeNull();
    $verificationUrl = null;
    Notification::assertSentOnDemand(VerifyEmailNotification::class,
        function ($notification, $channels, $notifiable) use (&$verificationUrl) {
            $verificationUrl = $notification->toMail($notifiable)->actionUrl;

            return $notifiable->routes['mail'] === 'new@example.org' && $channels === ['mail'];
        });
    $this->get('/cabinet')->assertRedirect('/cabinet/onboarding');
    $this->get($verificationUrl)->assertRedirect('/cabinet');
    expect($user->fresh()->email)->toBe('new@example.org')
        ->and($user->fresh()->pending_email)->toBeNull()
        ->and($user->fresh()->email_verified_at)->not->toBeNull();
    $this->get('/cabinet')->assertOk();
});

test('expired tampered stale and foreign verification links are rejected', function () {
    $user = User::factory()->unverified()->create(['pd_consent_at' => now(), 'pending_email' => 'new@example.org']);
    $url = URL::temporarySignedRoute('email.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1('new@example.org')]);
    $this->actingAs($user)->get($url.'&extra=tampered')->assertForbidden();
    $other = User::factory()->create(['pd_consent_at' => now()]);
    $this->actingAs($other)->get($url)->assertForbidden();
    $this->actingAs($user);
    $user->forceFill(['pending_email' => 'different@example.org'])->save();
    $this->get($url)->assertForbidden();
    $user->forceFill(['pending_email' => 'new@example.org'])->save();
    $this->travel(61)->minutes();
    $this->get($url)->assertForbidden();
    expect($user->fresh()->email_verified_at)->toBeNull();
});

test('blocking an existing session prevents access and onboarding actions', function () {
    $user = User::factory()->create(['pd_consent_at' => now()]);
    $this->actingAs($user)->get('/cabinet')->assertOk();
    $user->forceFill(['blocked_at' => now()])->save();
    $this->get('/cabinet')->assertForbidden();
});

test('verified user bypasses onboarding and guest cannot verify', function () {
    $user = User::factory()->create(['pd_consent_at' => now()]);
    $this->get('/cabinet/onboarding')->assertRedirect('/cabinet/login');
    $this->actingAs($user)->get('/cabinet/onboarding')->assertRedirect('/cabinet');
});

test('verification resend is limited with a useful validation error', function () {
    Notification::fake();
    $this->actingAs(User::factory()->unverified()->create(['pd_consent_at' => now()]));
    $page = Livewire::test(Onboarding::class)->set('email', 'school@example.org');
    for ($i = 0; $i < 3; $i++) {
        $page->call('sendVerification')->assertHasNoErrors();
    }
    $page->call('sendVerification')->assertHasErrors(['email']);
});

test('pending email link cannot bypass consent or be replayed', function () {
    $user = User::factory()->unverified()->create(['email' => null, 'pending_email' => 'school@example.org']);
    $url = URL::temporarySignedRoute('email.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1('school@example.org')]);
    $this->get($url)->assertRedirect('/cabinet/login');
    $this->actingAs($user)->get($url)->assertForbidden();
    $user->forceFill(['pd_consent_at' => now()])->save();
    $this->get($url)->assertRedirect('/cabinet');
    $this->get($url)->assertForbidden();
});

test('livewire updates recheck access when an authenticated user is blocked', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create(['pd_consent_at' => now()]);
    $response = $this->actingAs($user)->get('/cabinet/onboarding')->assertOk();
    preg_match_all('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
    $snapshot = collect($matches[1])->map(fn ($value) => html_entity_decode($value, ENT_QUOTES))
        ->first(fn ($value) => json_decode($value, true)['memo']['name'] === 'app.filament.pages.onboarding');
    expect($snapshot)->not->toBeNull();
    $payload = ['components' => [[
        'snapshot' => $snapshot,
        'updates' => ['email' => 'school@example.org'],
        'calls' => [['path' => '', 'method' => 'sendVerification', 'params' => []]],
    ]]];
    // Exercise the HTTP update endpoint, including persistent panel middleware.
    $this->postJson('/livewire/update', $payload)->assertOk();
    $user->forceFill(['blocked_at' => now()])->save();
    $this->postJson('/livewire/update', $payload)->assertForbidden();
});

test('dashboard livewire updates require completed onboarding', function (string $field) {
    $user = User::factory()->create(['pd_consent_at' => now()]);
    $response = $this->actingAs($user)->get('/cabinet')->assertOk();
    preg_match_all('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
    $snapshot = collect($matches[1])->map(fn ($value) => html_entity_decode($value, ENT_QUOTES))
        ->first(fn ($value) => json_decode($value, true)['memo']['name'] === app(ComponentRegistry::class)->getName(Dashboard::class));
    expect($snapshot)->not->toBeNull();
    $user->forceFill([$field => null])->save();
    $this->postJson('/livewire/update', ['components' => [[
        'snapshot' => $snapshot,
        'updates' => [],
        'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
    ]]])->assertRedirect('/cabinet/onboarding');
})->with(['pd_consent_at', 'email_verified_at']);
