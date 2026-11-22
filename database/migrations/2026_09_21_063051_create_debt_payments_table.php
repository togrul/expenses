<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('due_on')->nullable();
            $table->date('paid_at')->nullable();
            $table->unsignedBigInteger('paid_amount')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('split_from_id')->nullable()->constrained('debt_payments')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['debt_id', 'paid_at']);
            $table->index('due_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_payments');
    }
};
