<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Licence d'abonnement activée sur l'instance (monétisation).
 *
 * Une ligne par activation : la plus récente fait foi (renouvellement =
 * nouvelle ligne). On conserve l'historique pour l'audit commercial. Tous les
 * champs proviennent du jeton signé, recopiés pour l'affichage et les contrôles
 * (le jeton brut est conservé dans `token` pour re-vérification).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->string('identifiant');                 // identifiant client (clé d'activation)
            $table->string('client_name')->nullable();
            $table->string('plan')->default('basic');
            $table->json('modules')->nullable();           // modules déverrouillés (slugs ou ['*'])
            $table->unsignedInteger('max_users')->default(0); // 0 = illimité
            $table->unsignedInteger('max_farms')->default(0);
            $table->unsignedInteger('sms_quota')->default(0);
            $table->unsignedInteger('sms_used')->default(0);
            $table->string('fingerprint')->nullable();     // liaison domaine optionnelle
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable(); // toujours renseigné via le jeton signé ; nullable pour compat MySQL strict (NO_ZERO_DATE)
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_seen_at')->nullable(); // anti-recul d'horloge
            $table->text('token');                         // jeton signé brut
            $table->timestamps();

            $table->index(['identifiant', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
