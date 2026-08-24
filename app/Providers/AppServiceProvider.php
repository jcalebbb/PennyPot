<?php

namespace App\Providers;

use App\Models\FinancialAccount;
use App\Policies\FinancialAccountPolicy;
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
        Gate::policy(FinancialAccount::class, FinancialAccountPolicy::class);
    }
}
