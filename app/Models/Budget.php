<?php

namespace App\Models;

use Database\Factories\BudgetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['category_id', 'amount', 'start_date', 'end_date', 'currency'])]
class Budget extends Model
{
    /** @use HasFactory<BudgetFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function getRemainingAttribute(): float
    {
        return (float) $this->amount - (float) ($this->spent ?? 0);
    }

    public function getUsagePercentageAttribute(): float
    {
        return ((float) ($this->spent ?? 0) / (float) $this->amount) * 100;
    }

    public function getStatusAttribute(): string
    {
        return match (true) {
            $this->usage_percentage > 100 => 'Over budget',
            $this->usage_percentage >= 80 => 'Near limit',
            default => 'On track',
        };
    }
}
