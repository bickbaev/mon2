<?php

use App\Enums\NodeType;
use App\Events\NodeCreated;
use App\Exceptions\InvalidNodeMove;
use App\Models\Node;
use App\Services\NodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function nodes(): NodeService
{
    return app(NodeService::class);
}

/** Карта id => path всех узлов, для сравнения состояния дерева. */
function treeSnapshot(): array
{
    return Node::query()->orderBy('id')->get()->mapWithKeys(
        fn (Node $n) => [$n->id => [$n->parent_id, $n->path, $n->depth]]
    )->all();
}

test('path and depth are built', function () {
    $root = nodes()->create(null, NodeType::Authority, 'Министерство');
    $dept = nodes()->create($root, NodeType::Authority, 'Управление');
    $org = nodes()->create($dept, NodeType::Organization, 'Школа 1');
    $branch = nodes()->create($org, NodeType::Branch, 'Филиал');

    expect($root->path)->toBe("/{$root->id}/")
        ->and($root->depth)->toBe(0)
        ->and($root->parent_id)->toBeNull()
        ->and($dept->path)->toBe("/{$root->id}/{$dept->id}/")
        ->and($dept->depth)->toBe(1)
        ->and($org->depth)->toBe(2)
        ->and($branch->path)->toBe("/{$root->id}/{$dept->id}/{$org->id}/{$branch->id}/")
        ->and($branch->depth)->toBe(3)
        ->and($branch->fresh()->path)->toBe($branch->path)
        ->and($branch->type)->toBe(NodeType::Branch);
});

test('root must be authority', function () {
    nodes()->create(null, NodeType::Organization, 'Школа');
})->throws(InvalidArgumentException::class);

test('organization cannot sit under organization', function () {
    $root = nodes()->create(null, NodeType::Authority, 'Корень');
    $org = nodes()->create($root, NodeType::Organization, 'Школа');

    nodes()->create($org, NodeType::Organization, 'Другая школа');
})->throws(InvalidArgumentException::class);

test('authority cannot sit under organization', function () {
    $root = nodes()->create(null, NodeType::Authority, 'Корень');
    $org = nodes()->create($root, NodeType::Organization, 'Школа');

    nodes()->create($org, NodeType::Authority, 'Управление');
})->throws(InvalidArgumentException::class);

