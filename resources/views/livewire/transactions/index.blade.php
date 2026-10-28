<?php

use App\Models\FinancialAccount;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\TransactionCsvImporter;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;
    use WithPagination;

    public ?int $financial_account_id = null;
    public ?int $category_id = null;
    public string $type = 'expense';
    public string $description = '';
    public string $amount = '';
    public string $transaction_date = '';
    public ?int $editingTransactionId = null;

    public bool $showManualUpload = false;

    public string $importStep = 'account';

    public ?int $importFinancialAccountId = null;

    public $csvFile = null;

    /** @var list<string> */
    public array $importHeaders = [];

    /** @var list<list<string>> */
    public array $importRows = [];

    public int $importColumnCount = 0;

    public bool $importHasHeader = false;

    public string $importDateColumn = '';

    public string $importDateFormat = '';

    public string $importAmountColumn = '';

    public string $importTypeColumn = '';

    /** @var list<array{index: int, line: int, transaction_date: ?string, type: ?string, amount: ?string, errors: list<string>, valid: bool}> */
    public array $importPreview = [];

    /** @var list<int|string> */
    public array $selectedImportRows = [];

    public string $filterDateFrom = '';

    public string $filterDateTo = '';

    public string $filterAmount = '';

    public string $filterType = '';

    public string $filterFinancialAccountId = '';

    public string $appliedDateFrom = '';

    public string $appliedDateTo = '';

    public string $appliedAmount = '';

    public string $appliedType = '';

    public string $appliedFinancialAccountId = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Transaction::class);
        $this->transaction_date = now()->toDateString();
    }

    public function getTransactionsProperty()
    {
        $datesAreOrdered = $this->appliedDateFrom === '' || $this->appliedDateTo === '' || $this->appliedDateFrom <= $this->appliedDateTo;

        return Auth::user()->transactions()
            ->with(['financialAccount', 'category'])
            ->when($datesAreOrdered && $this->appliedDateFrom !== '', fn ($query) => $query->whereDate('transaction_date', '>=', $this->appliedDateFrom))
            ->when($datesAreOrdered && $this->appliedDateTo !== '', fn ($query) => $query->whereDate('transaction_date', '<=', $this->appliedDateTo))
            ->when($this->appliedAmount !== '' && is_numeric($this->appliedAmount), fn ($query) => $query->where('amount', number_format((float) $this->appliedAmount, 2, '.', '')))
            ->when(in_array($this->appliedType, Transaction::TYPES, true), fn ($query) => $query->where('type', $this->appliedType))
            ->when($this->appliedFinancialAccountId !== '', fn ($query) => $query->where('financial_account_id', $this->appliedFinancialAccountId))
            ->latest('transaction_date')
            ->latest('id')
            ->paginate(5);
    }

    public function applyTransactionFilters(): void
    {
        $this->validateDateFilters();
        $this->appliedDateFrom = $this->filterDateFrom;
        $this->appliedDateTo = $this->filterDateTo;
        $this->appliedAmount = $this->filterAmount;
        $this->appliedType = $this->filterType;
        $this->appliedFinancialAccountId = $this->filterFinancialAccountId;
        $this->resetPage();
    }

    public function clearTransactionFilters(): void
    {
        $this->reset([
            'filterDateFrom',
            'filterDateTo',
            'filterAmount',
            'filterType',
            'filterFinancialAccountId',
            'appliedDateFrom',
            'appliedDateTo',
            'appliedAmount',
            'appliedType',
            'appliedFinancialAccountId',
        ]);
        $this->resetValidation(['filterDateFrom', 'filterDateTo']);
        $this->resetPage();
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
        $this->category_id = $transaction->category_id;
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

    public function startManualUpload(): void
    {
        $this->authorize('create', Transaction::class);
        $this->resetImport();
        $this->showManualUpload = true;
        $this->importStep = 'account';
    }

    public function cancelManualUpload(): void
    {
        $this->resetImport();
    }

    public function continueImportToFile(): void
    {
        $this->authorize('create', Transaction::class);
        $this->validate([
            'importFinancialAccountId' => $this->importAccountRules(),
        ]);
        $this->importStep = 'file';
    }

    public function readImportFile(): void
    {
        $this->authorize('create', Transaction::class);
        $this->validate([
            'importFinancialAccountId' => $this->importAccountRules(),
        ]);

        if ($this->csvFile) {
            $this->validate([
                'csvFile' => ['required', 'file', 'extensions:csv', 'max:2048'],
            ]);

            try {
                $parsed = app(TransactionCsvImporter::class)->read($this->csvFile->get());
            } catch (\InvalidArgumentException $exception) {
                $this->addError('csvFile', $exception->getMessage());

                return;
            }

            $suggestion = app(TransactionCsvImporter::class)->suggestMapping($parsed['rows'][0], $parsed['column_count']);
            $this->importHeaders = $parsed['headers'];
            $this->importRows = $parsed['rows'];
            $this->importColumnCount = $parsed['column_count'];
            $this->importHasHeader = $parsed['has_header'];
            $this->importDateColumn = $suggestion['date'] === null ? '' : (string) $suggestion['date'];
            $this->importDateFormat = '';
            $this->importAmountColumn = $suggestion['amount'] === null ? '' : (string) $suggestion['amount'];
            $this->importTypeColumn = $suggestion['type'] === null ? '' : (string) $suggestion['type'];
            $this->importPreview = [];
            $this->selectedImportRows = [];
            $this->reset('csvFile');
        } elseif ($this->importRows === []) {
            $this->addError('csvFile', 'Choose a CSV file.');

            return;
        }

        $this->importStep = 'mapping';
    }

    public function continueImportToSummary(): void
    {
        $this->authorize('create', Transaction::class);

        $columnRules = ['required', 'integer', 'min:0', 'max:'.max(0, $this->importColumnCount - 1)];
        $rules = [
            'importDateColumn' => $columnRules,
            'importDateFormat' => ['required', Rule::in(array_keys(TransactionCsvImporter::DATE_FORMATS))],
            'importAmountColumn' => [...$columnRules, 'different:importDateColumn'],
        ];

        if ($this->importColumnCount === 3) {
            $rules['importTypeColumn'] = [...$columnRules, 'different:importDateColumn', 'different:importAmountColumn'];
        }

        $this->validate($rules);

        $this->importPreview = $this->buildImportPreview();
        $this->selectedImportRows = array_map(
            fn (array $row) => (string) $row['index'],
            array_values(array_filter($this->importPreview, fn (array $row) => $row['valid']))
        );
        $this->importStep = 'summary';
    }

    public function uploadImportedTransactions(): void
    {
        $this->authorize('create', Transaction::class);

        $validated = $this->validate([
            'importFinancialAccountId' => $this->importAccountRules(),
        ]);

        $preview = $this->buildImportPreview();
        $selected = array_map('strval', $this->selectedImportRows);
        $rows = array_values(array_filter(
            $preview,
            fn (array $row) => $row['valid'] && in_array((string) $row['index'], $selected, true)
        ));

        if ($rows === []) {
            $this->addError('selectedImportRows', 'Select at least one transaction without a warning.');

            return;
        }

        DB::transaction(function () use ($rows, $validated): void {
            foreach ($rows as $row) {
                Auth::user()->transactions()->create([
                    'financial_account_id' => $validated['importFinancialAccountId'],
                    'category_id' => null,
                    'type' => $row['type'],
                    'description' => null,
                    'amount' => $row['amount'],
                    'transaction_date' => $row['transaction_date'],
                ]);
            }
        });

        $count = count($rows);
        $this->resetImport();
        session()->flash('status', $count === 1 ? '1 transaction imported.' : "{$count} transactions imported.");
    }

    public function backImportStep(): void
    {
        $this->importStep = match ($this->importStep) {
            'file' => 'account',
            'mapping' => 'file',
            'summary' => 'mapping',
            default => 'account',
        };
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
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')
                    ->where(fn (Builder $query) => $query
                        ->where('user_id', Auth::id())
                        ->where('type', $this->type)),
            ],
            'type' => ['required', Rule::in(Transaction::TYPES)],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'transaction_date' => ['required', 'date'],
        ];
    }

    private function resetForm(): void
    {
        $this->reset(['financial_account_id', 'category_id', 'description', 'amount', 'editingTransactionId']);
        $this->type = 'expense';
        $this->transaction_date = now()->toDateString();
    }

    /**
     * @return list<string|\Illuminate\Validation\Rules\Exists>
     */
    private function validateDateFilters(): void
    {
        $toRules = ['nullable', 'date'];

        if ($this->filterDateFrom !== '') {
            $toRules[] = 'after_or_equal:filterDateFrom';
        }

        $this->validate([
            'filterDateFrom' => ['nullable', 'date'],
            'filterDateTo' => $toRules,
        ]);
    }

    private function importAccountRules(): array
    {
        return [
            'required',
            'integer',
            Rule::exists('financial_accounts', 'id')
                ->where(fn (Builder $query) => $query->where('user_id', Auth::id())),
        ];
    }

    /**
     * @return list<array{index: int, line: int, transaction_date: ?string, type: ?string, amount: ?string, errors: list<string>, valid: bool}>
     */
    private function buildImportPreview(): array
    {
        if ($this->importRows === [] || $this->importDateColumn === '' || $this->importAmountColumn === '' || $this->importDateFormat === '') {
            return [];
        }

        return app(TransactionCsvImporter::class)->preview(
            $this->importRows,
            (int) $this->importDateColumn,
            (int) $this->importAmountColumn,
            $this->importColumnCount === 3 && $this->importTypeColumn !== '' ? (int) $this->importTypeColumn : null,
            $this->importHasHeader,
            $this->importDateFormat,
        );
    }

    private function resetImport(): void
    {
        $this->reset([
            'showManualUpload',
            'importStep',
            'importFinancialAccountId',
            'csvFile',
            'importHeaders',
            'importRows',
            'importColumnCount',
            'importHasHeader',
            'importDateColumn',
            'importDateFormat',
            'importAmountColumn',
            'importTypeColumn',
            'importPreview',
            'selectedImportRows',
        ]);
        $this->importStep = 'account';
        $this->resetErrorBag();
    }
}; ?>

