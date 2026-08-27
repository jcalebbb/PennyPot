<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $category_id = null;
    public string $amount = '';
    public string $month = '';
    public string $currency = 'PHP';
    public ?int $editingBudgetId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Budget::class);
        $this->month = now()->format('Y-m');
    }

    public function getBudgetsProperty()
    {
        $spent = Transaction::query()
            ->join('financial_accounts', 'financial_accounts.id', '=', 'transactions.financial_account_id')
            ->selectRaw('COALESCE(SUM(transactions.amount), 0)')
            ->whereColumn('transactions.category_id', 'budgets.category_id')
            ->whereColumn('transactions.transaction_date', '>=', 'budgets.start_date')
            ->whereColumn('transactions.transaction_date', '<=', 'budgets.end_date')
            ->whereColumn('financial_accounts.currency', 'budgets.currency')
            ->where('transactions.user_id', Auth::id())
            ->where('financial_accounts.user_id', Auth::id())
            ->where('transactions.type', 'expense');

        return Auth::user()->budgets()
            ->with('category')
            ->select('budgets.*')
            ->selectSub($spent, 'spent')
            ->orderByDesc('start_date')
            ->orderBy('category_id')
            ->get();
    }

    public function getCategoriesProperty()
    {
        return Auth::user()->categories()->where('type', 'expense')->orderBy('name')->get();
    }

    public function getCurrenciesProperty(): array
    {
        return Auth::user()->financialAccounts()->distinct()->orderBy('currency')->pluck('currency')->all();
    }

    public function saveBudget(): void
    {
        $this->authorize('create', Budget::class);
        $validated = $this->validate($this->rules());
        [$startDate, $endDate] = $this->periodDates();

        if ($this->overlappingBudgetExists($startDate, $endDate)) {
            $this->addError('month', 'A budget already overlaps this period for the selected category and currency.');

            return;
        }

        Auth::user()->budgets()->create([
            'category_id' => $validated['category_id'],
            'amount' => $validated['amount'],
            'start_date' => $startDate,
            'end_date' => $endDate,
            'currency' => $validated['currency'],
        ]);

        $this->resetForm();
        session()->flash('status', 'Budget created.');
    }

    public function editBudget(int $budgetId): void
    {
        $budget = Budget::findOrFail($budgetId);
        $this->authorize('update', $budget);

        $this->editingBudgetId = $budget->id;
        $this->category_id = $budget->category_id;
        $this->amount = (string) $budget->amount;
        $this->month = $budget->start_date->format('Y-m');
        $this->currency = $budget->currency;
    }

    public function updateBudget(): void
    {
        $budget = Budget::findOrFail($this->editingBudgetId);
        $this->authorize('update', $budget);
        $validated = $this->validate($this->rules());
        [$startDate, $endDate] = $this->periodDates();

        if ($this->overlappingBudgetExists($startDate, $endDate, $budget->id)) {
            $this->addError('month', 'A budget already overlaps this period for the selected category and currency.');

            return;
        }

        $budget->update([
            'category_id' => $validated['category_id'],
            'amount' => $validated['amount'],
            'start_date' => $startDate,
            'end_date' => $endDate,
            'currency' => $validated['currency'],
        ]);

        $this->resetForm();
        session()->flash('status', 'Budget updated.');
    }

    public function deleteBudget(int $budgetId): void
    {
        $budget = Budget::findOrFail($budgetId);
        $this->authorize('delete', $budget);
        $budget->delete();

        if ($this->editingBudgetId === $budgetId) {
            $this->resetForm();
        }

        session()->flash('status', 'Budget deleted.');
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    private function rules(): array
    {
        return [
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')
                    ->where(fn (Builder $query) => $query
                        ->where('user_id', Auth::id())
                        ->where('type', 'expense')),
            ],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'month' => ['required', 'date_format:Y-m'],
            'currency' => ['required', 'string', 'size:3', 'uppercase', Rule::in($this->currencies)],
        ];
    }

    private function periodDates(): array
    {
        $startDate = Carbon::createFromFormat('!Y-m', $this->month)->startOfMonth();

        return [$startDate->toDateString(), $startDate->copy()->endOfMonth()->toDateString()];
    }

    private function overlappingBudgetExists(string $startDate, string $endDate, ?int $ignoreId = null): bool
    {
        return Auth::user()->budgets()
            ->where('category_id', $this->category_id)
            ->where('currency', $this->currency)
            ->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $startDate)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    private function resetForm(): void
    {
        $this->reset(['category_id', 'amount', 'editingBudgetId']);
        $this->month = now()->format('Y-m');
        $this->currency = 'PHP';
    }
}; ?>

