<?php

namespace Tests\Feature;

use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class FinancialAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_their_financial_accounts_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('financial-accounts.index'))
            ->assertOk()
            ->assertSeeVolt('financial-accounts.index');
    }

    public function test_user_can_create_their_own_financial_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Volt::test('financial-accounts.index')
            ->set('name', 'BPI Savings')
            ->set('institution', 'BPI')
            ->set('account_type', 'Savings')
            ->set('currency', 'PHP')
            ->set('starting_balance', '12500.00')
            ->call('saveAccount')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('financial_accounts', [
            'user_id' => $user->id,
            'name' => 'BPI Savings',
            'account_type' => 'Savings',
            'currency' => 'PHP',
            'starting_balance' => '12500.00',
        ]);
    }

    public function test_user_only_sees_their_own_financial_accounts(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownAccount = FinancialAccount::factory()->for($user)->create(['name' => 'My Cash']);
        FinancialAccount::factory()->for($otherUser)->create(['name' => 'Private Account']);

        $this->actingAs($user)
            ->get(route('financial-accounts.index'))
            ->assertSee($ownAccount->name)
            ->assertDontSee('Private Account');
    }

    public function test_user_cannot_update_another_users_financial_account(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $account = FinancialAccount::factory()->for($otherUser)->create();

        $this->actingAs($user);
        $component = Volt::test('financial-accounts.index')
            ->set('editingAccountId', $account->id)
            ->set('name', 'Unauthorized update');

        $component->call('updateAccount')->assertForbidden();
        $this->assertDatabaseMissing('financial_accounts', ['name' => 'Unauthorized update']);
    }

    public function test_user_cannot_delete_another_users_financial_account(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $account = FinancialAccount::factory()->for($otherUser)->create();

        $this->actingAs($user);

        Volt::test('financial-accounts.index')
            ->call('deleteAccount', $account->id)
            ->assertForbidden();

        $this->assertDatabaseHas('financial_accounts', ['id' => $account->id]);
    }

    public function test_user_can_update_and_delete_their_own_financial_account(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->for($user)->create(['name' => 'Old Name']);

        $this->actingAs($user);

        Volt::test('financial-accounts.index')
            ->call('editAccount', $account->id)
            ->set('name', 'Updated Name')
            ->set('starting_balance', '500.00')
            ->call('updateAccount')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('financial_accounts', [
            'id' => $account->id,
            'name' => 'Updated Name',
            'starting_balance' => '500.00',
        ]);

        Volt::test('financial-accounts.index')
            ->call('deleteAccount', $account->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('financial_accounts', ['id' => $account->id]);
    }
}
