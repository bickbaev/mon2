<?php

use App\Enums\NodeType;
use App\Enums\ReportStatus;
use App\Filament\Pages\CampaignReportsList;
use App\Filament\Pages\CampaignsOverview;
use App\Filament\Pages\CampaignStats;
use App\Models\Campaign;
use App\Models\Report;
use App\Models\User;
use App\Services\NodeService;
use App\Services\StatisticsAccess;
use App\Services\StatusStats;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

function statisticsContext(): array
{
    $nodes = app(NodeService::class);
    $root = $nodes->create(null, NodeType::Authority, 'Регион');
    $branch = $nodes->create($root, NodeType::Authority, 'Район');
    $school = $nodes->create($branch, NodeType::Organization, 'Школа');
    $empty = $nodes->create($branch, NodeType::Organization, 'Без отчётов');
    $sibling = $nodes->create($root, NodeType::Organization, 'Чужая школа');
    $actor = User::factory()->create(['node_id' => $branch->id, 'pd_consent_at' => now()]);
    $campaign = Campaign::factory()->create(['creator_node_id' => $root->id]);

    return [$actor, $campaign, $root, $branch, $school, $empty, $sibling];
}

test('statistics count the current subtree including its own report and retain empty children', function () {
    [$actor, $campaign, , $branch, $school, $empty, $sibling] = statisticsContext();
    Report::factory()->create(['campaign_id' => $campaign->id, 'node_id' => $branch->id, 'status' => ReportStatus::Accepted]);
    $report = Report::factory()->create(['campaign_id' => $campaign->id, 'node_id' => $school->id]);
    $descendant = app(NodeService::class)->create($school, NodeType::Branch, 'Филиал');
    Report::factory()->create(['campaign_id' => $campaign->id, 'node_id' => $descendant->id, 'status' => ReportStatus::Rework]);
    Report::factory()->create(['campaign_id' => $campaign->id, 'node_id' => $sibling->id]);
    $this->actingAs($actor);

    $stats = app(StatusStats::class)->counts($campaign, $branch);
    expect($stats)->toBe(['new' => 1, 'in_progress' => 0, 'rework' => 1, 'blocked' => 0, 'accepted' => 1, 'total' => 3]);
    $rows = collect(app(StatusStats::class)->forNode($campaign, $branch)['rows'])->keyBy(fn (array $row): int => $row['node']->id);
    expect($rows[$school->id]['counts']['total'])->toBe(2);
    expect($rows[$empty->id]['counts']['total'])->toBe(0);
    Livewire::test(CampaignStats::class, ['campaign' => $campaign->id, 'node' => $branch->id])->assertCanSeeTableRecords([$school, $empty]);
    Livewire::test(CampaignReportsList::class, ['campaign' => $campaign->id, 'node' => $branch->id, 'status' => 'new'])->assertCanSeeTableRecords([$report])->assertCountTableRecords(1);
});

test('overview excludes foreign and closed campaigns and includes own empty campaigns', function () {
    [$actor, $campaign, , $branch, $school, , $sibling] = statisticsContext();
    Report::factory()->create(['campaign_id' => $campaign->id, 'node_id' => $school->id]);
    $own = Campaign::factory()->create(['creator_node_id' => $branch->id]);
    $foreign = Campaign::factory()->create(['creator_node_id' => $sibling->id]);
    $closed = Campaign::factory()->create(['creator_node_id' => $branch->id, 'closed_at' => now()]);
    $this->actingAs($actor);

    Livewire::test(CampaignsOverview::class)->assertCanSeeTableRecords([$campaign, $own])->assertCanNotSeeTableRecords([$foreign, $closed]);
});

test('statistics reject leaf users and foreign nodes', function () {
    [$actor, $campaign, , $branch, $school, , $sibling] = statisticsContext();
    Report::factory()->create(['campaign_id' => $campaign->id, 'node_id' => $school->id]);
    $this->actingAs($actor)->get(CampaignStats::getUrl(['campaign' => $campaign->id, 'node' => $sibling->id]))->assertForbidden();
    $actor->forceFill(['node_id' => $school->id])->save();
    $this->get(CampaignsOverview::getUrl())->assertForbidden();
});

