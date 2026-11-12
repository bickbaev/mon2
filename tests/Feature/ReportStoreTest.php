<?php

use App\Enums\ReportStatus;
use App\Reports\Demo\DemoList;
use App\Services\ReportStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

test('list save validates all rows atomically and first save changes status', function () {
    [$report, $user] = storedDemoReport();
    $store = app(ReportStore::class);
    expect(fn () => $store->save($report, ['rows' => [['title' => 'Событие', 'date' => '2026-11-12', 'participants' => -1]]], $user))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('demo_event_rows', 0);
    expect($report->fresh()->status)->toBe(ReportStatus::New);
    $store->save($report, ['rows' => [['title' => 'Событие', 'date' => '2026-11-12', 'participants' => 10, 'forged' => 1]]], $user);
    $this->assertDatabaseHas('demo_event_rows', ['report_id' => $report->id, 'title' => 'Событие', 'participants' => 10]);
    expect($report->fresh()->status)->toBe(ReportStatus::InProgress);
    $store->save($report, ['rows' => []], $user);
    $this->assertDatabaseCount('demo_event_rows', 0);
});

test('empty list obeys type setting and invalid replacement preserves previous data', function () {
    [$report, $user] = storedDemoReport();
    $store = app(ReportStore::class);
    $store->save($report, ['rows' => [['title' => 'Первое', 'date' => '2026-11-12', 'participants' => 2]]], $user);
    $report->reportType->update(['list_allows_empty' => false]);
    expect(fn () => $store->save($report, ['rows' => []], $user))->toThrow(ValidationException::class);
    $this->assertDatabaseHas('demo_event_rows', ['title' => 'Первое']);
});

test('save rechecks blocked user closed campaign and foreign branch', function (string $change) {
    [$report, $user] = storedDemoReport();
    match ($change) {
        'blocked' => $user->forceFill(['blocked_at' => now()])->save(),
        'closed' => $report->campaign->update(['closed_at' => now()]),
        'foreign' => $user->forceFill(['node_id' => null])->save(),
    };
    expect(fn () => app(ReportStore::class)->save($report, ['rows' => []], $user))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('demo_event_rows', 0);
    expect($report->fresh()->status)->toBe(ReportStatus::New);
})->with(['blocked', 'closed', 'foreign']);

test('saving rework returns it to in progress and list replaces previous rows', function () {
    [$report, $user] = storedDemoReport();
    $report->update(['status' => ReportStatus::Rework]);
    app(ReportStore::class)->save($report, ['rows' => [['title' => 'Новое', 'date' => '2026-11-12', 'participants' => 1]]], $user);
    app(ReportStore::class)->save($report, ['rows' => [['title' => 'Замена', 'date' => '2026-11-13', 'participants' => 3]]], $user);
    $this->assertDatabaseCount('demo_event_rows', 1);
    $this->assertDatabaseHas('demo_event_rows', ['title' => 'Замена']);
    expect($report->fresh()->status)->toBe(ReportStatus::InProgress);
});

test('numbers beyond database range are validation errors and leave report unchanged', function () {
    [$report, $user] = storedDemoReport();
    expect(fn () => app(ReportStore::class)->save($report, ['rows' => [['title' => 'Событие', 'date' => '2026-11-12', 'participants' => 4294967296]]], $user))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('demo_event_rows', 0);
});

class CrossFieldDemoList extends DemoList
{
    public function validate(array $data, array $context = []): array
    {
        $validated = parent::validate($data, $context);
        if (($validated['title'] ?? '') === 'Запрещено') {
            throw ValidationException::withMessages(['title' => 'Название не допускается правилами отчёта.']);
        }

        return $validated;
    }
}

test('store calls report specific validation and leaves previous data intact on failure', function () {
    config(['reports.definitions' => [CrossFieldDemoList::class]]);
    [$report, $user] = storedDemoReport();
    expect(fn () => app(ReportStore::class)->save($report, ['rows' => [['title' => 'Запрещено', 'date' => '2026-11-12', 'participants' => 1]]], $user))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('demo_event_rows', 0);
    expect($report->fresh()->status)->toBe(ReportStatus::New);
});
