<?php

namespace App\Models;

use Database\Factories\FinancialAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'institution', 'account_type', 'currency', 'starting_balance'])]
class FinancialAccount extends Model
{
    /** @use HasFactory<FinancialAccountFactory> */
    use HasFactory;

    public const ACCOUNT_TYPES = [
        'Bank',
        'E-Wallet',
        'Cash',
        'Savings',
        'Investment',
        'Brokerage',
        'Credit Card',
        'Loan',
        'Other',
    ];

    protected function casts(): array
    {
        return [
            'starting_balance' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
