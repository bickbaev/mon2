<?php

use App\Enums\NodeType;
use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\Report;
use App\Models\User;
use App\Services\CampaignService;
use App\Services\CampaignTemplateService;
use App\Services\NodeSelection;
use App\Services\NodeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

function weeklyTemplate(array $overrides = []): array
{
    return array_replace(campaignData(), ['title' => 'Еженедельный сбор', 'periodicity' => 'weekly', 'start_weekday' => 1, 'duration_days' => 5, 'close_after_days' => 2, 'active' => true], $overrides);
}

test('spawning twice on due day creates one collection with calculated dates and frozen participants', function () {
    $this->travelTo(now()->setDate(2026, 11, 16)->startOfDay());
    [$actor, , $district, $school, $branch] = campaignContext();
    $template = app(CampaignTemplateService::class)->create($actor, weeklyTemplate(), new NodeSelection([$school->id]));
    app(NodeService::class)->create($district, NodeType::Organization, 'Поздняя школа');
    $this->artisan('campaigns:spawn')->assertSuccessful();
    $this->artisan('campaigns:spawn')->assertSuccessful();
    $campaign = Campaign::sole();
    expect($campaign->starts_at->toDateString())->toBe('2026-11-16');
    expect($campaign->ends_at->toDateString())->toBe('2026-11-21');
    expect($campaign->closes_at->toDateString())->toBe('2026-11-23');
    expect($campaign->reports()->pluck('node_id')->all())->toBe([$school->id]);
    expect($template->fresh()->last_spawned_for->toDateString())->toBe('2026-11-16');
    app(CampaignTemplateService::class)->addNode($actor, $template, $branch);
    $this->travelTo(now()->addWeek());
    $this->artisan('campaigns:spawn')->assertSuccessful();
    expect(Campaign::count())->toBe(2);
    expect(Campaign::latest('id')->first()->reports()->pluck('node_id')->all())->toBe([$school->id, $branch->id]);
    expect($campaign->reports()->count())->toBe(1);
});

test('missed scheduled starts are skipped and manual closure stays nullable', function () {
    $this->travelTo(now()->setDate(2026, 11, 15)->startOfDay());
    [$actor, , , $school] = campaignContext();
    $template = app(CampaignTemplateService::class)->create($actor, weeklyTemplate(['close_after_days' => null]), new NodeSelection([$school->id]));
    $this->travelTo(now()->setDate(2026, 11, 18));
    $this->artisan('campaigns:spawn')->assertSuccessful();
    expect(Campaign::count())->toBe(0);
    $this->travelTo(now()->setDate(2026, 11, 23));
    $campaign = app(CampaignService::class)->startFromTemplate($template);
    expect($campaign->closes_at)->toBeNull();
    expect($campaign->starts_at->toDateString())->toBe('2026-11-23');
});

test('inactive templates or creators without current permission are skipped', function (array $changes, bool $active) {
    $this->travelTo(now()->setDate(2026, 11, 16));
    [$actor, , , $school] = campaignContext();
    $template = app(CampaignTemplateService::class)->create($actor, weeklyTemplate(['active' => $active]), new NodeSelection([$school->id]));
    User::whereKey($actor)->update($changes);
    $this->artisan('campaigns:spawn')->assertSuccessful();
    expect(Campaign::count())->toBe(0);
    expect($template->fresh()->last_spawned_for)->toBeNull();
})->with([
    [['can_start_campaigns' => false], true],
    [['blocked_at' => '2026-11-16 09:00:00'], true],
    [[], false],
]);

test('unavailable participants are skipped and empty templates create nothing', function () {
    $this->travelTo(now()->setDate(2026, 11, 16));
    [$actor, $root, , $school, $branch] = campaignContext();
    $template = app(CampaignTemplateService::class)->create($actor, weeklyTemplate(), new NodeSelection([$school->id, $branch->id]));
    app(NodeService::class)->move($school, $root);
    $this->artisan('campaigns:spawn')->assertSuccessful();
    expect(Campaign::count())->toBe(0);
    expect($template->fresh()->last_spawned_for)->toBeNull();
});

test('deleted participants disappear from templates without affecting future jobs', function () {
    $this->travelTo(now()->setDate(2026, 11, 16));
    [$actor, , , , $branch] = campaignContext();
    $template = app(CampaignTemplateService::class)->create($actor, weeklyTemplate(), new NodeSelection([$branch->id]));
    $branch->delete();
    $this->artisan('campaigns:spawn')->assertSuccessful();
    expect(Campaign::count())->toBe(0);
    expect($template->nodes()->count())->toBe(0);
});

