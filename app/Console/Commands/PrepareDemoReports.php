<?php

namespace App\Console\Commands;

use App\Enums\NodeType;
use App\Enums\ReportStatus;
use App\Filament\Pages\ReportForm;
use App\Models\Campaign;
use App\Models\Dictionary;
use App\Models\Node;
use App\Models\Report;
use App\Models\ReportType;
use App\Models\User;
use App\Services\NodeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrepareDemoReports extends Command
{
    protected $signature = 'reports:demo';

    protected $description = 'Подготовить отдельную демонстрационную организацию и три отчёта без изменения заполненных данных';

    public function handle(NodeService $nodes): int
    {
        $admin = User::query()->where('is_admin', true)->whereNull('blocked_at')->first();
        if ($admin === null) {
            $this->error('Сначала создайте администратора системы.');

            return self::FAILURE;
        }
        if ($this->call('reports:sync') !== self::SUCCESS) {
            return self::FAILURE;
        }
        $reports = DB::transaction(function () use ($admin, $nodes): array {
            $dictionary = Dictionary::firstOrCreate(['key' => 'organization_types'], ['name' => 'Типы образовательных организаций']);
            $dictionary->items()->firstOrCreate(['label' => 'Школа'], ['sort' => 0, 'active' => true]);
            $root = Node::query()->whereNull('parent_id')->where('name', 'Демонстрационный регион')->first()
                ?? $nodes->create(null, NodeType::Authority, 'Демонстрационный регион');
            $organization = Node::query()->where('parent_id', $root->id)->where('name', 'Демонстрационная школа')->first()
                ?? $nodes->create($root, NodeType::Organization, 'Демонстрационная школа');
            $simple = ReportType::query()->where('key', 'demo_simple')->sole();
            $simple->update(['is_base' => true]);
            $reports = [Report::firstOrCreate(['report_type_id' => $simple->id, 'campaign_id' => null, 'node_id' => $organization->id])];
            foreach (['demo_list', 'demo_complex'] as $key) {
                $type = ReportType::query()->where('key', $key)->sole();
                $campaign = Campaign::firstOrCreate([
                    'report_type_id' => $type->id, 'creator_id' => $admin->id, 'title' => 'Демонстрация: '.$type->title,
                ], ['creator_node_id' => null, 'starts_at' => today(), 'ends_at' => today()->addYear(), 'start_status' => ReportStatus::New]);
                $reports[] = Report::firstOrCreate(['report_type_id' => $type->id, 'campaign_id' => $campaign->id, 'node_id' => $organization->id]);
            }

            return $reports;
        });
        $this->info('Демонстрационные отчёты подготовлены. Войдите под администратором.');
        foreach ($reports as $report) {
            $url = $report->reportType->key === 'demo_complex'
                ? route('demo-complex.show', ['report' => $report])
                : ReportForm::getUrl(['report' => $report->id], panel: 'app');
            $this->line($report->reportType->title.': '.$url);
        }

        return self::SUCCESS;
    }
}
