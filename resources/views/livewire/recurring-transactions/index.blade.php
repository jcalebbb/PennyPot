<?php

use App\Models\Category;
use App\Models\RecurringTransaction;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $financial_account_id = null;
    public ?int $category_id = null;
    public string $type = 'expense';
    public string $description = '';
    public string $amount = '';
    public string $frequency = 'monthly';
    public string $start_date = '';
    public string $end_date = '';
    public ?int $editingRecurringTransactionId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', RecurringTransaction::class);
        $this->start_date = now()->toDateString();
    }

    public function getRecurringTransactionsProperty()
    {
        return Auth::user()->recurringTransactions()
            ->with(['financialAccount', 'category'])
            ->orderByDesc('is_active')
            ->orderBy('next_occurrence')
            ->get();
    }

    public function getFinancialAccountsProperty()
    {
        return Auth::user()->financialAccounts()->orderBy('name')->get();
    }

    public function getCategoriesProperty()
    {
        return Auth::user()->categories()->where('type', $this->type)->orderBy('name')->get();
    }

    public function updatedType(): void
    {
        if ($this->category_id && ! Auth::user()->categories()->whereKey($this->category_id)->where('type', $this->type)->exists()) {
            $this->category_id = null;
        }
    }

    public function saveRecurringTransaction(): void
    {
        $this->authorize('create', RecurringTransaction::class);
        $validated = $this->validate($this->rules());

        Auth::user()->recurringTransactions()->create([
            ...$validated,
            'next_occurrence' => $validated['start_date'],
            'is_active' => true,
        ]);

        $this->resetForm();
        session()->flash('status', 'Recurring transaction created.');
    }

    public function editRecurringTransaction(int $recurringTransactionId): void
    {
        $recurring = RecurringTransaction::findOrFail($recurringTransactionId);
        $this->authorize('update', $recurring);

        $this->editingRecurringTransactionId = $recurring->id;
        $this->financial_account_id = $recurring->financial_account_id;
        $this->category_id = $recurring->category_id;
        $this->type = $recurring->type;
        $this->description = $recurring->description ?? '';
        $this->amount = (string) $recurring->amount;
        $this->frequency = $recurring->frequency;
        $this->start_date = $recurring->start_date->toDateString();
        $this->end_date = $recurring->end_date?->toDateString() ?? '';
    }

    public function updateRecurringTransaction(): void
    {
        $recurring = RecurringTransaction::findOrFail($this->editingRecurringTransactionId);
        $this->authorize('update', $recurring);
        $validated = $this->validate($this->rules());

        $nextOccurrence = $recurring->next_occurrence;
        if ($recurring->frequency !== $validated['frequency'] || $recurring->start_date->toDateString() !== $validated['start_date']) {
            $nextOccurrence = Carbon::parse($validated['start_date']);
        }

        $recurring->update([
            ...$validated,
            'next_occurrence' => $nextOccurrence->toDateString(),
        ]);

        $this->resetForm();
        session()->flash('status', 'Recurring transaction updated.');
    }

    public function toggleActive(int $recurringTransactionId): void
    {
        $recurring = RecurringTransaction::findOrFail($recurringTransactionId);
        $this->authorize('update', $recurring);

        $recurring->update(['is_active' => ! $recurring->is_active]);
        session()->flash('status', $recurring->is_active ? 'Recurring transaction resumed.' : 'Recurring transaction paused.');
    }

    public function deleteRecurringTransaction(int $recurringTransactionId): void
    {
        $recurring = RecurringTransaction::findOrFail($recurringTransactionId);
        $this->authorize('delete', $recurring);
        $recurring->delete();

        if ($this->editingRecurringTransactionId === $recurringTransactionId) {
            $this->resetForm();
        }

        session()->flash('status', 'Recurring transaction deleted.');
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
                Rule::exists('financial_accounts', 'id')->where(fn (Builder $query) => $query->where('user_id', Auth::id())),
            ],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where(fn (Builder $query) => $query->where('user_id', Auth::id())->where('type', $this->type)),
            ],
            'type' => ['required', Rule::in(['income', 'expense'])],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'frequency' => ['required', Rule::in(RecurringTransaction::FREQUENCIES)],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }

    private function resetForm(): void
    {
        $this->reset(['financial_account_id', 'category_id', 'description', 'amount', 'end_date', 'editingRecurringTransactionId']);
        $this->type = 'expense';
        $this->frequency = 'monthly';
        $this->start_date = now()->toDateString();
    }
}; ?>

<x-slot name="header">
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Recurring Transactions') }}</h2>
</x-slot>

