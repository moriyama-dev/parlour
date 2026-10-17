<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\WorkRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkRequest>
 */
class WorkRequestFactory extends Factory
{
    protected $model = WorkRequest::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'category' => 'change',
            'priority' => 'normal',
            'status' => 'queued',
            'position' => 1,
        ];
    }
}
