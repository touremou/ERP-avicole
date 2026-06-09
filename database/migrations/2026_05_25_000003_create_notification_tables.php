<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ajout du numéro WhatsApp sur la table users
        if (! Schema::hasColumn('users', 'whatsapp_phone')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('whatsapp_phone', 30)->nullable()->after('email');
            });
        }

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);

            // Canaux
            $table->boolean('channel_whatsapp')->default(true);
            $table->boolean('channel_database')->default(true);    // In-app toujours actif
            $table->boolean('channel_sms')->default(false);

            // Types de notifications
            $table->boolean('daily_summary')->default(true);        // Résumé 7h
            $table->boolean('alert_mortality')->default(true);
            $table->boolean('alert_stock')->default(true);
            $table->boolean('alert_energy')->default(true);
            $table->boolean('alert_sales')->default(false);
            $table->boolean('alert_fraud')->default(true);

            // Heures silencieuses (pas de WhatsApp entre 22h et 6h)
            $table->time('quiet_start')->default('22:00');
            $table->time('quiet_end')->default('06:00');

            $table->timestamps();

            $table->unique('user_id');
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 20);                          // whatsapp, sms, database
            $table->string('type', 50);                             // daily_summary, mortality_spike, etc.
            $table->string('title');
            $table->text('message');
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->json('provider_response')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notification_preferences');

        if (Schema::hasColumn('users', 'whatsapp_phone')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('whatsapp_phone');
            });
        }
    }
};
