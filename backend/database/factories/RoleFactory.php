<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => null,
            'name' => fake()->jobTitle(),
            'key' => 'role_' . fake()->unique()->word(),
        ];
    }
}
