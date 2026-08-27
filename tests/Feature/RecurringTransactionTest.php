<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RecurringTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_recurring_transactions(): void
    {
        $this->get(route('recurring-transactions.index'))->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_the_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('recurring-transactions.index'))
            ->assertOk()->assertSeeVolt('recurring-transactions.index');
    }

    public function test_user_can_create_update_delete_pause_and_resume_a_recurring_transaction(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        $this->actingAs($user);

        Volt::test('recurring-transactions.index')
            ->set('financial_account_id', $account->id)->set('category_id', $category->id)
            ->set('type', 'expense')->set('description', 'Rent')->set('amount', '1000.00')
            ->set('frequency', 'monthly')->set('start_date', '2026-08-01')->set('end_date', '2026-12-31')
            ->call('saveRecurringTransaction')->assertHasNoErrors();

        $recurring = RecurringTransaction::query()->firstOrFail();
        $this->assertSame($user->id, $recurring->user_id);
        $this->assertSame('2026-08-01', $recurring->next_occurrence->toDateString());

        Volt::test('recurring-transactions.index')->call('editRecurringTransaction', $recurring->id)
            ->set('amount', '1200.00')->call('updateRecurringTransaction')->assertHasNoErrors();
        $this->assertDatabaseHas('recurring_transactions', ['id' => $recurring->id, 'amount' => '1200.00']);

        Volt::test('recurring-transactions.index')->call('toggleActive', $recurring->id)->assertHasNoErrors();
        $this->assertFalse($recurring->refresh()->is_active);
        Volt::test('recurring-transactions.index')->call('toggleActive', $recurring->id)->assertHasNoErrors();
        $this->assertTrue($recurring->refresh()->is_active);

        Volt::test('recurring-transactions.index')->call('deleteRecurringTransaction', $recurring->id)->assertHasNoErrors();
        $this->assertDatabaseMissing('recurring_transactions', ['id' => $recurring->id]);
    }

    public function test_ownership_and_category_validation_are_enforced(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();
        $otherAccount = FinancialAccount::factory()->for($otherUser)->create();
        $income = Category::factory()->for($user)->create(['type' => 'income']);
        $otherCategory = Category::factory()->for($otherUser)->create(['type' => 'expense']);
        $this->actingAs($user);

        $component = Volt::test('recurring-transactions.index')
            ->set('financial_account_id', $otherAccount->id)->set('type', 'expense')
            ->set('category_id', $otherCategory->id)->set('amount', '100.00')->set('frequency', 'daily')->set('start_date', '2026-08-01');
        $component->call('saveRecurringTransaction')->assertHasErrors('financial_account_id');

        Volt::test('recurring-transactions.index')
            ->set('financial_account_id', $account->id)->set('type', 'expense')
            ->set('category_id', $otherCategory->id)->set('amount', '100.00')->set('frequency', 'daily')->set('start_date', '2026-08-01')
            ->call('saveRecurringTransaction')->assertHasErrors('category_id');

        Volt::test('recurring-transactions.index')
            ->set('financial_account_id', $account->id)->set('type', 'expense')
            ->set('category_id', $income->id)->set('amount', '100.00')->set('frequency', 'daily')->set('start_date', '2026-08-01')
            ->call('saveRecurringTransaction')->assertHasErrors('category_id');
    }

    public function test_nullable_category_and_date_amount_frequency_validation_work(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();
        $this->actingAs($user);

        Volt::test('recurring-transactions.index')
            ->set('financial_account_id', $account->id)->set('type', 'income')->set('amount', '100.00')
            ->set('frequency', 'weekly')->set('start_date', '2026-08-01')->set('end_date', '2026-07-01')
            ->call('saveRecurringTransaction')->assertHasErrors('end_date');

        Volt::test('recurring-transactions.index')
            ->set('financial_account_id', $account->id)->set('type', 'income')->set('amount', '0')
            ->set('frequency', 'invalid')->set('start_date', '2026-08-01')
            ->call('saveRecurringTransaction')->assertHasErrors(['amount', 'frequency']);

        Volt::test('recurring-transactions.index')
            ->set('financial_account_id', $account->id)->set('type', 'income')->set('amount', '100.00')
            ->set('frequency', 'weekly')->set('start_date', '2026-08-01')
            ->call('saveRecurringTransaction')->assertHasNoErrors();
        $this->assertDatabaseHas('recurring_transactions', ['category_id' => null, 'user_id' => $user->id]);
    }

    public function test_user_cannot_modify_another_users_recurring_transaction(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $account = FinancialAccount::factory()->for($otherUser)->create();
        $recurring = RecurringTransaction::factory()->for($otherUser)->create(['financial_account_id' => $account->id]);
        $this->actingAs($user);

        Volt::test('recurring-transactions.index')->set('editingRecurringTransactionId', $recurring->id)->call('updateRecurringTransaction')->assertForbidden();
        Volt::test('recurring-transactions.index')->call('toggleActive', $recurring->id)->assertForbidden();
        Volt::test('recurring-transactions.index')->call('deleteRecurringTransaction', $recurring->id)->assertForbidden();
    }

    public function test_daily_weekly_and_yearly_recurrences_generate_normal_transactions(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create(['starting_balance' => '500.00']);
        $category = Category::factory()->for($user)->create(['type' => 'expense']);

        foreach (['daily' => '2026-08-03', 'weekly' => '2026-08-01', 'yearly' => '2025-08-01'] as $frequency => $startDate) {
            RecurringTransaction::factory()->for($user)->create([
                'financial_account_id' => $account->id, 'category_id' => $category->id,
                'frequency' => $frequency, 'start_date' => $startDate, 'next_occurrence' => $startDate,
                'amount' => '25.00', 'type' => 'expense', 'description' => $frequency,
            ]);
        }

        $this->artisan('recurring-transactions:generate', ['--date' => '2026-08-05'])->assertExitCode(0);
        $dailyDates = Transaction::where('description', 'daily')->pluck('transaction_date')->map(fn ($date) => Carbon::parse($date)->toDateString())->all();
        $this->assertContains('2026-08-05', $dailyDates);
        $weeklyDates = Transaction::where('description', 'weekly')->pluck('transaction_date')->map(fn ($date) => Carbon::parse($date)->toDateString())->all();
        $this->assertContains('2026-08-01', $weeklyDates);

        $this->artisan('recurring-transactions:generate', ['--date' => '2026-08-06'])->assertExitCode(0);
        $yearlyDates = Transaction::where('description', 'yearly')->pluck('transaction_date')->map(fn ($date) => Carbon::parse($date)->toDateString())->all();
        $this->assertContains('2026-08-01', $yearlyDates);
        $this->assertSame('500.00', $account->refresh()->starting_balance);
    }

    public function test_monthly_recurrence_preserves_month_end_and_normal_dates(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();
        $lastDay = RecurringTransaction::factory()->for($user)->create([
            'financial_account_id' => $account->id, 'category_id' => null, 'frequency' => 'monthly', 'start_date' => '2026-01-31', 'next_occurrence' => '2026-01-31',
        ]);
        $normal = RecurringTransaction::factory()->for($user)->create([
            'financial_account_id' => $account->id, 'category_id' => null, 'frequency' => 'monthly', 'start_date' => '2026-01-15', 'next_occurrence' => '2026-01-15',
        ]);

        $this->artisan('recurring-transactions:generate', ['--date' => '2026-04-01'])->assertExitCode(0);

        $lastDayDates = Transaction::where('recurring_transaction_id', $lastDay->id)->pluck('transaction_date')->map(fn ($date) => Carbon::parse($date)->toDateString())->all();
        $normalDates = Transaction::where('recurring_transaction_id', $normal->id)->pluck('transaction_date')->map(fn ($date) => Carbon::parse($date)->toDateString())->all();
        $this->assertContains('2026-02-28', $lastDayDates);
        $this->assertContains('2026-03-31', $lastDayDates);
        $this->assertContains('2026-02-15', $normalDates);
        $this->assertContains('2026-03-15', $normalDates);
    }

    public function test_leap_year_end_date_inactive_and_future_occurrences_are_handled(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();
        $leap = RecurringTransaction::factory()->for($user)->create([
            'financial_account_id' => $account->id, 'category_id' => null, 'frequency' => 'monthly', 'start_date' => '2024-01-31', 'next_occurrence' => '2024-01-31', 'end_date' => '2024-03-01',
        ]);
        $future = RecurringTransaction::factory()->for($user)->create([
            'financial_account_id' => $account->id, 'category_id' => null, 'frequency' => 'daily', 'start_date' => '2026-09-01', 'next_occurrence' => '2026-09-01',
        ]);

        $this->artisan('recurring-transactions:generate', ['--date' => '2024-03-02'])->assertExitCode(0);
        $leapDates = Transaction::where('recurring_transaction_id', $leap->id)->pluck('transaction_date')->map(fn ($date) => Carbon::parse($date)->toDateString())->all();
        $this->assertContains('2024-02-29', $leapDates);
        $this->assertFalse($leap->refresh()->is_active);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertTrue($future->refresh()->is_active);
    }

    public function test_generation_is_idempotent_and_catches_up_multiple_templates(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();
        $first = RecurringTransaction::factory()->for($user)->create(['financial_account_id' => $account->id, 'category_id' => null, 'frequency' => 'daily', 'start_date' => '2026-08-01', 'next_occurrence' => '2026-08-01']);
        $second = RecurringTransaction::factory()->for($user)->create(['financial_account_id' => $account->id, 'category_id' => null, 'frequency' => 'daily', 'start_date' => '2026-08-03', 'next_occurrence' => '2026-08-03']);

        $this->artisan('recurring-transactions:generate', ['--date' => '2026-08-05'])->assertExitCode(0);
        $this->artisan('recurring-transactions:generate', ['--date' => '2026-08-05'])->assertExitCode(0);

        $this->assertSame(5, Transaction::where('recurring_transaction_id', $first->id)->count());
        $this->assertSame(3, Transaction::where('recurring_transaction_id', $second->id)->count());
        $this->assertSame(8, Transaction::count());
    }

    public function test_generated_transactions_flow_into_existing_features_and_manual_transactions_remain_unchanged(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create(['name' => 'Main', 'starting_balance' => '1000.00']);
        $category = Category::factory()->for($user)->create(['name' => 'Food', 'type' => 'expense']);
        Budget::factory()->for($user)->for($category)->create(['amount' => '500.00', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'currency' => 'PHP']);
        $manual = Transaction::factory()->for($user)->for($account, 'financialAccount')->create(['description' => 'Manual', 'type' => 'expense', 'amount' => '10.00', 'transaction_date' => '2026-08-01', 'category_id' => $category->id]);
        RecurringTransaction::factory()->for($user)->create(['financial_account_id' => $account->id, 'category_id' => $category->id, 'frequency' => 'daily', 'start_date' => '2026-08-02', 'next_occurrence' => '2026-08-02', 'amount' => '25.00', 'description' => 'Generated']);

        $this->artisan('recurring-transactions:generate', ['--date' => '2026-08-02'])->assertExitCode(0);
        $generated = Transaction::where('description', 'Generated')->firstOrFail();

        $this->assertSame($user->id, $generated->user_id);
        $this->assertSame($account->id, $generated->financial_account_id);
        $this->assertSame($category->id, $generated->category_id);
        $this->assertNotNull($generated->recurring_transaction_id);
        $this->assertNull($manual->refresh()->recurring_transaction_id);

        $this->actingAs($user)->get(route('dashboard'))->assertSee('965.00');
        $this->get(route('budgets.index'))->assertSee('35.00');
        $this->get(route('reports.index'))->assertSee('Generated');
    }
}
