<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumns('financial_accounts', [
            'user_id',
            'name',
            'institution',
            'account_type',
            'currency',
            'starting_balance',
        ])) {
            return;
        }

        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->foreignId('user_id')->after('id')->constrained()->cascadeOnDelete();
            $table->string('name')->after('user_id');
            $table->string('institution')->nullable()->after('name');
            $table->string('account_type')->after('institution');
            $table->string('currency', 3)->default('PHP')->after('account_type');
            $table->decimal('starting_balance', 15, 2)->default(0)->after('currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn([
                'name',
                'institution',
                'account_type',
                'currency',
                'starting_balance',
            ]);
        });
    }
};
