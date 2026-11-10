<?php

use App\Enums\NodeType;
use App\Enums\ReportStatus;
use App\Models\Campaign;
use App\Models\Report;
use App\Models\ReportType;
use App\Models\User;
use App\Services\NodeService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function reportNodes(): array
{
    $service = app(NodeService::class);
    $root = $service->create(null, NodeType::Authority, 'Регион');
    $district = $service->create($root, NodeType::Authority, 'Район');
    $org = $service->create($district, NodeType::Organization, 'Школа');
    $foreign = $service->create($root, NodeType::Organization, 'Другая школа');

    return [$root, $district, $org, $foreign];
}

test('base report uniqueness is enforced in database even without model events', function () {
    [, , $org] = reportNodes();
    $type = ReportType::factory()->base()->create();
    $report = Report::factory()->create(['node_id' => $org->id, 'report_type_id' => $type->id, 'campaign_id' => null]);
    expect($report->fresh()->status)->toBe(ReportStatus::New);
    expect(fn () => DB::table('reports')->insert(['report_type_id' => $type->id, 'node_id' => $org->id, 'status' => 'new']))->toThrow(QueryException::class);
});

test('campaign reports are unique per campaign type and node but permit another campaign', function () {
    [, , $org] = reportNodes();
    $campaign = Campaign::factory()->create();
    $first = Report::factory()->for($campaign)->create(['report_type_id' => $campaign->report_type_id, 'node_id' => $org->id]);
    $another = Campaign::factory()->create(['report_type_id' => $campaign->report_type_id]);
    $second = Report::factory()->for($another, 'campaign')->create(['report_type_id' => $campaign->report_type_id, 'node_id' => $org->id]);
    expect($second->campaign->is($another))->toBeTrue();
    expect($first->reportType->is($campaign->reportType))->toBeTrue();
    expect(fn () => DB::table('reports')->insert(['report_type_id' => $campaign->report_type_id, 'campaign_id' => $campaign->id, 'node_id' => $org->id, 'status' => 'new']))->toThrow(QueryException::class);
});

test('report rejects campaign type mismatch and invalid base assignment', function () {
    [$root, , $org] = reportNodes();
    $campaign = Campaign::factory()->create();
    $type = ReportType::factory()->create();
    expect(fn () => Report::factory()->create(['campaign_id' => $campaign->id, 'report_type_id' => $type->id, 'node_id' => $org->id]))->toThrow(DomainException::class);
    expect(fn () => Report::factory()->create(['campaign_id' => null, 'report_type_id' => $type->id, 'node_id' => $org->id]))->toThrow(DomainException::class);
    $base = ReportType::factory()->base()->create();
    expect(fn () => Report::factory()->create(['campaign_id' => null, 'report_type_id' => $base->id, 'node_id' => $root->id]))->toThrow(DomainException::class);
});

test('closing uses start of closing date and explicit closure but not deadline', function () {
    $this->travelTo(now()->setDate(2026, 11, 10)->startOfDay());
    $campaign = Campaign::factory()->create(['ends_at' => '2026-11-01', 'closes_at' => '2026-11-11']);
    expect($campaign->isClosed())->toBeFalse();
    $this->travelTo(now()->addDay());
    expect($campaign->isClosed())->toBeTrue();
    $campaign->update(['closes_at' => null]);
    expect($campaign->isClosed())->toBeFalse();
    $campaign->update(['closed_at' => now()]);
    expect($campaign->isClosed())->toBeTrue();
});

test('branch scope includes self and descendants and excludes other branches', function () {
    [$root, $district, $org, $foreign] = reportNodes();
    $campaign = Campaign::factory()->create();
    $reports = collect([$root, $district, $org, $foreign])->map(fn ($node) => Report::factory()->create(['campaign_id' => $campaign->id, 'report_type_id' => $campaign->report_type_id, 'node_id' => $node->id]));
    $actor = User::factory()->create(['node_id' => $district->id]);
    expect(Report::query()->inBranchOf($actor)->pluck('id')->all())->toBe([$reports[1]->id, $reports[2]->id]);
    expect(Report::query()->inBranchOf(User::factory()->create())->count())->toBe(0);
    expect(Report::query()->inBranchOf(User::factory()->create(['is_admin' => true]))->count())->toBe(4);
    expect(Report::query()->inBranchOf(User::factory()->create(['is_admin' => true, 'blocked_at' => now()]))->count())->toBe(0);
});
