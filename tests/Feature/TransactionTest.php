<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    public function test_user_can_create_a_transaction_with_a_compatible_category(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense', 'name' => 'Food']);

        $this->actingAs($user);

        Volt::test('transactions.index')
            ->set('financial_account_id', $account->id)
            ->set('type', 'expense')
            ->set('category_id', $category->id)
            ->set('amount', '25.00')
            ->set('transaction_date', '2026-08-27')
            ->call('saveTransaction')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('transactions', ['category_id' => $category->id]);
    }

    public function test_user_can_create_an_uncategorized_transaction(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();

        $this->actingAs($user);

        Volt::test('transactions.index')
            ->set('financial_account_id', $account->id)
            ->set('type', 'expense')
            ->set('amount', '25.00')
            ->set('transaction_date', '2026-08-27')
            ->call('saveTransaction')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('transactions', ['financial_account_id' => $account->id, 'category_id' => null]);
    }

    public function test_changing_type_clears_an_incompatible_category_while_creating(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);

        $this->actingAs($user);

        Volt::test('transactions.index')
            ->set('category_id', $category->id)
            ->set('type', 'income')
            ->assertSet('category_id', null);
    }

    public function test_changing_type_clears_an_incompatible_category_while_editing(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        $transaction = Transaction::factory()->for($user)->for($account, 'financialAccount')->create([
            'category_id' => $category->id,
            'type' => 'expense',
        ]);

        $this->actingAs($user);

        Volt::test('transactions.index')
            ->call('editTransaction', $transaction->id)
            ->set('type', 'income')
            ->assertSet('category_id', null);
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

    public function test_user_cannot_create_a_transaction_with_another_users_category(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();
        $category = Category::factory()->for($otherUser)->create(['type' => 'expense']);

        $this->actingAs($user);

        Volt::test('transactions.index')
            ->set('financial_account_id', $account->id)
            ->set('type', 'expense')
            ->set('category_id', $category->id)
            ->set('amount', '100.00')
            ->set('transaction_date', '2026-08-27')
            ->call('saveTransaction')
            ->assertHasErrors('category_id');
    }

    public function test_user_cannot_use_a_category_with_an_incompatible_transaction_type(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();
        $incomeCategory = Category::factory()->for($user)->create(['type' => 'income']);

        $this->actingAs($user);

        Volt::test('transactions.index')
            ->set('financial_account_id', $account->id)
            ->set('type', 'expense')
            ->set('category_id', $incomeCategory->id)
            ->set('amount', '100.00')
            ->set('transaction_date', '2026-08-27')
            ->call('saveTransaction')
            ->assertHasErrors('category_id');
    }

    public function test_user_can_update_the_category_on_their_transaction(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();
        $oldCategory = Category::factory()->for($user)->create(['type' => 'expense']);
        $newCategory = Category::factory()->for($user)->create(['type' => 'expense']);
        $transaction = Transaction::factory()->for($user)->for($account, 'financialAccount')->create([
            'category_id' => $oldCategory->id,
            'type' => 'expense',
        ]);

        $this->actingAs($user);

        Volt::test('transactions.index')
            ->call('editTransaction', $transaction->id)
            ->set('category_id', $newCategory->id)
            ->call('updateTransaction')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'category_id' => $newCategory->id]);
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

    public function test_user_cannot_update_their_transaction_to_another_users_category(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        $otherCategory = Category::factory()->for($otherUser)->create(['type' => 'expense']);
        $transaction = Transaction::factory()->for($user)->for($account, 'financialAccount')->create([
            'category_id' => $category->id,
            'type' => 'expense',
        ]);

        $this->actingAs($user);

        Volt::test('transactions.index')
            ->call('editTransaction', $transaction->id)
            ->set('category_id', $otherCategory->id)
            ->call('updateTransaction')
            ->assertHasErrors('category_id');

        $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'category_id' => $category->id]);
    }

    public function test_user_cannot_use_an_expense_category_for_an_income_transaction(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();
        $expenseCategory = Category::factory()->for($user)->create(['type' => 'expense']);

        $this->actingAs($user);

        Volt::test('transactions.index')
            ->set('financial_account_id', $account->id)
            ->set('type', 'income')
            ->set('category_id', $expenseCategory->id)
            ->set('amount', '100.00')
            ->set('transaction_date', '2026-08-27')
            ->call('saveTransaction')
            ->assertHasErrors('category_id');
    }

    public function test_deleting_a_category_uncategorizes_its_transactions(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create();
        $transaction = Transaction::factory()->for($user)->for($account, 'financialAccount')->create([
            'category_id' => $category->id,
        ]);

        $category->delete();

        $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'category_id' => null]);
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

    public function test_transactions_page_offers_manual_csv_upload(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('transactions.index'))
            ->assertOk()
            ->assertSee('Manual upload');
    }

    public function test_user_can_import_selected_rows_from_a_two_column_csv(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();
        $csv = "Date,Amount\n2026-10-01,-42.50\n2026-10-02,1500.00\n2026-10-03,0\n";

        $this->actingAs($user);

        Volt::test('transactions.index')
            ->set('importFinancialAccountId', $account->id)
            ->call('continueImportToFile')
            ->set('csvFile', UploadedFile::fake()->createWithContent('statement.csv', $csv))
            ->call('readImportFile')
            ->assertHasNoErrors()
            ->assertSet('importStep', 'mapping')
            ->set('importDateFormat', 'Y-m-d')
            ->call('continueImportToSummary')
            ->assertSet('importStep', 'summary')
            ->set('selectedImportRows', ['0'])
            ->call('uploadImportedTransactions')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'type' => 'expense',
            'amount' => '42.50',
            'description' => null,
            'category_id' => null,
        ]);
    }

    public function test_user_can_import_a_three_column_csv_and_skip_rows_with_warnings(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();
        $csv = "date,amount,type\n2026-10-01,80.00,expense\n2026-10-02,-15.00,income\n2026-10-03,10.00,transfer\n";

        $this->actingAs($user);

        Volt::test('transactions.index')
            ->set('importFinancialAccountId', $account->id)
            ->call('continueImportToFile')
            ->set('csvFile', UploadedFile::fake()->createWithContent('statement.csv', $csv))
            ->call('readImportFile')
            ->set('importDateFormat', 'Y-m-d')
            ->call('continueImportToSummary')
            ->assertSet('selectedImportRows', ['0'])
            ->call('uploadImportedTransactions')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('transactions', 1);
        $transaction = Transaction::query()->firstOrFail();
        $this->assertSame('expense', $transaction->type);
        $this->assertSame('80.00', $transaction->amount);
        $this->assertSame('2026-10-01', $transaction->transaction_date->toDateString());
    }

    public function test_csv_import_rejects_a_file_that_does_not_have_two_or_three_columns(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();

        $this->actingAs($user);

        Volt::test('transactions.index')
            ->set('importFinancialAccountId', $account->id)
            ->call('continueImportToFile')
            ->set('csvFile', UploadedFile::fake()->createWithContent('statement.csv', "a,b,c,d\n1,2,3,4\n"))
            ->call('readImportFile')
            ->assertHasErrors('csvFile')
            ->assertSet('importStep', 'file');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_user_cannot_import_transactions_into_another_users_account(): void
    {
        $user = User::factory()->create();
        $otherAccount = FinancialAccount::factory()->for(User::factory())->create();

        $this->actingAs($user);

        Volt::test('transactions.index')
            ->set('importFinancialAccountId', $otherAccount->id)
            ->call('continueImportToFile')
            ->assertHasErrors('importFinancialAccountId');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_transaction_list_shows_five_per_page(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create();

        foreach (range(1, 6) as $day) {
            Transaction::factory()->for($user)->for($account, 'financialAccount')->create([
                'description' => "Paged {$day}",
                'transaction_date' => sprintf('2026-01-%02d', $day),
                'type' => 'expense',
                'amount' => '10.00',
            ]);
        }

        $this->actingAs($user);

        Volt::test('transactions.index')
            ->assertSee('Paged 6')
            ->assertSee('Paged 2')
            ->assertDontSee('Paged 1')
            ->call('gotoPage', 2)
            ->assertSee('Paged 1')
            ->assertDontSee('Paged 6');
    }

    public function test_transaction_list_filters_by_date_range_amount_type_and_account(): void
    {
        $user = User::factory()->create();
        $checking = FinancialAccount::factory()->for($user)->create(['name' => 'Checking']);
        $savings = FinancialAccount::factory()->for($user)->create(['name' => 'Savings']);

        Transaction::factory()->for($user)->for($checking, 'financialAccount')->create([
            'description' => 'January rent',
            'transaction_date' => '2026-01-15',
            'type' => 'expense',
            'amount' => '10.00',
        ]);
        Transaction::factory()->for($user)->for($checking, 'financialAccount')->create([
            'description' => 'February salary',
            'transaction_date' => '2026-02-01',
            'type' => 'income',
            'amount' => '20.00',
        ]);
        Transaction::factory()->for($user)->for($savings, 'financialAccount')->create([
            'description' => 'Savings transfer',
            'transaction_date' => '2026-01-20',
            'type' => 'expense',
            'amount' => '10.00',
        ]);

        $this->actingAs($user);

        Volt::test('transactions.index')
            ->set('filterDateFrom', '2026-01-01')
            ->set('filterDateTo', '2026-01-31')
            ->assertSee('February salary')
            ->call('applyTransactionFilters')
            ->assertSee('January rent')
            ->assertSee('Savings transfer')
            ->assertDontSee('February salary')
            ->set('filterDateFrom', '')
            ->set('filterDateTo', '')
            ->set('filterAmount', '20.00')
            ->call('applyTransactionFilters')
            ->assertSee('February salary')
            ->assertDontSee('January rent')
            ->call('clearTransactionFilters')
            ->assertSee('January rent')
            ->set('filterType', 'income')
            ->call('applyTransactionFilters')
            ->assertSee('February salary')
            ->assertDontSee('January rent')
            ->call('clearTransactionFilters')
            ->set('filterFinancialAccountId', (string) $savings->id)
            ->call('applyTransactionFilters')
            ->assertSee('Savings transfer')
            ->assertDontSee('January rent');
    }

    public function test_transaction_date_filter_requires_the_start_on_or_before_the_end(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Volt::test('transactions.index')
            ->set('filterDateFrom', '2026-02-01')
            ->set('filterDateTo', '2026-01-01')
            ->call('applyTransactionFilters')
            ->assertHasErrors('filterDateTo');
    }
}
