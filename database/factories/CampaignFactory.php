<?php

namespace Database\Factories;

use App\Enums\ReportStatus;
use App\Models\ReportType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CampaignFactory extends Factory
{
    public function definition(): array
    {
        return ['report_type_id' => ReportType::factory(), 'creator_id' => User::factory()->state(['is_admin' => true]), 'creator_node_id' => null, 'title' => fake()->sentence(), 'starts_at' => today(), 'ends_at' => today()->addMonth(), 'closes_at' => null, 'start_status' => ReportStatus::New];
    }
}
