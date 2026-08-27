<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Policies\CategoryPolicy;
use App\Policies\FinancialAccountPolicy;
use App\Policies\TransactionPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(FinancialAccount::class, FinancialAccountPolicy::class);
        Gate::policy(Transaction::class, TransactionPolicy::class);
    }
}
