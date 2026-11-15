<?php

use App\Enums\NodeType;
use App\Enums\ReportStatus;
use App\Models\Campaign;
use App\Models\Report;
use App\Models\ReportType;
use App\Models\User;
use App\Services\CampaignService;
use App\Services\NodeSelection;
use App\Services\NodeSelector;
use App\Services\NodeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

test('four selection methods combine without duplicates and exclude own node', function () {
    [$actor, , $district, $school, $branch] = campaignContext();
    $selector = app(NodeSelector::class);
    expect($selector->resolve($actor, new NodeSelection(nodeIds: [$district->id, $school->id]))->modelKeys())->toBe([$school->id]);
    expect($selector->resolve($actor, new NodeSelection(allInBranch: [$district->id]))->modelKeys())->toBe([$school->id, $branch->id]);
    expect($selector->resolve($actor, new NodeSelection(organizationsInBranch: [$district->id]))->modelKeys())->toBe([$school->id]);
    expect($selector->resolve($actor, new NodeSelection(branchesInBranch: [$district->id]))->modelKeys())->toBe([$branch->id]);
    expect($selector->resolve($actor, new NodeSelection([$school->id], [$district->id], [$district->id], [$school->id]))->modelKeys())->toBe([$school->id, $branch->id]);
});

test('foreign and missing selections fail', function (string $field) {
    [$actor, , , $school, , $foreign] = campaignContext();
    expect(fn () => app(NodeSelector::class)->resolve($actor, new NodeSelection(...[$field => [$school->id, $foreign->id]])))->toThrow(ValidationException::class);
    expect(fn () => app(NodeSelector::class)->resolve($actor, new NodeSelection(nodeIds: [999999])))->toThrow(ValidationException::class);
})->with(['nodeIds', 'allInBranch', 'organizationsInBranch', 'branchesInBranch']);

test('empty selection creates nothing', function () {
    [$actor] = campaignContext();
    expect(fn () => app(CampaignService::class)->start($actor, campaignData(), new NodeSelection))->toThrow(ValidationException::class);
    expect(Campaign::count())->toBe(0);
    expect(Report::count())->toBe(0);
});

test('start rechecks revoked or blocked actor permissions', function (array $changes) {
    [$actor, , , $school] = campaignContext();
    User::whereKey($actor)->update($changes);
    expect(fn () => app(CampaignService::class)->start($actor, campaignData(), new NodeSelection([$school->id])))->toThrow(AuthorizationException::class);
    expect(Campaign::count())->toBe(0);
})->with([['changes' => ['can_start_campaigns' => false]], ['changes' => ['blocked_at' => '2026-11-15 10:00:00']]]);

test('invalid dates and settings create nothing', function (array $changes) {
    [$actor, , , $school] = campaignContext();
    expect(fn () => app(CampaignService::class)->start($actor, campaignData($changes), new NodeSelection([$school->id])))->toThrow(ValidationException::class);
    expect(Campaign::count())->toBe(0);
})->with([[['ends_at' => '2026-11-14']], [['closes_at' => '2026-11-19']], [['start_status' => 'unknown']], [['remind_days_before' => -1]]]);

test('base and inactive report types cannot be collected', function (array $changes) {
    [$actor, , , $school] = campaignContext();
    $type = ReportType::factory()->create($changes);
    expect(fn () => app(CampaignService::class)->start($actor, campaignData(['report_type_id' => $type->id]), new NodeSelection([$school->id])))->toThrow(ValidationException::class);
})->with([['changes' => ['is_base' => true]], ['changes' => ['active' => false]]]);

test('start freezes participants and applies start status ignoring forged ownership', function (ReportStatus $status) {
    [$actor, $root, $district, $school, $branch] = campaignContext();
    $campaign = app(CampaignService::class)->start($actor, campaignData(['start_status' => $status->value, 'creator_id' => 999, 'creator_node_id' => $root->id, 'closed_at' => now()]), new NodeSelection(allInBranch: [$district->id]));
    app(NodeService::class)->create($district, NodeType::Organization, 'Новая школа');
    expect($campaign->creator_id)->toBe($actor->id);
    expect($campaign->creator_node_id)->toBe($district->id);
    expect($campaign->closed_at)->toBeNull();
    expect($campaign->reports()->pluck('node_id')->all())->toBe([$school->id, $branch->id]);
    expect($campaign->reports()->get()->every(fn (Report $report): bool => $report->status === $status))->toBeTrue();
})->with(ReportStatus::cases());

