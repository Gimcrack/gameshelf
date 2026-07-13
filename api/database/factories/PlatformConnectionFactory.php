<?php

namespace Database\Factories;

use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformConnection>
 */
class PlatformConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'platform' => 'steam',
            'external_account_id' => (string) fake()->numberBetween(76561197960000000, 76561197999999999),
            'status' => 'pending',
        ];
    }
}
