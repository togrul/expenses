<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_payment_id')->constrained()->cascadeOnDelete();
            $table->date('due_on');
            $table->unsignedBigInteger('amount');
            $table->date('paid_at')->nullable();
            $table->unsignedBigInteger('paid_amount')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['recurring_payment_id', 'due_on']);
            $table->index('due_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_occurrences');
    }
};
