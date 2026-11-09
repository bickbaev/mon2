<?php

use App\Enums\NodeType;
use App\Models\Node;
use App\Models\User;
use App\Services\NodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function () {
    $service = app(NodeService::class);
    $this->root = $service->create(null, NodeType::Authority, 'Регион');
    $this->dept = $service->create($this->root, NodeType::Authority, 'Район');
    $this->org = $service->create($this->dept, NodeType::Organization, 'Школа');
    $this->foreign = $service->create($this->root, NodeType::Organization, 'Другая школа');
    $this->actor = User::factory()->create(['node_id' => $this->dept->id]);
    $this->child = User::factory()->create(['node_id' => $this->org->id]);
});

test('superior manages users of descendants', function () {
    expect($this->actor->canManageUser($this->child))->toBeTrue();
    foreach (['view', 'update', 'delete', 'block'] as $ability) {
        expect(Gate::forUser($this->actor)->allows($ability, $this->child))->toBeTrue();
    }
    expect(Gate::forUser($this->actor)->allows('viewAny', User::class))->toBeTrue()
        ->and(Gate::forUser($this->actor)->allows('create', [User::class, $this->org]))->toBeTrue();
});

test('user cannot manage peer or ancestor', function () {
    $peer = User::factory()->create(['node_id' => $this->dept->id]);
    $ancestor = User::factory()->create(['node_id' => $this->root->id]);
    $outsider = User::factory()->create(['node_id' => $this->foreign->id]);
    foreach ([$this->actor, $peer, $ancestor, $outsider] as $target) {
        expect($this->actor->canManageUser($target))->toBeFalse();
        foreach (['view', 'update', 'delete', 'block'] as $ability) {
            expect(Gate::forUser($this->actor)->allows($ability, $target))->toBeFalse();
        }
    }
    expect(Gate::forUser($this->actor)->allows('create', [User::class, $this->dept]))->toBeFalse()
        ->and(Gate::forUser($this->actor)->allows('create', [User::class, $this->foreign]))->toBeFalse();
});

test('admin without node sees everything but cannot manage self', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    expect($admin->node_id)->toBeNull()
        ->and($admin->isAdmin())->toBeTrue()
        ->and($admin->canSeeNode($this->foreign))->toBeTrue()
        ->and($admin->canManageUser($this->actor))->toBeTrue()
        ->and($admin->canManageUser($admin))->toBeFalse()
        ->and(User::query()->inBranchOf($admin)->count())->toBe(User::count())
        ->and(Gate::forUser($admin)->allows('create', [Node::class, null]))->toBeTrue();
});

test('only admin can grant flags', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    expect(Gate::forUser($admin)->allows('grantFlags', User::class))->toBeTrue()
        ->and(Gate::forUser($this->actor)->allows('grantFlags', User::class))->toBeFalse();
});

test('can manage structure flag limits creation to own branch', function () {
    expect(Gate::forUser($this->actor)->allows('create', [Node::class, $this->org]))->toBeFalse();
    $this->actor->forceFill(['can_manage_structure' => true])->save();
    foreach ([$this->dept, $this->org] as $parent) {
        expect(Gate::forUser($this->actor)->allows('create', [Node::class, $parent]))->toBeTrue();
    }
    foreach ([$this->root, $this->foreign, null] as $parent) {
        expect(Gate::forUser($this->actor)->allows('create', [Node::class, $parent]))->toBeFalse();
    }
});

test('visibility includes own node while user lists contain only descendants', function () {
    expect($this->actor->canSeeNode($this->dept))->toBeTrue()
        ->and($this->actor->canSeeNode($this->org))->toBeTrue()
        ->and($this->actor->canSeeNode($this->root))->toBeFalse()
        ->and($this->actor->canSeeNode($this->foreign))->toBeFalse()
        ->and(User::query()->inBranchOf($this->actor)->pluck('id')->all())->toBe([$this->child->id]);
});

test('user without node has no branch permissions', function () {
    $unassigned = User::factory()->create();
    expect($unassigned->canSeeNode($this->root))->toBeFalse()
        ->and($unassigned->canManageUser($this->child))->toBeFalse()
        ->and(User::query()->inBranchOf($unassigned)->count())->toBe(0)
        ->and(Gate::forUser($unassigned)->allows('viewAny', User::class))->toBeFalse();
});

test('user schema supports login optional shared email and permission casts', function () {
    $first = User::factory()->create(['email' => null]);
    User::factory()->create(['email' => 'shared@example.org']);
    User::factory()->create(['email' => 'shared@example.org']);
    $first->forceFill(['blocked_at' => now(), 'pd_consent_at' => now(), 'pending_email' => 'new@example.org'])->save();
    expect($first->fresh()->isBlocked())->toBeTrue()
        ->and($this->actor->isBlocked())->toBeFalse()
        ->and($this->actor->is_admin)->toBeFalse()
        ->and($this->actor->can_start_campaigns)->toBeFalse()
        ->and($this->actor->can_manage_structure)->toBeFalse()
        ->and($this->actor->node->is($this->dept))->toBeTrue()
        ->and($first->login)->not->toBeEmpty();
});
