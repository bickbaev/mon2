<?php

use App\Enums\ReportStatus;
use App\Events\ReportReturnedForRework;
use App\Models\Report;
use App\Models\User;
use App\Services\ReportStatusService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function transitionFixture(ReportStatus $status): array
{
    $report = Report::factory()->create(['status' => $status]);

    return [$report, $report->campaign->creator];
}

dataset('allowed report transitions', [
    ['new', 'in_progress'], ['new', 'blocked'],
    ['in_progress', 'rework'], ['in_progress', 'accepted'], ['in_progress', 'blocked'],
    ['rework', 'in_progress'], ['rework', 'blocked'],
    ['accepted', 'in_progress'], ['accepted', 'rework'], ['accepted', 'blocked'],
    ['blocked', 'in_progress'], ['blocked', 'rework'],
]);

test('allowed transitions persist and emit rework only when appropriate', function (string $from, string $to) {
    [$report, $admin] = transitionFixture(ReportStatus::from($from));
    Event::fake([ReportReturnedForRework::class]);
    app(ReportStatusService::class)->transition($report, ReportStatus::from($to), $admin);
    expect($report->fresh()->status->value)->toBe($to);
    expect($report->status->value)->toBe($to);
    if ($to === 'rework') {
        Event::assertDispatched(ReportReturnedForRework::class, fn ($event) => $event->report->is($report) && $event->report->status === ReportStatus::Rework && $event->actor->is($admin));
    } else {
        Event::assertNotDispatched(ReportReturnedForRework::class);
    }
})->with('allowed report transitions');

test('invalid edges are denied even for admin without changes or events', function (string $from, string $to) {
    [$report, $admin] = transitionFixture(ReportStatus::from($from));
    Event::fake([ReportReturnedForRework::class]);
    expect(fn () => app(ReportStatusService::class)->transition($report, ReportStatus::from($to), $admin))->toThrow(DomainException::class);
    expect($report->fresh()->status->value)->toBe($from);
    Event::assertNotDispatched(ReportReturnedForRework::class);
})->with([
    ['new', 'new'], ['new', 'rework'], ['new', 'accepted'],
    ['in_progress', 'new'], ['in_progress', 'in_progress'],
    ['rework', 'new'], ['rework', 'rework'], ['rework', 'accepted'],
    ['accepted', 'new'], ['accepted', 'accepted'],
    ['blocked', 'new'], ['blocked', 'accepted'], ['blocked', 'blocked'],
]);

test('owner automatically moves new and rework into progress but cannot review or unblock', function (ReportStatus $from) {
    [$report] = transitionFixture($from);
    $owner = User::factory()->create(['node_id' => $report->node_id]);
    $service = app(ReportStatusService::class);
    $service->transition($report, ReportStatus::InProgress, $owner);
    expect($report->fresh()->status)->toBe(ReportStatus::InProgress);
    Event::fake([ReportReturnedForRework::class]);
    expect(fn () => $service->transition($report, ReportStatus::Rework, $owner))->toThrow(AuthorizationException::class);
    expect($report->fresh()->status)->toBe(ReportStatus::InProgress);
    Event::assertNotDispatched(ReportReturnedForRework::class);
})->with([ReportStatus::New, ReportStatus::Rework]);

test('status service uses current persisted report rather than stale snapshot', function () {
    [$report, $admin] = transitionFixture(ReportStatus::New);
    $stale = $report->fresh();
    app(ReportStatusService::class)->transition($report, ReportStatus::Blocked, $admin);
    expect(fn () => app(ReportStatusService::class)->transition($stale, ReportStatus::Blocked, $admin))->toThrow(DomainException::class);
    expect($report->fresh()->status)->toBe(ReportStatus::Blocked);
});

test('blocking actor after loading prevents service transition', function () {
    [$report, $admin] = transitionFixture(ReportStatus::InProgress);
    User::whereKey($admin->id)->update(['blocked_at' => now()]);
    Event::fake([ReportReturnedForRework::class]);
    expect(fn () => app(ReportStatusService::class)->transition($report, ReportStatus::Rework, $admin))->toThrow(AuthorizationException::class);
    expect($report->fresh()->status)->toBe(ReportStatus::InProgress);
    Event::assertNotDispatched(ReportReturnedForRework::class);
});

test('campaign closure after loading is enforced for normal creator', function () {
    [$report] = transitionFixture(ReportStatus::InProgress);
    $creator = User::factory()->create(['node_id' => $report->node->parent_id]);
    $report->campaign->update(['creator_id' => $creator->id]);
    $report->load('campaign');
    DB::table('campaigns')->where('id', $report->campaign_id)->update(['closed_at' => now()]);
    expect(fn () => app(ReportStatusService::class)->transition($report, ReportStatus::Rework, $creator))->toThrow(AuthorizationException::class);
    expect($report->fresh()->status)->toBe(ReportStatus::InProgress);
});

test('rework events are discarded when encompassing transaction rolls back', function () {
    [$report, $admin] = transitionFixture(ReportStatus::InProgress);
    Event::fake([ReportReturnedForRework::class]);
    try {
        DB::transaction(function () use ($report, $admin): void {
            app(ReportStatusService::class)->transition($report, ReportStatus::Rework, $admin);
            Event::assertNotDispatched(ReportReturnedForRework::class);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    expect($report->fresh()->status)->toBe(ReportStatus::InProgress);
    Event::assertNotDispatched(ReportReturnedForRework::class);
});
