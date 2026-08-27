<?php

use App\Models\FinancialAccount;
use App\Models\Transaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $financial_account_id = null;
    public string $type = 'expense';
    public string $description = '';
    public string $amount = '';
    public string $transaction_date = '';
    public ?int $editingTransactionId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Transaction::class);
        $this->transaction_date = now()->toDateString();
    }

    public function getTransactionsProperty()
    {
        return Auth::user()->transactions()->with('financialAccount')->latest('transaction_date')->latest()->get();
    }

    public function getFinancialAccountsProperty()
    {
        return Auth::user()->financialAccounts()->orderBy('name')->get();
    }

    public function saveTransaction(): void
    {
        $this->authorize('create', Transaction::class);

        $validated = $this->validate($this->rules());

        Auth::user()->transactions()->create($validated);

        $this->resetForm();
        session()->flash('status', 'Transaction created.');
    }

    public function editTransaction(int $transactionId): void
    {
        $transaction = Transaction::findOrFail($transactionId);
        $this->authorize('update', $transaction);

        $this->editingTransactionId = $transaction->id;
        $this->financial_account_id = $transaction->financial_account_id;
        $this->type = $transaction->type;
        $this->description = $transaction->description ?? '';
        $this->amount = (string) $transaction->amount;
        $this->transaction_date = $transaction->transaction_date->toDateString();
    }

    public function updateTransaction(): void
    {
        $transaction = Transaction::findOrFail($this->editingTransactionId);
        $this->authorize('update', $transaction);

        $transaction->update($this->validate($this->rules()));

        $this->resetForm();
        session()->flash('status', 'Transaction updated.');
    }

    public function deleteTransaction(int $transactionId): void
    {
        $transaction = Transaction::findOrFail($transactionId);
        $this->authorize('delete', $transaction);

        $transaction->delete();

        if ($this->editingTransactionId === $transactionId) {
            $this->resetForm();
        }

        session()->flash('status', 'Transaction deleted.');
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    private function rules(): array
    {
        return [
            'financial_account_id' => [
                'required',
                'integer',
                Rule::exists('financial_accounts', 'id')
                    ->where(fn (Builder $query) => $query->where('user_id', Auth::id())),
            ],
            'type' => ['required', Rule::in(Transaction::TYPES)],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'transaction_date' => ['required', 'date'],
        ];
    }

    private function resetForm(): void
    {
        $this->reset(['financial_account_id', 'description', 'amount', 'editingTransactionId']);
        $this->type = 'expense';
        $this->transaction_date = now()->toDateString();
    }
}; ?>

<x-slot name="header">
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        {{ __('Transactions') }}
    </h2>
</x-slot>

<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <section class="p-6 bg-white shadow-sm sm:rounded-lg">
            <header>
                <h3 class="text-lg font-medium text-gray-900">
                    {{ $editingTransactionId ? __('Edit transaction') : __('Add transaction') }}
                </h3>
            </header>

            <form wire:submit="{{ $editingTransactionId ? 'updateTransaction' : 'saveTransaction' }}" class="mt-6 space-y-6">
                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <x-input-label for="financial_account_id" :value="__('Financial account')" />
                        <select wire:model="financial_account_id" id="financial_account_id" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" required>
                            <option value="">{{ __('Select an account') }}</option>
                            @foreach ($this->financialAccounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('financial_account_id')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="type" :value="__('Type')" />
                        <select wire:model="type" id="type" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" required>
                            @foreach (\App\Models\Transaction::TYPES as $transactionType)
                                <option value="{{ $transactionType }}">{{ ucfirst($transactionType) }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('type')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="description" :value="__('Description (optional)')" />
                        <x-text-input wire:model="description" id="description" class="mt-1 block w-full" type="text" maxlength="255" />
                        <x-input-error :messages="$errors->get('description')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="amount" :value="__('Amount')" />
                        <x-text-input wire:model="amount" id="amount" class="mt-1 block w-full" type="number" min="0.01" step="0.01" required />
                        <x-input-error :messages="$errors->get('amount')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="transaction_date" :value="__('Date')" />
                        <x-text-input wire:model="transaction_date" id="transaction_date" class="mt-1 block w-full" type="date" required />
                        <x-input-error :messages="$errors->get('transaction_date')" class="mt-2" />
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <x-primary-button>{{ $editingTransactionId ? __('Update transaction') : __('Add transaction') }}</x-primary-button>

                    @if ($editingTransactionId)
                        <x-secondary-button type="button" wire:click="cancelEdit">{{ __('Cancel') }}</x-secondary-button>
                    @endif
                </div>
            </form>
        </section>

        <section class="p-6 bg-white shadow-sm sm:rounded-lg">
            <header>
                <h3 class="text-lg font-medium text-gray-900">{{ __('Your transactions') }}</h3>
            </header>

            @if (session('status'))
                <p class="mt-4 text-sm font-medium text-green-600">{{ session('status') }}</p>
            @endif

            <div class="mt-6 space-y-4">
                @forelse ($this->transactions as $transaction)
                    <article wire:key="transaction-{{ $transaction->id }}" class="flex flex-col gap-4 border border-gray-200 rounded-md p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h4 class="font-medium text-gray-900">{{ $transaction->description ?: ucfirst($transaction->type) }}</h4>
                            <p class="text-sm text-gray-600">
                                {{ $transaction->financialAccount->name }} · {{ $transaction->transaction_date->format('M j, Y') }}
                            </p>
                            <p class="mt-1 text-sm {{ $transaction->type === 'income' ? 'text-green-600' : 'text-gray-900' }}">
                                {{ $transaction->type === 'income' ? '+' : '-' }}{{ $transaction->financialAccount->currency }} {{ number_format((float) $transaction->amount, 2) }}
                            </p>
                        </div>

                        <div class="flex gap-2">
                            <x-secondary-button type="button" wire:click="editTransaction({{ $transaction->id }})">{{ __('Edit') }}</x-secondary-button>
                            <x-danger-button type="button" wire:click="deleteTransaction({{ $transaction->id }})" wire:confirm="{{ __('Delete this transaction?') }}">{{ __('Delete') }}</x-danger-button>
                        </div>
                    </article>
                @empty
                    <p class="text-sm text-gray-600">{{ __('No transactions yet.') }}</p>
                @endforelse
            </div>
        </section>
    </div>
</div>