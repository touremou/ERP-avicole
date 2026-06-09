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
        Schema::table('batches', function (Blueprint $table) {
            $table->decimal('actual_sell_price_per_unit', 15, 2)->nullable();
            $table->decimal('additional_costs', 15, 2)->default(0);
            $table->decimal('total_revenue', 15, 2)->nullable();
            $table->decimal('margin', 15, 2)->nullable();
            $table->date('closing_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropColumn(['actual_sell_price_per_unit', 'additional_costs', 'total_revenue', 'margin', 'closing_date']);
        });
    }
};
