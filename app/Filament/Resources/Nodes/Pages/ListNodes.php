<?php

namespace App\Filament\Resources\Nodes\Pages;

use App\Enums\NodeType;
use App\Filament\Resources\Nodes\NodeResource;
use App\Models\Node;
use App\Services\NodeService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use SolutionForest\FilamentNestableTree\Concerns\InteractsWithTree;
use SolutionForest\FilamentNestableTree\Tree;

class ListNodes extends Page
{
    use InteractsWithTree;

    protected static string $resource = NodeResource::class;

    protected static ?string $title = 'Организации';

    public function boot(): void
    {
        $actor = Filament::auth()->user()?->fresh();
        abort_if($actor === null || $actor->isBlocked(), 403);
        Gate::forUser($actor)->authorize('viewAny', Node::class);
    }

    public function tree(Tree $tree): Tree
    {
        return $tree
            ->records(function (): array {
                $query = NodeResource::getEloquentQuery();
                $actor = Filament::auth()->user()->fresh();

                return $this->treeNodesFor($actor->isAdmin()
                    ? $query->whereNull('parent_id')
                    : $query->whereKey($actor->node_id));
            })
            ->asyncChildren(function (int|string $parentId): array {
                $parent = NodeResource::getEloquentQuery()->find($parentId);
                abort_if($parent === null, 404);

                return $this->treeNodesFor(NodeResource::getEloquentQuery()->where('parent_id', $parent->id));
            })
            ->draggable(false);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.resources.nodes.tree'),
        ]);
    }

    public function createChildAction(): Action
    {
        return Action::make('createChild')
            ->label('Добавить дочерний узел')
            ->modalHeading(fn (array $arguments): string => 'Добавить узел: '.$this->childParent($arguments)->name)
            ->modalSubmitActionLabel('Создать')
            ->schema(function (array $arguments): array {
                $parent = $this->childParent($arguments);
                $options = array_filter(NodeResource::typeOptions(),
                    fn (string $type): bool => NodeType::from($type)->canSitUnder($parent->type), ARRAY_FILTER_USE_KEY);

                return [
                    TextInput::make('name')->label('Название')->required()->maxLength(255),
                    Select::make('type')->label('Тип')->options($options)->required()
                        ->in(array_keys($options))
                        ->default($parent->type === NodeType::Authority ? NodeType::Organization->value : NodeType::Branch->value),
                ];
            })
            ->action(function (array $data, array $arguments, NodeService $service): void {
                $parent = $this->childParent($arguments);
                try {
                    $service->create($parent, NodeType::from($data['type']), $data['name']);
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages([
                        $this->getMountedActionSchema()->getStatePath().'.type' => $exception->getMessage(),
                    ]);
                }

                $this->dispatch('node-created', parentId: $parent->id,
                    children: $this->treeNodesFor(NodeResource::getEloquentQuery()->where('parent_id', $parent->id)));
                Notification::make()->title('Узел добавлен')->success()->send();
            });
    }

    /** @param array<string, mixed> $arguments */
    protected function childParent(array $arguments): Node
    {
        $parentId = $arguments['parentId'] ?? null;
        abort_unless(is_int($parentId) || (is_string($parentId) && ctype_digit($parentId)), 404);
        $parent = NodeResource::getEloquentQuery()->find($parentId);
        abort_if($parent === null, 404);
        abort_if($parent->type === NodeType::Branch, 403);
        Gate::forUser(Filament::auth()->user()->fresh())->authorize('create', [Node::class, $parent]);

        return $parent;
    }

    /** @param array<int|string, mixed> $orderedNodes */
    public function saveTreeOrder(array $orderedNodes = [], ?string $treeKey = null): void
    {
        abort(403);
    }

    /**
     * @return list<array{id: int, name: string, type: string, children: array{}, _childrenLoaded: bool, can_create_child: bool}>
     */
    protected function treeNodesFor(Builder $query): array
    {
        $canCreate = NodeResource::canCreate();

        return $query->select(['id', 'name', 'type'])->withExists('children')
            ->orderBy('name')->orderBy('id')->get()
            ->map(fn (Node $node): array => [
                'id' => $node->id,
                'name' => $node->name,
                'type' => $node->type->value,
                'children' => [],
                '_childrenLoaded' => ! $node->children_exists,
                'can_create_child' => $canCreate && $node->type !== NodeType::Branch,
            ])->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->visible(fn (): bool => Filament::auth()->user()->fresh()->isAdmin()),
        ];
    }
}
