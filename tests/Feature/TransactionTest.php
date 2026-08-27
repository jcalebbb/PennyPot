<?php

namespace Tests\Feature;

use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class TransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_the_transactions_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('transactions.index'))
            ->assertOk()
            ->assertSeeVolt('transactions.index');
    }

    public function test_guest_cannot_view_the_transactions_page(): void
    {
        $this->get(route('transactions.index'))
            ->assertRedirect(route('login'));
    }

    public function test_user_can_create_an_income_transaction(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();

        $this->actingAs($user);

        Volt::test('transactions.index')
            ->set('financial_account_id', $account->id)
            ->set('type', 'income')
            ->set('description', 'Salary')
            ->set('amount', '25000.00')
            ->set('transaction_date', '2026-08-27')
            ->call('saveTransaction')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'type' => 'income',
            'description' => 'Salary',
        ]);

        $transaction = Transaction::query()->where('description', 'Salary')->firstOrFail();
        $this->assertSame('25000.00', $transaction->amount);
        $this->assertSame('2026-08-27', $transaction->transaction_date->toDateString());
    }

    public function test_user_can_create_an_expense_transaction(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();

        $this->actingAs($user);

        Volt::test('transactions.index')
            ->set('financial_account_id', $account->id)
            ->set('type', 'expense')
            ->set('amount', '125.50')
            ->set('transaction_date', '2026-08-27')
            ->call('saveTransaction')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'type' => 'expense',
            'amount' => '125.50',
        ]);
    }

    public function test_user_only_sees_their_own_transactions(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownAccount = FinancialAccount::factory()->for($user)->create();
        $otherAccount = FinancialAccount::factory()->for($otherUser)->create();

        Transaction::factory()->for($user)->for($ownAccount, 'financialAccount')->create(['description' => 'My transaction']);
        Transaction::factory()->for($otherUser)->for($otherAccount, 'financialAccount')->create(['description' => 'Private transaction']);

        $this->actingAs($user)
            ->get(route('transactions.index'))
            ->assertSee('My transaction')
            ->assertDontSee('Private transaction');
    }

    public function test_user_cannot_create_a_transaction_for_another_users_account(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherAccount = FinancialAccount::factory()->for($otherUser)->create();

        $this->actingAs($user);

        Volt::test('transactions.index')
            ->set('financial_account_id', $otherAccount->id)
            ->set('type', 'expense')
            ->set('amount', '100.00')
            ->set('transaction_date', '2026-08-27')
            ->call('saveTransaction')
            ->assertHasErrors('financial_account_id');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_user_cannot_update_another_users_transaction(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $account = FinancialAccount::factory()->for($otherUser)->create();
        $transaction = Transaction::factory()->for($otherUser)->for($account, 'financialAccount')->create();

        $this->actingAs($user);

        Volt::test('transactions.index')
            ->set('editingTransactionId', $transaction->id)
            ->call('updateTransaction')
            ->assertForbidden();

        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
    }

    public function test_user_cannot_delete_another_users_transaction(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $account = FinancialAccount::factory()->for($otherUser)->create();
        $transaction = Transaction::factory()->for($otherUser)->for($account, 'financialAccount')->create();

        $this->actingAs($user);

        Volt::test('transactions.index')
            ->call('deleteTransaction', $transaction->id)
            ->assertForbidden();

        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
    }

    public function test_user_cannot_update_their_transaction_to_another_users_account(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();
        $otherAccount = FinancialAccount::factory()->for($otherUser)->create();
        $transaction = Transaction::factory()->for($user)->for($account, 'financialAccount')->create();

        $this->actingAs($user);

        Volt::test('transactions.index')
            ->call('editTransaction', $transaction->id)
            ->set('financial_account_id', $otherAccount->id)
            ->call('updateTransaction')
            ->assertHasErrors('financial_account_id');

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'financial_account_id' => $account->id,
        ]);
    }

    public function test_user_can_update_and_delete_their_own_transaction(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();
        $transaction = Transaction::factory()->for($user)->for($account, 'financialAccount')->create(['description' => 'Old description']);

        $this->actingAs($user);

        Volt::test('transactions.index')
            ->call('editTransaction', $transaction->id)
            ->set('description', 'Updated description')
            ->set('amount', '75.00')
            ->call('updateTransaction')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'description' => 'Updated description',
            'amount' => '75.00',
        ]);

        Volt::test('transactions.index')
            ->call('deleteTransaction', $transaction->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('transactions', ['id' => $transaction->id]);
    }
}
