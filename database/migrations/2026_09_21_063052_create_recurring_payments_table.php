<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('AZN');
            $table->boolean('is_variable')->default(false);
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('frequency', 10)->default('monthly');
            $table->unsignedTinyInteger('interval')->default(1);
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->unsignedTinyInteger('month_of_year')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedTinyInteger('remind_days_before')->default(3);
            $table->boolean('is_active')->default(true);
            $table->string('note', 255)->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_payments');
    }
};
