<?php

use App\Enums\NodeType;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Services\NodeService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

function userManagementFixture(): array
{
    $service = app(NodeService::class);
    $root = $service->create(null, NodeType::Authority, 'Регион');
    $district = $service->create($root, NodeType::Authority, 'Район');
    $school = $service->create($district, NodeType::Organization, 'Школа');
    $foreign = $service->create($root, NodeType::Organization, 'Чужая школа');
    $actor = User::factory()->create(['node_id' => $district->id, 'pd_consent_at' => now()]);

    return [$actor, $district, $school, $foreign];
}

test('users list includes only descendants and foreign edit returns not found', function () {
    [$actor, $district, $school, $foreign] = userManagementFixture();
    $child = User::factory()->create(['node_id' => $school->id]);
    $outsider = User::factory()->create(['node_id' => $foreign->id]);
    $peer = User::factory()->create(['node_id' => $district->id]);
    $this->actingAs($actor);
    Livewire::test(ListUsers::class)->assertCanSeeTableRecords([$child])->assertCanNotSeeTableRecords([$actor, $outsider, $peer]);
    $this->get('/cabinet/users/'.$outsider->id.'/edit')->assertNotFound();
});

test('superior creates user with temporary password shown once outside snapshot', function () {
    [$actor, $district, $school] = userManagementFixture();
    $this->actingAs($actor);
    $page = Livewire::test(CreateUser::class)->fillForm(['login' => 'new-school-user', 'name' => 'Сотрудник', 'node_id' => $school->id])
        ->set('data.is_admin', true)->set('data.can_start_campaigns', true)->set('data.can_manage_structure', true)
        ->call('create')->assertHasNoFormErrors();
    $created = User::where('login', 'new-school-user')->sole();
    expect($created->node_id)->toBe($school->id);
    expect($created->is_admin)->toBeFalse();
    expect($created->can_start_campaigns)->toBeFalse();
    expect($created->can_manage_structure)->toBeFalse();
    expect($created->pd_consent_at)->toBeNull();
    expect($created->email_verified_at)->toBeNull();
    expect($created->email)->toBeNull();
    $scripts = implode('', array_column($page->effects['xjs'] ?? [], 'expression'));
    preg_match('/"password":"([a-zA-Z0-9]+)"/', $scripts, $match);
    expect($match)->toHaveKey(1);
    $password = $match[1];
    expect(Hash::check($password, $created->password))->toBeTrue();
    expect(json_encode($page->snapshot))->not->toContain($password);
    expect(json_encode(session()->all()))->not->toContain($password);
    $page->call('$refresh');
    expect(json_encode($page->effects))->not->toContain($password);
    $this->get('/cabinet/users/create')->assertOk()->assertDontSee($password);
});

test('creating user rejects own or foreign node', function (string $target) {
    [$actor, $district, $school, $foreign] = userManagementFixture();
    $this->actingAs($actor);
    Livewire::test(CreateUser::class)->fillForm([
        'login' => 'invalid-user', 'name' => 'Сотрудник', 'node_id' => $target === 'own' ? $district->id : $foreign->id,
    ])->call('create')->assertHasFormErrors(['node_id']);
    expect(User::where('login', 'invalid-user')->exists())->toBeFalse();
})->with(['own', 'foreign']);

test('editing user ignores forged rights password and onboarding fields', function () {
    [$actor, $district, $school] = userManagementFixture();
    $child = User::factory()->create(['node_id' => $school->id]);
    $originalPassword = $child->password;
    $this->actingAs($actor);
    Livewire::test(EditUser::class, ['record' => $child->id])->fillForm(['name' => 'Изменено'])
        ->set('data.is_admin', true)->set('data.can_start_campaigns', true)->set('data.can_manage_structure', true)
        ->set('data.password', 'attacker-password')->set('data.pd_consent_at', now()->toDateTimeString())
        ->set('data.email', 'attacker@example.org')->set('data.blocked_at', now()->toDateTimeString())
        ->call('save')->assertHasNoFormErrors();
    expect($child->fresh()->name)->toBe('Изменено');
    expect($child->fresh()->password)->toBe($originalPassword);
    expect($child->fresh()->is_admin)->toBeFalse();
    expect($child->fresh()->can_start_campaigns)->toBeFalse();
    expect($child->fresh()->can_manage_structure)->toBeFalse();
    expect($child->fresh()->pd_consent_at)->toBeNull();
    expect($child->fresh()->blocked_at)->toBeNull();
    expect($child->fresh()->email)->toBe($child->email);
});

test('editing user cannot move account to foreign branch', function () {
    [$actor, $district, $school, $foreign] = userManagementFixture();
    $child = User::factory()->create(['node_id' => $school->id]);
    $this->actingAs($actor);
    Livewire::test(EditUser::class, ['record' => $child->id])->fillForm(['node_id' => $foreign->id])
        ->call('save')->assertHasFormErrors(['node_id']);
    expect($child->fresh()->node_id)->toBe($school->id);
});

