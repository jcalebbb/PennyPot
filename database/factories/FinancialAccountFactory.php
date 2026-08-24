<?php

namespace Database\Factories;

use App\Models\FinancialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialAccount>
 */
class FinancialAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'institution' => fake()->optional()->company(),
            'account_type' => fake()->randomElement(FinancialAccount::ACCOUNT_TYPES),
            'currency' => 'PHP',
            'starting_balance' => fake()->randomFloat(2, 0, 100000),
        ];
    }
}
