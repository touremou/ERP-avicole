<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatches', function (Blueprint $table) {
            if (! Schema::hasColumn('dispatches', 'intended_receiver_id')) {
                // Récepteur désigné à la création de l'expédition : c'est lui
                // qui sera notifié et qui pourra valider la réception (un
                // responsable logistique.M restant en secours). On pointe sur
                // users.id car valider = se connecter (comparaison Auth::id()).
                $table->foreignId('intended_receiver_id')
                    ->nullable()
                    ->after('dispatched_by')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('dispatches', function (Blueprint $table) {
            if (Schema::hasColumn('dispatches', 'intended_receiver_id')) {
                $table->dropConstrainedForeignId('intended_receiver_id');
            }
        });
    }
};
