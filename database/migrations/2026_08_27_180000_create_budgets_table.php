<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('currency', 3);
            $table->timestamps();

            $table->index(['user_id', 'start_date', 'end_date']);
            $table->index(['user_id', 'category_id', 'currency', 'start_date']);
            $table->unique(['user_id', 'category_id', 'currency', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
