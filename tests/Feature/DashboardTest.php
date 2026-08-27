<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_the_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_the_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeVolt('pages.dashboard');
    }

    public function test_account_balance_includes_only_that_accounts_income_and_expenses(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create(['name' => 'Main account', 'starting_balance' => '1000.00']);
        $otherAccount = FinancialAccount::factory()->for($user)->create(['name' => 'Other account', 'starting_balance' => '500.00']);
        Transaction::factory()->for($user)->for($account, 'financialAccount')->create(['type' => 'income', 'amount' => '250.00']);
        Transaction::factory()->for($user)->for($account, 'financialAccount')->create(['type' => 'expense', 'amount' => '75.00']);
        Transaction::factory()->for($user)->for($otherAccount, 'financialAccount')->create(['type' => 'income', 'amount' => '900.00']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertSee('1,175.00')
            ->assertSee('1,400.00');
    }

    public function test_dashboard_shows_income_expenses_and_net_cash_flow(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();
        Transaction::factory()->for($user)->for($account, 'financialAccount')->create(['type' => 'income', 'amount' => '1200.00']);
        Transaction::factory()->for($user)->for($account, 'financialAccount')->create(['type' => 'expense', 'amount' => '300.00']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertSee('1,200.00')
            ->assertSee('300.00')
            ->assertSee('900.00');
    }

    public function test_dashboard_shows_only_the_users_data(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create(['name' => 'My account']);
        $otherAccount = FinancialAccount::factory()->for($otherUser)->create(['name' => 'Private account']);
        Transaction::factory()->for($user)->for($account, 'financialAccount')->create(['description' => 'My transaction']);
        Transaction::factory()->for($otherUser)->for($otherAccount, 'financialAccount')->create(['description' => 'Private transaction']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertSee('My account')
            ->assertSee('My transaction')
            ->assertDontSee('Private account')
            ->assertDontSee('Private transaction');
    }

    public function test_dashboard_shows_the_five_most_recent_transactions_in_order(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();

        foreach (range(1, 6) as $number) {
            Transaction::factory()->for($user)->for($account, 'financialAccount')->create([
                'description' => "Transaction {$number}",
                'transaction_date' => "2026-08-0{$number}",
            ]);
        }

        $response = $this->actingAs($user)->get(route('dashboard'));
        $response->assertSee('Transaction 6')->assertDontSee('Transaction 1');
        $content = $response->getContent();
        $this->assertLessThan(strpos($content, 'Transaction 5'), strpos($content, 'Transaction 6'));
        $this->assertLessThan(strpos($content, 'Transaction 4'), strpos($content, 'Transaction 5'));
    }

    public function test_dashboard_handles_no_accounts_and_no_transactions(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertSee('No financial accounts yet.')
            ->assertSee('No transactions yet.')
            ->assertSee('No expense transactions yet.');
    }

    public function test_dashboard_includes_uncategorized_expenses(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();
        Transaction::factory()->for($user)->for($account, 'financialAccount')->create(['type' => 'expense', 'amount' => '80.00', 'category_id' => null]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertSee('Uncategorized')
            ->assertSee('80.00');
    }

    public function test_dashboard_groups_totals_by_currency(): void
    {
        $user = User::factory()->create();
        $phpAccount = FinancialAccount::factory()->for($user)->create(['currency' => 'PHP', 'starting_balance' => '1000.00']);
        $usdAccount = FinancialAccount::factory()->for($user)->create(['currency' => 'USD', 'starting_balance' => '100.00']);
        Transaction::factory()->for($user)->for($phpAccount, 'financialAccount')->create(['type' => 'income', 'amount' => '200.00']);
        Transaction::factory()->for($user)->for($usdAccount, 'financialAccount')->create(['type' => 'income', 'amount' => '50.00']);

        $content = $this->actingAs($user)->get(route('dashboard'))->getContent();

        $this->assertSame(1, substr_count($content, 'PHP total balance'));
        $this->assertSame(1, substr_count($content, 'USD total balance'));
        $this->assertStringContainsString('1,200.00', $content);
        $this->assertStringContainsString('150.00', $content);
    }

    public function test_dashboard_shows_expense_totals_by_category_and_currency(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create(['name' => 'Food', 'type' => 'expense']);
        Transaction::factory()->for($user)->for($account, 'financialAccount')->create(['category_id' => $category->id, 'type' => 'expense', 'amount' => '125.00']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertSee('Food')
            ->assertSee('125.00');
    }

    public function test_dashboard_shows_current_month_budget_progress(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create(['currency' => 'PHP']);
        $category = Category::factory()->for($user)->create(['name' => 'Food', 'type' => 'expense']);
        Budget::factory()->for($user)->for($category)->create([
            'amount' => '1000.00',
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'currency' => 'PHP',
        ]);
        Transaction::factory()->for($user)->for($account, 'financialAccount')->create([
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => '250.00',
            'transaction_date' => now()->startOfMonth()->toDateString(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertSee("This month's budgets")
            ->assertSee('Food')
            ->assertSee('250.00')
            ->assertSee('750.00')
            ->assertSee('On track');
    }
}
