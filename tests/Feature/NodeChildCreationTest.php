<?php

use App\Enums\NodeType;
use App\Filament\Resources\Nodes\Pages\ListNodes;
use App\Models\Node;
use App\Models\User;
use App\Services\NodeService;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

function childCreationFixture(): array
{
    $service = app(NodeService::class);
    $root = $service->create(null, NodeType::Authority, 'Регион');
    $district = $service->create($root, NodeType::Authority, 'Район');
    $school = $service->create($district, NodeType::Organization, 'Школа');
    $branch = $service->create($school, NodeType::Branch, 'Филиал');
    $foreign = $service->create($root, NodeType::Authority, 'Чужой район');
    $actor = User::factory()->create([
        'node_id' => $district->id, 'can_manage_structure' => true, 'pd_consent_at' => now(),
    ]);

    return [$root, $district, $school, $branch, $foreign, $actor];
}

test('tree offers child creation only on supported parents with permission', function (bool $canManage) {
    [$root, $district, $school, $branch, $foreign, $actor] = childCreationFixture();
    $actor->forceFill(['can_manage_structure' => $canManage])->save();
    $this->actingAs($actor);

    $page = Livewire::test(ListNodes::class)->assertOk();

    expect($page->get('treeNodes.0.can_create_child'))->toBe($canManage);
    $page->call('loadChildren', $district->id);
    expect($page->effects['returns'][0][0]['can_create_child'])->toBe($canManage);
    $page->call('loadChildren', $school->id);
    expect($page->effects['returns'][0][0]['can_create_child'])->toBeFalse();
    $page->assertActionHidden('create');
})->with([true, false]);

test('child dialog creates an allowed node under its selected parent', function (NodeType $parentType, NodeType $childType) {
    [$root, $district, $school, $branch, $foreign, $actor] = childCreationFixture();
    $parent = $parentType === NodeType::Authority ? $district : $school;
    $this->actingAs($actor);

    $page = Livewire::test(ListNodes::class)
        ->mountAction('createChild', ['parentId' => $parent->id])
        ->assertMountedActionModalSee($parent->name)
        ->fillForm(['name' => 'Новый дочерний узел', 'type' => $childType->value, 'parent_id' => $foreign->id])
        ->callMountedAction()->assertHasNoActionErrors();

    $created = Node::where('name', 'Новый дочерний узел')->sole();
    expect($created->parent_id)->toBe($parent->id);
    expect($created->type)->toBe($childType);
    expect($created->path)->toBe($parent->path.$created->id.'/');
    expect($created->depth)->toBe($parent->depth + 1);
    $page->assertDispatched('node-created', function (string $event, array $data) use ($parent, $created): bool {
        return $data['parentId'] === $parent->id && in_array($created->id, array_column($data['children'], 'id'), true);
    });
    expect(array_column($page->get('treeNodes'), 'id'))->toBe([$district->id]);
})->with([
    'authority under authority' => [NodeType::Authority, NodeType::Authority],
    'organization under authority' => [NodeType::Authority, NodeType::Organization],
    'branch under organization' => [NodeType::Organization, NodeType::Branch],
]);

test('child dialog rejects unauthorized parents', function (string $target, int $status) {
    [$root, $district, $school, $branch, $foreign, $actor] = childCreationFixture();
    $parentId = match ($target) {
        'foreign' => $foreign->id,
        'ancestor' => $root->id,
        'branch' => $branch->id,
        'missing' => $foreign->id + 1000,
        'no permission' => $district->id,
    };
    if ($target === 'no permission') {
        $actor->forceFill(['can_manage_structure' => false])->save();
    }
    $this->actingAs($actor);

    Livewire::test(ListNodes::class)->mountAction('createChild', ['parentId' => $parentId])->assertStatus($status);

    $this->assertDatabaseCount('nodes', 5);
})->with([
    'foreign branch' => ['foreign', 404],
    'ancestor' => ['ancestor', 404],
    'leaf branch' => ['branch', 403],
    'missing parent' => ['missing', 404],
    'missing permission' => ['no permission', 403],
]);

test('child dialog rechecks permission before saving', function () {
    [$root, $district, $school, $branch, $foreign, $actor] = childCreationFixture();
    $this->actingAs($actor);
    $page = Livewire::test(ListNodes::class)->mountAction('createChild', ['parentId' => $district->id])
        ->fillForm(['name' => 'Новая школа', 'type' => 'organization']);
    $actor->forceFill(['can_manage_structure' => false])->save();

    $page->callMountedAction()->assertForbidden();

    $this->assertDatabaseMissing('nodes', ['name' => 'Новая школа']);
});

test('admin without a node can add children to a root', function () {
    [$root] = childCreationFixture();
    $this->actingAs(User::factory()->create(['is_admin' => true, 'pd_consent_at' => now()]));

    Livewire::test(ListNodes::class)->assertActionVisible('create')
        ->callAction('createChild', ['name' => 'Новый район', 'type' => 'authority'], ['parentId' => $root->id])
        ->assertHasNoActionErrors();

    $this->assertDatabaseHas('nodes', ['name' => 'Новый район', 'parent_id' => $root->id, 'depth' => 1]);
});

test('child dialog requires an explicit valid parent', function (array $arguments) {
    $this->actingAs(User::factory()->create(['is_admin' => true, 'pd_consent_at' => now()]));

    Livewire::test(ListNodes::class)->mountAction('createChild', $arguments)->assertNotFound();

    $this->assertDatabaseCount('nodes', 0);
})->with([
    'missing parent' => [[]],
    'array instead of id' => [['parentId' => []]],
]);

test('child dialog validates name and compatible type', function (array $data, array $errors) {
    [$root, $district, $school, $branch, $foreign, $actor] = childCreationFixture();
    $this->actingAs($actor);

    Livewire::test(ListNodes::class)->callAction('createChild', $data, ['parentId' => $school->id])
        ->assertHasActionErrors($errors)->assertNotDispatched('node-created');

    $this->assertDatabaseCount('nodes', 5);
})->with([
    'missing name' => [['name' => '', 'type' => 'branch'], ['name' => 'required']],
    'long name' => [['name' => str_repeat('a', 256), 'type' => 'branch'], ['name' => 'max']],
    'incompatible type' => [['name' => 'Название', 'type' => 'organization'], ['type']],
    'unknown type' => [['name' => 'Название', 'type' => 'unknown'], ['type']],
]);
