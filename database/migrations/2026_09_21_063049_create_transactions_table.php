<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('AZN');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->date('occurred_on')->index();
            $table->string('note', 255)->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'occurred_on']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
