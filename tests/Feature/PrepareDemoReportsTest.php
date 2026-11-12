<?php

use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\User;

test('demo command prepares three reports without duplicating them on repeat', function () {
    User::factory()->create(['is_admin' => true, 'pd_consent_at' => now()]);
    $this->artisan('reports:demo')->assertSuccessful();
    $this->assertDatabaseCount('reports', 3);
    $this->assertDatabaseCount('campaigns', 2);
    $this->assertDatabaseCount('nodes', 2);
    $this->assertDatabaseHas('dictionaries', ['key' => 'organization_types']);
    $report = Report::whereNull('campaign_id')->sole();
    $report->update(['status' => ReportStatus::InProgress]);
    $this->artisan('reports:demo')->assertSuccessful();
    $this->assertDatabaseCount('reports', 3);
    $this->assertDatabaseCount('campaigns', 2);
    expect($report->fresh()->status)->toBe(ReportStatus::InProgress);
});
