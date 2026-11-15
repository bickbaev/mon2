<?php

namespace Database\Factories;

use App\Enums\Periodicity;
use App\Enums\ReportStatus;
use App\Models\ReportType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CampaignTemplateFactory extends Factory
{
    public function definition(): array
    {
        return ['creator_id' => User::factory()->state(['is_admin' => true]), 'report_type_id' => ReportType::factory(), 'title' => fake()->sentence(), 'periodicity' => Periodicity::Weekly, 'start_weekday' => 1, 'duration_days' => 5, 'close_after_days' => null, 'start_status' => ReportStatus::New, 'active' => true];
    }
}
