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

class ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_reports(): void
    {
        $this->get(route('reports.index'))->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_reports_with_current_month_default(): void
    {
        $user = User::factory()->create();
        $component = Volt::actingAs($user)->test('reports.index');

        $component->assertSet('preset', 'current_month')
            ->assertSet('start_date', now()->startOfMonth()->toDateString())
            ->assertSet('end_date', now()->endOfMonth()->toDateString());

        $this->actingAs($user)->get(route('reports.index'))
            ->assertOk()
            ->assertSeeVolt('reports.index');
    }

    public function test_date_presets_and_custom_date_range_work(): void
    {
        $user = User::factory()->create();
        $component = Volt::actingAs($user)->test('reports.index');

        $component->set('preset', 'last_30_days')
            ->assertSet('start_date', now()->subDays(29)->toDateString())
            ->assertSet('end_date', now()->toDateString())
            ->set('preset', 'last_3_months')
            ->assertSet('start_date', now()->subMonths(2)->startOfMonth()->toDateString())
            ->set('preset', 'current_year')
            ->assertSet('start_date', now()->startOfYear()->toDateString())
            ->assertSet('end_date', now()->endOfYear()->toDateString())
            ->set('preset', 'custom')
            ->set('start_date', '2026-01-10')
            ->set('end_date', '2026-02-20')
            ->call('applyFilters')
            ->assertHasNoErrors();
    }

    public function test_invalid_custom_date_range_is_rejected(): void
    {
        $user = User::factory()->create();

        Volt::actingAs($user)->test('reports.index')
            ->set('preset', 'custom')
            ->set('start_date', '2026-02-20')
            ->set('end_date', '2026-01-10')
            ->call('applyFilters')
            ->assertHasErrors('end_date');
    }

    public function test_summary_totals_and_monthly_trends_are_currency_separated(): void
    {
        $user = User::factory()->create();
        $php = FinancialAccount::factory()->for($user)->create(['currency' => 'PHP']);
        $usd = FinancialAccount::factory()->for($user)->create(['currency' => 'USD']);
        Transaction::factory()->for($user)->for($php, 'financialAccount')->create(['type' => 'income', 'amount' => '1000.00', 'transaction_date' => now()->startOfMonth()]);
        Transaction::factory()->for($user)->for($php, 'financialAccount')->create(['type' => 'expense', 'amount' => '250.00', 'transaction_date' => now()->startOfMonth()]);
        Transaction::factory()->for($user)->for($usd, 'financialAccount')->create(['type' => 'income', 'amount' => '100.00', 'transaction_date' => now()->startOfMonth()]);

        $component = Volt::actingAs($user)->test('reports.index');
        $summaries = $component->get('summaryTotals');

        $this->assertSame(['PHP', 'USD'], $summaries->pluck('currency')->all());
        $this->assertSame(1000.0, (float) $summaries->first()->total_income);
        $this->assertSame(250.0, (float) $summaries->first()->total_expenses);
        $this->assertSame(750.0, (float) $summaries->first()->net_cash_flow);
        $this->assertCount(2, $component->get('monthlyTrends'));
    }

    public function test_date_range_excludes_transactions_outside_the_range(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();
        Transaction::factory()->for($user)->for($account, 'financialAccount')->create(['type' => 'income', 'amount' => '100.00', 'transaction_date' => '2026-01-15']);
        Transaction::factory()->for($user)->for($account, 'financialAccount')->create(['type' => 'income', 'amount' => '900.00', 'transaction_date' => '2026-02-15']);

        $component = Volt::actingAs($user)->test('reports.index')
            ->set('preset', 'custom')
            ->set('start_date', '2026-01-01')
            ->set('end_date', '2026-01-31');
        $summary = $component->get('summaryTotals')->first();

        $this->assertSame(100.0, (float) $summary->total_income);
        $this->assertCount(1, $component->get('recentTransactions'));
    }

    public function test_category_breakdowns_include_uncategorized_values_and_percentages(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create(['currency' => 'PHP']);
        $food = Category::factory()->for($user)->create(['name' => 'Food', 'type' => 'expense']);
        $salary = Category::factory()->for($user)->create(['name' => 'Salary', 'type' => 'income']);
        Transaction::factory()->for($user)->for($account, 'financialAccount')->create(['category_id' => $food->id, 'type' => 'expense', 'amount' => '75.00', 'transaction_date' => now()->startOfMonth()]);
        Transaction::factory()->for($user)->for($account, 'financialAccount')->create(['type' => 'expense', 'amount' => '25.00', 'transaction_date' => now()->startOfMonth()]);
        Transaction::factory()->for($user)->for($account, 'financialAccount')->create(['category_id' => $salary->id, 'type' => 'income', 'amount' => '200.00', 'transaction_date' => now()->startOfMonth()]);

        $component = Volt::actingAs($user)->test('reports.index');
        $expenses = $component->get('expenseBreakdown');
        $income = $component->get('incomeBreakdown');

        $this->assertSame(['Food', 'Uncategorized'], $expenses->pluck('name')->all());
        $this->assertSame(75.0, (float) $expenses->first()->percentage);
        $this->assertSame('Salary', $income->first()->name);
        $this->assertSame(100.0, (float) $income->first()->percentage);
    }

    public function test_account_activity_includes_zero_activity_accounts(): void
    {
        $user = User::factory()->create();
        $active = FinancialAccount::factory()->for($user)->create(['name' => 'Active']);
        $empty = FinancialAccount::factory()->for($user)->create(['name' => 'Empty']);
        Transaction::factory()->for($user)->for($active, 'financialAccount')->create(['type' => 'income', 'amount' => '500.00', 'transaction_date' => now()->startOfMonth()]);

        $activity = Volt::actingAs($user)->test('reports.index')->get('accountActivity');
        $emptyRow = $activity->firstWhere('id', $empty->id);

        $this->assertSame(0.0, $emptyRow->report_income);
        $this->assertSame(0.0, $emptyRow->report_expenses);
        $this->assertSame(500.0, $activity->firstWhere('id', $active->id)->net_activity);
    }

    public function test_budget_reports_calculate_actuals_and_exclude_other_currency_and_periods(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create(['currency' => 'PHP']);
        $usdAccount = FinancialAccount::factory()->for($user)->create(['currency' => 'USD']);
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        Budget::factory()->for($user)->for($category)->create(['amount' => '1000.00', 'currency' => 'PHP', 'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth()]);
        Budget::factory()->for($user)->for($category)->create(['amount' => '500.00', 'currency' => 'PHP', 'start_date' => now()->subMonth()->startOfMonth(), 'end_date' => now()->subMonth()->endOfMonth()]);
        Transaction::factory()->for($user)->for($account, 'financialAccount')->create(['category_id' => $category->id, 'type' => 'expense', 'amount' => '850.00', 'transaction_date' => now()->startOfMonth()]);
        Transaction::factory()->for($user)->for($usdAccount, 'financialAccount')->create(['category_id' => $category->id, 'type' => 'expense', 'amount' => '400.00', 'transaction_date' => now()->startOfMonth()]);

        $reports = Volt::actingAs($user)->test('reports.index')->get('budgetReports');
        $budget = $reports->first();

        $this->assertCount(1, $reports);
        $this->assertSame(850.0, (float) $budget->spent);
        $this->assertSame(150.0, $budget->remaining);
        $this->assertSame(85.0, $budget->usage_percentage);
        $this->assertSame('Near limit', $budget->status);
    }

    public function test_over_budget_status_and_recent_transaction_limit_and_ordering_work(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        $budget = Budget::factory()->for($user)->for($category)->create(['amount' => '100.00', 'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth()]);
        Transaction::factory()->for($user)->for($account, 'financialAccount')->create(['category_id' => $category->id, 'type' => 'expense', 'amount' => '150.00', 'transaction_date' => now()->startOfMonth()]);

        foreach (range(1, 11) as $number) {
            Transaction::factory()->for($user)->for($account, 'financialAccount')->create(['description' => "Report transaction {$number}", 'type' => 'expense', 'amount' => '1.00', 'transaction_date' => now()->startOfMonth()->addDays($number)]);
        }

        $component = Volt::actingAs($user)->test('reports.index');
        $reports = $component->get('budgetReports');
        $recent = $component->get('recentTransactions');

        $this->assertSame('Over budget', $reports->first()->status);
        $this->assertCount(10, $recent);
        $this->assertSame('Report transaction 11', $recent->first()->description);
        $this->assertSame('Report transaction 2', $recent->last()->description);
    }

    public function test_reports_isolate_all_user_data_and_render_empty_states(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherAccount = FinancialAccount::factory()->for($otherUser)->create(['name' => 'Private account']);
        $otherCategory = Category::factory()->for($otherUser)->create(['name' => 'Private category', 'type' => 'expense']);
        Budget::factory()->for($otherUser)->for($otherCategory)->create();
        Transaction::factory()->for($otherUser)->for($otherAccount, 'financialAccount')->create(['description' => 'Private transaction']);

        $this->actingAs($user)->get(route('reports.index'))
            ->assertSee('No report data for this date range.')
            ->assertSee('No expense activity for this date range.')
            ->assertSee('No income activity for this date range.')
            ->assertSee('No financial accounts yet.')
            ->assertSee('No budgets overlap this date range.')
            ->assertSee('No transactions for this date range.')
            ->assertDontSee('Private account')
            ->assertDontSee('Private category')
            ->assertDontSee('Private transaction');
    }
}
