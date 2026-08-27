<?php

use App\Models\Budget;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $preset = 'current_month';

    public string $start_date = '';

    public string $end_date = '';

    public function mount(): void
    {
        $this->setPresetDates();
    }

    public function updatedPreset(): void
    {
        if ($this->preset !== 'custom') {
            $this->setPresetDates();
        }
    }

    public function applyFilters(): void
    {
        $this->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);
    }

    public function getSummaryTotalsProperty()
    {
        [$startDate, $endDate] = $this->normalizedRange();

        return $this->transactionQuery($startDate, $endDate)
            ->select('financial_accounts.currency')
            ->selectRaw("SUM(CASE WHEN transactions.type = 'income' THEN transactions.amount ELSE 0 END) AS total_income")
            ->selectRaw("SUM(CASE WHEN transactions.type = 'expense' THEN transactions.amount ELSE 0 END) AS total_expenses")
            ->selectRaw("SUM(CASE WHEN transactions.type = 'income' THEN transactions.amount ELSE -transactions.amount END) AS net_cash_flow")
            ->groupBy('financial_accounts.currency')
            ->orderBy('financial_accounts.currency')
            ->get();
    }

    public function getMonthlyTrendsProperty()
    {
        [$startDate, $endDate] = $this->normalizedRange();
        $monthExpression = DB::getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', transactions.transaction_date)"
            : "DATE_FORMAT(transactions.transaction_date, '%Y-%m')";

        return $this->transactionQuery($startDate, $endDate)
            ->select('financial_accounts.currency')
            ->selectRaw("{$monthExpression} AS month")
            ->selectRaw("SUM(CASE WHEN transactions.type = 'income' THEN transactions.amount ELSE 0 END) AS total_income")
            ->selectRaw("SUM(CASE WHEN transactions.type = 'expense' THEN transactions.amount ELSE 0 END) AS total_expenses")
            ->selectRaw("SUM(CASE WHEN transactions.type = 'income' THEN transactions.amount ELSE -transactions.amount END) AS net_cash_flow")
            ->groupBy('month', 'financial_accounts.currency')
            ->orderByDesc('month')
            ->orderBy('financial_accounts.currency')
            ->get();
    }

    public function getExpenseBreakdownProperty()
    {
        return $this->categoryBreakdown('expense');
    }

    public function getIncomeBreakdownProperty()
    {
        return $this->categoryBreakdown('income');
    }

    public function getAccountActivityProperty()
    {
        [$startDate, $endDate] = $this->normalizedRange();
        $activity = $this->transactionQuery($startDate, $endDate)
            ->select('financial_accounts.id', 'financial_accounts.name', 'financial_accounts.institution', 'financial_accounts.account_type', 'financial_accounts.currency')
            ->selectRaw("SUM(CASE WHEN transactions.type = 'income' THEN transactions.amount ELSE 0 END) AS total_income")
            ->selectRaw("SUM(CASE WHEN transactions.type = 'expense' THEN transactions.amount ELSE 0 END) AS total_expenses")
            ->groupBy('financial_accounts.id', 'financial_accounts.name', 'financial_accounts.institution', 'financial_accounts.account_type', 'financial_accounts.currency')
            ->get()
            ->keyBy('id');

        return Auth::user()->financialAccounts()->orderBy('name')->get()->map(function ($account) use ($activity) {
            $row = $activity->get($account->id);
            $account->report_income = (float) ($row->total_income ?? 0);
            $account->report_expenses = (float) ($row->total_expenses ?? 0);
            $account->net_activity = $account->report_income - $account->report_expenses;

            return $account;
        });
    }

    public function getBudgetReportsProperty()
    {
        [$startDate, $endDate] = $this->normalizedRange();
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
            ->whereDate('start_date', '>=', $startDate)
            ->whereDate('end_date', '<=', $endDate)
            ->select('budgets.*')
            ->selectSub($spent, 'spent')
            ->orderByDesc('start_date')
            ->get();
    }

    public function getRecentTransactionsProperty()
    {
        [$startDate, $endDate] = $this->normalizedRange();

        return Auth::user()->transactions()
            ->with(['financialAccount', 'category'])
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->latest('transaction_date')
            ->latest('created_at')
            ->latest('id')
            ->limit(10)
            ->get();
    }

    private function categoryBreakdown(string $type)
    {
        [$startDate, $endDate] = $this->normalizedRange();
        $rows = $this->transactionQuery($startDate, $endDate)
            ->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')
            ->select('financial_accounts.currency')
            ->selectRaw("COALESCE(categories.name, 'Uncategorized') AS name")
            ->selectRaw('SUM(transactions.amount) AS total')
            ->where('transactions.type', $type)
            ->groupBy('categories.id', 'categories.name', 'financial_accounts.currency')
            ->orderByDesc('total')
            ->get();
        $totals = $rows->groupBy('currency')->map(fn ($items) => $items->sum(fn ($item) => (float) $item->total));

        return $rows->map(function ($row) use ($totals) {
            $row->percentage = $totals[$row->currency] > 0
                ? ((float) $row->total / $totals[$row->currency]) * 100
                : 0;

            return $row;
        });
    }

    private function transactionQuery(string $startDate, string $endDate)
    {
        return Auth::user()->transactions()
            ->join('financial_accounts', 'financial_accounts.id', '=', 'transactions.financial_account_id')
            ->where('financial_accounts.user_id', Auth::id())
            ->whereBetween('transactions.transaction_date', [$startDate, $endDate]);
    }

    private function normalizedRange(): array
    {
        try {
            $this->validate([
                'start_date' => ['required', 'date'],
                'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            ]);
        } catch (ValidationException) {
            return ['9999-01-01', '0001-01-01'];
        }

        return [
            Carbon::parse($this->start_date)->startOfDay()->toDateString(),
            Carbon::parse($this->end_date)->endOfDay()->toDateString(),
        ];
    }

    private function setPresetDates(): void
    {
        $today = Carbon::today();

        [$start, $end] = match ($this->preset) {
            'last_30_days' => [$today->copy()->subDays(29), $today],
            'last_3_months' => [$today->copy()->subMonths(2)->startOfMonth(), $today],
            'current_year' => [$today->copy()->startOfYear(), $today->copy()->endOfYear()],
            default => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
        };

        $this->start_date = $start->toDateString();
        $this->end_date = $end->toDateString();
    }
}; ?>

<x-slot name="header">
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Reports') }}</h2>
</x-slot>

<div class="py-12">
    <div class="max-w-7xl mx-auto space-y-6 sm:px-6 lg:px-8">
        <section class="bg-white p-6 shadow-sm sm:rounded-lg">
            <form wire:submit="applyFilters" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
                <div>
                    <x-input-label for="preset" :value="__('Date range')" />
                    <select wire:model.live="preset" id="preset" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                        <option value="current_month">{{ __('Current month') }}</option>
                        <option value="last_30_days">{{ __('Last 30 days') }}</option>
                        <option value="last_3_months">{{ __('Last 3 months') }}</option>
                        <option value="current_year">{{ __('Current year') }}</option>
                        <option value="custom">{{ __('Custom range') }}</option>
                    </select>
                </div>
                <div>
                    <x-input-label for="start_date" :value="__('Start date')" />
                    <x-text-input wire:model="start_date" id="start_date" class="mt-1 block w-full" type="date" required />
                    <x-input-error :messages="$errors->get('start_date')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="end_date" :value="__('End date')" />
                    <x-text-input wire:model="end_date" id="end_date" class="mt-1 block w-full" type="date" required />
                    <x-input-error :messages="$errors->get('end_date')" class="mt-2" />
                </div>
                <x-primary-button>{{ __('Apply filters') }}</x-primary-button>
            </form>
        </section>

        <section class="grid gap-6 sm:grid-cols-3">
            @forelse ($this->summaryTotals as $total)
                <article class="bg-white p-6 shadow-sm sm:rounded-lg sm:col-span-3">
                    <h3 class="text-lg font-medium text-gray-900">{{ $total->currency }}</h3>
                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div><p class="text-sm text-gray-600">{{ __('Income') }}</p><p class="text-xl font-semibold text-green-600">{{ number_format((float) $total->total_income, 2) }}</p></div>
                        <div><p class="text-sm text-gray-600">{{ __('Expenses') }}</p><p class="text-xl font-semibold text-gray-900">{{ number_format((float) $total->total_expenses, 2) }}</p></div>
                        <div><p class="text-sm text-gray-600">{{ __('Net cash flow') }}</p><p class="text-xl font-semibold {{ $total->net_cash_flow >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ number_format((float) $total->net_cash_flow, 2) }}</p></div>
                    </div>
                </article>
            @empty
                <article class="bg-white p-6 shadow-sm sm:rounded-lg sm:col-span-3"><p class="text-sm text-gray-600">{{ __('No report data for this date range.') }}</p></article>
            @endforelse
        </section>

        <section class="bg-white p-6 shadow-sm sm:rounded-lg">
            <h3 class="text-lg font-medium text-gray-900">{{ __('Monthly trend') }}</h3>
            <div class="mt-6 overflow-x-auto">
                @if ($this->monthlyTrends->isEmpty()) <p class="text-sm text-gray-600">{{ __('No monthly activity for this date range.') }}</p> @else
                    <table class="min-w-full text-left text-sm"><thead><tr class="border-b text-gray-600"><th class="pb-3">{{ __('Month') }}</th><th class="pb-3">{{ __('Currency') }}</th><th class="pb-3">{{ __('Income') }}</th><th class="pb-3">{{ __('Expenses') }}</th><th class="pb-3">{{ __('Net') }}</th></tr></thead><tbody>
                        @foreach ($this->monthlyTrends as $trend)<tr class="border-b border-gray-100"><td class="py-3">{{ $trend->month }}</td><td>{{ $trend->currency }}</td><td class="text-green-600">{{ number_format((float) $trend->total_income, 2) }}</td><td>{{ number_format((float) $trend->total_expenses, 2) }}</td><td>{{ number_format((float) $trend->net_cash_flow, 2) }}</td></tr>@endforeach
                    </tbody></table>
                @endif
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            @foreach ([['expenseBreakdown', 'Expense breakdown', 'No expense activity for this date range.'], ['incomeBreakdown', 'Income breakdown', 'No income activity for this date range.']] as [$property, $heading, $empty])
                <section class="bg-white p-6 shadow-sm sm:rounded-lg"><h3 class="text-lg font-medium text-gray-900">{{ __($heading) }}</h3><div class="mt-6 space-y-4">@forelse ($this->{$property} as $row)<div class="flex items-center justify-between border-b border-gray-100 pb-3"><div><p class="font-medium text-gray-900">{{ $row->name }}</p><p class="text-sm text-gray-600">{{ $row->currency }} · {{ number_format((float) $row->percentage, 1) }}%</p></div><p class="text-gray-900">{{ number_format((float) $row->total, 2) }}</p></div>@empty<p class="text-sm text-gray-600">{{ __($empty) }}</p>@endforelse</div></section>
            @endforeach
        </div>

        <section class="bg-white p-6 shadow-sm sm:rounded-lg"><h3 class="text-lg font-medium text-gray-900">{{ __('Account activity') }}</h3><div class="mt-6 space-y-4">@forelse ($this->accountActivity as $account)<article class="border-b border-gray-100 pb-4"><div class="flex flex-col gap-2 sm:flex-row sm:justify-between"><div><p class="font-medium text-gray-900">{{ $account->name }}</p><p class="text-sm text-gray-600">{{ $account->account_type }} @if ($account->institution) · {{ $account->institution }} @endif · {{ $account->currency }}</p></div><p class="font-medium text-gray-900">{{ number_format($account->net_activity, 2) }} {{ __('net') }}</p></div><p class="mt-2 text-sm text-gray-600">{{ __('Income') }} {{ number_format($account->report_income, 2) }} · {{ __('Expenses') }} {{ number_format($account->report_expenses, 2) }}</p></article>@empty<p class="text-sm text-gray-600">{{ __('No financial accounts yet.') }}</p>@endforelse</div></section>

        <section class="bg-white p-6 shadow-sm sm:rounded-lg"><h3 class="text-lg font-medium text-gray-900">{{ __('Budget vs actual') }}</h3><div class="mt-6 space-y-4">@forelse ($this->budgetReports as $budget)<article class="border-b border-gray-100 pb-4"><div class="flex flex-col gap-2 sm:flex-row sm:justify-between"><div><p class="font-medium text-gray-900">{{ $budget->category->name }}</p><p class="text-sm text-gray-600">{{ $budget->currency }} · {{ $budget->start_date->format('M j, Y') }} - {{ $budget->end_date->format('M j, Y') }}</p></div><p class="font-medium {{ $budget->status === 'Over budget' ? 'text-red-600' : ($budget->status === 'Near limit' ? 'text-yellow-600' : 'text-green-600') }}">{{ $budget->status }}</p></div><p class="mt-2 text-sm text-gray-600">{{ __('Budget') }} {{ number_format((float) $budget->amount, 2) }} · {{ __('Spent') }} {{ number_format((float) ($budget->spent ?? 0), 2) }} · {{ __('Remaining') }} {{ number_format($budget->remaining, 2) }} · {{ number_format($budget->usage_percentage, 1) }}%</p></article>@empty<p class="text-sm text-gray-600">{{ __('No budgets overlap this date range.') }}</p>@endforelse</div></section>

        <section class="bg-white p-6 shadow-sm sm:rounded-lg"><h3 class="text-lg font-medium text-gray-900">{{ __('Recent transactions') }}</h3><div class="mt-6 space-y-4">@forelse ($this->recentTransactions as $transaction)<article class="flex flex-col gap-2 border-b border-gray-100 pb-4 sm:flex-row sm:items-center sm:justify-between"><div><p class="font-medium text-gray-900">{{ $transaction->description ?: ucfirst($transaction->type) }}</p><p class="text-sm text-gray-600">{{ ucfirst($transaction->type) }} · {{ $transaction->financialAccount->name }} @if ($transaction->category) · {{ $transaction->category->name }} @endif · {{ $transaction->transaction_date->format('M j, Y') }}</p></div><p class="{{ $transaction->type === 'income' ? 'text-green-600' : 'text-gray-900' }}">{{ $transaction->type === 'income' ? '+' : '-' }}{{ $transaction->financialAccount->currency }} {{ number_format((float) $transaction->amount, 2) }}</p></article>@empty<p class="text-sm text-gray-600">{{ __('No transactions for this date range.') }}</p>@endforelse</div></section>
    </div>
</div>