test('creator and superior manage collection but peers and foreign users cannot', function () {
    test()->travelTo(now()->setDate(2026, 11, 15));
    [$actor, $root, $district, $school, $branch, $foreign] = campaignContext();
    $service = app(CampaignService::class);
    $campaign = $service->start($actor, campaignData(), new NodeSelection([$school->id]));
    foreach ([$district, $school, $foreign] as $node) {
        expect(fn () => $service->close(User::factory()->create(['node_id' => $node->id]), $campaign))->toThrow(AuthorizationException::class);
    }
    $superior = User::factory()->create(['node_id' => $root->id]);
    $report = $service->addNode($superior, $campaign, $branch);
    expect($service->addNode($actor, $campaign, $branch)->id)->toBe($report->id);
    expect(fn () => $service->addNode($superior, $campaign, $foreign))->toThrow(ValidationException::class);
    expect($campaign->reports()->count())->toBe(2);
    expect($service->blockAll($superior, $campaign))->toBe(2);
    expect($report->fresh()->status)->toBe(ReportStatus::Blocked);
    $service->close($actor, $campaign);
    expect($campaign->fresh()->closed_at)->not->toBeNull();
    expect(fn () => $service->addNode($actor, $campaign, $school))->toThrow(AuthorizationException::class);
    expect(fn () => $service->blockAll($superior, $campaign))->toThrow(AuthorizationException::class);
});

test('closed campaign reports cannot be edited or have status changed even by administrators', function () {
    $campaign = Campaign::factory()->create(['closed_at' => now()]);
    $report = Report::factory()->create(['campaign_id' => $campaign->id, 'report_type_id' => $campaign->report_type_id, 'status' => ReportStatus::InProgress]);
    $admin = User::factory()->create(['is_admin' => true]);
    expect(Gate::forUser($admin)->allows('edit', $report))->toBeFalse();
    expect(Gate::forUser($admin)->allows('setStatus', [$report, ReportStatus::Accepted]))->toBeFalse();
});

test('failure while assigning reports rolls back the collection and partial assignments', function () {
    [$actor, , $district] = campaignContext();
    $created = 0;
    Report::creating(function () use (&$created): void {
        if (++$created === 2) {
            throw new RuntimeException('Назначение прервано');
        }
    });
    expect(fn () => app(CampaignService::class)->start($actor, campaignData(), new NodeSelection(allInBranch: [$district->id])))->toThrow(RuntimeException::class, 'Назначение прервано');
    expect(Campaign::count())->toBe(0);
    expect(Report::count())->toBe(0);
});

test('administrator without a node can start and manage collections without the launch flag', function () {
    $this->travelTo(now()->setDate(2026, 11, 15));
    [, , , $school] = campaignContext();
    $admin = User::factory()->create(['is_admin' => true]);
    $service = app(CampaignService::class);
    $campaign = $service->start($admin, campaignData(), new NodeSelection([$school->id]));
    expect($campaign->creator_node_id)->toBeNull();
    expect($campaign->reports()->count())->toBe(1);
    $service->close($admin, $campaign);
    expect($campaign->fresh()->isClosed())->toBeTrue();
});

test('administrator own node is excluded by every selection mode', function (string $mode, NodeType $type) {
    [$actor, $root, $district, $school, $branch] = campaignContext();
    $own = match ($type) {
        NodeType::Authority => $district,
        NodeType::Organization => $school,
        NodeType::Branch => $branch,
    };
    $admin = User::factory()->create(['is_admin' => true, 'node_id' => $own->id]);
    $selection = $mode === 'nodeIds'
        ? new NodeSelection([$own->id, $root->id])
        : new NodeSelection(...[$mode => [$root->id]]);
    if ($mode === 'branchesInBranch') {
        expect(fn () => app(NodeSelector::class)->resolve($admin, $selection))->toThrow(ValidationException::class);
    } else {
        expect(app(NodeSelector::class)->resolve($admin, $selection)->modelKeys())->not->toContain($own->id);
    }
})->with([
    ['nodeIds', NodeType::Authority],
    ['allInBranch', NodeType::Authority],
    ['organizationsInBranch', NodeType::Organization],
    ['branchesInBranch', NodeType::Branch],
]);
