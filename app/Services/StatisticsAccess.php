<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Node;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;

class StatisticsAccess
{
    public function actor(): ?User
    {
        return User::query()->with('node')->find(Filament::auth()->id());
    }

    public function root(?User $actor = null): ?Node
    {
        $actor = $actor === null ? $this->actor() : $actor->fresh(['node']);
        if ($actor === null || $actor->isBlocked()) {
            return null;
        }

        return $actor->isAdmin() ? Node::query()->whereNull('parent_id')->first() : $actor->node;
    }

    public function allowed(): bool
    {
        return $this->root()?->children()->exists() ?? false;
    }

    public function authorizeNode(?int $id = null): Node
    {
        abort_unless($this->allowed(), 403);
        $root = $this->root();

        return $id === null ? $root : Node::query()->inBranch($root)->find($id) ?? abort(403);
    }

    public function campaigns(?User $viewer = null): Builder
    {
        $viewer = $viewer === null ? $this->actor() : $viewer->fresh(['node']);
        abort_if($viewer === null, 403);
        $root = $this->root($viewer);
        abort_unless($root !== null && $root->children()->exists(), 403);

        return Campaign::query()->whereNull('closed_at')
            ->where(fn (Builder $dates): Builder => $dates->whereNull('closes_at')->orWhereDate('closes_at', '>', today()))
            ->where(function (Builder $campaigns) use ($root, $viewer): void {
                $campaigns->whereHas('creatorNode', fn (Builder $nodes): Builder => $nodes->inBranch($root))
                    ->orWhere(function (Builder $higher) use ($root): void {
                        $ancestorIds = array_values(array_filter(explode('/', trim($root->path, '/'))));
                        $higher->where(fn (Builder $creators): Builder => $creators->whereIn('creator_node_id', $ancestorIds)->orWhereNull('creator_node_id'))
                            ->whereHas('reports.node', fn (Builder $nodes): Builder => $nodes->inBranch($root));
                    });
                if (($viewer?->isAdmin() ?? false)) {
                    $campaigns->orWhereNull('creator_node_id');
                }
            });
    }

    public function authorizeCampaign(int $id): Campaign
    {
        return $this->campaigns()->with('reportType')->find($id) ?? abort(403);
    }
}
