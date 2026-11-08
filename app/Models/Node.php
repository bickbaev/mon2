<?php

namespace App\Models;

use App\Enums\NodeType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Узел иерархии. path вида "/1/5/9/" включает сам узел.
 * path/depth поддерживает NodeService, напрямую их не меняют.
 */
class Node extends Model
{
    protected $fillable = ['parent_id', 'type', 'name', 'path', 'depth'];

    protected function casts(): array
    {
        return [
            'type' => NodeType::class,
            'depth' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Ветка корня: сам узел и все потомки (только потомки при $withSelf = false).
     * Префикс включает завершающий "/", поэтому "/1/" не совпадает с "/10/".
     */
    public function scopeInBranch(Builder $q, Node $root, bool $withSelf = true): Builder
    {
        $q->where('path', 'like', self::escapeLike($root->path).'%');

        return $withSelf ? $q : $q->where('id', '!=', $root->id);
    }

    /** Строго выше: сам узел предком себя не считается. */
    public function isAncestorOf(Node $other): bool
    {
        return $this->id !== $other->id && str_starts_with($other->path, $this->path);
    }

    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
