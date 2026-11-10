<?php

use App\Enums\NodeType;
use App\Enums\ReportStatus;
use App\Models\Campaign;
use App\Models\Report;
use App\Models\ReportType;
use App\Models\User;
use App\Services\NodeService;
use Illuminate\Support\Facades\Gate;

function reportPolicyFixture(bool $base, string $role, ReportStatus $status): array
{
    $service = app(NodeService::class);
    $root = $service->create(null, NodeType::Authority, 'Регион');
    $district = $service->create($root, NodeType::Authority, 'Район');
    $org = $service->create($district, NodeType::Organization, 'Школа');
    $foreign = $service->create($root, NodeType::Organization, 'Сосед');
    $creator = User::factory()->create(['node_id' => $district->id]);
    $type = ReportType::factory()->create(['is_base' => $base]);
    $campaign = $base ? null : Campaign::factory()->create(['report_type_id' => $type->id, 'creator_id' => $creator->id, 'creator_node_id' => $district->id]);
    $report = Report::factory()->create(['report_type_id' => $type->id, 'campaign_id' => $campaign?->id, 'node_id' => $org->id, 'status' => $status]);
    $actor = match ($role) {
        'creator' => $creator,
        'owner', 'owner_colleague' => User::factory()->create(['node_id' => $org->id]),
        'ancestor', 'creator_colleague' => User::factory()->create(['node_id' => $district->id]),
        'higher' => User::factory()->create(['node_id' => $root->id]),
        'foreign' => User::factory()->create(['node_id' => $foreign->id]),
        'admin' => User::factory()->create(['is_admin' => true]),
        'blocked_admin' => User::factory()->create(['is_admin' => true, 'blocked_at' => now()]),
        default => User::factory()->create(),
    };

    return [$report, $actor];
}

dataset('report access roles', [
    'owner' => ['owner', true, true, false, false],
    'owner colleague' => ['owner_colleague', true, true, false, false],
    'creator' => ['creator', true, false, true, true],
    'ancestor' => ['ancestor', true, false, true, false],
    'creator colleague' => ['creator_colleague', true, false, true, false],
    'higher ancestor' => ['higher', true, false, true, false],
    'foreign branch' => ['foreign', false, false, false, false],
    'unassigned' => ['unassigned', false, false, false, false],
    'admin' => ['admin', true, true, true, true],
    'blocked admin' => ['blocked_admin', false, false, false, false],
]);

test('report permissions distinguish owner ancestors and exact campaign creator', function (string $role, bool $view, bool $ownerEdit, bool $superiorEdit, bool $creatorEdit, bool $base, ReportStatus $status) {
    [$report, $actor] = reportPolicyFixture($base, $role, $status);
    $expected = match ($status) {
        ReportStatus::New, ReportStatus::InProgress, ReportStatus::Rework => $ownerEdit,
        ReportStatus::Blocked => $superiorEdit,
        ReportStatus::Accepted => $base ? $superiorEdit : $creatorEdit,
    };
    expect(Gate::forUser($actor)->allows('view', $report))->toBe($view);
    expect(Gate::forUser($actor)->allows('edit', $report))->toBe($expected);
    expect(Gate::forUser($actor)->allows('update', $report))->toBe($expected);
})->with('report access roles')->with([false, true])->with(ReportStatus::cases());

test('accepted base status can be removed by any ancestor but campaign status only by creator', function (string $role, bool $view, bool $ownerEdit, bool $superiorEdit, bool $creatorEdit, bool $base) {
    [$report, $actor] = reportPolicyFixture($base, $role, ReportStatus::Accepted);
    foreach ([ReportStatus::InProgress, ReportStatus::Rework, ReportStatus::Blocked] as $to) {
        expect(Gate::forUser($actor)->allows('setStatus', [$report, $to]))->toBe($base ? $superiorEdit : $creatorEdit);
    }
})->with('report access roles')->with([false, true]);

test('closed campaign forbids normal view edit and status changes', function (string $role) {
    [$report, $actor] = reportPolicyFixture(false, $role, ReportStatus::InProgress);
    $report->campaign->update(['closed_at' => now()]);
    foreach (['view', 'edit', 'update'] as $ability) {
        expect(Gate::forUser($actor)->allows($ability, $report))->toBe($role === 'admin');
    }
    expect(Gate::forUser($actor)->allows('setStatus', [$report, ReportStatus::Rework]))->toBe($role === 'admin');
})->with(['owner', 'creator', 'higher', 'admin', 'blocked_admin']);

test('foreign report cannot be resolved through authorized branch query by id', function () {
    [$report, $actor] = reportPolicyFixture(false, 'foreign', ReportStatus::New);
    expect(Report::query()->inBranchOf($actor)->find($report->id))->toBeNull();
    expect(Gate::forUser($actor)->allows('view', $report))->toBeFalse();
});

test('working and blocked status changes require ancestors while automatic transitions require owner', function (string $role, bool $view, bool $ownerEdit, bool $superiorEdit) {
    [$report, $actor] = reportPolicyFixture(false, $role, ReportStatus::InProgress);
    foreach ([ReportStatus::Rework, ReportStatus::Accepted, ReportStatus::Blocked] as $to) {
        expect(Gate::forUser($actor)->allows('setStatus', [$report, $to]))->toBe($superiorEdit);
    }
    $report->update(['status' => ReportStatus::Blocked]);
    expect(Gate::forUser($actor)->allows('setStatus', [$report, ReportStatus::InProgress]))->toBe($superiorEdit);
    expect(Gate::forUser($actor)->allows('setStatus', [$report, ReportStatus::New]))->toBeFalse();
    foreach ([ReportStatus::New, ReportStatus::Rework] as $from) {
        $report->update(['status' => $from]);
        expect(Gate::forUser($actor)->allows('setStatus', [$report, ReportStatus::InProgress]))->toBe($ownerEdit);
        expect(Gate::forUser($actor)->allows('setStatus', [$report, ReportStatus::Blocked]))->toBe($superiorEdit);
    }
})->with('report access roles');