test('template settings validate calendar and duration before writing', function (array $changes) {
    [$actor, , , $school] = campaignContext();
    expect(fn () => app(CampaignTemplateService::class)->create($actor, weeklyTemplate($changes), new NodeSelection([$school->id])))->toThrow(ValidationException::class);
    expect(CampaignTemplate::count())->toBe(0);
})->with([
    [['periodicity' => 'daily']],
    [['start_weekday' => 0]],
    [['duration_days' => -1]],
    [['close_after_days' => -1]],
    [['periodicity' => 'monthly', 'start_day' => 32]],
    [['periodicity' => 'yearly', 'start_day' => 1, 'start_month' => 13]],
]);

test('template management rechecks authority and preserves fixed membership when settings change', function () {
    [$actor, $root, , $school, $branch, $foreign] = campaignContext();
    $service = app(CampaignTemplateService::class);
    $template = $service->create($actor, weeklyTemplate(), new NodeSelection([$school->id]));
    $stranger = User::factory()->create(['node_id' => $foreign->id]);
    expect(fn () => $service->update($stranger, $template, ['active' => false]))->toThrow(AuthorizationException::class);
    $superior = User::factory()->create(['node_id' => $root->id]);
    $service->update($superior, $template, ['active' => false, 'creator_id' => $stranger->id]);
    expect($template->fresh()->active)->toBeFalse();
    expect($template->fresh()->creator_id)->toBe($actor->id);
    $service->addNode($superior, $template, $branch);
    $service->addNode($actor, $template, $branch);
    expect($template->nodes()->count())->toBe(2);
    expect(fn () => $service->addNode($superior, $template, $foreign))->toThrow(ValidationException::class);
});

test('close due only closes collections with a reached closure date', function () {
    $this->travelTo(now()->setDate(2026, 11, 16));
    $past = Campaign::factory()->create(['closes_at' => '2026-11-15']);
    $today = Campaign::factory()->create(['closes_at' => '2026-11-16']);
    $future = Campaign::factory()->create(['closes_at' => '2026-11-17']);
    $manual = Campaign::factory()->create(['closes_at' => null]);
    $closed = Campaign::factory()->create(['closes_at' => '2026-11-15', 'closed_at' => now()->subDays(2)]);
    $closedAt = $closed->closed_at->toDateTimeString();
    $this->artisan('campaigns:close-due')->assertSuccessful();
    expect($past->fresh()->closed_at)->not->toBeNull();
    expect($today->fresh()->closed_at)->not->toBeNull();
    expect($future->fresh()->closed_at)->toBeNull();
    expect($manual->fresh()->closed_at)->toBeNull();
    expect($closed->fresh()->closed_at->toDateTimeString())->toBe($closedAt);
});

test('failed assignment rolls back the spawn marker and can be retried the same day', function () {
    $this->travelTo(now()->setDate(2026, 11, 16));
    [$actor, , $district] = campaignContext();
    $template = app(CampaignTemplateService::class)->create($actor, weeklyTemplate(), new NodeSelection(allInBranch: [$district->id]));
    $created = 0;
    Report::creating(function () use (&$created): void {
        if (++$created === 2) {
            throw new RuntimeException('Назначение прервано');
        }
    });
    expect(fn () => app(CampaignService::class)->startFromTemplate($template))->toThrow(RuntimeException::class);
    expect(Campaign::count())->toBe(0);
    expect(Report::count())->toBe(0);
    expect($template->fresh()->last_spawned_for)->toBeNull();
    app(CampaignService::class)->startFromTemplate($template);
    expect(Campaign::count())->toBe(1);
    expect(Report::count())->toBe(2);
});

test('resetting last spawn marker does not duplicate an existing collection', function () {
    $this->travelTo(now()->setDate(2026, 11, 16));
    [$actor, , , $school] = campaignContext();
    $template = app(CampaignTemplateService::class)->create($actor, weeklyTemplate(), new NodeSelection([$school->id]));
    app(CampaignService::class)->startFromTemplate($template);
    $template->update(['last_spawned_for' => null]);
    expect(app(CampaignService::class)->startFromTemplate($template))->toBeNull();
    expect(Campaign::count())->toBe(1);
});
