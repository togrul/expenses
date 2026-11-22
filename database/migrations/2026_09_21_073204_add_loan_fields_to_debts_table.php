<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debts', function (Blueprint $table) {
            $table->string('kind', 10)->default('personal')->after('direction');
            $table->unsignedBigInteger('principal')->nullable()->after('total_amount');
            $table->decimal('interest_rate', 6, 3)->nullable()->after('principal');
            $table->unsignedSmallInteger('term_months')->nullable()->after('interest_rate');
            $table->string('schedule_type', 12)->nullable()->after('term_months');
        });

        Schema::table('debt_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('principal_amount')->nullable()->after('amount');
            $table->unsignedBigInteger('interest_amount')->nullable()->after('principal_amount');
            $table->unsignedSmallInteger('installment_number')->nullable()->after('interest_amount');
        });
    }

    public function down(): void
    {
        Schema::table('debt_payments', function (Blueprint $table) {
            $table->dropColumn(['principal_amount', 'interest_amount', 'installment_number']);
        });

        Schema::table('debts', function (Blueprint $table) {
            $table->dropColumn(['kind', 'principal', 'interest_rate', 'term_months', 'schedule_type']);
        });
    }
};
