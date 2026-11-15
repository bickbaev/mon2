<?php

namespace App\Services;

use App\Enums\NodeType;
use App\Models\Node;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class NodeSelector
{
    public function available(User $actor, bool $withSelf = false): Builder
    {
        $query = Node::query();
        if ($actor->isBlocked()) {
            return $query->whereRaw('1 = 0');
        }
        if ($actor->isAdmin()) {
            return ! $withSelf && $actor->node_id !== null ? $query->whereKeyNot($actor->node_id) : $query;
        }
        $root = $actor->node_id === null ? null : Node::query()->find($actor->node_id);

        return $root === null ? $query->whereRaw('1 = 0') : $query->inBranch($root, $withSelf);
    }

    /** @return Collection<int, Node> */
    public function resolve(User $actor, NodeSelection $selection): Collection
    {
        $groups = get_object_vars($selection);
        $rules = [];
        foreach ($groups as $name => $ids) {
            $rules[$name] = ['array'];
            $rules[$name.'.*'] = ['integer', 'min:1'];
        }
        Validator::make($groups, $rules)->validate();
        $ids = array_values(array_unique(array_merge(...array_values($groups))));
        $selected = $this->available($actor, true)->whereIn('id', $ids)->get()->keyBy('id');
        if ($selected->count() !== count($ids)) {
            throw ValidationException::withMessages(['nodeIds' => 'Выбран несуществующий узел или узел вне вашей ветки.']);
        }
        $resolved = $selection->nodeIds;
        foreach ([
            'allInBranch' => null,
            'organizationsInBranch' => NodeType::Organization,
            'branchesInBranch' => NodeType::Branch,
        ] as $group => $type) {
            foreach ($selection->{$group} as $id) {
                $query = $this->available($actor)->inBranch($selected->get($id));
                if ($type !== null) {
                    $query->where('type', $type);
                }
                $resolved = array_merge($resolved, $query->pluck('id')->all());
            }
        }
        $nodes = $this->available($actor)->whereIn('id', $resolved)->orderBy('id')->get();
        if ($nodes->isEmpty()) {
            throw ValidationException::withMessages(['nodeIds' => 'Выберите хотя бы один подчинённый узел.']);
        }

        return $nodes;
    }
}