<x-slot name="header">
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        {{ __('Budgets') }}
    </h2>
</x-slot>

<div class="py-12">
    <div class="max-w-7xl mx-auto space-y-6 sm:px-6 lg:px-8">
        <section class="p-6 bg-white shadow-sm sm:rounded-lg">
            <header>
                <h3 class="text-lg font-medium text-gray-900">
                    {{ $editingBudgetId ? __('Edit budget') : __('Add budget') }}
                </h3>
            </header>

            <form wire:submit="{{ $editingBudgetId ? 'updateBudget' : 'saveBudget' }}" class="mt-6 space-y-6">
                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <x-input-label for="category_id" :value="__('Expense category')" />
                        <select wire:model="category_id" id="category_id" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" required>
                            <option value="">{{ __('Select a category') }}</option>
                            @foreach ($this->categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('category_id')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="currency" :value="__('Currency')" />
                        <select wire:model="currency" id="currency" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" required>
                            @foreach ($this->currencies as $availableCurrency)
                                <option value="{{ $availableCurrency }}">{{ $availableCurrency }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('currency')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="month" :value="__('Month')" />
                        <x-text-input wire:model="month" id="month" class="mt-1 block w-full" type="month" required />
                        <x-input-error :messages="$errors->get('month')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="amount" :value="__('Budget amount')" />
                        <x-text-input wire:model="amount" id="amount" class="mt-1 block w-full" type="number" min="0.01" step="0.01" required />
                        <x-input-error :messages="$errors->get('amount')" class="mt-2" />
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <x-primary-button>{{ $editingBudgetId ? __('Update budget') : __('Add budget') }}</x-primary-button>
                    @if ($editingBudgetId)
                        <x-secondary-button type="button" wire:click="cancelEdit">{{ __('Cancel') }}</x-secondary-button>
                    @endif
                </div>
            </form>
        </section>

        <section class="p-6 bg-white shadow-sm sm:rounded-lg">
            <header>
                <h3 class="text-lg font-medium text-gray-900">{{ __('Your budgets') }}</h3>
            </header>

            @if (session('status'))
                <p class="mt-4 text-sm font-medium text-green-600">{{ session('status') }}</p>
            @endif

            <div class="mt-6 space-y-4">
                @forelse ($this->budgets as $budget)
                    <article wire:key="budget-{{ $budget->id }}" class="border border-gray-200 rounded-md p-4">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h4 class="font-medium text-gray-900">{{ $budget->category->name }}</h4>
                                <p class="text-sm text-gray-600">{{ $budget->currency }} · {{ $budget->start_date->format('F Y') }}</p>
                            </div>
                            <span class="text-sm font-medium {{ $budget->status === 'Over budget' ? 'text-red-600' : ($budget->status === 'Near limit' ? 'text-yellow-600' : 'text-green-600') }}">{{ $budget->status }}</span>
                        </div>

                        <div class="mt-4 grid gap-4 sm:grid-cols-4">
                            <div><p class="text-xs text-gray-500">{{ __('Budget') }}</p><p class="font-medium text-gray-900">{{ number_format((float) $budget->amount, 2) }}</p></div>
                            <div><p class="text-xs text-gray-500">{{ __('Spent') }}</p><p class="font-medium text-gray-900">{{ number_format((float) ($budget->spent ?? 0), 2) }}</p></div>
                            <div><p class="text-xs text-gray-500">{{ __('Remaining') }}</p><p class="font-medium {{ $budget->remaining < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($budget->remaining, 2) }}</p></div>
                            <div><p class="text-xs text-gray-500">{{ __('Used') }}</p><p class="font-medium text-gray-900">{{ number_format($budget->usage_percentage, 1) }}%</p></div>
                        </div>

                        <div class="mt-4 flex gap-2">
                            <x-secondary-button type="button" wire:click="editBudget({{ $budget->id }})">{{ __('Edit') }}</x-secondary-button>
                            <x-danger-button type="button" wire:click="deleteBudget({{ $budget->id }})" wire:confirm="{{ __('Delete this budget?') }}">{{ __('Delete') }}</x-danger-button>
                        </div>
                    </article>
                @empty
                    <p class="text-sm text-gray-600">{{ __('No budgets yet.') }}</p>
                @endforelse
            </div>
        </section>
    </div>
</div>