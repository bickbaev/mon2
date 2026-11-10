<?php

use App\Enums\NodeType;
use App\Filament\Resources\Nodes\Pages\CreateNode;
use App\Filament\Resources\Nodes\Pages\ListNodes;
use App\Models\Node;
use App\Models\User;
use App\Services\NodeService;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

function structureFixture(): array
{
    $service = app(NodeService::class);
    $root = $service->create(null, NodeType::Authority, 'Регион');
    $district = $service->create($root, NodeType::Authority, 'Район');
    $school = $service->create($district, NodeType::Organization, 'Школа');
    $foreign = $service->create($root, NodeType::Organization, 'Чужая школа');

    return [$root, $district, $school, $foreign];
}

test('nodes list contains own branch and filters by type and parent', function () {
    [$root, $district, $school, $foreign] = structureFixture();
    $this->actingAs(User::factory()->create(['node_id' => $district->id, 'pd_consent_at' => now()]));
    Livewire::test(ListNodes::class)->assertCanSeeTableRecords([$district, $school])
        ->assertCanNotSeeTableRecords([$root, $foreign])
        ->filterTable('type', 'organization')->assertCanSeeTableRecords([$school])->assertCanNotSeeTableRecords([$district])
        ->resetTableFilters()->filterTable('parent_id', $district->id)->assertCanSeeTableRecords([$school]);
});

test('structure flag is required and foreign parent is rejected', function () {
    [$root, $district, $school, $foreign] = structureFixture();
    $actor = User::factory()->create(['node_id' => $district->id, 'pd_consent_at' => now()]);
    $this->actingAs($actor)->get('/cabinet/nodes/create')->assertForbidden();
    $actor->forceFill(['can_manage_structure' => true])->save();
    Livewire::test(CreateNode::class)->fillForm(['name' => 'Новая школа', 'type' => 'organization', 'parent_id' => $foreign->id])
        ->call('create')->assertHasFormErrors(['parent_id']);
    expect(Node::where('name', 'Новая школа')->exists())->toBeFalse();
});

test('creating a node uses tree service and rejects incompatible type', function () {
    [$root, $district, $school] = structureFixture();
    $this->actingAs(User::factory()->create(['node_id' => $district->id, 'can_manage_structure' => true, 'pd_consent_at' => now()]));
    Livewire::test(CreateNode::class)->fillForm(['name' => 'Филиал', 'type' => 'branch', 'parent_id' => $district->id])
        ->call('create')->assertHasFormErrors(['type']);
    Livewire::test(CreateNode::class)->fillForm(['name' => 'Филиал', 'type' => 'branch', 'parent_id' => $school->id])
        ->call('create')->assertHasNoFormErrors();
    $branch = Node::where('name', 'Филиал')->sole();
    expect($branch->path)->toBe($school->path.$branch->id.'/');
    expect($branch->depth)->toBe($school->depth + 1);
});

test('admin creates a root and node modification routes are absent', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true, 'pd_consent_at' => now()]));
    Livewire::test(CreateNode::class)->fillForm(['name' => 'Регион', 'type' => 'authority', 'parent_id' => null])
        ->call('create')->assertHasNoFormErrors();
    $root = Node::where('name', 'Регион')->sole();
    expect($root->parent_id)->toBeNull();
    $this->get('/cabinet/nodes/'.$root->id.'/edit')->assertNotFound();
});