<div class="py-12">
    <div class="max-w-7xl mx-auto space-y-6 sm:px-6 lg:px-8">
        <section class="p-6 bg-white shadow-sm sm:rounded-lg">
            <header><h3 class="text-lg font-medium text-gray-900">{{ $editingRecurringTransactionId ? __('Edit recurring transaction') : __('Add recurring transaction') }}</h3></header>
            <form wire:submit="{{ $editingRecurringTransactionId ? 'updateRecurringTransaction' : 'saveRecurringTransaction' }}" class="mt-6 space-y-6">
                <div class="grid gap-6 sm:grid-cols-2">
                    <div><x-input-label for="financial_account_id" :value="__('Financial account')" /><select wire:model="financial_account_id" id="financial_account_id" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" required><option value="">{{ __('Select an account') }}</option>@foreach ($this->financialAccounts as $account)<option value="{{ $account->id }}">{{ $account->name }} ({{ $account->currency }})</option>@endforeach</select><x-input-error :messages="$errors->get('financial_account_id')" class="mt-2" /></div>
                    <div><x-input-label for="type" :value="__('Type')" /><select wire:model.live="type" id="type" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" required><option value="expense">{{ __('Expense') }}</option><option value="income">{{ __('Income') }}</option></select><x-input-error :messages="$errors->get('type')" class="mt-2" /></div>
                    <div><x-input-label for="category_id" :value="__('Category (optional)')" /><select wire:model="category_id" id="category_id" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"><option value="">{{ __('No category') }}</option>@foreach ($this->categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select><x-input-error :messages="$errors->get('category_id')" class="mt-2" /></div>
                    <div><x-input-label for="frequency" :value="__('Frequency')" /><select wire:model="frequency" id="frequency" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" required>@foreach (\App\Models\RecurringTransaction::FREQUENCIES as $option)<option value="{{ $option }}">{{ ucfirst($option) }}</option>@endforeach</select><x-input-error :messages="$errors->get('frequency')" class="mt-2" /></div>
                    <div><x-input-label for="description" :value="__('Description (optional)')" /><x-text-input wire:model="description" id="description" class="mt-1 block w-full" type="text" maxlength="255" /><x-input-error :messages="$errors->get('description')" class="mt-2" /></div>
                    <div><x-input-label for="amount" :value="__('Amount')" /><x-text-input wire:model="amount" id="amount" class="mt-1 block w-full" type="number" min="0.01" step="0.01" required /><x-input-error :messages="$errors->get('amount')" class="mt-2" /></div>
                    <div><x-input-label for="start_date" :value="__('Start date')" /><x-text-input wire:model="start_date" id="start_date" class="mt-1 block w-full" type="date" required /><x-input-error :messages="$errors->get('start_date')" class="mt-2" /></div>
                    <div><x-input-label for="end_date" :value="__('End date (optional)')" /><x-text-input wire:model="end_date" id="end_date" class="mt-1 block w-full" type="date" /><x-input-error :messages="$errors->get('end_date')" class="mt-2" /></div>
                </div>
                <div class="flex items-center gap-4"><x-primary-button>{{ $editingRecurringTransactionId ? __('Update recurring transaction') : __('Add recurring transaction') }}</x-primary-button>@if ($editingRecurringTransactionId)<x-secondary-button type="button" wire:click="cancelEdit">{{ __('Cancel') }}</x-secondary-button>@endif</div>
            </form>
        </section>

        <section class="p-6 bg-white shadow-sm sm:rounded-lg">
            <header><h3 class="text-lg font-medium text-gray-900">{{ __('Your recurring transactions') }}</h3></header>
            @if (session('status'))<p class="mt-4 text-sm font-medium text-green-600">{{ session('status') }}</p>@endif
            <div class="mt-6 space-y-4">
                @forelse ($this->recurringTransactions as $recurring)
                    <article wire:key="recurring-{{ $recurring->id }}" class="border border-gray-200 rounded-md p-4">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div><h4 class="font-medium text-gray-900">{{ $recurring->description ?: ucfirst($recurring->type) }}</h4><p class="text-sm text-gray-600">{{ ucfirst($recurring->type) }} · {{ ucfirst($recurring->frequency) }} · {{ $recurring->financialAccount->name }} @if ($recurring->category) · {{ $recurring->category->name }} @endif</p><p class="mt-1 text-sm text-gray-900">{{ $recurring->financialAccount->currency }} {{ number_format((float) $recurring->amount, 2) }} · {{ __('Next') }} {{ $recurring->next_occurrence->format('M j, Y') }} @if ($recurring->end_date) · {{ __('Until') }} {{ $recurring->end_date->format('M j, Y') }} @endif</p></div><span class="text-sm font-medium {{ $recurring->is_active ? 'text-green-600' : 'text-gray-500' }}">{{ $recurring->is_active ? __('Active') : __('Inactive') }}</span></div>
                        <div class="mt-4 flex flex-wrap gap-2"><x-secondary-button type="button" wire:click="editRecurringTransaction({{ $recurring->id }})">{{ __('Edit') }}</x-secondary-button><x-secondary-button type="button" wire:click="toggleActive({{ $recurring->id }})">{{ $recurring->is_active ? __('Pause') : __('Resume') }}</x-secondary-button><x-danger-button type="button" wire:click="deleteRecurringTransaction({{ $recurring->id }})" wire:confirm="{{ __('Delete this recurring transaction? Generated transactions will remain.') }}">{{ __('Delete') }}</x-danger-button></div>
                    </article>
                @empty
                    <p class="text-sm text-gray-600">{{ __('No recurring transactions yet.') }}</p>
                @endforelse
            </div>
        </section>
    </div>
</div>