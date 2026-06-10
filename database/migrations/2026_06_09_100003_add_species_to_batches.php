<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('batches', function (Blueprint $table) {
            if (! Schema::hasColumn('batches', 'species_id')) {
                $table->foreignId('species_id')->nullable()->after('farm_id')
                    ->constrained('species')->nullOnDelete();
            }
            if (! Schema::hasColumn('batches', 'production_type_id')) {
                $table->foreignId('production_type_id')->nullable()->after('species_id')
                    ->constrained('production_types')->nullOnDelete();
            }
        });
    }
    public function down(): void {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\Species::class);
            $table->dropForeignIdFor(\App\Models\ProductionType::class);
            $table->dropColumn(['species_id', 'production_type_id']);
        });
    }
};
