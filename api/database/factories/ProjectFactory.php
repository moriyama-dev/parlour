<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'status' => 'active',
            'staging_url' => 'https://staging.example.com',
            'production_url' => null,
        ];
    }
}
