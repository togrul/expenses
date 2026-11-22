<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 12)->default('i_owe');
            $table->string('counterparty', 120);
            $table->string('title', 150)->nullable();
            $table->unsignedBigInteger('total_amount');
            $table->char('currency', 3)->default('AZN');
            $table->date('started_on')->nullable();
            $table->date('due_on')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debts');
    }
};
