<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('weather_readings', function (Blueprint $table) {
            $table->index('plot_id', 'weather_readings_plot_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('weather_readings', function (Blueprint $table) {
            $table->dropIndex('weather_readings_plot_id_index');
        });
    }
};