test('admin can grant flags and create administrator outside tree', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true, 'pd_consent_at' => now()]));
    Livewire::test(CreateUser::class)->fillForm([
        'login' => 'new-admin', 'name' => 'Администратор', 'node_id' => null,
        'is_admin' => true, 'can_start_campaigns' => true, 'can_manage_structure' => true,
    ])->call('create')->assertHasNoFormErrors();
    $user = User::where('login', 'new-admin')->sole();
    expect($user->is_admin)->toBeTrue();
    expect($user->node_id)->toBeNull();
    expect($user->can_start_campaigns)->toBeTrue();
    expect($user->can_manage_structure)->toBeTrue();
});

test('superior blocks unblocks and deletes descendant through authorized actions', function () {
    [$actor, $district, $school] = userManagementFixture();
    $child = User::factory()->create(['node_id' => $school->id]);
    $this->actingAs($actor);
    $page = Livewire::test(ListUsers::class);
    $page->callAction(TestAction::make('block')->table($child));
    expect($child->fresh()->isBlocked())->toBeTrue();
    $page->callAction(TestAction::make('unblock')->table($child));
    expect($child->fresh()->isBlocked())->toBeFalse();
    $page->callAction(TestAction::make('delete')->table($child));
    expect(User::find($child->id))->toBeNull();
});

test('leaf user cannot open user management and admin cannot edit self there', function () {
    [$actor, $district, $school] = userManagementFixture();
    $leaf = User::factory()->create(['node_id' => $school->id, 'pd_consent_at' => now()]);
    $this->actingAs($leaf)->get('/cabinet/users')->assertForbidden();
    $admin = User::factory()->create(['is_admin' => true, 'pd_consent_at' => now()]);
    $this->actingAs($admin)->get('/cabinet/users/'.$admin->id.'/edit')->assertForbidden();
});

test('forged table actions cannot affect own peer or foreign account', function (string $action, string $target) {
    [$actor, $district, $school, $foreign] = userManagementFixture();
    $victim = match ($target) {
        'self' => $actor,
        'peer' => User::factory()->create(['node_id' => $district->id]),
        'foreign' => User::factory()->create(['node_id' => $foreign->id]),
    };
    $this->actingAs($actor);
    $page = Livewire::test(ListUsers::class);
    $blockedAt = $action === 'unblock' ? now()->startOfSecond() : null;
    $victim->forceFill(['blocked_at' => $blockedAt])->save();
    $page
        ->call('mountAction', $action, [], ['table' => true, 'recordKey' => (string) $victim->id])
        ->call('callMountedAction');
    expect($victim->fresh())->not->toBeNull();
    expect($victim->fresh()->blocked_at?->toDateTimeString())->toBe($blockedAt?->toDateTimeString());
})->with(['block', 'unblock', 'delete'])->with(['self', 'peer', 'foreign']);

test('admin demotion after opening create form cannot grant rights', function () {
    [$actor, $district, $school] = userManagementFixture();
    $actor->forceFill(['is_admin' => true])->save();
    $this->actingAs($actor);
    $page = Livewire::test(CreateUser::class)->fillForm([
        'login' => 'after-demotion', 'name' => 'Сотрудник', 'node_id' => $school->id,
        'can_start_campaigns' => true, 'can_manage_structure' => true,
    ]);
    User::whereKey($actor->id)->update(['is_admin' => false]);
    $page->call('create')->assertHasNoFormErrors();
    $created = User::where('login', 'after-demotion')->sole();
    expect($created->can_start_campaigns)->toBeFalse();
    expect($created->can_manage_structure)->toBeFalse();
});

test('blocked session cannot save an already opened user editor through livewire HTTP', function () {
    [$actor, $district, $school] = userManagementFixture();
    $child = User::factory()->create(['node_id' => $school->id]);
    $response = $this->actingAs($actor)->get('/cabinet/users/'.$child->id.'/edit')->assertOk();
    preg_match_all('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
    $snapshot = collect($matches[1])->map(fn ($value) => html_entity_decode($value, ENT_QUOTES))
        ->first(fn ($value) => json_decode($value, true)['memo']['name'] === 'app.filament.resources.users.pages.edit-user');
    expect($snapshot)->not->toBeNull();
    User::whereKey($actor->id)->update(['blocked_at' => now()]);
    $this->postJson('/livewire/update', ['components' => [[
        'snapshot' => $snapshot, 'updates' => ['data.name' => 'Взломано'],
        'calls' => [['path' => '', 'method' => 'save', 'params' => []]],
    ]]])->assertForbidden();
    expect($child->fresh()->name)->toBe($child->name);
});
