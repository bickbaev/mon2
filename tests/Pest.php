<?php

use App\Models\Campaign;
use App\Models\Dictionary;
use App\Models\DictionaryItem;
use App\Models\Report;
use App\Models\ReportType;
use App\Models\User;
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
