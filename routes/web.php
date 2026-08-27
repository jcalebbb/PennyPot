<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::view('/', 'welcome');

Volt::route('dashboard', 'pages.dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Volt::route('financial-accounts', 'financial-accounts.index')
    ->middleware(['auth'])
    ->name('financial-accounts.index');

Volt::route('transactions', 'transactions.index')
    ->middleware(['auth'])
    ->name('transactions.index');

Volt::route('categories', 'categories.index')
    ->middleware(['auth'])
    ->name('categories.index');

Volt::route('budgets', 'budgets.index')
    ->middleware(['auth'])
    ->name('budgets.index');

Volt::route('reports', 'reports.index')
    ->middleware(['auth'])
    ->name('reports.index');

Volt::route('recurring-transactions', 'recurring-transactions.index')
    ->middleware(['auth'])
    ->name('recurring-transactions.index');

require __DIR__.'/auth.php';
