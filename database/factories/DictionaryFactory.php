<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class DictionaryFactory extends Factory
{
    public function definition(): array
    {
        return ['key' => fake()->unique()->slug(), 'name' => fake()->word()];
    }
}
