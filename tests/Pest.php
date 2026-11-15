<?php

use App\Enums\NodeType;
use App\Models\Campaign;
use App\Models\Dictionary;
use App\Models\DictionaryItem;
use App\Models\Report;
use App\Models\ReportType;
use App\Models\User;
use App\Services\NodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

function actingAsUser(User $u)
{
    return test()->actingAs($u);
}

function storedDemoReport(string $key = 'demo_list'): array
{
    test()->artisan('reports:sync')->assertSuccessful();
    $type = ReportType::where('key', $key)->sole();
    $campaign = Campaign::factory()->create(['report_type_id' => $type->id]);
    $report = Report::factory()->create(['report_type_id' => $type->id, 'campaign_id' => $campaign->id]);
    $user = User::factory()->create(['node_id' => $report->node_id, 'pd_consent_at' => now()]);

    return [$report, $user];
}

function simpleDemoPayload(): array
{
    $dictionary = Dictionary::firstOrCreate(['key' => 'organization_types'], ['name' => 'Типы организаций']);
    $item = DictionaryItem::create(['dictionary_id' => $dictionary->id, 'label' => 'Школа', 'sort' => 0]);

    return ['count' => 5, 'description' => 'Проверка', 'organization_type' => $item->id, 'date' => '2026-11-12'];
}

function campaignContext(): array
{
    $service = app(NodeService::class);
    $root = $service->create(null, NodeType::Authority, 'Регион');
    $district = $service->create($root, NodeType::Authority, 'Район');
    $school = $service->create($district, NodeType::Organization, 'Школа');
    $branch = $service->create($school, NodeType::Branch, 'Филиал');
    $foreign = $service->create($root, NodeType::Organization, 'Другая школа');
    $actor = User::factory()->create(['node_id' => $district->id, 'can_start_campaigns' => true]);

    return [$actor, $root, $district, $school, $branch, $foreign];
}

function campaignData(array $overrides = []): array
{
    return array_replace(['report_type_id' => ReportType::factory()->create()->id, 'starts_at' => '2026-11-15', 'ends_at' => '2026-11-20', 'closes_at' => '2026-11-25', 'start_status' => 'new'], $overrides);
}
