<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        // Sécurisation de la table buildings
        Schema::table('buildings', function (Blueprint $table) {
            if (!Schema::hasColumn('buildings', 'min_sanitary_days')) {
                $table->integer('min_sanitary_days')->default(14);
            }
            if (!Schema::hasColumn('buildings', 'max_sanitary_days')) {
                $table->integer('max_sanitary_days')->default(21);
            }
        });

        // Sécurisation de la table stocks
        Schema::table('stocks', function (Blueprint $table) {
            if (!Schema::hasColumn('stocks', 'alert_threshold')) {
                $table->decimal('alert_threshold', 10, 2)->default(10.00);
            }
        });
    }

    public function down() {
        Schema::table('buildings', function (Blueprint $table) {
            $table->dropColumn(['min_sanitary_days', 'max_sanitary_days']);
        });
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropColumn('alert_threshold');
        });
    }
};