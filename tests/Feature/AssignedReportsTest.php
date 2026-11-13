<?php

use App\Enums\ReportStatus;
use App\Filament\Pages\BaseReports;
use App\Filament\Pages\ReportForm;
use App\Filament\Widgets\CampaignReports;
use App\Models\Campaign;
use App\Models\Report;
use App\Models\ReportType;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

test('assigned reports page renders two separate tables', function () {
    $report = Report::factory()->create();
    $actor = User::factory()->create(['node_id' => $report->node_id, 'pd_consent_at' => now()]);
    $this->actingAs($actor);

    Livewire::test(BaseReports::class)
        ->assertSee('Назначенные отчёты')
        ->assertSee('Базовые отчёты')
        ->assertSeeLivewire(CampaignReports::class)
        ->assertCanNotSeeTableRecords([$report]);
});

test('campaign table shows own open reports and excludes base foreign and closed reports', function () {
    $open = Report::factory()->create();
    $actor = User::factory()->create(['node_id' => $open->node_id, 'pd_consent_at' => now()]);
    $foreign = Report::factory()->create();
    $base = Report::factory()->create(['report_type_id' => ReportType::factory()->base(), 'campaign_id' => null, 'node_id' => $open->node_id]);
    $hidden = collect([
        ['closed_at' => now()],
        ['closes_at' => today()],
        ['closes_at' => today()->subDay()],
    ])->map(fn (array $attributes) => Report::factory()->create([
        'campaign_id' => Campaign::factory()->create($attributes)->id,
        'node_id' => $open->node_id,
    ]));
    $future = Report::factory()->create([
        'campaign_id' => Campaign::factory()->create(['closes_at' => today()->addDay(), 'ends_at' => today()->subDay()])->id,
        'node_id' => $open->node_id,
        'status' => ReportStatus::InProgress,
    ]);
    $this->actingAs($actor);

    Livewire::test(CampaignReports::class)
        ->assertCanSeeTableRecords([$open, $future])
        ->assertCanNotSeeTableRecords([$foreign, $base, ...$hidden])
        ->assertTableActionHasUrl('open', ReportForm::getUrl(['report' => $open->id]), $open)
        ->assertTableActionHidden('accept', $open)
        ->filterTable('status', ReportStatus::InProgress->value)
        ->assertCanSeeTableRecords([$future])->assertCanNotSeeTableRecords([$open]);
});

test('campaign table rechecks blocked users on refresh', function () {
    $report = Report::factory()->create();
    $actor = User::factory()->create(['node_id' => $report->node_id, 'pd_consent_at' => now()]);
    $this->actingAs($actor);
    $table = Livewire::test(CampaignReports::class)->assertCanSeeTableRecords([$report]);
    User::query()->whereKey($actor)->update(['blocked_at' => now()]);

    $table->call('$refresh')->assertForbidden();
});

test('campaign report row displays collection dates status and open link', function () {
    $campaign = Campaign::factory()->create(['starts_at' => '2026-11-01', 'ends_at' => '2026-12-15']);
    $report = Report::factory()->create(['campaign_id' => $campaign->id, 'status' => ReportStatus::InProgress]);
    $this->actingAs(User::factory()->create(['node_id' => $report->node_id, 'pd_consent_at' => now()]));

    Livewire::test(CampaignReports::class)
        ->assertSee('Начало сбора')->assertSee('01.11.2026')
        ->assertSee('Сдать до')->assertSee('15.12.2026')
        ->assertSee('В работе')->assertSee('Открыть')
        ->assertDontSee('Организация')
        ->assertDontSee($campaign->title)
        ->assertTableActionHasUrl('open', ReportForm::getUrl(['report' => $report->id]), $report);
});