<x-slot name="header">
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        {{ __('Transactions') }}
    </h2>
</x-slot>

<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @if ($showManualUpload)
            <section class="p-6 bg-white shadow-sm sm:rounded-lg">
                <header class="flex items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900">{{ __('Manual upload') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">
                            @if ($importStep === 'account')
                                {{ __('Step 1 of 4: Choose an account') }}
                            @elseif ($importStep === 'file')
                                {{ __('Step 2 of 4: Upload a CSV file') }}
                            @elseif ($importStep === 'mapping')
                                {{ __('Step 3 of 4: Map columns') }}
                            @else
                                {{ __('Step 4 of 4: Review and upload') }}
                            @endif
                        </p>
                    </div>
                    <x-secondary-button type="button" wire:click="cancelManualUpload">{{ __('Cancel') }}</x-secondary-button>
                </header>

                @if ($importStep === 'account')
                    <form wire:submit="continueImportToFile" class="mt-6 space-y-6">
                        @if ($this->financialAccounts->isEmpty())
                            <p class="text-sm text-gray-600">
                                {{ __('Add a financial account before importing transactions.') }}
                                <a href="{{ route('financial-accounts.index') }}" class="underline text-gray-900" wire:navigate>{{ __('Financial Accounts') }}</a>
                            </p>
                        @else
                            <div>
                                <x-input-label for="import_financial_account_id" :value="__('Financial account')" />
                                <select wire:model="importFinancialAccountId" id="import_financial_account_id" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" required>
                                    <option value="">{{ __('Select an account') }}</option>
                                    @foreach ($this->financialAccounts as $account)
                                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('importFinancialAccountId')" class="mt-2" />
                            </div>
                            <x-primary-button>{{ __('Next') }}</x-primary-button>
                        @endif
                    </form>
                @elseif ($importStep === 'file')
                    <form wire:submit="readImportFile" class="mt-6 space-y-6">
                        <div>
                            <x-input-label for="csvFile" :value="__('CSV file')" />
                            <input wire:model.live="csvFile" id="csvFile" type="file" accept=".csv,text/csv" class="mt-1 block w-full text-sm text-gray-600" />
                            <p class="mt-2 text-sm text-gray-600" wire:loading wire:target="csvFile">{{ __('Uploading...') }}</p>
                            <p class="mt-2 text-sm text-gray-600">{{ __('Use a CSV with 2 columns (date and amount) or 3 columns (date, amount, and type).') }}</p>
                            <x-input-error :messages="$errors->get('csvFile')" class="mt-2" />
                        </div>
                        <div class="flex items-center gap-4">
                            <x-secondary-button type="button" wire:click="backImportStep">{{ __('Back') }}</x-secondary-button>
                            <span wire:loading wire:target="csvFile">
                                <x-primary-button type="button" class="disabled:opacity-25" disabled>{{ __('Next') }}</x-primary-button>
                            </span>
                            <span wire:loading.remove wire:target="csvFile">
                                @if ($csvFile || $importRows !== [])
                                    <x-primary-button>{{ __('Next') }}</x-primary-button>
                                @else
                                    <x-primary-button type="button" class="disabled:opacity-25" disabled>{{ __('Next') }}</x-primary-button>
                                @endif
                            </span>
                        </div>
                    </form>
                @elseif ($importStep === 'mapping')
                    <form wire:submit="continueImportToSummary" class="mt-6 space-y-6">
                        <p class="text-sm text-gray-600">
                            @if ($importColumnCount === 2)
                                {{ __('This file has 2 columns. A negative amount is an expense and a positive amount is income.') }}
                            @else
                                {{ __('This file has 3 columns. Type must be income or expense.') }}
                            @endif
                        </p>
                        <div class="grid gap-6 sm:grid-cols-2">
                            <div>
                                <x-input-label for="import_date_column" :value="__('Which column is the date?')" />
                                <select wire:model="importDateColumn" id="import_date_column" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" required>
                                    <option value="">{{ __('Select a column') }}</option>
                                    @foreach (array_slice(\App\Services\TransactionCsvImporter::COLUMN_LABELS, 0, $importColumnCount) as $index => $label)
                                        <option value="{{ $index }}">{{ __($label) }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('importDateColumn')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="import_date_format" :value="__('What is the date format used in the CSV file?')" />
                                <select wire:model="importDateFormat" id="import_date_format" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" required>
                                    <option value="">{{ __('Select a format') }}</option>
                                    @foreach (\App\Services\TransactionCsvImporter::DATE_FORMATS as $format => $label)
                                        <option value="{{ $format }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('importDateFormat')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="import_amount_column" :value="__('Which column is the amount?')" />
                                <select wire:model="importAmountColumn" id="import_amount_column" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" required>
                                    <option value="">{{ __('Select a column') }}</option>
                                    @foreach (array_slice(\App\Services\TransactionCsvImporter::COLUMN_LABELS, 0, $importColumnCount) as $index => $label)
                                        <option value="{{ $index }}">{{ __($label) }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('importAmountColumn')" class="mt-2" />
                            </div>
                            @if ($importColumnCount === 3)
                                <div>
                                    <x-input-label for="import_type_column" :value="__('Which column is the type?')" />
                                    <select wire:model="importTypeColumn" id="import_type_column" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" required>
                                        <option value="">{{ __('Select a column') }}</option>
                                        @foreach (array_slice(\App\Services\TransactionCsvImporter::COLUMN_LABELS, 0, $importColumnCount) as $index => $label)
                                            <option value="{{ $index }}">{{ __($label) }}</option>
                                        @endforeach
                                    </select>
                                    <x-input-error :messages="$errors->get('importTypeColumn')" class="mt-2" />
                                </div>
                            @endif
                        </div>
                        <div class="flex items-center gap-4">
                            <x-secondary-button type="button" wire:click="backImportStep">{{ __('Back') }}</x-secondary-button>
                            <x-primary-button>{{ __('Next') }}</x-primary-button>
                        </div>
                    </form>
                @else
                    <div class="mt-6 space-y-6">
                        <p class="text-sm text-gray-600">{{ __('Rows with a warning cannot be selected. Checked rows are the ones that will be uploaded.') }}</p>
                        <x-input-error :messages="$errors->get('selectedImportRows')" class="mt-2" />
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="text-left text-gray-600">
                                        <th class="py-2 pr-4 font-medium">{{ __('Import') }}</th>
                                        <th class="py-2 pr-4 font-medium">{{ __('Row') }}</th>
                                        <th class="py-2 pr-4 font-medium">{{ __('Date') }}</th>
                                        <th class="py-2 pr-4 font-medium">{{ __('Type') }}</th>
                                        <th class="py-2 font-medium">{{ __('Amount') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($importPreview as $row)
                                        <tr wire:key="import-row-{{ $row['index'] }}" class="border-t border-gray-200 {{ $row['valid'] ? '' : 'bg-amber-50' }}">
                                            <td class="py-3 pr-4">
                                                @if ($row['valid'])
                                                    <input type="checkbox" value="{{ $row['index'] }}" wire:model.live="selectedImportRows" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" aria-label="{{ __('Select row :row', ['row' => $row['line']]) }}" />
                                                @endif
                                            </td>
                                            <td class="py-3 pr-4 text-gray-900">{{ $row['line'] }}</td>
                                            <td class="py-3 pr-4 text-gray-900">{{ $row['transaction_date'] ?: '—' }}</td>
                                            <td class="py-3 pr-4 text-gray-900">{{ $row['type'] ? ucfirst($row['type']) : '—' }}</td>
                                            <td class="py-3 text-gray-900">
                                                <span class="inline-flex items-center gap-2">
                                                    <span>{{ $row['amount'] ?: '—' }}</span>
                                                    @if (! $row['valid'])
                                                        <span class="inline-flex items-center gap-1 text-amber-700">
                                                            <svg class="size-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                                <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 6a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 6zm0 8a1 1 0 1 0 0-2 1 1 0 0 0 0 2z" clip-rule="evenodd" />
                                                            </svg>
                                                            <span>{{ implode(' ', $row['errors']) }}</span>
                                                        </span>
                                                    @endif
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="flex items-center gap-4">
                            <x-secondary-button type="button" wire:click="backImportStep">{{ __('Back') }}</x-secondary-button>
                            @if (count($selectedImportRows) === 0)
                                <x-primary-button type="button" class="disabled:opacity-25" disabled>{{ __('Upload') }}</x-primary-button>
                            @else
                                <x-primary-button type="button" wire:click="uploadImportedTransactions">{{ __('Upload') }}</x-primary-button>
                            @endif
                        </div>
                    </div>
                @endif
            </section>
        @endif

        <section class="p-6 bg-white shadow-sm sm:rounded-lg">
            <header class="flex items-center justify-between gap-4">
                <h3 class="text-lg font-medium text-gray-900">
                    {{ $editingTransactionId ? __('Edit transaction') : __('Add transaction') }}
                </h3>
                @unless ($showManualUpload)
                    <x-secondary-button type="button" wire:click="startManualUpload">{{ __('Manual upload') }}</x-secondary-button>
                @endunless
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
                        <select wire:model.live="type" id="type" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" required>
                            @foreach (\App\Models\Transaction::TYPES as $transactionType)
                                <option value="{{ $transactionType }}">{{ ucfirst($transactionType) }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('type')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="category_id" :value="__('Category (optional)')" />
                        <select wire:model="category_id" id="category_id" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="">{{ __('No category') }}</option>
                            @foreach ($this->categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('category_id')" class="mt-2" />
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

            <form wire:submit="applyTransactionFilters" class="mt-6 space-y-4">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <x-input-label for="filter_date_from" :value="__('Date from')" />
                        <x-text-input wire:model="filterDateFrom" id="filter_date_from" class="mt-1 block w-full" type="date" />
                        <x-input-error :messages="$errors->get('filterDateFrom')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="filter_date_to" :value="__('Date to')" />
                        <x-text-input wire:model="filterDateTo" id="filter_date_to" class="mt-1 block w-full" type="date" />
                        <x-input-error :messages="$errors->get('filterDateTo')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="filter_amount" :value="__('Amount')" />
                        <x-text-input wire:model="filterAmount" id="filter_amount" class="mt-1 block w-full" type="number" min="0.01" step="0.01" />
                        <x-input-error :messages="$errors->get('filterAmount')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="filter_type" :value="__('Type')" />
                        <select wire:model="filterType" id="filter_type" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="">{{ __('All') }}</option>
                            @foreach (\App\Models\Transaction::TYPES as $transactionType)
                                <option value="{{ $transactionType }}">{{ ucfirst($transactionType) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="filter_financial_account_id" :value="__('Financial account')" />
                        <select wire:model="filterFinancialAccountId" id="filter_financial_account_id" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="">{{ __('All') }}</option>
                            @foreach ($this->financialAccounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <x-primary-button>{{ __('Apply') }}</x-primary-button>
                    <x-secondary-button type="button" wire:click="clearTransactionFilters">{{ __('Clear') }}</x-secondary-button>
                </div>
            </form>

            @if (session('status'))
                <p class="mt-4 text-sm font-medium text-green-600">{{ session('status') }}</p>
            @endif

            <div class="mt-6 space-y-4">
                @forelse ($this->transactions as $transaction)
                    <article wire:key="transaction-{{ $transaction->id }}" class="flex flex-col gap-4 border border-gray-200 rounded-md p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h4 class="font-medium text-gray-900">{{ $transaction->description ?: ucfirst($transaction->type) }}</h4>
                            <p class="text-sm text-gray-600">
                                {{ $transaction->financialAccount->name }}
                                @if ($transaction->category)
                                    · {{ $transaction->category->name }}
                                @endif
                                · {{ $transaction->transaction_date->format('M j, Y') }}
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
                    <p class="text-sm text-gray-600">
                        {{ ($appliedDateFrom || $appliedDateTo || $appliedAmount !== '' || $appliedType !== '' || $appliedFinancialAccountId !== '') ? __('No transactions match these filters.') : __('No transactions yet.') }}
                    </p>
                @endforelse
            </div>

            <div class="mt-6">
                {{ $this->transactions->links() }}
            </div>
        </section>
    </div>
</div>