<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_budgets(): void
    {
        $this->get(route('budgets.index'))->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_budgets(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('budgets.index'))
            ->assertOk()
            ->assertSeeVolt('budgets.index');
    }

    public function test_user_can_create_a_monthly_budget_for_an_expense_category(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        FinancialAccount::factory()->for($user)->create(['currency' => 'PHP']);

        $this->actingAs($user);

        Volt::test('budgets.index')
            ->set('category_id', $category->id)
            ->set('amount', '8000.00')
            ->set('month', '2026-08')
            ->set('currency', 'PHP')
            ->call('saveBudget')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('budgets', [
            'user_id' => $user->id,
            'category_id' => $category->id,
            'currency' => 'PHP',
        ]);

        $budget = Budget::query()->where('category_id', $category->id)->firstOrFail();
        $this->assertSame('8000.00', $budget->amount);
        $this->assertSame('2026-08-01', $budget->start_date->toDateString());
        $this->assertSame('2026-08-31', $budget->end_date->toDateString());
    }

    public function test_income_categories_and_other_users_categories_are_rejected(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $incomeCategory = Category::factory()->for($user)->create(['type' => 'income']);
        $otherCategory = Category::factory()->for($otherUser)->create(['type' => 'expense']);
        FinancialAccount::factory()->for($user)->create(['currency' => 'PHP']);

        $this->actingAs($user);

        Volt::test('budgets.index')
            ->set('category_id', $incomeCategory->id)
            ->set('amount', '1000.00')
            ->set('month', '2026-08')
            ->set('currency', 'PHP')
            ->call('saveBudget')
            ->assertHasErrors('category_id');

        Volt::test('budgets.index')
            ->set('category_id', $otherCategory->id)
            ->set('amount', '1000.00')
            ->set('month', '2026-08')
            ->set('currency', 'PHP')
            ->call('saveBudget')
            ->assertHasErrors('category_id');

        $this->assertDatabaseCount('budgets', 0);
    }

    public function test_user_id_is_assigned_server_side_and_currency_must_be_available(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        $otherUser = User::factory()->create();
        $otherAccount = FinancialAccount::factory()->for($otherUser)->create(['currency' => 'USD']);

        $this->actingAs($user);

        Volt::test('budgets.index')
            ->set('category_id', $category->id)
            ->set('amount', '1000.00')
            ->set('month', '2026-08')
            ->set('currency', 'USD')
            ->call('saveBudget')
            ->assertHasErrors('currency');

        $this->assertDatabaseMissing('budgets', ['category_id' => $category->id, 'user_id' => $otherUser->id]);
        $this->assertNotNull($otherAccount->fresh());
    }

    public function test_duplicate_and_overlapping_budgets_are_rejected_but_different_months_are_allowed(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        FinancialAccount::factory()->for($user)->create(['currency' => 'PHP']);
        Budget::factory()->for($user)->for($category)->create([
            'currency' => 'PHP',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]);

        $this->actingAs($user);

        Volt::test('budgets.index')
            ->set('category_id', $category->id)
            ->set('amount', '1000.00')
            ->set('month', '2026-08')
            ->set('currency', 'PHP')
            ->call('saveBudget')
            ->assertHasErrors('month');

        Volt::test('budgets.index')
            ->set('category_id', $category->id)
            ->set('amount', '1000.00')
            ->set('month', '2026-09')
            ->set('currency', 'PHP')
            ->call('saveBudget')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('budgets', 2);
    }

    public function test_user_can_update_and_delete_their_budget(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        FinancialAccount::factory()->for($user)->create(['currency' => 'PHP']);
        $budget = Budget::factory()->for($user)->for($category)->create(['amount' => '1000.00']);

        $this->actingAs($user);

        Volt::test('budgets.index')
            ->call('editBudget', $budget->id)
            ->set('amount', '2000.00')
            ->call('updateBudget')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('budgets', ['id' => $budget->id, 'amount' => '2000.00']);

        Volt::test('budgets.index')->call('deleteBudget', $budget->id)->assertHasNoErrors();
        $this->assertDatabaseMissing('budgets', ['id' => $budget->id]);
    }

    public function test_user_cannot_update_or_delete_another_users_budget(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $category = Category::factory()->for($otherUser)->create(['type' => 'expense']);
        $budget = Budget::factory()->for($otherUser)->for($category)->create();

        $this->actingAs($user);

        Volt::test('budgets.index')->set('editingBudgetId', $budget->id)->call('updateBudget')->assertForbidden();
        Volt::test('budgets.index')->call('deleteBudget', $budget->id)->assertForbidden();
        $this->assertDatabaseHas('budgets', ['id' => $budget->id]);
    }

    public function test_budget_spending_calculates_remaining_usage_and_over_budget_status(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        $account = FinancialAccount::factory()->for($user)->create(['currency' => 'PHP']);
        $budget = Budget::factory()->for($user)->for($category)->create([
            'amount' => '1000.00',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'currency' => 'PHP',
        ]);
        Transaction::factory()->for($user)->for($account, 'financialAccount')->create([
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => '850.00',
            'transaction_date' => '2026-08-15',
        ]);

        $this->actingAs($user);
        $component = Volt::test('budgets.index');
        $budgetView = $component->get('budgets')->first();

        $this->assertSame(850.0, (float) $budgetView->spent);
        $this->assertSame(150.0, $budgetView->remaining);
        $this->assertSame(85.0, $budgetView->usage_percentage);
        $this->assertSame('Near limit', $budgetView->status);

        $budget->update(['amount' => '500.00']);
        $overBudgetView = Volt::test('budgets.index')->get('budgets')->first();
        $this->assertSame('Over budget', $overBudgetView->status);
        $this->assertGreaterThan(100, $overBudgetView->usage_percentage);
    }

    public function test_budget_spending_excludes_income_other_users_other_currencies_and_outside_dates(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        $otherCategory = Category::factory()->for($otherUser)->create(['type' => 'expense']);
        $phpAccount = FinancialAccount::factory()->for($user)->create(['currency' => 'PHP']);
        $usdAccount = FinancialAccount::factory()->for($user)->create(['currency' => 'USD']);
        $otherAccount = FinancialAccount::factory()->for($otherUser)->create(['currency' => 'PHP']);
        $budget = Budget::factory()->for($user)->for($category)->create([
            'amount' => '1000.00',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'currency' => 'PHP',
        ]);

        Transaction::factory()->for($user)->for($phpAccount, 'financialAccount')->create(['category_id' => $category->id, 'type' => 'expense', 'amount' => '100.00', 'transaction_date' => '2026-08-10']);
        Transaction::factory()->for($user)->for($phpAccount, 'financialAccount')->create(['category_id' => $category->id, 'type' => 'income', 'amount' => '200.00', 'transaction_date' => '2026-08-10']);
        Transaction::factory()->for($user)->for($phpAccount, 'financialAccount')->create(['category_id' => $category->id, 'type' => 'expense', 'amount' => '300.00', 'transaction_date' => '2026-09-01']);
        Transaction::factory()->for($user)->for($usdAccount, 'financialAccount')->create(['category_id' => $category->id, 'type' => 'expense', 'amount' => '400.00', 'transaction_date' => '2026-08-10']);
        Transaction::factory()->for($otherUser)->for($otherAccount, 'financialAccount')->create(['category_id' => $otherCategory->id, 'type' => 'expense', 'amount' => '500.00', 'transaction_date' => '2026-08-10']);

        $this->actingAs($user);
        $budgetView = Volt::test('budgets.index')->get('budgets')->first();

        $this->assertSame(100.0, (float) $budgetView->spent);
    }

    public function test_zero_budget_is_rejected_and_no_spending_is_zero(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        FinancialAccount::factory()->for($user)->create(['currency' => 'PHP']);

        $this->actingAs($user);

        Volt::test('budgets.index')
            ->set('category_id', $category->id)
            ->set('amount', '0')
            ->set('month', '2026-08')
            ->set('currency', 'PHP')
            ->call('saveBudget')
            ->assertHasErrors('amount');

        $budget = Budget::factory()->for($user)->for($category)->create(['amount' => '1000.00']);
        $budgetView = Volt::test('budgets.index')->get('budgets')->first();

        $this->assertSame(0.0, (float) $budgetView->spent);
        $this->assertSame(0.0, $budgetView->usage_percentage);
        $this->assertSame('On track', $budgetView->status);
    }

    public function test_deleting_a_category_deletes_budgets_and_preserves_transactions_as_uncategorized(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        $account = FinancialAccount::factory()->for($user)->create();
        $budget = Budget::factory()->for($user)->for($category)->create();
        $transaction = Transaction::factory()->for($user)->for($account, 'financialAccount')->create(['category_id' => $category->id]);

        $category->delete();

        $this->assertDatabaseMissing('budgets', ['id' => $budget->id]);
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'category_id' => null]);
    }
}
