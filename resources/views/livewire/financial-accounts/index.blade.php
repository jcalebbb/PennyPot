<?php

use App\Models\FinancialAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $name = '';
    public string $institution = '';
    public string $account_type = '';
    public string $currency = 'PHP';
    public string $starting_balance = '0.00';
    public ?int $editingAccountId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', FinancialAccount::class);
        $this->account_type = FinancialAccount::ACCOUNT_TYPES[0];
    }

    public function getAccountsProperty()
    {
        return Auth::user()->financialAccounts()->latest()->get();
    }

    public function saveAccount(): void
    {
        $validated = $this->validate($this->rules());

        $this->authorize('create', FinancialAccount::class);

        Auth::user()->financialAccounts()->create($validated);

        $this->resetForm();
        session()->flash('status', 'Financial account created.');
    }

    public function editAccount(int $accountId): void
    {
        $account = FinancialAccount::findOrFail($accountId);
        $this->authorize('update', $account);

        $this->editingAccountId = $account->id;
        $this->name = $account->name;
        $this->institution = $account->institution ?? '';
        $this->account_type = $account->account_type;
        $this->currency = $account->currency;
        $this->starting_balance = (string) $account->starting_balance;
    }

    public function updateAccount(): void
    {
        $account = FinancialAccount::findOrFail($this->editingAccountId);
        $this->authorize('update', $account);

        $account->update($this->validate($this->rules()));

        $this->resetForm();
        session()->flash('status', 'Financial account updated.');
    }

    public function deleteAccount(int $accountId): void
    {
        $account = FinancialAccount::findOrFail($accountId);
        $this->authorize('delete', $account);

        $account->delete();

        if ($this->editingAccountId === $accountId) {
            $this->resetForm();
        }

        session()->flash('status', 'Financial account deleted.');
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'institution' => ['nullable', 'string', 'max:255'],
            'account_type' => ['required', Rule::in(FinancialAccount::ACCOUNT_TYPES)],
            'currency' => ['required', 'string', 'size:3', 'uppercase'],
            'starting_balance' => ['required', 'numeric', 'decimal:0,2'],
        ];
    }

    private function resetForm(): void
    {
        $this->reset(['name', 'institution', 'starting_balance', 'editingAccountId']);
        $this->account_type = FinancialAccount::ACCOUNT_TYPES[0];
        $this->currency = 'PHP';
    }
}; ?>

<x-slot name="header">
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        {{ __('Financial Accounts') }}
    </h2>
</x-slot>

<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <section class="p-6 bg-white shadow-sm sm:rounded-lg">
            <header>
                <h3 class="text-lg font-medium text-gray-900">
                    {{ $editingAccountId ? __('Edit financial account') : __('Add financial account') }}
                </h3>
            </header>

            <form wire:submit="{{ $editingAccountId ? 'updateAccount' : 'saveAccount' }}" class="mt-6 space-y-6">
                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <x-input-label for="name" :value="__('Name')" />
                        <x-text-input wire:model="name" id="name" class="mt-1 block w-full" type="text" required />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="institution" :value="__('Institution (optional)')" />
                        <x-text-input wire:model="institution" id="institution" class="mt-1 block w-full" type="text" />
                        <x-input-error :messages="$errors->get('institution')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="account_type" :value="__('Account type')" />
                        <select wire:model="account_type" id="account_type" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            @foreach (\App\Models\FinancialAccount::ACCOUNT_TYPES as $type)
                                <option value="{{ $type }}">{{ $type }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('account_type')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="currency" :value="__('Currency')" />
                        <x-text-input wire:model="currency" id="currency" class="mt-1 block w-full" type="text" maxlength="3" required />
                        <x-input-error :messages="$errors->get('currency')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="starting_balance" :value="__('Starting balance')" />
                        <x-text-input wire:model="starting_balance" id="starting_balance" class="mt-1 block w-full" type="number" step="0.01" required />
                        <x-input-error :messages="$errors->get('starting_balance')" class="mt-2" />
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <x-primary-button>{{ $editingAccountId ? __('Update account') : __('Add account') }}</x-primary-button>

                    @if ($editingAccountId)
                        <x-secondary-button type="button" wire:click="cancelEdit">{{ __('Cancel') }}</x-secondary-button>
                    @endif
                </div>
            </form>
        </section>

        <section class="p-6 bg-white shadow-sm sm:rounded-lg">
            <header>
                <h3 class="text-lg font-medium text-gray-900">{{ __('Your accounts') }}</h3>
            </header>

            @if (session('status'))
                <p class="mt-4 text-sm font-medium text-green-600">{{ session('status') }}</p>
            @endif

            <div class="mt-6 space-y-4">
                @forelse ($this->accounts as $account)
                    <article wire:key="account-{{ $account->id }}" class="flex flex-col gap-4 border border-gray-200 rounded-md p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h4 class="font-medium text-gray-900">{{ $account->name }}</h4>
                            <p class="text-sm text-gray-600">
                                {{ $account->account_type }}
                                @if ($account->institution)
                                    · {{ $account->institution }}
                                @endif
                            </p>
                            <p class="mt-1 text-sm text-gray-900">{{ $account->currency }} {{ number_format((float) $account->starting_balance, 2) }}</p>
                        </div>

                        <div class="flex gap-2">
                            <x-secondary-button type="button" wire:click="editAccount({{ $account->id }})">{{ __('Edit') }}</x-secondary-button>
                            <x-danger-button type="button" wire:click="deleteAccount({{ $account->id }})" wire:confirm="{{ __('Delete this financial account?') }}">{{ __('Delete') }}</x-danger-button>
                        </div>
                    </article>
                @empty
                    <p class="text-sm text-gray-600">{{ __('No financial accounts yet.') }}</p>
                @endforelse
            </div>
        </section>
    </div>
</div>