<?php

namespace App\Services;

use App\Enums\Periodicity;
use App\Enums\ReportStatus;
use App\Models\CampaignTemplate;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CampaignTemplateService
{
    public function __construct(private NodeSelector $selector) {}

    public function create(User $actor, array $data, NodeSelection $selection): CampaignTemplate
    {
        return DB::transaction(function () use ($actor, $data, $selection): CampaignTemplate {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            Gate::forUser($actor)->authorize('create', CampaignTemplate::class);
            $data = $this->validated($data);
            $nodes = $this->selector->resolve($actor, $selection);
            $template = CampaignTemplate::query()->create(array_merge($data, ['creator_id' => $actor->id, 'creator_node_id' => $actor->node_id]));
            $template->nodes()->sync($nodes->modelKeys());

            return $template;
        }, 3);
    }

    public function update(User $actor, CampaignTemplate $template, array $data): CampaignTemplate
    {
        return DB::transaction(function () use ($actor, $template, $data): CampaignTemplate {
            $template = CampaignTemplate::query()->lockForUpdate()->findOrFail($template->id);
            $actor = User::query()->findOrFail($actor->id);
            Gate::forUser($actor)->authorize('update', $template);
            $template->update($this->validated(array_replace($template->attributesToArray(), $data)));

            return $template;
        }, 3);
    }

    public function addNode(User $actor, CampaignTemplate $template, Node $node): void
    {
        DB::transaction(function () use ($actor, $template, $node): void {
            $template = CampaignTemplate::query()->lockForUpdate()->findOrFail($template->id);
            $actor = User::query()->findOrFail($actor->id);
            Gate::forUser($actor)->authorize('update', $template);
            $owner = $template->creator()->firstOrFail();
            $this->selector->resolve($owner, new NodeSelection([$node->id]));
            $this->selector->resolve($actor, new NodeSelection([$node->id]));
            $template->nodes()->syncWithoutDetaching([$node->id]);
        }, 3);
    }

    private function validated(array $data): array
    {
        $data = Validator::make($data, [
            'title' => ['nullable', 'string', 'max:255'],
            'report_type_id' => ['required', 'integer', Rule::exists('report_types', 'id')->where('is_base', false)->where('active', true)],
            'periodicity' => ['required', Rule::enum(Periodicity::class)],
            'start_weekday' => ['exclude_unless:periodicity,weekly', 'required', 'integer', 'between:1,7'],
            'start_day' => ['exclude_if:periodicity,weekly', 'required', 'integer', 'between:1,31'],
            'start_month' => ['exclude_unless:periodicity,yearly', 'required', 'integer', 'between:1,12'],
            'duration_days' => ['required', 'integer', 'between:0,65535'],
            'close_after_days' => ['nullable', 'integer', 'between:0,65535'],
            'start_status' => ['required', Rule::enum(ReportStatus::class)],
            'remind_days_before' => ['nullable', 'integer', 'between:0,65535'],
            'active' => ['sometimes', 'boolean'],
        ])->validate();

        return array_replace(['start_weekday' => null, 'start_day' => null, 'start_month' => null, 'active' => true], $data);
    }
}
