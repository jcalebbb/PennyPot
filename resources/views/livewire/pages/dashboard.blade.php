<?php

use App\Models\FinancialAccount;
use App\Models\Transaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', FinancialAccount::class);
    }

    public function getAccountBalancesProperty()
    {
        $userId = Auth::id();
        $income = Transaction::query()
            ->selectRaw('COALESCE(SUM(amount), 0)')
            ->whereColumn('financial_account_id', 'financial_accounts.id')
            ->where('user_id', $userId)
            ->where('type', 'income');
        $expenses = Transaction::query()
            ->selectRaw('COALESCE(SUM(amount), 0)')
            ->whereColumn('financial_account_id', 'financial_accounts.id')
            ->where('user_id', $userId)
            ->where('type', 'expense');

        return Auth::user()->financialAccounts()
            ->select('financial_accounts.*')
            ->selectSub($income, 'total_income')
            ->selectSub($expenses, 'total_expenses')
            ->selectRaw('starting_balance + (('.$income->toSql().') - ('.$expenses->toSql().')) AS current_balance')
            ->addBinding(array_merge($income->getBindings(), $expenses->getBindings()), 'select')
            ->orderBy('name')
            ->get();
    }

    public function getBalanceTotalsProperty()
    {
        return $this->accountBalances
            ->groupBy('currency')
            ->map(fn ($accounts, $currency) => [
                'currency' => $currency,
                'amount' => $accounts->sum(fn ($account) => (float) $account->current_balance),
            ]);
    }

    public function getCashFlowTotalsProperty()
    {
        return Auth::user()->transactions()
            ->join('financial_accounts', 'financial_accounts.id', '=', 'transactions.financial_account_id')
            ->select('financial_accounts.currency')
            ->selectRaw("SUM(CASE WHEN transactions.type = 'income' THEN transactions.amount ELSE 0 END) AS total_income")
            ->selectRaw("SUM(CASE WHEN transactions.type = 'expense' THEN transactions.amount ELSE 0 END) AS total_expenses")
            ->selectRaw("SUM(CASE WHEN transactions.type = 'income' THEN transactions.amount ELSE -transactions.amount END) AS net_cash_flow")
            ->groupBy('financial_accounts.currency')
            ->orderBy('financial_accounts.currency')
            ->get();
    }

    public function getRecentTransactionsProperty()
    {
        return Auth::user()->transactions()
            ->with(['financialAccount', 'category'])
            ->latest('transaction_date')
            ->latest('created_at')
            ->latest('id')
            ->limit(5)
            ->get();
    }

    public function getExpenseCategoriesProperty()
    {
        return Auth::user()->transactions()
            ->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')
            ->join('financial_accounts', 'financial_accounts.id', '=', 'transactions.financial_account_id')
            ->select('financial_accounts.currency')
            ->where('transactions.type', 'expense')
            ->selectRaw("COALESCE(categories.name, 'Uncategorized') AS name")
            ->selectRaw('SUM(transactions.amount) AS total')
            ->groupBy('categories.id', 'categories.name', 'financial_accounts.currency')
            ->orderByDesc('total')
            ->get();
    }
}; ?>

<x-slot name="header">
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        {{ __('Dashboard') }}
    </h2>
</x-slot>

<div class="py-12">
    <div class="max-w-7xl mx-auto space-y-6 sm:px-6 lg:px-8">
        <section class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            @forelse ($this->balanceTotals as $total)
                <article class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <p class="text-sm text-gray-600">{{ $total['currency'] }} {{ __('total balance') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format($total['amount'], 2) }}</p>
                </article>
            @empty
                <article class="bg-white p-6 shadow-sm sm:rounded-lg sm:col-span-2 lg:col-span-4">
                    <p class="text-sm text-gray-600">{{ __('No financial accounts yet.') }}</p>
                </article>
            @endforelse

            @foreach ($this->cashFlowTotals as $total)
                <article class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <p class="text-sm text-gray-600">{{ $total->currency }} {{ __('income') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-green-600">{{ number_format((float) $total->total_income, 2) }}</p>
                </article>
                <article class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <p class="text-sm text-gray-600">{{ $total->currency }} {{ __('expenses') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format((float) $total->total_expenses, 2) }}</p>
                </article>
                <article class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <p class="text-sm text-gray-600">{{ $total->currency }} {{ __('net cash flow') }}</p>
                    <p class="mt-2 text-2xl font-semibold {{ $total->net_cash_flow >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ number_format((float) $total->net_cash_flow, 2) }}</p>
                </article>
            @endforeach
        </section>

        <section class="bg-white p-6 shadow-sm sm:rounded-lg">
            <h3 class="text-lg font-medium text-gray-900">{{ __('Accounts') }}</h3>
            <div class="mt-6 space-y-4">
                @forelse ($this->accountBalances as $account)
                    <article class="flex flex-col gap-2 border border-gray-200 rounded-md p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h4 class="font-medium text-gray-900">{{ $account->name }}</h4>
                            <p class="text-sm text-gray-600">{{ $account->account_type }} @if ($account->institution) · {{ $account->institution }} @endif</p>
                        </div>
                        <p class="font-medium text-gray-900">{{ $account->currency }} {{ number_format((float) $account->current_balance, 2) }}</p>
                    </article>
                @empty
                    <p class="text-sm text-gray-600">{{ __('Add a financial account to see your balances.') }}</p>
                @endforelse
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="bg-white p-6 shadow-sm sm:rounded-lg">
                <h3 class="text-lg font-medium text-gray-900">{{ __('Recent transactions') }}</h3>
                <div class="mt-6 space-y-4">
                    @forelse ($this->recentTransactions as $transaction)
                        <article class="flex items-center justify-between border-b border-gray-100 pb-4">
                            <div>
                                <p class="font-medium text-gray-900">{{ $transaction->description ?: ucfirst($transaction->type) }}</p>
                                <p class="text-sm text-gray-600">{{ $transaction->financialAccount->name }} @if ($transaction->category) · {{ $transaction->category->name }} @endif · {{ $transaction->transaction_date->format('M j, Y') }}</p>
                            </div>
                            <p class="text-sm {{ $transaction->type === 'income' ? 'text-green-600' : 'text-gray-900' }}">{{ $transaction->type === 'income' ? '+' : '-' }}{{ $transaction->financialAccount->currency }} {{ number_format((float) $transaction->amount, 2) }}</p>
                        </article>
                    @empty
                        <p class="text-sm text-gray-600">{{ __('No transactions yet.') }}</p>
                    @endforelse
                </div>
            </section>

            <section class="bg-white p-6 shadow-sm sm:rounded-lg">
                <h3 class="text-lg font-medium text-gray-900">{{ __('Expense categories') }}</h3>
                <div class="mt-6 space-y-4">
                    @forelse ($this->expenseCategories as $category)
                        <div class="flex items-center justify-between border-b border-gray-100 pb-4">
                            <span class="text-gray-900">{{ $category->name }}</span>
                            <span class="text-gray-700">{{ $category->currency }} {{ number_format((float) $category->total, 2) }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-gray-600">{{ __('No expense transactions yet.') }}</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
</div>