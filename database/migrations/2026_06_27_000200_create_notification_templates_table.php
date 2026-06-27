<?php

use App\Models\NotificationTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100);
            $table->string('channel', 20)->default('whatsapp');
            $table->string('label');
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['key', 'channel']);
        });

        // Pré-remplit la table à partir du catalogue livré, pour que les
        // messages soient immédiatement visibles et éditables dans l'IHM.
        $now = now();
        foreach (NotificationTemplate::catalog() as $key => $tpl) {
            DB::table('notification_templates')->insert([
                'key'        => $key,
                'channel'    => 'whatsapp',
                'label'      => $tpl['label'],
                'body'       => $tpl['default'],
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
