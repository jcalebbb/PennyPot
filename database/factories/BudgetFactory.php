<?php

namespace Database\Factories;

use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Budget>
 */
class BudgetFactory extends Factory
{
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('-6 months', 'now')->modify('first day of this month');

        return [
            'user_id' => User::factory(),
            'category_id' => Category::factory(['type' => 'expense']),
            'amount' => fake()->randomFloat(2, 1, 100000),
            'start_date' => $startDate,
            'end_date' => (clone $startDate)->modify('last day of this month'),
            'currency' => 'PHP',
        ];
    }
}
