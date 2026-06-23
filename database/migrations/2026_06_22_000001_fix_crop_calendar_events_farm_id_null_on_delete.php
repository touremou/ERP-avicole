<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('crop_calendar_events', function (Blueprint $table) {
            $table->dropForeign(['farm_id']);
            $table->foreign('farm_id')->references('id')->on('farms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crop_calendar_events', function (Blueprint $table) {
            $table->dropForeign(['farm_id']);
            $table->foreign('farm_id')->references('id')->on('farms')->cascadeOnDelete();
        });
    }
};
