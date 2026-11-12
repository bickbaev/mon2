<?php

namespace App\Services;

use App\Enums\NodeType;
use App\Events\NodeCreated;
use App\Exceptions\InvalidNodeMove;
use App\Models\Node;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class NodeService
{
    public function create(?Node $parent, NodeType $type, string $name): Node
    {
        return DB::transaction(function () use ($parent, $type, $name) {
            // родителя перечитываем под блокировкой: его могли перенести после загрузки модели
            if ($parent !== null) {
                $parent = Node::lockForUpdate()->find($parent->id)
                    ?? throw new InvalidArgumentException('Родительский узел не найден.');
            }

            if (! $type->canSitUnder($parent?->type)) {
                throw new InvalidArgumentException(sprintf(
                    'Узел типа "%s" нельзя создать %s.',
                    $type->value,
                    $parent ? 'под узлом типа "'.$parent->type->value.'"' : 'в корне',
                ));
            }

            $node = Node::create([
                'parent_id' => $parent?->id,
                'type' => $type,
                // path зависит от id, поэтому заполняется сразу после вставки
                'name' => $name,
                'path' => '',
                'depth' => $parent ? $parent->depth + 1 : 0,
            ]);

            $node->path = ($parent?->path ?? '/').$node->id.'/';
            $node->save();

            event(new NodeCreated($node));

            return $node;
        });
    }

    public function move(Node $node, Node $newParent): void
    {
        DB::transaction(function () use ($node, $newParent) {
            // актуальное состояние узлов под блокировкой
            $locked = Node::whereIn('id', [$node->id, $newParent->id])->lockForUpdate()->get()->keyBy('id');
            $current = $locked->get($node->id) ?? throw new InvalidNodeMove('Узел не найден.');
            $target = $locked->get($newParent->id) ?? throw new InvalidNodeMove('Новый родитель не найден.');

            if ($current->parent_id === null) {
                throw new InvalidNodeMove('Корневой узел нельзя перенести.');
            }
            if ($current->id === $target->id || $current->isAncestorOf($target)) {
                throw new InvalidNodeMove('Нельзя перенести узел под самого себя или своего потомка.');
            }
            if (! $current->type->canSitUnder($target->type)) {
                throw new InvalidNodeMove(sprintf(
                    'Узел типа "%s" нельзя перенести под узел типа "%s".',
                    $current->type->value,
                    $target->type->value,
                ));
            }

            $oldPath = $current->path;
            $newPath = $target->path.$current->id.'/';
            $depthDelta = ($target->depth + 1) - $current->depth;

            // вся ветка (включая сам узел) одним UPDATE по префиксу path
            DB::update(
                'UPDATE nodes SET path = CONCAT(?, SUBSTRING(path, ?)), depth = depth + ?, parent_id = IF(id = ?, ?, parent_id) WHERE path LIKE ?',
                [$newPath, mb_strlen($oldPath) + 1, $depthDelta, $current->id, $target->id, Node::escapeLike($oldPath).'%'],
            );
        });

        $node->refresh();
    }
}
