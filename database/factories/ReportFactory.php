<?php

namespace Database\Factories;

use App\Enums\NodeType;
use App\Enums\ReportStatus;
use App\Models\Campaign;
use App\Services\NodeService;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'report_type_id' => fn (array $attributes): int => Campaign::query()->findOrFail($attributes['campaign_id'])->report_type_id,
            'node_id' => function (): int {
                $service = app(NodeService::class);
                $root = $service->create(null, NodeType::Authority, 'Регион');

                return $service->create($root, NodeType::Organization, 'Школа')->id;
            },
            'status' => ReportStatus::New,
        ];
    }
}
