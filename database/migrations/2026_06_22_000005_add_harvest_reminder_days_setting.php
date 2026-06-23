<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->updateOrInsert(
            ['group' => 'cultures', 'key' => 'harvest_reminder_days', 'farm_id' => null],
            [
                'value'         => '7',
                'type'          => 'number',
                'label'         => 'Fenêtre rappel récolte (jours)',
                'description'   => 'Nombre de jours avant la récolte prévue pour déclencher un rappel.',
                'unit'          => 'jours',
                'display_order' => 10,
                'is_sensitive'  => false,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('group', 'cultures')
            ->where('key', 'harvest_reminder_days')
            ->whereNull('farm_id')
            ->delete();
    }
};