test('branch must sit under organization', function () {
    $root = nodes()->create(null, NodeType::Authority, 'Корень');

    expect(fn () => nodes()->create($root, NodeType::Branch, 'Филиал'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => nodes()->create(null, NodeType::Branch, 'Филиал'))
        ->toThrow(InvalidArgumentException::class);

    $org = nodes()->create($root, NodeType::Organization, 'Школа');
    expect(nodes()->create($org, NodeType::Branch, 'Филиал')->parent_id)->toBe($org->id);
});

test('branch cannot have children', function () {
    $root = nodes()->create(null, NodeType::Authority, 'Корень');
    $org = nodes()->create($root, NodeType::Organization, 'Школа');
    $branch = nodes()->create($org, NodeType::Branch, 'Филиал');

    foreach (NodeType::cases() as $type) {
        expect(fn () => nodes()->create($branch, $type, 'Потомок'))
            ->toThrow(InvalidArgumentException::class);
    }
});

test('in branch scope returns descendants only when without self', function () {
    $root = nodes()->create(null, NodeType::Authority, 'Корень');
    $dept = nodes()->create($root, NodeType::Authority, 'Управление');
    $org = nodes()->create($dept, NodeType::Organization, 'Школа');
    $branch = nodes()->create($org, NodeType::Branch, 'Филиал');
    $otherOrg = nodes()->create($root, NodeType::Organization, 'Чужая школа');

    $withSelf = Node::query()->inBranch($dept)->pluck('id')->all();
    $withoutSelf = Node::query()->inBranch($dept, false)->pluck('id')->all();

    expect($withSelf)->toEqualCanonicalizing([$dept->id, $org->id, $branch->id])
        ->and($withoutSelf)->toEqualCanonicalizing([$org->id, $branch->id])
        ->and($withSelf)->not->toContain($otherOrg->id);
});

test('in branch scope does not mix prefix ids', function () {
    $a = nodes()->create(null, NodeType::Authority, 'Узел A');
    $aChild = nodes()->create($a, NodeType::Authority, 'Потомок A');

    // Узел с id вида "<id A>0": путь /{a}0/ начинается с /{a}, но не с /{a}/.
    $tenXId = (int) ($a->id.'0');
    DB::table('nodes')->insert([
        'id' => $tenXId,
        'parent_id' => null,
        'type' => NodeType::Authority->value,
        'name' => 'Узел с похожим id',
        'path' => "/{$tenXId}/",
        'depth' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $ids = Node::query()->inBranch($a)->pluck('id')->all();

    expect($ids)->toEqualCanonicalizing([$a->id, $aChild->id])
        ->and($ids)->not->toContain($tenXId);
});

test('is ancestor of is strictly above', function () {
    $root = nodes()->create(null, NodeType::Authority, 'Корень');
    $org = nodes()->create($root, NodeType::Organization, 'Школа');
    $other = nodes()->create($root, NodeType::Organization, 'Другая');

    expect($root->isAncestorOf($org))->toBeTrue()
        ->and($org->isAncestorOf($root))->toBeFalse()
        ->and($org->isAncestorOf($org))->toBeFalse()
        ->and($other->isAncestorOf($org))->toBeFalse();
});

test('node created event is dispatched after create', function () {
    Event::fake([NodeCreated::class]);

    $root = nodes()->create(null, NodeType::Authority, 'Корень');

    Event::assertDispatched(NodeCreated::class, fn (NodeCreated $e) => $e->node->is($root));
    Event::assertDispatchedTimes(NodeCreated::class, 1);
});

test('node created event is not dispatched when rules are violated', function () {
    Event::fake([NodeCreated::class]);

    try {
        nodes()->create(null, NodeType::Branch, 'Филиал');
    } catch (InvalidArgumentException) {
    }

    Event::assertNotDispatched(NodeCreated::class);
});

test('move recalculates whole subtree', function () {
    $root = nodes()->create(null, NodeType::Authority, 'Корень');
    $deptA = nodes()->create($root, NodeType::Authority, 'Управление A');
    $deptB = nodes()->create($root, NodeType::Authority, 'Управление B');
    $subDept = nodes()->create($deptA, NodeType::Authority, 'Отдел');
    $org = nodes()->create($subDept, NodeType::Organization, 'Школа');
    $branch = nodes()->create($org, NodeType::Branch, 'Филиал');
    $sibling = nodes()->create($deptB, NodeType::Organization, 'Соседняя школа');

    nodes()->move($subDept, $deptB);

    $subDept->refresh();
    $org->refresh();
    $branch->refresh();

    expect($subDept->parent_id)->toBe($deptB->id)
        ->and($subDept->path)->toBe("/{$root->id}/{$deptB->id}/{$subDept->id}/")
        ->and($subDept->depth)->toBe(2)
        ->and($org->path)->toBe("/{$root->id}/{$deptB->id}/{$subDept->id}/{$org->id}/")
        ->and($org->depth)->toBe(3)
        ->and($branch->path)->toBe("/{$root->id}/{$deptB->id}/{$subDept->id}/{$org->id}/{$branch->id}/")
        ->and($branch->depth)->toBe(4)
        ->and($sibling->fresh()->path)->toBe("/{$root->id}/{$deptB->id}/{$sibling->id}/")
        ->and(Node::query()->inBranch($deptA, false)->count())->toBe(0)
        ->and(Node::query()->inBranch($deptB, false)->count())->toBe(4);
});

test('move under own descendant is rejected and tree unchanged', function () {
    $root = nodes()->create(null, NodeType::Authority, 'Корень');
    $dept = nodes()->create($root, NodeType::Authority, 'Управление');
    $subDept = nodes()->create($dept, NodeType::Authority, 'Отдел');
    $org = nodes()->create($subDept, NodeType::Organization, 'Школа');

    $before = treeSnapshot();

    expect(fn () => nodes()->move($dept, $subDept))->toThrow(InvalidNodeMove::class)
        ->and(treeSnapshot())->toBe($before);

    // под самого себя
    expect(fn () => nodes()->move($dept, $dept))->toThrow(InvalidNodeMove::class)
        ->and(treeSnapshot())->toBe($before);
});

test('move violating type rules is rejected and tree unchanged', function () {
    $root = nodes()->create(null, NodeType::Authority, 'Корень');
    $orgA = nodes()->create($root, NodeType::Organization, 'Школа A');
    $orgB = nodes()->create($root, NodeType::Organization, 'Школа B');
    $branch = nodes()->create($orgA, NodeType::Branch, 'Филиал');

    $before = treeSnapshot();

    // организация под организацию, филиал под орган
    expect(fn () => nodes()->move($orgA, $orgB))->toThrow(InvalidNodeMove::class)
        ->and(fn () => nodes()->move($branch, $root))->toThrow(InvalidNodeMove::class)
        ->and(fn () => nodes()->move($root, $orgB))->toThrow(InvalidNodeMove::class)
        ->and(treeSnapshot())->toBe($before);

    // допустимый перенос филиала в другую организацию
    nodes()->move($branch, $orgB);
    expect($branch->fresh()->path)->toBe("/{$root->id}/{$orgB->id}/{$branch->id}/");
});