test('statistics reauthorize closed campaigns and moved viewer branches on hydration', function () {
    [$actor, $campaign, , $branch, $school, , $sibling] = statisticsContext();
    Report::factory()->create(['campaign_id' => $campaign->id, 'node_id' => $school->id]);
    $this->actingAs($actor);
    $stats = Livewire::test(CampaignStats::class, ['campaign' => $campaign->id, 'node' => $branch->id]);
    $list = Livewire::test(CampaignReportsList::class, ['campaign' => $campaign->id, 'node' => $branch->id]);
    $campaign->update(['closed_at' => now()]);
    $stats->call('$refresh')->assertForbidden();
    $list->call('$refresh')->assertForbidden();
    $campaign->update(['closed_at' => null]);
    $page = Livewire::test(CampaignStats::class, ['campaign' => $campaign->id, 'node' => $branch->id]);
    $actor->forceFill(['node_id' => $sibling->id])->save();
    $page->call('$refresh')->assertForbidden();
});

test('administrator statistics start at the root and require a populated hierarchy', function () {
    [$actor, $campaign, $root, $branch, $school] = statisticsContext();
    $admin = User::factory()->create(['is_admin' => true, 'node_id' => $school->id, 'pd_consent_at' => now()]);
    $this->actingAs($admin);

    Livewire::test(CampaignStats::class, ['campaign' => $campaign->id])->assertSet('nodeId', $root->id)->assertCanSeeTableRecords([$branch]);
});

test('statistics reject inaccessible campaigns expired collections and invalid status links', function () {
    [$actor, $campaign, , $branch, $school, , $sibling] = statisticsContext();
    Report::factory()->create(['campaign_id' => $campaign->id, 'node_id' => $school->id]);
    $foreign = Campaign::factory()->create(['creator_node_id' => $sibling->id]);
    $this->actingAs($actor);

    $this->get(CampaignStats::getUrl(['campaign' => $foreign->id, 'node' => $branch->id]))->assertForbidden();
    $this->get(CampaignReportsList::getUrl(['campaign' => $campaign->id, 'node' => $branch->id, 'status' => 'invalid']))->assertNotFound();
    $this->get(CampaignReportsList::getUrl(['campaign' => $campaign->id, 'node' => $branch->id, 'status' => ['new']]))->assertNotFound();
    $campaign->update(['closes_at' => today()]);
    $this->get(CampaignReportsList::getUrl(['campaign' => $campaign->id, 'node' => $branch->id]))->assertForbidden();
});

test('statistics overview rechecks blocked accounts on hydration', function () {
    [$actor] = statisticsContext();
    $this->actingAs($actor);
    $page = Livewire::test(CampaignsOverview::class);
    $actor->forceFill(['blocked_at' => now()])->save();

    $page->call('$refresh')->assertForbidden();
});

test('statistics show report type and support campaigns without an optional title', function () {
    [$actor, $campaign, , $branch, $school] = statisticsContext();
    $campaign->update(['title' => null]);
    Report::factory()->create(['campaign_id' => $campaign->id, 'node_id' => $school->id]);
    $this->actingAs($actor);

    $this->get(CampaignStats::getUrl(['campaign' => $campaign->id, 'node' => $branch->id]))->assertOk()->assertSee($campaign->reportType->title);
    Livewire::test(CampaignStats::class, ['campaign' => $campaign->id, 'node' => $branch->id])->assertSee($campaign->reportType->title);
    Livewire::test(CampaignsOverview::class)->assertSee($campaign->reportType->title);
});

test('statistics refresh a supplied viewer and do not borrow the authenticated administrators access', function () {
    [$actor, $campaign, , , $school, , $sibling] = statisticsContext();
    Report::factory()->create(['campaign_id' => $campaign->id, 'node_id' => $school->id]);
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);
    $unrelated = Campaign::factory()->create(['creator_node_id' => null]);
    $access = app(StatisticsAccess::class);

    expect($access->campaigns($actor)->pluck('id')->all())->not->toContain($unrelated->id);
    User::query()->whereKey($actor->id)->update(['node_id' => $sibling->id]);
    expect($access->root($actor)->id)->toBe($sibling->id);
});

test('statistics aggregate child branches without a query for each child', function () {
    [$actor, $campaign, , $branch, $school] = statisticsContext();
    Report::factory()->create(['campaign_id' => $campaign->id, 'node_id' => $school->id]);
    $this->actingAs($actor);
    $stats = app(StatusStats::class);
    DB::enableQueryLog();
    $stats->forNode($campaign, $branch);
    $initialQueries = count(DB::getQueryLog());
    DB::disableQueryLog();
    foreach (range(1, 5) as $index) {
        $child = app(NodeService::class)->create($branch, NodeType::Organization, 'Школа '.$index);
        Report::factory()->create(['campaign_id' => $campaign->id, 'node_id' => $child->id]);
    }
    DB::flushQueryLog();
    DB::enableQueryLog();

    $result = $stats->forNode($campaign, $branch);
    $expandedQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($result['total']['total'])->toBe(6);
    expect($expandedQueries)->toBe($initialQueries);
});
