<?php

namespace Database\Factories;

use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
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
            'financial_account_id' => FinancialAccount::factory(),
            'type' => fake()->randomElement(Transaction::TYPES),
            'description' => fake()->optional()->sentence(3),
            'amount' => fake()->randomFloat(2, 0.01, 100000),
            'transaction_date' => fake()->date(),
        ];
    }
}
