<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('energy_sources', function (Blueprint $table) {
            $table->string('serial_number')->nullable()->after('model');
            $table->date('purchase_date')->nullable()->after('serial_number');
            $table->decimal('purchase_price', 14, 2)->nullable()->after('purchase_date');
            $table->unsignedSmallInteger('depreciation_years')->default(10)->after('purchase_price');
            $table->date('warranty_expiry')->nullable()->after('depreciation_years');
            $table->string('service_contract_ref')->nullable()->after('warranty_expiry');
        });
    }

    public function down(): void
    {
        Schema::table('energy_sources', function (Blueprint $table) {
            $table->dropColumn([
                'serial_number', 'purchase_date', 'purchase_price',
                'depreciation_years', 'warranty_expiry', 'service_contract_ref',
            ]);
        });
    }
};
