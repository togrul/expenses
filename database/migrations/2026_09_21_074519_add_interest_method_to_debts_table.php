<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debts', function (Blueprint $table) {
            $table->unsignedBigInteger('monthly_payment')->nullable()->after('schedule_type');
            $table->string('interest_method', 10)->nullable()->after('monthly_payment');
        });
    }

    public function down(): void
    {
        Schema::table('debts', function (Blueprint $table) {
            $table->dropColumn(['monthly_payment', 'interest_method']);
        });
    }
};
