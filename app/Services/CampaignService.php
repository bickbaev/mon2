<?php

namespace App\Services;

use App\Enums\ReportStatus;
use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\Node;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CampaignService
{
    public function __construct(private NodeSelector $selector) {}

    public function startFromTemplate(CampaignTemplate $template): ?Campaign
    {
        return DB::transaction(function () use ($template): ?Campaign {
            $template = CampaignTemplate::query()->lockForUpdate()->findOrFail($template->id);
            $today = CarbonImmutable::today();
            if (! $template->active || $template->last_spawned_for?->greaterThanOrEqualTo($today)
                || ! app(ScheduleCalculator::class)->nextStart($template, $today->subDay())->equalTo($today)) {
                return null;
            }
            $actor = $template->creator()->lockForUpdate()->first();
            if ($actor === null || ! Gate::forUser($actor)->allows('create', Campaign::class)) {
                return null;
            }
            $type = $template->reportType()->lockForUpdate()->first();
            if ($type === null || ! $type->active || $type->is_base) {
                return null;
            }
            $nodes = $this->selector->available($actor)->whereIn('id', $template->nodes()->select('nodes.id'))->orderBy('id')->get();
            if ($nodes->isEmpty()) {
                return null;
            }
            $existing = $template->campaigns()->whereDate('starts_at', $today)->first();
            if ($existing !== null) {
                $template->update(['last_spawned_for' => $today]);

                return null;
            }
            $end = $today->addDays($template->duration_days);
            $campaign = $this->createCampaign($actor, [
                'title' => $template->title,
                'report_type_id' => $template->report_type_id,
                'starts_at' => $today,
                'ends_at' => $end,
                'closes_at' => $template->close_after_days === null ? null : $end->addDays($template->close_after_days),
                'start_status' => $template->start_status,
                'remind_days_before' => $template->remind_days_before,
                'template_id' => $template->id,
            ], $nodes);
            $template->update(['last_spawned_for' => $today]);

            return $campaign;
        }, 3);
    }

    public function start(User $actor, array $data, NodeSelection $selection): Campaign
    {
        return DB::transaction(function () use ($actor, $data, $selection): Campaign {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            Gate::forUser($actor)->authorize('create', Campaign::class);
            $data = Validator::make($data, [
                'title' => ['nullable', 'string', 'max:255'],
                'report_type_id' => ['required', 'integer', Rule::exists('report_types', 'id')->where('is_base', false)->where('active', true)],
                'starts_at' => ['required', 'date_format:Y-m-d'],
                'ends_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_at'],
                'closes_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:ends_at'],
                'start_status' => ['required', Rule::enum(ReportStatus::class)],
                'remind_days_before' => ['nullable', 'integer', 'min:0', 'max:65535'],
            ])->validate();

            return $this->createCampaign($actor, $data, $this->selector->resolve($actor, $selection));
        }, 3);
    }

    /** @param Collection<int, Node> $nodes */
    private function createCampaign(User $actor, array $data, Collection $nodes): Campaign
    {
        $campaign = Campaign::query()->create(array_merge($data, ['creator_id' => $actor->id, 'creator_node_id' => $actor->node_id]));
        foreach ($nodes as $node) {
            $campaign->reports()->create(['report_type_id' => $campaign->report_type_id, 'node_id' => $node->id, 'status' => $campaign->start_status]);
        }

        return $campaign;
    }

    public function addNode(User $actor, Campaign $campaign, Node $node): Report
    {
        return DB::transaction(function () use ($actor, $campaign, $node): Report {
            [$actor, $campaign] = $this->authorizeManagement($actor, $campaign);
            $owner = $campaign->creator()->firstOrFail();
            $this->selector->resolve($owner, new NodeSelection([$node->id]));
            $this->selector->resolve($actor, new NodeSelection([$node->id]));

            return $campaign->reports()->firstOrCreate(['node_id' => $node->id], ['report_type_id' => $campaign->report_type_id, 'status' => $campaign->start_status]);
        }, 3);
    }

    public function blockAll(User $actor, Campaign $campaign): int
    {
        return DB::transaction(function () use ($actor, $campaign): int {
            [, $campaign] = $this->authorizeManagement($actor, $campaign);

            return $campaign->reports()->update(['status' => ReportStatus::Blocked->value]);
        }, 3);
    }

    public function close(User $actor, Campaign $campaign): void
    {
        DB::transaction(function () use ($actor, $campaign): void {
            [, $campaign] = $this->authorizeManagement($actor, $campaign);
            $campaign->update(['closed_at' => now()]);
        }, 3);
    }

    /** @return array{User, Campaign} */
    private function authorizeManagement(User $actor, Campaign $campaign): array
    {
        $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);
        $actor = User::query()->findOrFail($actor->id);
        Gate::forUser($actor)->authorize('manage', $campaign);

        return [$actor, $campaign];
    }
}
