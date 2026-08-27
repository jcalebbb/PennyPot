<?php

namespace App\Console\Commands;

use App\Models\RecurringTransaction;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateRecurringTransactions extends Command
{
    protected $signature = 'recurring-transactions:generate {--date= : Generate occurrences due on or before this date (YYYY-MM-DD)}';

    protected $description = 'Generate normal transactions for due recurring transactions';

    public function handle(): int
    {
        $generationDate = $this->generationDate();
        $ids = RecurringTransaction::query()
            ->where('is_active', true)
            ->whereDate('next_occurrence', '<=', $generationDate)
            ->pluck('id');
        $generated = 0;

        foreach ($ids as $id) {
            $generated += $this->processRecurringTransaction($id, $generationDate);
        }

        $this->info("Generated {$generated} transaction(s).");

        return self::SUCCESS;
    }

    private function processRecurringTransaction(int $id, Carbon $generationDate): int
    {
        return DB::transaction(function () use ($id, $generationDate): int {
            $recurring = RecurringTransaction::query()->lockForUpdate()->find($id);

            if (! $recurring || ! $recurring->is_active || $recurring->next_occurrence->gt($generationDate)) {
                return 0;
            }

            $recurring->load(['user', 'financialAccount', 'category']);

            if (
                (int) $recurring->financialAccount->user_id !== (int) $recurring->user_id
                || ($recurring->category && (
                    (int) $recurring->category->user_id !== (int) $recurring->user_id
                    || $recurring->category->type !== $recurring->type
                ))
            ) {
                $this->warn("Skipped invalid recurring transaction {$recurring->id}.");

                return 0;
            }

            $generated = 0;
            $occurrence = $recurring->next_occurrence->copy();

            while ($occurrence->lte($generationDate)) {
                if ($recurring->end_date && $occurrence->gt($recurring->end_date)) {
                    $recurring->update(['is_active' => false]);

                    break;
                }

                $recurring->user->transactions()->firstOrCreate(
                    [
                        'recurring_transaction_id' => $recurring->id,
                        'transaction_date' => $occurrence->toDateString(),
                    ],
                    [
                        'user_id' => $recurring->user_id,
                        'financial_account_id' => $recurring->financial_account_id,
                        'category_id' => $recurring->category_id,
                        'type' => $recurring->type,
                        'description' => $recurring->description,
                        'amount' => $recurring->amount,
                    ],
                );
                $generated++;

                $nextOccurrence = $this->nextOccurrence($occurrence, $recurring);
                $recurring->update(['next_occurrence' => $nextOccurrence->toDateString()]);
                $occurrence = $nextOccurrence;

                if ($recurring->end_date && $occurrence->gt($recurring->end_date)) {
                    $recurring->update(['is_active' => false]);

                    break;
                }
            }

            return $generated;
        });
    }

    private function nextOccurrence(Carbon $occurrence, RecurringTransaction $recurring): Carbon
    {
        return match ($recurring->frequency) {
            'daily' => $occurrence->copy()->addDay(),
            'weekly' => $occurrence->copy()->addWeek(),
            'monthly' => $this->nextMonthlyOccurrence($occurrence, $recurring->start_date),
            'yearly' => $occurrence->copy()->addYearNoOverflow(),
        };
    }

    private function nextMonthlyOccurrence(Carbon $occurrence, Carbon $startDate): Carbon
    {
        $nextMonth = $occurrence->copy()->addMonthNoOverflow();

        if ($startDate->isLastOfMonth()) {
            return $nextMonth->endOfMonth();
        }

        return $nextMonth->day(min($startDate->day, $nextMonth->daysInMonth));
    }

    private function generationDate(): Carbon
    {
        $date = $this->option('date');

        if ($date) {
            return Carbon::createFromFormat('!Y-m-d', $date)->startOfDay();
        }

        return Carbon::today();
    }
}
