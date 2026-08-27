<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\RecurringTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringTransaction>
 */
class RecurringTransactionFactory extends Factory
{
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('-3 months', '+1 month');

        return [
            'user_id' => User::factory(),
            'financial_account_id' => FinancialAccount::factory(),
            'category_id' => Category::factory(['type' => 'expense']),
            'type' => 'expense',
            'description' => fake()->optional()->sentence(3),
            'amount' => fake()->randomFloat(2, 0.01, 100000),
            'frequency' => fake()->randomElement(RecurringTransaction::FREQUENCIES),
            'start_date' => $startDate,
            'next_occurrence' => $startDate,
            'end_date' => null,
            'is_active' => true,
        ];
    }

    public function active(): static
    {
        return $this->state(['is_active' => true]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
