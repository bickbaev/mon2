<?php

namespace Database\Factories;

use App\Enums\ReportKind;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReportTypeFactory extends Factory
{
    public function definition(): array
    {
        return ['key' => fake()->unique()->slug(), 'kind' => ReportKind::Simple, 'title' => fake()->sentence(), 'is_base' => false, 'is_required' => false, 'auto_create' => false, 'excel_enabled' => true, 'list_allows_empty' => true, 'active' => true];
    }

    public function base(): static
    {
        return $this->state(fn (array $attributes): array => ['is_base' => true]);
    }
}
