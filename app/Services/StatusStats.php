<?php

namespace App\Services;

use App\Enums\ReportStatus;
use App\Models\Campaign;
use App\Models\Node;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class StatusStats
{
    /** @return array<string, int> */
    public function counts(Campaign $campaign, Node $node): array
    {
        $access = app(StatisticsAccess::class);
        $node = $access->authorizeNode($node->id);
        $access->authorizeCampaign($campaign->id);
        $grouped = Report::query()->where('campaign_id', $campaign->id)
            ->whereHas('node', fn (Builder $nodes): Builder => $nodes->inBranch($node))
            ->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $counts = [];
        foreach (ReportStatus::cases() as $status) {
            $counts[$status->value] = (int) ($grouped[$status->value] ?? 0);
        }
        $counts['total'] = array_sum($counts);

        return $counts;
    }

    /** @return array{rows: list<array{node: Node, counts: array<string, int>}>, total: array<string, int>} */
    public function forNode(Campaign $campaign, Node $node): array
    {
        $access = app(StatisticsAccess::class);
        $node = $access->authorizeNode($node->id);
        $access->authorizeCampaign($campaign->id);
        $children = $node->children()->orderBy('name')->get();
        $groups = Report::query()->join('nodes', 'nodes.id', '=', 'reports.node_id')
            ->where('campaign_id', $campaign->id)->where('nodes.path', 'like', Node::escapeLike($node->path).'%')
            ->selectRaw('nodes.path, reports.status, COUNT(*) AS aggregate')->groupBy('nodes.path', 'reports.status')->toBase()->get();
        $total = $this->emptyCounts();
        $rows = $children->map(fn (Node $child): array => ['node' => $child, 'counts' => $this->emptyCounts()])->all();
        foreach ($groups as $group) {
            $count = (int) $group->aggregate;
            $total[$group->status] += $count;
            $total['total'] += $count;
            foreach ($rows as &$row) {
                if (str_starts_with($group->path, $row['node']->path)) {
                    $row['counts'][$group->status] += $count;
                    $row['counts']['total'] += $count;
                }
            }
            unset($row);
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string, int> */
    private function emptyCounts(): array
    {
        return array_fill_keys([...array_column(ReportStatus::cases(), 'value'), 'total'], 0);
    }

    /** @return list<array{campaign: Campaign, counts: array<string, int>}> */
    public function overview(User $viewer): array
    {
        $access = app(StatisticsAccess::class);
        $root = $access->root($viewer);
        $campaigns = $access->campaigns($viewer)->with('reportType')->orderByDesc('id')->get();
        $groups = Report::query()->whereIn('campaign_id', $campaigns->modelKeys())
            ->whereHas('node', fn (Builder $nodes): Builder => $nodes->inBranch($root))
            ->selectRaw('campaign_id, status, COUNT(*) AS aggregate')->groupBy('campaign_id', 'status')->toBase()->get()->groupBy('campaign_id');

        return $campaigns->map(function (Campaign $campaign) use ($groups): array {
            $counts = $this->emptyCounts();
            foreach ($groups->get($campaign->id, []) as $group) {
                $counts[$group->status] = (int) $group->aggregate;
                $counts['total'] += (int) $group->aggregate;
            }

            return ['campaign' => $campaign, 'counts' => $counts];
        })->all();
    }
}
