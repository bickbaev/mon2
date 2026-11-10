<?php

namespace Database\Factories;

use App\Models\Dictionary;
use Illuminate\Database\Eloquent\Factories\Factory;

class DictionaryItemFactory extends Factory
{
    public function definition(): array
    {
        return ['dictionary_id' => Dictionary::factory(), 'label' => fake()->word(), 'active' => true, 'sort' => 0];
    }
}
