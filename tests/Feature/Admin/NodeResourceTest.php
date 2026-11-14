<?php

use App\Enums\NodeType;
use App\Filament\Resources\Nodes\NodeResource;
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

test('organizations navigation is visible only for nodes with children', function (NodeType $type, bool $hasChildren, bool $visible) {
    $service = app(NodeService::class);
    $root = $service->create(null, NodeType::Authority, 'Регион');
    $node = match ($type) {
        NodeType::Authority => $root,
        NodeType::Organization => $service->create($root, NodeType::Organization, 'Школа'),
        NodeType::Branch => $service->create(
            $service->create($root, NodeType::Organization, 'Школа'), NodeType::Branch, 'Филиал',
        ),
    };
    if ($hasChildren) {
        $service->create($node, $type === NodeType::Authority ? NodeType::Organization : NodeType::Branch, 'Подчинённый узел');
    }
    $this->actingAs(User::factory()->create(['node_id' => $node->id, 'pd_consent_at' => now()]));

    $response = $this->get('/cabinet/profile')->assertOk();
    $link = 'href="'.NodeResource::getUrl().'"';

    if ($visible) {
        $response->assertSee($link, false);
    } else {
        $response->assertDontSee($link, false);
    }
})->with([
    'authority with children' => [NodeType::Authority, true, true],
    'authority without children' => [NodeType::Authority, false, false],
    'organization with branch' => [NodeType::Organization, true, true],
    'organization without children' => [NodeType::Organization, false, false],
    'branch without children' => [NodeType::Branch, false, false],
]);

test('admin sees organizations navigation without a node', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true, 'pd_consent_at' => now()]));

    $this->get('/cabinet')->assertOk()->assertSee('href="'.NodeResource::getUrl().'"', false);
});

test('nodes tree starts at own node and loads children on demand', function () {
    [$root, $district, $school, $foreign] = structureFixture();
    $this->actingAs(User::factory()->create(['node_id' => $district->id, 'pd_consent_at' => now()]));

    $page = Livewire::test(ListNodes::class)->assertOk();

    expect(array_column($page->get('treeNodes'), 'id'))->toBe([$district->id]);
    expect($page->get('treeNodes.0.children'))->toBe([]);
    expect($page->get('treeNodes.0._childrenLoaded'))->toBeFalse();
    $page->call('loadChildren', $district->id)->assertReturned([
        ['id' => $school->id, 'name' => 'Школа', 'type' => 'organization', 'children' => [], '_childrenLoaded' => true, 'can_create_child' => false],
    ]);
});

test('admin sees all roots and children sorted by name without grandchildren', function () {
    [$root, $district, $school, $foreign] = structureFixture();
    $anotherRoot = app(NodeService::class)->create(null, NodeType::Authority, 'Другой регион');
    $this->actingAs(User::factory()->create(['is_admin' => true, 'pd_consent_at' => now()]));

    $page = Livewire::test(ListNodes::class)->assertOk();

    expect(array_column($page->get('treeNodes'), 'id'))->toBe([$anotherRoot->id, $root->id]);
    $page->call('loadChildren', $root->id)->assertReturned([
        ['id' => $district->id, 'name' => 'Район', 'type' => 'authority', 'children' => [], '_childrenLoaded' => false, 'can_create_child' => true],
        ['id' => $foreign->id, 'name' => 'Чужая школа', 'type' => 'organization', 'children' => [], '_childrenLoaded' => true, 'can_create_child' => true],
    ]);
});

test('tree rejects requests for nodes outside own branch', function (string $target) {
    [$root, $district, $school, $foreign] = structureFixture();
    $this->actingAs(User::factory()->create(['node_id' => $district->id, 'pd_consent_at' => now()]));
    $nodeId = match ($target) {
        'ancestor' => $root->id,
        'foreign' => $foreign->id,
        'missing' => $foreign->id + 1000,
    };

    Livewire::test(ListNodes::class)->call('loadChildren', $nodeId)->assertNotFound();
})->with(['ancestor', 'foreign', 'missing']);

test('user without a node cannot open the tree', function () {
    $this->actingAs(User::factory()->create(['pd_consent_at' => now()]));

    $this->get('/cabinet/nodes')->assertForbidden();
    Livewire::test(ListNodes::class)->assertForbidden();
});

test('tree rechecks blocking before loading a branch', function () {
    [$root, $district] = structureFixture();
    $actor = User::factory()->create(['node_id' => $district->id, 'pd_consent_at' => now()]);
    $this->actingAs($actor);
    $page = Livewire::test(ListNodes::class)->assertOk();
    $actor->forceFill(['blocked_at' => now()])->save();

    $page->call('loadChildren', $district->id)->assertForbidden();
});

test('tree rechecks the user branch before loading children', function () {
    [$root, $district, $school, $foreign] = structureFixture();
    $actor = User::factory()->create(['node_id' => $district->id, 'pd_consent_at' => now()]);
    $this->actingAs($actor);
    $page = Livewire::test(ListNodes::class)->assertOk();
    $actor->forceFill(['node_id' => $foreign->id])->save();

    $page->call('loadChildren', $district->id)->assertNotFound();
});

test('tree cannot persist a forged reorder request', function () {
    [$root, $district, $school, $foreign] = structureFixture();
    $this->actingAs(User::factory()->create(['is_admin' => true, 'pd_consent_at' => now()]));

    Livewire::test(ListNodes::class)->call('saveTreeOrder', [
        ['id' => $foreign->id, 'children' => [['id' => $school->id]]],
    ])->assertForbidden();

    $this->assertDatabaseHas('nodes', [
        'id' => $school->id, 'parent_id' => $district->id, 'path' => $school->path, 'depth' => $school->depth,
    ]);
});

test('tree with 2000 organizations loads only the requested level', function () {
    $service = app(NodeService::class);
    $root = $service->create(null, NodeType::Authority, 'Регион');
    $districts = [];
    for ($districtIndex = 0; $districtIndex < 20; $districtIndex++) {
        $district = $service->create($root, NodeType::Authority, sprintf('Район %02d', $districtIndex));
        $districts[] = $district;
        for ($schoolIndex = 0; $schoolIndex < 100; $schoolIndex++) {
            $service->create($district, NodeType::Organization, sprintf('Школа %02d-%02d', $districtIndex, $schoolIndex));
        }
    }
    $this->actingAs(User::factory()->create(['is_admin' => true, 'pd_consent_at' => now()]));

    $page = Livewire::test(ListNodes::class)->assertOk();

    expect($page->get('treeNodes'))->toHaveCount(1);
    expect($page->get('treeNodes.0.children'))->toBe([]);
    $page->call('loadChildren', $root->id);
    expect($page->effects['returns'][0])->toHaveCount(20);
    expect(array_column($page->effects['returns'][0], 'children'))->toBe(array_fill(0, 20, []));
    $page->call('loadChildren', $districts[0]->id);
    expect($page->effects['returns'][0])->toHaveCount(100);
    expect($page->effects['returns'][0][0]['name'])->toBe('Школа 00-00');
    $this->assertDatabaseCount('nodes', 2021);
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
