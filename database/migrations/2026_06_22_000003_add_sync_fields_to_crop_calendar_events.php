<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('crop_calendar_events', function (Blueprint $table) {
            if (!Schema::hasColumn('crop_calendar_events', 'is_synced')) {
                $table->boolean('is_synced')->default(true)->after('notes');
                $table->timestamp('last_sync_at')->nullable()->after('is_synced');
            }
        });
    }

    public function down(): void
    {
        Schema::table('crop_calendar_events', function (Blueprint $table) {
            $table->dropColumn(['is_synced', 'last_sync_at']);
        });
    }
};
