<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'created_by' => User::factory()->developer(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'type' => 'staging_review',
            'status' => 'pending_review',
            'staging_url' => 'https://staging.example.com/preview',
            'due_date' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'approved']);
    }
}
